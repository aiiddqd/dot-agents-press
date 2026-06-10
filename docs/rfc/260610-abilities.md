**RFC: Abilities System для плагина dot-agents-press**

**Название:** Dot Agents Press Abilities Architecture v1.0  
**Дата:** 10 июня 2026  
**Статус:** Draft (адаптировано под текущий код плагина)

### 1. Цель

Собрать расширяемую и безопасную систему Abilities для `_dot-agents-press`, где:
- каждая ability реализуется отдельным PHP-классом;
- все abilities физически находятся только в папке `abilities/`;
- регистрация abilities происходит автоматически при инициализации плагина;
- добавление новой ability не требует правок в нескольких местах.

Ключевой принцип: **single source of truth для abilities — директория `abilities/`.**

### 2. Привязка к текущей архитектуре плагина

В текущем плагине уже есть bootstrap через `dot-agents-press.php` и singleton `Dot_Agents_Press` (`includes/class-dot-agents-press.php`).

Интеграция abilities должна опираться на этот bootstrap, а не на отдельный изолированный entrypoint.

Предлагаемая точка подключения:
- загрузка registry-класса в `Dot_Agents_Press::load_dependencies()`;
- запуск регистрации abilities через хук `dot_agents_press_loaded` (или внутри конструктора после загрузки зависимостей);
- фактический вызов WordPress Abilities API только при наличии `wp_register_ability()`.

### 3. Целевая структура директорий

```bash
/wp-content/plugins/_dot-agents-press/
├── dot-agents-press.php
├── includes/
│   └── class-dot-agents-press.php
├── abilities/                                # ← все abilities только здесь
│   ├── base.php                              # базовый абстрактный класс
│   ├── registry.php                          # автодискавери и регистрация
│   ├── discover-skills.php
│   ├── read-file.php
│   ├── list-directory.php
│   ├── write-file.php
│   ├── execute-command.php
│   └── ...
├── agents-template/
└── docs/
```

Правило именования файлов abilities:
- имя файла должно совпадать с именем ability (slug-часть после `dot-agents-press/`);
- без префикса `class-` и без служебных суффиксов;
- инфраструктурные файлы: `base.php` и `registry.php`.

Пример соответствия:
- `dot-agents-press/read-file` → `abilities/read-file.php`
- `dot-agents-press/discover-skills` → `abilities/discover-skills.php`

### 4. Контракт базовой ability

```php
<?php

namespace DotAgentsPress\Abilities;

abstract class AbilityAbstract {

    public string $name; // dot-agents-press/read-file
    public string $label;
    public string $description;
    public string $category = 'dot-agents-press';

    abstract public function get_input_schema(): array;

    public function get_output_schema(): array {
        return [
            'type' => 'object',
        ];
    }

    abstract public function execute( array $args );

    public function get_definition(): array {
        return [
            'name'                => $this->name,
            'label'               => $this->label,
            'description'         => $this->description,
            'category'            => $this->category,
            'input_schema'        => $this->get_input_schema(),
            'output_schema'       => $this->get_output_schema(),
            'execute_callback'    => [ $this, 'execute' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ];
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }
}
```

### 5. Registry: автозагрузка и регистрация из abilities/

```php
<?php

namespace DotAgentsPress\Abilities;

class Registry {

    private string $abilities_dir;

    public function __construct( ?string $abilities_dir = null ) {
        $this->abilities_dir = $abilities_dir ?: DAP_PLUGIN_DIR . 'abilities/';
    }

    public function register_all(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        foreach ( $this->discover_files() as $file ) {
            require_once $file;

            $class_name = $this->resolve_class_from_file( $file );
            if ( ! class_exists( $class_name ) ) {
                continue;
            }

            $ability = new $class_name();
            if ( ! $ability instanceof AbilityAbstract ) {
                continue;
            }

            wp_register_ability( $ability->name, $ability->get_definition() );
        }
    }

    private function discover_files(): array {
        $files = glob( $this->abilities_dir . '*.php' ) ?: [];
        return array_values(
            array_filter(
                $files,
                static fn( string $path ) => ! str_ends_with( $path, 'base.php' )
                    && ! str_ends_with( $path, 'registry.php' )
            )
        );
    }

    private function resolve_class_from_file( string $file ): string {
        $slug = basename( $file, '.php' ); // read-file
        $slug = str_replace( '-', ' ', $slug );
        $slug = str_replace( ' ', '_', ucwords( $slug ) );

        return 'DotAgentsPress\\Abilities\\' . $slug;
    }
}
```

### 6. Подключение в текущий bootstrap

Рекомендуемый вариант для `includes/class-dot-agents-press.php`:

1. В `load_dependencies()` добавить:
- `require_once DAP_PLUGIN_DIR . 'abilities/base.php';`
- `require_once DAP_PLUGIN_DIR . 'abilities/registry.php';`

2. После `do_action( 'dot_agents_press_loaded' );` инициировать регистрацию:

```php
add_action( 'dot_agents_press_loaded', function () {
    $registry = new \DotAgentsPress\Abilities\Registry();
    $registry->register_all();
} );
```

Так abilities остаются полностью изолированы в `abilities/`, а ядро плагина знает только про registry.

### 7. Примеры abilities для dot-agents-press

Минимальный набор v1:
1. `dot-agents-press/discover-skills` — поиск `SKILL.md` в `.agents/skills` и `agents-template/skills`.
2. `dot-agents-press/read-file` — безопасное чтение файлов из allowlist-директорий.
3. `dot-agents-press/list-directory` — список файлов/папок (без чтения содержимого).
4. `dot-agents-press/write-file` — запись в разрешённые пути с защитой от path traversal.
5. `dot-agents-press/execute-command` — выполнение только whitelist-команд.

### 8. Безопасность (обязательные требования)

1. Все пути нормализуются через `realpath()` и проверяются на вхождение в allowlist.
2. Все shell-команды проверяются префиксным whitelist через фильтр `dap_ability_command_whitelist`.
3. Для ошибок использовать `WP_Error` со стабильными кодами (`access_denied`, `not_readable`, `forbidden`, `invalid_input`).
4. Доступ по умолчанию: `manage_options`, с возможностью расширить через фильтр/override в конкретной ability.
5. В логике abilities не должно быть прямых SQL-запросов без `$wpdb->prepare()`.

### 9. Расширяемость

1. Новая ability добавляется созданием нового файла в `abilities/` с именем, равным slug ability.
2. Registry автоматически подхватывает ее без изменений в bootstrap.
3. Поведение allowlist, лимитов и таймаутов настраивается через `apply_filters()`.
4. Для дорогих операций (`discover-skills`, скан директорий) допускается transient-кэш.

### 10. Критерии готовности v1

1. В репозитории создана директория `abilities/` и инфраструктурные классы (`base`, `registry`).
2. Не менее 3 рабочих abilities зарегистрированы через Abilities API.
3. Все abilities грузятся только из `abilities/` (без ручных require в других папках, кроме base/registry).
4. Проверены сценарии отказа: недоступный путь, команда вне whitelist, отсутствие прав.
5. Добавлена документация для разработчика: как создать новую ability за 1 файл.