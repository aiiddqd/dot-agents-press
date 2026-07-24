# RFC: Интеграция Telegram-бота в _dot-agents-press

**Дата:** 2026-07-12  
**Статус:** Предложение  
**Плагин:** `_dot-agents-press`  
**Версия:** v0.2+

---

## Суть

Интегрировать прототип Telegram-бота (`./bot`) в плагин `_dot-agents-press` как встроенный мессенджер. Бот сейчас:
- Работает отдельно как PHP-проект с Neuron AI (`neuron-core/neuron-ai` через Composer)
- Поддерживает webhook и polling режимы приёма событий от Telegram
- Читает контекст из `.agents/` и вики-документацию
- Использует OpenRouter как основной AI-провайдер

Предлагаемое решение:
1. **Модульная архитектура** — вынести логику в специализированный класс на уровне WordPress
2. **Единый AI-движок** — использовать существующую систему `DotAgentsPress\Agent` (WP AI Client)
3. **Админ-панель** — добавить настройки Telegram прямо в плагин
4. **REST API** — webhook-эндпоинт для приёма сообщений от Telegram
5. **Контекст агента** — автоматическая загрузка `.agents/system-prompt.md` и других файлов
6. **Поэтапный rollout** — Фаза 1 = базовый чат, Фаза 2 = команды, Фаза 3 = продвинутые функции

---

## 1. Текущее состояние: Прототип бота

### 1.1 Структура бота в папке

```
./
├── vendor/                # Зависимости (Neuron AI через Composer)
├── composer.json
```

### 1.2 Текущие возможности

| Функция | Поддержка | Статус |
|---|---|---|
| **Приём событий** | Webhook от Telegram | ✅ Работает |
| **Генерация ответа** | Neuron AI + контекст .agents | ✅ Работает |
| **AI-провайдер** | OpenRouter | ✅ Настраивается |
| **.agents контекст** | Читает `../AGENTS.md` и скиллы | ✅ Работает |
| **Вики-знания** | Читает `../src/content/docs/**/*.md` | ✅ Работает |
| **Логирование** | В файл `bot.log` | ✅ Работает |
| **История чата** | Нет (stateless) | ⚠️ В планах |
| **Медиа** | Только текст | ⚠️ V0.3+ |

### 1.3 Текущая конфигурация

Константы в `wp-config.php`:

```
define( 'DAP_TELEGRAM_BOT_TOKEN', '123456:ABC...' );
define( 'DAP_TELEGRAM_WEBHOOK_SECRET', 'secret_key' );

// Neuron AI провайдер (OpenRouter)
define( 'DAP_AI_PROVIDER', 'openrouter' );
define( 'OPENROUTER_API_KEY', 'sk-or-v1-...' );
define( 'DAP_OPENROUTER_MODEL', 'deepseek/deepseek-chat' );

// .agents контекст
define( 'DAP_AI_READ_AGENTS_CONTEXT', true );
define( 'DAP_AI_READ_DOCS_CONTEXT', true );
```

**Зависимости:** Бот использует `neuron-core/neuron-ai` через Composer (`composer.json`).

---

## 2. Архитектура интеграции

### 2.1 Цели дизайна

- **Минимум дублирования** — логика бота живёт в специализированном классе, а не в отдельном проекте
- **Плагин first** — Telegram = встроенная функция, а не внешний сервис
- **Единый AI-движок** — использовать `DotAgentsPress\Agent` и WP AI Client (Connectors API)
- **Консистентность** — админ-панель для Telegram = админ-панель для других мессенджеров в будущем
- **Fallback** — бот может работать отдельно (для тестирования, OpenClaw, CI/CD)

### 2.2 Структура плагина после интеграции

```
_dot-agents-press/
├── includes/
│   ├── TelegramBridge.php             ← Основной класс: webhook + отправка ответа в Telegram
│   ├── Settings.php                   ← Telegram-поля настроек (token, secret, default agent, user id)
│   ├── Api.php                        ← Переиспользуем `handle_chat()` для AI-ответа
│   ├── Agent.php                      ← БЕЗ ИЗМЕНЕНИЙ (переиспользуем)
│   ├── Main.php                       ← Инициализация `TelegramBridge` и регистрация `rest_api_init`
│   └── [остальное]
├── views/
│   └── settings.php                   ← ИЗМЕНЕНИЕ: секция настроек Telegram
├── bot/                               ← СОХРАНИТЬ: для standalone режима
│   └── [существующая структура]
└── dot-agents-press.php               ← ИЗМЕНЕНИЕ: загрузить новые классы
```

### 2.3 Взаимодействие компонентов

**Telegram → WordPress:**
1. Пользователь отправляет сообщение боту в Telegram
2. Telegram API отправляет webhook на `POST /wp-json/dot-agents-press/v1/telegram/webhook`
3. `TelegramBridge::handle_webhook()` распарсивает payload и валидирует подпись
4. `TelegramBridge` определяет агента и готовит запрос в API-пайплайн плагина

**WordPress → AI → Telegram:**
1. `TelegramBridge` создаёт внутренний `WP_REST_Request` и вызывает `Api::handle_chat()`
2. `Api::handle_chat()` использует `Agent`/WP AI Client (через `wp_ai_client_prompt()`)
3. Система автоматически применяет `.agents/system-prompt.md` (если есть)
4. Получает ответ от AI-провайдера
5. `TelegramBridge::send_message()` отправляет ответ обратно в Telegram через Bot API

### 2.4 WordPress hooks и фильтры

Вместо сырого PHP будем использовать WordPress-паттерны:

**Регистрация маршрута:**
- Hook: `rest_api_init` — зарегистрировать `/telegram/webhook` эндпоинт
- Капаблити: любой пользователь (webhook от Telegram, не аутентифицированный)

**Перед отправкой ответа:**
- Filter: `dap_telegram_response` — позволить плагинам/темам модифицировать ответ перед отправкой
- Filter: `dap_telegram_context` — добавить дополнительный контекст из сайта

**После обработки:**
- Action: `dap_telegram_message_processed` — выполнить действия после ответа (логирование, метрики, и т.д.)

### 2.5 Конфигурация и опции WordPress

Новые опции (хранятся в `wp_options`):
- `dap_telegram_enabled` — включен ли Telegram бот
- `dap_telegram_bot_token` — токен бота от BotFather
- `dap_telegram_webhook_secret` — для верификации вебхука (опционально)
- `dap_telegram_default_agent_id` — какой агент по умолчанию обрабатывает сообщения
- `dap_telegram_ai_provider` — провайдер (openrouter, openai и т.д.)
- `dap_telegram_ai_model` — модель (gpt-4, claude-opus и т.д.)

**Где настраивается:** Admin UI → Dot Agents Press → Telegram Bot

---

## 3. Фазы реализации

### Фаза 1: PoC (немедленно)

**Цели:**
- Базовый flow: сообщение → обработка → ответ
- Вебхук от Telegram
- Интеграция с `DotAgentsPress\Agent`

**Результаты:**
- `includes/TelegramBridge.php` — основной класс (webhook + отправка ответа)
- Регистрация `rest_api_init` в `includes/Main.php` для маршрута Telegram
- Регистрация маршрута `/wp-json/dot-agents-press/v1/telegram/webhook`
- Поля настроек в админ-панели
- Локальное тестирование

**Сроки:** 2–3 дня

### Фаза 2: Команды и контекст (v0.2)

**Цели:**
- Слеш-команды (`/help`, `/status`)
- Регистрация/удаление вебхука из админ-панели
- Обработка ошибок и логирование
- Тестирование подключения

**Результаты:**
- Обработчик команд
- REST-эндпоинты в админ-панели для управления
- Улучшенные сообщения об ошибках

**Сроки:** 1–2 дня

### Фаза 3: Продвинутые функции (v0.3+)

**Цели:**
- Поддержка медиа (файлы, изображения)
- История разговоров (в БД)
- Маршрутизация на разных агентов
- Ограничение частоты запросов (rate limiting)
- Контекст из WP-контента (RAG)

**Результаты:**
- Обработчики загрузки/скачивания файлов
- Таблица `dap_conversations` в БД
- Диспетчер для маршрутизации агентов

**Сроки:** Будущие версии

---

## 4. Сохранение standalone-бота

Папка `./bot` остаётся в репозитории для:
- **Standalone-режима** — может работать отдельно от WordPress
- **Локального тестирования** — без необходимости устанавливать плагин
- **CI/CD** — изолированные тесты бота
- **Документации** — эталонная реализация

Плагин будет использовать Neuron AI через `composer.json` (зависимость `neuron-core/neuron-ai`).

---

## 5. Зависимости и требования

| Компонент | Требование | Статус |
|---|---|---|
| **Neuron AI** | `neuron-core/neuron-ai` через Composer | ✅ Основная зависимость |
| **OpenRouter API** | API ключ в константе `OPENROUTER_API_KEY` (`wp-config.php`) | ✅ Обязателен |
| **.agents контекст** | Читается автоматически через DotAgentsPress\Agent | ✅ Работает |
| **Вики-знания** | Читаются из `/src/content/docs/` | ✅ Работает |
| **WP AI Client** | Используется для интеграции с плагином | ✅ Встроен |
| **REST API** | WordPress 5.9+ | ✅ Стандартно |

---

## 6. Стратегия тестирования

### 6.1 Unit-тесты
- Инициализация моста
- Обработка сообщений
- Валидация вебхука
- Отправка ответов

### 6.2 Integration-тесты
- Полный flow: вебхук → агент → ответ
- Обработка команд
- Сохранение настроек

### 6.3 Ручное тестирование
1. Создать бота в Telegram (BotFather)
2. Заполнить токен в админ-панели плагина
3. Установить вебхук через админ-панель
4. Отправить сообщение боту
5. Проверить ответ
6. Протестировать команды (`/help`, `/status`)

---

## 7. Критерии успеха

- ✅ Вебхук получен и обработан без ошибок
- ✅ Сообщение передано `DotAgentsPress\Agent` корректно
- ✅ Ответ от AI пришёл и отправлен в Telegram
- ✅ Админ-панель позволяет настроить все параметры
- ✅ Можно установить/удалить вебхук из админ-панели
- ✅ Логирование фиксирует все события

---

## 8. Путь миграции: standalone → плагин

Для пользователей, запускающих бота отдельно:

1. **Проверить** значения констант в `wp-config.php`
2. **Установить** версию плагина (v0.2+)
3. **Перенести** значения из констант в опции WordPress (если нужен UI-режим)
4. **Установить** вебхук через админ-панель плагина
5. **Деактивировать** standalone-бота (или запускать параллельно во время переходного периода)

---

## 9. Ссылки и материалы

- [Telegram Bot API Docs](https://core.telegram.org/bots/api)
- [Neuron AI Documentation](https://neuron-ai.dev/)
- [OpenRouter Models](https://openrouter.ai/docs/models)
- [RFC: PoC .agents Protocol](./260609-rfc-poc-dot-agents.md)
- [RFC: MVP Architecture](./260608-mvp/rfc.md)
- [.agents Protocol Spec](https://dotagentsprotocol.com/)
- Standalone-бот: `./bot/README.md`

---

**Автор:** AI (2026-07-12)  
**Статус:** Готово к обсуждению и обратной связи
