# RFC: WP-CLI Commands for dot-agents-press

**Status:** Draft  
**Author:** aa  
**Date:** 2026-06-10

---

## 1. Introduction

The `dot-agents-press` plugin already provides the REST endpoint `/wp-json/dot-agents-press/v1/chat` and the `[dot_agent]` shortcode for working with AI agents via the browser. However, for automation, debugging, and SSH workflows, it is more convenient to interact with agents directly from the terminal, without curl and without a browser.

This RFC describes a set of WP-CLI commands under the `wp dap` namespace that allow sending a message to an agent, receiving a response, and checking the current configuration.

---

## 2. Goals and Motivation

### Goals

- Add the `wp dap chat` WP-CLI command to send a message to the default agent directly from the terminal
- Add the `wp dap status` command to view the provider, model, and available commands

### Motivation

- At the moment, an agent cannot be reached without a browser or manual curl calls, which slows down automation and debugging
- Prompt development needs a fast loop: "change `system_prompt` -> verify response" without page reloads
- WP-CLI is already used across the project for automation, so agents should be available in the same environment
- Agents should be runnable in CI/CD and cron scenarios

---

## 3. Components and Details

### 3.1. Namespace and Entry Point

All commands are registered under `wp dap` through the `Commands` class added to `app/Commands.php`.

Регистрация:

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'dap', Commands::class );
}
```

Class loading is added in `dot-agents-press.php` with the `defined('WP_CLI') && WP_CLI` guard.

### 3.2. `wp dap chat`

Sends a single message to the default agent and prints the response text to stdout.

**Syntax:**

```bash
wp dap chat <message>
```

**Arguments:**

| Argument | Type | Description |
|---|---|---|
| `<message>` | positional | User message text |

**Example:**

```bash
wp dap chat "Hi, what can you do?"
```

**Output:** assistant response text only in stdout.

**Agent:** the first `enabled = 1` agent from the `dap_agents` table. If none exists, return an error with a hint.

**Implementation:** calls `AgentsProtocol` directly, building `messages` from a single item `[{"role":"user","content":"<message>"}]`.

### 3.3. `wp dap status`

Prints the current plugin configuration: provider, model, API key (masked), and a list of available CLI commands.

**Syntax:**

```bash
wp dap status [--format=<format>]
```

**Example output:**

```
dot-agents-press v1.2.0
Provider : openrouter
Model    : anthropic/claude-haiku-4
API key  : sk-or-v1-••••••••••••••••abcd
Agents   : 3 registered, 2 enabled

Available commands:
  wp dap chat      Send a message to an agent
  wp dap status    Show current provider / model / config
  wp dap agents    Manage agents (list, get, enable, disable)
```

**Data source:** `Settings::get()` for provider/model/key; `DAP_Agent::get_all()` for the agent list.

### 3.4. `wp dap agents list`

Prints a table of registered agents.

```bash
wp dap agents list
# +----+------------------+------------------+------------+---------------------------------+---------+
# | id | slug             | name             | provider   | model                           | enabled |
# +----+------------------+------------------+------------+---------------------------------+---------+
# | 1  | personal-assist  | Personal Assist  | openrouter | anthropic/claude-haiku-4        | true    |
# | 2  | content-writer   | Content Writer   | openrouter | openai/gpt-4o                   | false   |
# +----+------------------+------------------+------------+---------------------------------+---------+
```

Uses standard `WP_CLI\Utils\format_items()`, supports `--format=table|json|csv|yaml`.

### 3.5. Error Handling

- No API key -> `WP_CLI::error('API key is not configured. Run: wp dap status')` (exit code 1)
- No enabled agents -> `WP_CLI::error('No enabled agents found.')` (exit code 1)
- API response contains an error -> print the error message from the response and return exit code 1

### Architecture Decisions

- **Reuse `AgentsProtocol`** instead of duplicating AI call logic. The CLI command stays provider-agnostic and delegates to the same layer as the REST API.
- **Synchronous execution**. WP-CLI is a synchronous environment, so async is unnecessary. If the provider returns a stream, read it fully and print when complete.
- **No interactive mode** in v1. Single-turn only (`chat <message>`). Multi-turn (history) is a separate RFC.

---

## 4. Definition of Done

- [ ] Create `app/Commands.php` and register the `Commands` class under `wp dap`
- [ ] `wp dap chat "text"` sends a request and prints the agent response to stdout
- [ ] `wp dap status` prints provider, model, masked API key, and command list
- [ ] `wp dap agents list` prints an agents table
- [ ] Missing API key -> proper error with hint
- [ ] Missing enabled agents -> proper error

### Testing

- Manual test via `wp dap chat "test" --allow-root` in a wp-env environment
- Verify exit codes for invalid/error scenarios

---

## 5. Additions

**Dependencies:**
- WP-CLI must be installed in the environment (already available locally via `wp-env`)
- `AgentsProtocol.php` / `DAP_API` are reused without changes

**Risks:**
- Long responses (GPT-4o, large context) may cause CLI waiting/hanging. Mitigation: 60s timeout via `--timeout`, print a warning if exceeded.
- API key leakage in shell history. The key is passed via WordPress Settings, not CLI arguments, so it does not appear in `.bash_history`.

**Out of scope (next iterations):**
- `--agent=<slug>` to select a specific agent
- `--format=json` for piping
- `--stream` for streaming output
- `wp dap agents enable/disable` to manage agents from CLI
- `wp dap set-provider` to change provider/model

**Related materials:**
- REST API: `includes/class-api.php`
- Provider settings: `app/Settings.php`
- Agent model: `includes/class-agent.php`
- Current roadmap: `ROADMAP.md`

---

## 6. Conclusion

MVP: three commands, `wp dap chat <message>`, `wp dap status`, and `wp dap agents list`. The minimal implementation lives in `app/Commands.php` and reuses `AgentsProtocol`. It covers the core scenario: message an agent and get a response from the terminal. Options like `--agent`, `--format`, `--stream`, and agent management are planned for future iterations.

