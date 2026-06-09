# RFC: PoC — Telegram Bot Integration + .agents Protocol Entry Point

**Date:** 2026-06-09
**Status:** In Progress
**Plugin:** `_dot-agents-press`

---

## TL;DR

Implement a PoC consisting of three components:
1. **Telegram Bot Bridge** — webhook handler that receives messages from Telegram and routes them to an AI agent via the plugin's existing chat endpoint.
2. **System prompt** — load `agents.md` and `system-prompt.md` from the `.agents/` directory one level above `wp-config.php` (per the [.agents Protocol](https://dotagentsprotocol.com/) specification).
3. **Entry point** — auto-discovery of `.agents/agents.md` and `.agents/system-prompt.md` via the path `ABSPATH . '../.agents/'`.
4. All new classes go into the `app` folder, not `includes`.

---

## 1. Context & Motivation

The `_dot-agents-press` plugin already supports:
- Storing agents in the DB (`dap_agents` table) with fields: `system_prompt`, `provider`, `model`, `temperature`, `api_key`.
- Serving chat completions via REST API: `POST /wp-json/dot-agents-press/v1/chat`.
- Supporting OpenAI and Anthropic (OpenAI-compatible) providers.

**What the PoC needs:**
- Allow communication with an agent via Telegram (private bot messages).
- Teach the agent to read the system prompt from the filesystem — from `.agents/agent.md` at the project level (above `wp-config.php`), not just from the DB.
- This is the first step toward full `.agents` Protocol support (see RFC `260608-mvp`).

---

## 2. PoC Components

### Settings

Example: https://wpcraft.ru/wp-admin/options-general.php?page=dot-agents-config
Class: `app/Settings.php`

- Default model — `deepseek-v4-pro`
- Default provider — `openrouter`
- API Token — renamed to `telegram_bot_token`
- Telegram ID — for protection — private assistant only

### 2.1 Telegram Bot Bridge

**File:** `app/TelegramBridge.php`
**Class:** `DotAgentsPress\TelegramBridge`

**Logic:**
1. Registers a REST endpoint: `POST /wp-json/dot-agents-press/v1/telegram/webhook`
2. Accepts updates from the Telegram Bot API (JSON).
3. Extracts `message.text` and `message.chat.id`.
4. Calls the existing `DAP_API::handle_chat()` (or internal method `DAP_Agent::chat()`) with the user message.
5. Sends the response back via Telegram Bot API (`sendMessage`).

**PoC simplifications:**
- One hardcoded agent_id (from plugin settings or a constant).
- No complex routing — all messages go to a single agent.
- No conversation history (each request — new context, only system prompt + user message).
- No media/file support — text only.

**Settings (add to Settings.php):**
- `telegram_bot_token` — bot token
- `telegram_webhook_secret` — secret for webhook verification (optional)
- `telegram_default_agent_id` — default agent ID

### 2.2 System Prompt from .agents/

**File:** `app/AgentsProtocol.php`
**Class:** `DotAgentsPress\AgentsProtocol`

**Specification:** The [.agents Protocol](https://dotagentsprotocol.com/) defines two key files:
- **`agents.md`** — instructions/guidelines for the agent (AGENTS.md-compatible format, build steps, conventions)
- **`system-prompt.md`** — the actual system prompt

**Logic:**
1. When loading an agent, checks for files at:
   ```
   {ABSPATH}/../.agents/system-prompt.md   ← primary source of the system prompt
   {ABSPATH}/../.agents/agents.md           ← additional instructions
   ```
   Where `ABSPATH` is the WordPress root (where `wp-config.php` lives).

2. **Merge strategy (PoC — variant A):**
   - **`system-prompt.md`** (if present) → used as the primary system prompt
   - **`agents.md`** (if present) → appended at the end of the system prompt as "Project Guidelines"
   - **`system_prompt` from DB** → appended at the end (role: agent-specific context from the admin panel)
   - Final order: `system-prompt.md` + `agents.md` + `system_prompt` from DB

3. Files are not required — if absent, only `system_prompt` from the DB is used.

4. **AGENTS.md compatibility:** the `agents.md` file is semantically identical to `AGENTS.md` from the OpenAI/Linux Foundation specification. For backward compatibility, `../AGENTS.md` (without the `.agents/` folder) is also checked.

**Why `../.agents/` (above wp-config.php)?**
- `wp-config.php` lives in the WordPress root (e.g., `/var/www/html/wp-config.php`).
- `.agents/` one level up is the project/repository level (`/var/www/html/../.agents/` → `/var/www/.agents/`).
- This aligns with the `.agents` Protocol: agent configuration lives at the project root, not inside `wp-content`.
- Alternative: if WordPress is installed as a subdirectory (e.g., `project/public/wp-config.php`), then `.agents/` ends up at `project/.agents/` — which is the correct behavior.

### 2.3 Entry Point: agents.md + system-prompt.md

**File discovery specification:**

```php
// Search order (first found wins in its category):

// Category: System Prompt
$system_prompt_paths = [
    ABSPATH . '../.agents/system-prompt.md',   // .agents Protocol (priority)
];

// Category: Agent Instructions
$agents_md_paths = [
    ABSPATH . '../.agents/agents.md',           // .agents Protocol (priority)
    ABSPATH . '../AGENTS.md',                   // Legacy: AGENTS.md at project root
];
```

- **Two independent files:** `system-prompt.md` and `agents.md` are read separately and concatenated
- **Priority:** `.agents/system-prompt.md` → `.agents/agents.md` → `../AGENTS.md` (legacy)
- **Caching:** content is read once per agent load (static cache for the request duration)
- **Frontmatter:** at the PoC stage, frontmatter (`---` fences) is not parsed — content is used as-is. Full frontmatter support — next iteration.

---

## 3. .agents Protocol Compliance (v2026-02-24)

| Spec Element | PoC Support | Status |
|---|---|---|
| `agents.md` — agent instructions | ✅ Read from `.agents/agents.md` | PoC |
| `system-prompt.md` — system prompt | ✅ Read from `.agents/system-prompt.md` | PoC |
| `AGENTS.md` (legacy, no folder) | ✅ Supported as fallback | PoC |
| `mcp.json` — MCP servers | ❌ Out of PoC scope | Future |
| `models.json` — model presets | ❌ Out of PoC scope | Future |
| `skills/*/skill.md` | ❌ Out of PoC scope | Future |
| `agents/*/agent.md` — sub-agents | ❌ Out of PoC scope | Future |
| `tasks/*/task.md` | ❌ Out of PoC scope | Future |
| `memories/*.md` | ❌ Out of PoC scope | Future |
| Frontmatter (`---` fences) | ❌ Not parsed in PoC | Future |
| Two-layer merge (~/.agents + ./.agents) | ❌ Workspace layer only | Future |
| `.dotagents` bundles / Hub | ❌ Out of PoC scope | Future |

> **Principle:** The PoC implements the minimum subset of the specification sufficient to load the system prompt from the filesystem. Remaining elements — as the plugin evolves (see RFC `260608-mvp`).

---

## 4. File Structure (new/modified)

```
_dot-agents-press/
├── app/
│   ├── Settings.php                 ← MODIFIED: add telegram_* fields
│   ├── TelegramBridge.php           ← NEW: Telegram webhook handler
│   └── AgentsProtocol.php           ← NEW: .agents/ file loader
├── includes/
│   ├── class-api.php                ← MODIFIED: add register_telegram_route()
│   └── class-agent.php              ← MODIFIED: resolve_system_prompt() accounting for .agents/
└── dot-agents-press.php              ← MODIFIED: load new classes
```

---

## 5. Data Flow (Telegram → AI → Telegram)

```
Telegram User
    │
    ▼
Telegram Bot API ──webhook──► POST /wp-json/dot-agents-press/v1/telegram/webhook
    │                              │
    │                         DotAgentsPress\TelegramBridge
    │                              │
    │                         1. Verify secret (optional)
    │                         2. Extract chat_id + text
    │                         3. Call DAP_Agent::chat(agent_id, text)
    │                              │
    │                         DAP_Agent
    │                              │
    │                         1. Load agent from DB
    │                         2. AgentsProtocol::build_system_prompt()
    │                            → read .agents/system-prompt.md
    │                            → read .agents/agents.md
    │                            → merge with system_prompt from DB
    │                         3. Call AI API (OpenAI/Anthropic)
    │                         4. Return response
    │                              │
    │                         DotAgentsPress\TelegramBridge
    │                              │
    │                         sendMessage(chat_id, reply)
    │                              │
    ◄──────────────────────────────┘
```

---

## 6. Code: Key Signatures

### 6.1 `app/TelegramBridge.php`

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

### 6.2 `app/AgentsProtocol.php`

```php
namespace DotAgentsPress;

class AgentsProtocol {
    public static function get_project_root(): string;          // ABSPATH . '../'
    public static function get_agents_dir(): string;           // ABSPATH . '../.agents/'
    public static function find_system_prompt(): ?string;      // reads .agents/system-prompt.md
    public static function find_agents_md(): ?string;          // reads .agents/agents.md or ../AGENTS.md
    public static function build_system_prompt( string $db_prompt ): string;
    // ↑ Concatenates: system-prompt.md + agents.md + DB prompt
}
```

### 6.3 Modification to `class-api.php`

```php
// In register_routes() add:
register_rest_route( self::REST_NAMESPACE, '/telegram/webhook', [
    'methods'             => 'POST',
    'callback'            => [ $this->telegram_bridge, 'handle_webhook' ],
    'permission_callback' => '__return_true',
] );
```

### 6.4 Working via OpenRouter Provider (SDK example)

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
		$builder = wp_ai_client_prompt( 'Respond in a single word: capital of France?' )
			->using_model_preference(
				'deepseek/deepseek-v4-pro',
				'anthropic/claude-haiku-4-5',
				'google/gemini-2.5-flash',
				'openai/gpt-4o-mini'
			);
		$result = $builder->generate_text_result();
		echo "Prompt: Capital of France?\n";
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

## 7. Implementation Plan

| # | Task | File | Complexity |
|---|------|------|------------|
| 1 | `app/AgentsProtocol.php` — find and read `.agents/system-prompt.md` + `.agents/agents.md` | NEW | Low |
| 2 | Modify `class-agent.php` — call `AgentsProtocol::build_system_prompt()` when loading system_prompt | MOD | Low |
| 3 | `app/TelegramBridge.php` — webhook handler + sendMessage | NEW | Medium |
| 4 | Register telegram webhook route in `class-api.php` | MOD | Low |
| 5 | Add Telegram settings to `Settings.php` | MOD | Low |
| 6 | Load new classes in `dot-agents-press.php` | MOD | Low |
| 7 | WP-CLI command for webhook setup | NEW | Low |
| 8 | Testing: ngrok + Telegram + real API key | — | Medium |

---

## 8. Security

- **Webhook secret:** optional `X-Telegram-Bot-Api-Secret-Token` header verification.
- **Telegram Bot Token:** stored in WP options (not in code), accessible only to admins.
- **Rate limiting:** at the PoC level — no limits. In the future — via `DAP_Rate_Limiter`.
- **Input sanitization:** all Telegram input is run through `sanitize_text_field`.
- **.agents/ reading:** read-only file access, no writes. Path is hardcoded relative to `ABSPATH`.

---

## 9. PoC Limitations (what we're NOT doing)

- ❌ Media/files/voice from Telegram
- ❌ Conversation history (each request — clean context)
- ❌ Multi-agent routing
- ❌ Support for other messengers
- ❌ Full .agents/ parsing (only agent.md)
- ❌ MCP tools / skills / memories
- ❌ Inline buttons / rich messages
- ❌ Response streaming (full response only)

---

## 10. Acceptance Criteria

- [ ] Sending a message to the Telegram bot → receiving an AI response
- [ ] System prompt is read from `../.agents/system-prompt.md` + `../.agents/agents.md` (if files exist)
- [ ] If no files exist — `system_prompt` from the DB is used
- [ ] Webhook is successfully set via WP-CLI (`wp dap telegram set-webhook`)
- [ ] Code passes security review (sanitize/escape)
- [x] Codebase implemented (PoC implementation done)

---

## 11. Implementation Results (2026-06-09)

| # | File | Action |
|---|------|--------|
| 1 | `app/AgentsProtocol.php` | **NEW** — find and read `.agents/system-prompt.md` + `.agents/agents.md` + `../AGENTS.md` |
| 2 | `app/TelegramBridge.php` | **NEW** — webhook handler + sendMessage + webhook management |
| 3 | `app/Settings.php` | **MODIFIED** — fields: telegram_bot_token, telegram_authorized_user_id, webhook_secret, default_agent_id; default model → deepseek-v4-pro |
| 4 | `includes/class-api.php` | **MODIFIED** — `handle_chat()` calls `AgentsProtocol::build_system_prompt()` |
| 5 | `includes/class-dot-agents-press.php` | **MODIFIED** — load TelegramBridge, AgentsProtocol, WP-CLI commands |

**WP-CLI Commands:**
```
wp dap telegram set-webhook     # Set up webhook
wp dap telegram delete-webhook  # Delete webhook
wp dap telegram status          # Webhook info
```
