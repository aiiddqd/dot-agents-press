# Dot Agents Press

**Your AI Agents on the web.** An OpenClaw alternative — manage and embed conversational AI agents directly in WordPress.

---

## About the `.agents` Protocol

This plugin is built in the spirit of the [.agents Protocol](https://dotagentsprotocol.com/) — an open directory convention for AI agent configuration.

**Key ideas:**

- **One directory (`.agents/`)** — houses everything an agent needs: MCP tools, `AGENTS.md` instructions, Skills, Sub-Agents, Tasks, and Memories. Plain JSON and Markdown, no proprietary schemas.
- **Vendor-neutral** — works with any AI tool, editor, or agent framework. No lock-in.
- **Git-friendly** — the entire agent configuration can be committed, diffed, branched, and shared.
- **Layered** — global defaults at `~/.agents/`, workspace overrides at `./.agents/` (workspace wins on conflict).
- **Seven standards, one place** — MCP, AGENTS.md, Skills, ACP, Sub-Agents, Tasks, and Memories converge in a single predictable directory.

Dot Agents Press brings this vision to WordPress — giving you a first-class WP-Admin UI for creating and managing conversational AI agents that can be embedded anywhere via shortcodes or the REST API.

---

## Features

- **Manage multiple agents** from a dedicated WP-Admin menu
- **OpenAI & Anthropic** support out of the box (plus any OpenAI-compatible endpoint)
- **Shortcode embed** — drop a chat widget anywhere with `[dot_agent id="1"]` or `[dot_agent slug="my-agent"]`
- **Per-agent configuration** — system prompt, welcome message, model, temperature, provider-override API key
- **REST API** — `POST /wp-json/dot-agents-press/v1/chat` for headless or JavaScript-driven use
- **Agent-level API key override** — store encrypted per-agent keys or use a single global key from Settings
- **Accessible, responsive chat UI** — keyboard-navigable, `aria-live` region, auto-resizing input

---

## Requirements

| Requirement   | Version  |
|---------------|----------|
| WordPress     | ≥ 6.0    |
| PHP           | ≥ 8.0    |
| PHP extension | `openssl` (for API key encryption) |

---

## Installation

1. Clone or download this repository into `wp-content/plugins/dot-agents-press/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Navigate to **AI Agents → Settings** and enter your OpenAI and/or Anthropic API key.
4. Go to **AI Agents → Add New** to create your first agent.
5. Copy the generated shortcode and paste it into any page or post.

---

## Usage

### Shortcodes

```
[dot_agent id="3"]
[dot_agent slug="my-support-bot"]
```

### REST API

**Endpoint:** `POST /wp-json/dot-agents-press/v1/chat`

**Request body:**
```json
{
  "agent_id": 3,
  "messages": [
    { "role": "user", "content": "Hello, what can you help me with?" }
  ]
}
```

**Response:**
```json
{
  "role":    "assistant",
  "content": "Hi! I can help you with …",
  "model":   "gpt-4o",
  "usage":   { "prompt_tokens": 42, "completion_tokens": 67, "total_tokens": 109 }
}
```

---

## Configuration

### Global API Keys (Settings page)

Navigate to **AI Agents → Settings** and enter your keys. They are stored in the WordPress options table.

### wp-config.php constants (recommended for production)

For better security, define keys as PHP constants — they take precedence over the Settings values:

```php
define( 'DAP_OPENAI_API_KEY',    'sk-…' );
define( 'DAP_ANTHROPIC_API_KEY', 'sk-ant-…' );
```

> **Note:** constant support is wired into `DAP_Agent::resolve_api_key()`.

### Per-agent API key override

When editing an agent, leave **API Key Override** blank to use the global key, or enter a key to use only for that agent. Keys are encrypted with AES-256-CBC using WordPress's `AUTH_KEY` as the cipher key.

---

## CSS Customisation

The chat widget uses CSS custom properties for easy theming. Add overrides to your theme:

```css
.dap-chat-widget {
  --dap-user-bg:     #7c3aed;   /* user bubble colour */
  --dap-header-bg:   #5b21b6;   /* header background  */
  --dap-radius:      8px;
}
```

---

## Supported Providers

| Provider   | `provider` value | Models (presets)                              |
|------------|-----------------|-----------------------------------------------|
| OpenAI     | `openai`        | `gpt-4o`, `gpt-4o-mini`, `gpt-4-turbo`, …    |
| Anthropic  | `anthropic`     | `claude-opus-4-5`, `claude-sonnet-4-5`, …    |
| Custom     | `custom`        | Any OpenAI-compatible endpoint (model free text) |

---

## Telegram Bot Setup

You can talk to your agent directly in Telegram — the plugin handles webhook delivery and routes messages to the configured agent.

### Step 1: Create a Telegram bot

1. Open Telegram and message [@BotFather](https://t.me/BotFather).
2. Send `/newbot` and follow the prompts — choose a name and a username ending in `bot`.
3. Copy the token BotFather gives you (looks like `123456:ABC-DEF1234ghIkl`).

### Step 2: Get your Telegram user ID

1. Message [@userinfobot](https://t.me/userinfobot) on Telegram.
2. It will reply with your numeric `Id` — copy it.

This ensures **only you** can talk to the bot (private assistant mode).

### Step 3: Configure the plugin

1. Go to **Settings → Dot Agents Config** in WordPress admin.
2. Fill in:
   - **Telegram Bot Token** — the token from @BotFather
   - **Telegram User ID** — your numeric Telegram ID
   - **Webhook Secret** — (optional) a random string for webhook verification
   - **Default Agent ID** — the ID of the agent that will respond (or leave empty to use the first enabled agent)
3. Save changes.

### Step 4: Set the webhook

Use WP-CLI to connect Telegram to your WordPress site:

```bash
wp dap telegram set-webhook
```

Verify it worked:

```bash
wp dap telegram status
```

Expected output:
```
URL:             https://yoursite.com/wp-json/dot-agents-press/v1/telegram/webhook
Has custom cert: no
Pending updates: 0
```

### Step 5: Test it

Open your bot in Telegram and send a message. The agent will reply.

**Tip:** If the bot doesn't respond, check `wp dap telegram status` for errors. Common issues: self-signed SSL certificate (Telegram requires valid HTTPS), or the webhook URL not being publicly reachable. Use [ngrok](https://ngrok.com/) for local testing.

### Managing the webhook

```bash
wp dap telegram set-webhook      # Install / update the webhook
wp dap telegram delete-webhook   # Remove the webhook
wp dap telegram status           # Show current webhook info
```

### How it works

```
Telegram → webhook → POST /wp-json/dot-agents-press/v1/telegram/webhook
                          │
                   TelegramBridge
                          │
                   1. Verify secret (optional)
                   2. Authorize user (telegram_authorized_user_id)
                   3. Resolve agent → DAP_API::handle_chat()
                   4. AgentsProtocol::build_system_prompt()
                      → .agents/system-prompt.md
                      → .agents/agents.md
                      → DB system_prompt
                   5. AI API call → reply
                          │
                   sendMessage(chat_id, reply)
                          │
Telegram ←────────────────┘
```

### .agents/ Protocol integration

The plugin automatically reads agent instructions from your project root (one level above `wp-config.php`):

```
project/                      ← your repo root
├── .agents/
│   ├── system-prompt.md      ← primary system prompt (priority 1)
│   └── agents.md             ← project guidelines (priority 2)
└── public/                   ← WordPress root (ABSPATH)
    └── wp-config.php
```

The final system prompt is assembled as: `system-prompt.md` + `agents.md` + agent's DB prompt. All files are optional — if none exist, only the DB prompt is used.

Legacy fallback: if `.agents/agents.md` doesn't exist, the plugin also checks for `AGENTS.md` in the project root (same level as `.agents/`).

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
