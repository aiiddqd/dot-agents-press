# RFC: PoC — Telegram Bot Integration + .agents Protocol Entry Point

**Дата:** 2026-06-09
**Статус:** Draft
**Плагин:** `_dot-agents-press`

---

## TL;DR

Реализовать PoC из трёх компонентов:
1. **Telegram Bot Bridge** — webhook-обработчик, принимает сообщения из Telegram и маршрутизирует в AI-агента через существующий chat endpoint плагина.
2. **Системный промпт** — загрузка `AGENTS.md` / `agent.md` из `.agents/` директории на один уровень выше `wp-config.php`.
3. **Entry point** — авто-обнаружение `.agents/agent.md` (или `AGENTS.md`) по пути `ABSPATH . '../.agents/agent.md'`.

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

### 2.1 Telegram Bot Bridge

**Файл:** `includes/class-telegram-bridge.php`

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

**Файл:** `includes/class-agents-protocol.php`

**Логика:**
1. При загрузке агента проверяет наличие файла `AGENTS.md` или `agent.md` по пути:
   ```
   {ABSPATH}/../.agents/agent.md
   {ABSPATH}/../.agents/AGENTS.md
   ```
   Где `ABSPATH` — корень WordPress (там где `wp-config.php`).

2. Если файл найден — читает его содержимое и **дополняет** system_prompt агента (prepend или append).

3. Стратегия слияния (на выбор, для PoC — вариант A):
   - **A (prepend):** содержимое файла в начало system_prompt из БД
   - **B (replace):** если файл есть — он побеждает БД
   - **C (append):** содержимое файла в конец

4. Файл не обязан существовать — если нет, используется только `system_prompt` из БД.

**Почему `../.agents/` (выше wp-config.php)?**
- `wp-config.php` лежит в корне WordPress (например, `/var/www/html/wp-config.php`).
- `.agents/` на уровень выше — это уровень проекта/репозитория (`/var/www/html/../.agents/` → `/var/www/.agents/`).
- Это соответствует `.agents` Protocol: конфигурация агента живёт в корне проекта, а не внутри `wp-content`.
- Альтернатива: если WordPress установлен как subdirectory (например, `project/public/wp-config.php`), то `.agents/` окажется в `project/.agents/` — это правильное поведение.

### 2.3 Entry Point: AGENTS.md / agent.md

**Спецификация обнаружения файла:**

```php
// Порядок поиска:
$search_paths = [
    ABSPATH . '../.agents/agent.md',
    ABSPATH . '../.agents/AGENTS.md',
    ABSPATH . '../AGENTS.md',
];
```

- Приоритет: `agent.md` → `AGENTS.md` → `../AGENTS.md`
- Кеширование: содержимое читается один раз при загрузке агента (с кешем на срок жизни запроса).
- MD5-хеш файла сохраняется в опцию для отслеживания изменений.

---

## 3. Структура файлов (новые/изменяемые)

```
_dot-agents-press/
├── includes/
│   ├── class-telegram-bridge.php    ← NEW: Telegram webhook handler
│   └── class-agents-protocol.php    ← NEW: .agents/ file loader
├── app/
│   └── Settings.php                 ← MODIFIED: добавить telegram_* поля
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
    │                         DAP_Telegram_Bridge
    │                              │
    │                         1. Проверка secret (опционально)
    │                         2. Извлечение chat_id + text
    │                         3. Вызов DAP_Agent::chat(agent_id, text)
    │                              │
    │                         DAP_Agent
    │                              │
    │                         1. Загрузка агента из БД
    │                         2. DAP_Agents_Protocol::load_system_prompt()
    │                            → чтение ../.agents/agent.md
    │                            → слияние с system_prompt из БД
    │                         3. Вызов AI API (OpenAI/Anthropic)
    │                         4. Возврат ответа
    │                              │
    │                         DAP_Telegram_Bridge
    │                              │
    │                         sendMessage(chat_id, reply)
    │                              │
    ◄──────────────────────────────┘
```

---

## 5. Код: ключевые сигнатуры

### 5.1 `class-telegram-bridge.php`

```php
class DAP_Telegram_Bridge {
    public function __construct();
    public function register_routes(): void;
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error;
    public function set_webhook(): bool;        // WP-CLI / admin action
    public function delete_webhook(): bool;
    private function send_message( string $chat_id, string $text ): array|WP_Error;
    private function verify_secret( WP_REST_Request $request ): bool;
}
```

### 5.2 `class-agents-protocol.php`

```php
class DAP_Agents_Protocol {
    public static function get_project_root(): string;     // ABSPATH . '../'
    public static function find_agent_file(): ?string;     // первый найденный agent.md / AGENTS.md
    public static function load_system_prompt(): ?string;  // содержимое файла или null
    public static function merge_prompt( string $db_prompt, ?string $file_prompt ): string;
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

---

## 6. План реализации

| # | Задача | Файл | Сложность |
|---|--------|------|-----------|
| 1 | `class-agents-protocol.php` — поиск и чтение `../.agents/agent.md` | NEW | Низкая |
| 2 | Модификация `class-agent.php` — вызов `merge_prompt` при загрузке system_prompt | MOD | Низкая |
| 3 | `class-telegram-bridge.php` — webhook handler + sendMessage | NEW | Средняя |
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
