# RFC: PoC — Telegram Bot Integration + .agents Protocol Entry Point

**Дата:** 2026-06-09
**Статус:** In Progress
**Плагин:** `_dot-agents-press`

---

## TL;DR

Реализовать PoC из трёх компонентов:
1. **Telegram Bot Bridge** — webhook-обработчик, принимает сообщения из Telegram и маршрутизирует в AI-агента через существующий chat endpoint плагина.
2. **Системный промпт** — загрузка `agents.md` и `system-prompt.md` из `.agents/` директории на один уровень выше `wp-config.php` (по спецификации [.agents Protocol](https://dotagentsprotocol.com/)).
3. **Entry point** — авто-обнаружение `.agents/agents.md` и `.agents/system-prompt.md` по пути `ABSPATH . '../.agents/'`.
4. Все новые классы пишем в папку `app`, а не `includes`.
---

## 1. Контекст и мотивация

Плагин `_dot-agents-press` уже умеет:
- Хранить агентов в БД (таблица `dap_agents`) с полями `system_prompt`, `provider`, `model`, `temperature`, `api_key`.
- Отдавать chat completion через REST API: `POST /wp-json/dot-agents-press/v1/chat`.
- Поддерживать OpenAI и Anthropic (OpenAI-compatible) провайдеров.

**Что нужно для PoC:**
- Дать возможность общаться с агентом через Telegram (личные сообщения боту).
- Научить агента читать системный промпт из файловой системы — из `.agents/agent.md` на уровне проекта (выше `wp-config.php`), а не только из БД.
- Это первый шаг к полноценной поддержке `.agents` Protocol (см. RFC `260608-mvp`).

---

## 2. Компоненты PoC

### Settings

example https://wpcraft.ru/wp-admin/options-general.php?page=dot-agents-config
class app/Settings.php

- default model - deepseek-v4-pro
- default provider - openrouter
- API Token - replace to telegram_bot_token
- telegram id - for protecting - only private assistant

### 2.1 Telegram Bot Bridge

**Файл:** `app/TelegramBridge.php`
**Класс:** `DotAgentsPress\TelegramBridge`

**Логика:**
1. Регистрирует REST endpoint: `POST /wp-json/dot-agents-press/v1/telegram/webhook`
2. Принимает update от Telegram Bot API (JSON).
3. Извлекает `message.text` и `message.chat.id`.
4. Вызывает существующий `DAP_API::handle_chat()` (или внутренний метод `DAP_Agent::chat()`) с сообщением пользователя.
5. Отправляет ответ обратно через Telegram Bot API (`sendMessage`).

**Упрощения PoC:**
- Один жёстко заданный agent_id (из настроек плагина или константы).
- Без сложной маршрутизации — все сообщения идут в одного агента.
- Без хранения истории диалога (каждый запрос — новый контекст, только system prompt + user message).
- Без поддержки медиа/файлов — только текст.

**Настройки (добавить в Settings.php):**
- `telegram_bot_token` — токен бота
- `telegram_webhook_secret` — секрет для верификации webhook (опционально)
- `telegram_default_agent_id` — ID агента по умолчанию

### 2.2 Системный промпт из .agents/

**Файл:** `app/AgentsProtocol.php`
**Класс:** `DotAgentsPress\AgentsProtocol`

**Спецификация:** [.agents Protocol](https://dotagentsprotocol.com/) определяет два ключевых файла:
- **`agents.md`** — инструкции/гайдлайны для агента (AGENTS.md-совместимый формат, build steps, conventions)
- **`system-prompt.md`** — собственно системный промпт

**Логика:**
1. При загрузке агента проверяет наличие файлов по пути:
   ```
   {ABSPATH}/../.agents/system-prompt.md   ← основной источник системного промпта
   {ABSPATH}/../.agents/agents.md           ← дополнительные инструкции
   ```
   Где `ABSPATH` — корень WordPress (там где `wp-config.php`).

2. **Стратегия слияния (PoC — вариант A):**
   - **`system-prompt.md`** (если есть) → используется как основной system prompt
   - **`agents.md`** (если есть) → добавляется в конец system prompt как «Project Guidelines»
   - **`system_prompt` из БД** → добавляется в конец (роль: агент-специфичный контекст из админки)
   - Итоговый порядок: `system-prompt.md` + `agents.md` + `system_prompt из БД`

3. Файлы не обязаны существовать — если нет, используется только `system_prompt` из БД.

4. **Совместимость с AGENTS.md:** файл `agents.md` семантически идентичен `AGENTS.md` из спецификации OpenAI/Linux Foundation. Для обратной совместимости также проверяется `../AGENTS.md` (без папки `.agents/`).

**Почему `../.agents/` (выше wp-config.php)?**
- `wp-config.php` лежит в корне WordPress (например, `/var/www/html/wp-config.php`).
- `.agents/` на уровень выше — это уровень проекта/репозитория (`/var/www/html/../.agents/` → `/var/www/.agents/`).
- Это соответствует `.agents` Protocol: конфигурация агента живёт в корне проекта, а не внутри `wp-content`.
- Альтернатива: если WordPress установлен как subdirectory (например, `project/public/wp-config.php`), то `.agents/` окажется в `project/.agents/` — это правильное поведение.

### 2.3 Entry Point: agents.md + system-prompt.md

**Спецификация обнаружения файлов:**

```php
// Порядок поиска (первый найденный — побеждает в своей категории):

// Категория: System Prompt
$system_prompt_paths = [
    ABSPATH . '../.agents/system-prompt.md',   // .agents Protocol (приоритет)
];

// Категория: Agent Instructions
$agents_md_paths = [
    ABSPATH . '../.agents/agents.md',           // .agents Protocol (приоритет)
    ABSPATH . '../AGENTS.md',                   // Legacy: AGENTS.md в корне проекта
];
```

- **Два независимых файла:** `system-prompt.md` и `agents.md` читаются раздельно и склеиваются
- **Приоритет:** `.agents/system-prompt.md` → `.agents/agents.md` → `../AGENTS.md` (legacy)
- **Кеширование:** содержимое читается один раз при загрузке агента (static-кеш на время запроса)
- **Frontmatter:** на этапе PoC frontmatter (`---` fences) не парсится — содержимое используется как есть. Полная поддержка frontmatter — в следующей итерации.

---

## 3. Соответствие .agents Protocol (v2026-02-24)

| Элемент спецификации | Поддержка в PoC | Статус |
|---|---|---|
| `agents.md` — инструкции агента | ✅ Читается из `.agents/agents.md` | PoC |
| `system-prompt.md` — системный промпт | ✅ Читается из `.agents/system-prompt.md` | PoC |
| `AGENTS.md` (legacy, без папки) | ✅ Поддерживается как fallback | PoC |
| `mcp.json` — MCP servers | ❌ Вне скоупа PoC | Будущее |
| `models.json` — model presets | ❌ Вне скоупа PoC | Будущее |
| `skills/*/skill.md` | ❌ Вне скоупа PoC | Будущее |
| `agents/*/agent.md` — sub-agents | ❌ Вне скоупа PoC | Будущее |
| `tasks/*/task.md` | ❌ Вне скоупа PoC | Будущее |
| `memories/*.md` | ❌ Вне скоупа PoC | Будущее |
| Frontmatter (`---` fences) | ❌ Не парсится в PoC | Будущее |
| Two-layer merge (~/.agents + ./.agents) | ❌ Только workspace-слой | Будущее |
| `.dotagents` bundles / Hub | ❌ Вне скоупа PoC | Будущее |

> **Принцип:** PoC реализует минимальное подмножество спецификации, достаточное для загрузки системного промпта из файловой системы. Остальные элементы — по мере развития плагина (см. RFC `260608-mvp`).

---

## 4. Структура файлов (новые/изменяемые)

```
_dot-agents-press/
├── app/
│   ├── Settings.php                 ← MODIFIED: добавить telegram_* поля
│   ├── TelegramBridge.php           ← NEW: Telegram webhook handler
│   └── AgentsProtocol.php           ← NEW: .agents/ file loader
├── includes/
│   ├── class-api.php                ← MODIFIED: добавить register_telegram_route()
│   └── class-agent.php              ← MODIFIED: resolve_system_prompt() с учётом .agents/
└── dot-agents-press.php              ← MODIFIED: загрузка новых классов
```

---

## 4. Поток данных (Telegram → AI → Telegram)

```
Telegram User
    │
    ▼
Telegram Bot API ──webhook──► POST /wp-json/dot-agents-press/v1/telegram/webhook
    │                              │
    │                         DotAgentsPress\TelegramBridge
    │                              │
    │                         1. Проверка secret (опционально)
    │                         2. Извлечение chat_id + text
    │                         3. Вызов DAP_Agent::chat(agent_id, text)
    │                              │
    │                         DAP_Agent
    │                              │
    │                         1. Загрузка агента из БД
    │                         2. AgentsProtocol::build_system_prompt()
    │                            → чтение .agents/system-prompt.md
    │                            → чтение .agents/agents.md
    │                            → слияние с system_prompt из БД
    │                         3. Вызов AI API (OpenAI/Anthropic)
    │                         4. Возврат ответа
    │                              │
    │                         DotAgentsPress\TelegramBridge
    │                              │
    │                         sendMessage(chat_id, reply)
    │                              │
    ◄──────────────────────────────┘
```

---

## 5. Код: ключевые сигнатуры

### 5.1 `app/TelegramBridge.php`

```php
namespace DotAgentsPress;

class TelegramBridge {
    public function __construct();
    public function register_routes(): void;
    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error;
    public function set_webhook(): bool;        // WP-CLI / admin action
    public function delete_webhook(): bool;
    private function send_message( string $chat_id, string $text ): array|\WP_Error;
    private function verify_secret( \WP_REST_Request $request ): bool;
}
```

### 5.2 `app/AgentsProtocol.php`

```php
namespace DotAgentsPress;

class AgentsProtocol {
    public static function get_project_root(): string;          // ABSPATH . '../'
    public static function get_agents_dir(): string;           // ABSPATH . '../.agents/'
    public static function find_system_prompt(): ?string;      // читает .agents/system-prompt.md
    public static function find_agents_md(): ?string;          // читает .agents/agents.md или ../AGENTS.md
    public static function build_system_prompt( string $db_prompt ): string;
    // ↑ Склеивает: system-prompt.md + agents.md + DB prompt
}
```

### 5.3 Модификация `class-api.php`

```php
// В register_routes() добавить:
register_rest_route( self::REST_NAMESPACE, '/telegram/webhook', [
    'methods'             => 'POST',
    'callback'            => [ $this->telegram_bridge, 'handle_webhook' ],
    'permission_callback' => '__return_true',
] );
```

### 5.4. Работа через провайдер OpenRouter (пример из SDK)

```
/**
 * Debug AI endpoint — ?dap_test_ai=1 for status, ?dap_test_ai=prompt&q=Hello to generate.
 * Uses wp_ai_client_prompt() routed through the active Connector (OpenRouter).
 */
add_action( 'wp_loaded', function () {
	if ( empty( $_GET['dap_test_ai'] ) || ! function_exists( 'wp_ai_client_prompt' ) ) {
		return;
	}

	// Only allow admins.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized.' );
	}

	header( 'Content-Type: text/plain; charset=utf-8' );

	// If a prompt is requested, run text generation.
	if ( 'prompt' === $_GET['dap_test_ai'] && ! empty( $_GET['q'] ) ) {
		if ( ! wp_supports_ai() ) {
			echo "ERROR: wp_supports_ai() returned false.\n";
			exit;
		}

		$prompt = sanitize_text_field( wp_unslash( $_GET['q'] ) );

		$builder = wp_ai_client_prompt( $prompt )
			->using_model_preference(
				'deepseek/deepseek-v4-pro',
				'anthropic/claude-haiku-4-5',
				'google/gemini-2.5-flash',
				'openai/gpt-4o-mini'
			);

		echo "=== Generating text ===\n";
		echo "Prompt: " . $prompt . "\n\n";

		try {
			$result = $builder->generate_text_result();
			echo "Result: " . $result->toText() . "\n\n";

			// Show which model was actually used.
			$modelMeta    = $result->getModelMetadata();
			$providerMeta = $result->getProviderMetadata();
			echo "=== Model used ===\n";
			printf( "  Provider: %s (%s)\n", $providerMeta->getName(), $providerMeta->getId() );
			printf( "  Model:    %s (%s)\n", $modelMeta->getName(), $modelMeta->getId() );

			$usage = $result->getTokenUsage();
			if ( $usage ) {
				printf( "  Tokens:   %d in / %d out / %d total\n",
					$usage->getPromptTokens(),
					$usage->getCompletionTokens(),
					$usage->getTotalTokens()
				);
			}
		} catch ( \Throwable $e ) {
			echo "ERROR: " . $e->getMessage() . "\n";
		}
		echo "\n=== Done ===\n";
		exit;
	}

	// Default: dump AI status.
	$registry = \WordPress\AiClient\AiClient::defaultRegistry();

	echo "=== AI Status ===\n";
	echo "wp_supports_ai(): " . ( wp_supports_ai() ? 'true' : 'false' ) . "\n\n";

	echo "=== Providers ===\n";
	foreach ( [ 'openrouter', 'anthropic', 'google', 'openai' ] as $id ) {
		printf( "  %s: %s\n", $id, $registry->hasProvider( $id ) ? 'registered' : 'not registered' );
	}
	echo "\n";

	// Check API key sources.
	echo "=== API Key ===\n";
	$env_key = getenv( 'OPENROUTER_API_KEY' );
	echo "OPENROUTER_API_KEY env: " . ( $env_key ? substr( $env_key, 0, 12 ) . '...' : 'NOT SET' ) . "\n";
	echo "OPENROUTER_API_KEY constant: " . ( defined( 'OPENROUTER_API_KEY' ) ? substr( OPENROUTER_API_KEY, 0, 12 ) . '...' : 'NOT DEFINED' ) . "\n";
	$db_key = get_option( 'connectors_ai_openrouter_api_key' );
	echo "connectors_ai_openrouter_api_key option: " . ( $db_key ? substr( $db_key, 0, 12 ) . '...' : 'NOT SET' ) . "\n";
	echo "\n";

	// List models from OpenRouter provider.
	echo "=== OpenRouter models (first 10) ===\n";
	try {
		$className = $registry->getProviderClassName( 'openrouter' );
		$all = $className::modelMetadataDirectory()->listModelMetadata();
		foreach ( array_slice( $all, 0, 10 ) as $model ) {
			printf( "  %s (%s)\n", $model->getId(), $model->getName() );
		}
		echo "  ... total: " . count( $all ) . " models\n";
	} catch ( \Throwable $e ) {
		echo "  Error listing models: " . $e->getMessage() . "\n";
	}
	echo "\nUse ?dap_test_ai=prompt&q=Hello to test text generation.\n";

	// Also run a quick smoke test.
	echo "\n=== Quick smoke test ===\n";
	try {
		$builder = wp_ai_client_prompt( 'Ответь одним словом: столица Франции?' )
			->using_model_preference(
				'deepseek/deepseek-v4-pro',
				'anthropic/claude-haiku-4-5',
				'google/gemini-2.5-flash',
				'openai/gpt-4o-mini'
			);
		$result = $builder->generate_text_result();
		echo "Prompt: Столица Франции?\n";
		echo "Answer: " . $result->toText() . "\n";
		$modelMeta = $result->getModelMetadata();
		printf( "Model:  %s\n", $modelMeta->getId() );
		$usage = $result->getTokenUsage();
		printf( "Tokens: %d in / %d out\n", $usage->getPromptTokens(), $usage->getCompletionTokens() );
	} catch ( \Throwable $e ) {
		echo "ERROR: " . $e->getMessage() . "\n";
	}
	echo "\n=== Done ===\n";
	exit;
} );
```

## 6. План реализации

| # | Задача | Файл | Сложность |
|---|--------|------|-----------|
| 1 | `app/AgentsProtocol.php` — поиск и чтение `.agents/system-prompt.md` + `.agents/agents.md` | NEW | Низкая |
| 2 | Модификация `class-agent.php` — вызов `AgentsProtocol::build_system_prompt()` при загрузке system_prompt | MOD | Низкая |
| 3 | `app/TelegramBridge.php` — webhook handler + sendMessage | NEW | Средняя |
| 4 | Регистрация telegram webhook route в `class-api.php` | MOD | Низкая |
| 5 | Добавление настроек Telegram в `Settings.php` | MOD | Низкая |
| 6 | Загрузка новых классов в `dot-agents-press.php` | MOD | Низкая |
| 7 | WP-CLI команда для установки webhook | NEW | Низкая |
| 8 | Тестирование: ngrok + Telegram + реальный API-ключ | — | Средняя |

---

## 7. Безопасность

- **Webhook secret:** опциональная проверка заголовка `X-Telegram-Bot-Api-Secret-Token`.
- **Telegram Bot Token:** хранится в опциях WP (не в коде), доступен только админам.
- **Rate limiting:** на уровне PoC — без ограничений. В будущем — через `DAP_Rate_Limiter`.
- **Input sanitization:** весь ввод из Telegram прогоняется через `sanitize_text_field`.
- **.agents/ чтение:** только чтение файлов, без записи. Путь жёстко фиксирован относительно `ABSPATH`.

---

## 8. Ограничения PoC (что НЕ делаем)

- ❌ Медиа/файлы/голосовые из Telegram
- ❌ История диалога (каждый запрос — чистый контекст)
- ❌ Мульти-агент маршрутизация
- ❌ Поддержка других мессенджеров
- ❌ Полный парсинг .agents/ (только agent.md)
- ❌ MCP tools / skills / memories
- ❌ Inline-кнопки / rich-сообщения
- ❌ Streaming ответов (только полный ответ)

---

## 9. Критерии готовности

- [ ] Отправка сообщения в Telegram бот → получение ответа от AI
- [ ] Системный промпт читается из `../.agents/agent.md` (если файл существует)
- [ ] Если файла нет — используется `system_prompt` из БД
- [ ] Webhook успешно устанавливается через WP-CLI
- [ ] Код проходит проверку на безопасность (sanitize/escape)
