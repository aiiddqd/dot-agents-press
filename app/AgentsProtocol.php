<?php
/**
 * .agents Protocol — reads agent instructions and system prompt from the
 * filesystem according to the .agents Protocol specification.
 *
 * @see https://dotagentsprotocol.com/
 * @package DotAgentsPress
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the project-level .agents/ directory and provides helpers to
 * discover agents, read prompts, and parse YAML frontmatter.
 *
 * Agent structure:
 *   .agents/
 *   ├── system-prompt.md              ← global prefix for all agents
 *   ├── agents.md                     ← project guidelines (AGENTS.md)
 *   ├── models.json                   ← optional model presets
 *   └── agents/
 *       └── {slug}/
 *           ├── agent.md              ← YAML frontmatter + body = system prompt
 *           └── config.json           ← optional tool/connection config
 */
class AgentsProtocol {

	/**
	 * Returns the project root directory — one level above WordPress ABSPATH.
	 *
	 * Examples:
	 *   ABSPATH = /var/www/html/         → /var/www/
	 *   ABSPATH = /var/www/project/wp/   → /var/www/project/
	 *
	 * @return string Normalized path with trailing slash.
	 */
	public static function get_project_root(): string {
		return trailingslashit( realpath( ABSPATH . '..' ) ?: ABSPATH . '..' );
	}

	/**
	 * Returns the .agents/ directory path inside the project root.
	 *
	 * @return string Normalized path with trailing slash.
	 */
	public static function get_agents_dir(): string {
		return self::get_project_root() . '.agents/';
	}

	// -------------------------------------------------------------------------
	// Global prompts
	// -------------------------------------------------------------------------

	/**
	 * Reads the .agents/system-prompt.md file if it exists.
	 *
	 * For agent.md files, use the YAML frontmatter + body directly — this
	 * method is for the GLOBAL system-prompt.md (applied to all agents).
	 *
	 * @return string|null File contents (without frontmatter) or null.
	 */
	public static function find_system_prompt(): ?string {
		$path = self::get_agents_dir() . 'system-prompt.md';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$contents = @file_get_contents( $path );
		if ( $contents === false ) {
			return null;
		}

		return self::strip_frontmatter( $contents );
	}

	/**
	 * Reads the .agents/agents.md file, falling back to ../AGENTS.md (legacy).
	 *
	 * @return string|null File contents (without frontmatter) or null.
	 */
	public static function find_agents_md(): ?string {
		$paths = [
			self::get_agents_dir() . 'agents.md',
			self::get_project_root() . 'AGENTS.md',
		];

		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				$contents = @file_get_contents( $path );
				if ( $contents !== false && trim( $contents ) !== '' ) {
					return self::strip_frontmatter( $contents );
				}
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Agent discovery
	// -------------------------------------------------------------------------

	/**
	 * Discover an agent by slug from .agents/agents/{slug}/agent.md.
	 *
	 * Returns an object with:
	 *   - slug, name, description
	 *   - model, provider, temperature
	 *   - enabled, welcome_msg
	 *   - system_prompt (the body of agent.md)
	 *
	 * @param string $slug Agent directory name.
	 * @return object|null Agent data or null if not found.
	 */
	public static function discover_agent( string $slug ): ?object {
		$path = self::get_agents_dir() . "agents/{$slug}/agent.md";

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}

		$parsed      = self::parse_frontmatter( $raw );
		$frontmatter = $parsed['frontmatter'];
		$body        = trim( $parsed['body'] );

		$settings = dot_agents_press()->settings();

		return (object) [
			'slug'          => $slug,
			'name'          => $frontmatter['name'] ?? $slug,
			'description'   => $frontmatter['description'] ?? '',
			'model'         => $frontmatter['model'] ?? $settings->get( 'default_model', 'deepseek/deepseek-v4-pro' ),
			'provider'      => $frontmatter['provider'] ?? 'openrouter',
			'temperature'   => (float) ( $frontmatter['temperature'] ?? 0.7 ),
			'enabled'       => (bool) ( $frontmatter['enabled'] ?? true ),
			'welcome_msg'   => $frontmatter['welcome_msg'] ?? '',
			'system_prompt' => $body,
		];
	}

	/**
	 * List all agents found in .agents/agents/.
	 *
	 * @return object[] Array of agent objects (see discover_agent).
	 */
	public static function list_agents(): array {
		$dir = self::get_agents_dir() . 'agents/';

		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$agents = [];
		$items  = @scandir( $dir );
		if ( ! $items ) {
			return [];
		}

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$agent_dir = $dir . $item;
			if ( ! is_dir( $agent_dir ) ) {
				continue;
			}

			$agent_file = $agent_dir . '/agent.md';
			if ( ! is_readable( $agent_file ) ) {
				continue;
			}

			$agent = self::discover_agent( $item );
			if ( $agent ) {
				$agents[] = $agent;
			}
		}

		return $agents;
	}

	/** Resolve the first enabled agent (fallback when no slug specified). */
	public static function first_enabled_agent(): ?object {
		foreach ( self::list_agents() as $agent ) {
			if ( $agent->enabled ) {
				return $agent;
			}
		}
		return null;
	}

	/**
	 * Resolve an agent for Telegram/webhook use.
	 *
	 * Priority: settings slug → first enabled agent in .agents/agents/.
	 */
	public static function resolve_telegram_agent(): ?object {
		$slug = dot_agents_press()->settings()->get( 'telegram_default_agent_slug', '' );

		if ( $slug !== '' ) {
			$agent = self::discover_agent( $slug );
			if ( $agent && $agent->enabled ) {
				return $agent;
			}
		}

		return self::first_enabled_agent();
	}

	// -------------------------------------------------------------------------
	// Prompt building
	// -------------------------------------------------------------------------

	/**
	 * Builds the final system prompt by merging (in order):
	 * 1. .agents/system-prompt.md                       (global prefix)
	 * 2. agent's own body from agent.md                 (agent-specific)
	 * 3. .agents/agents.md (or ../AGENTS.md)            (project guidelines)
	 *
	 * @param string $agent_body The body of the agent's agent.md (after frontmatter).
	 * @return string Merged system prompt.
	 */
	public static function build_system_prompt( string $agent_body ): string {
		$parts  = [];
		$has_fs = false;

		$global_sp = self::find_system_prompt();
		if ( $global_sp !== null && $global_sp !== '' ) {
			$parts[] = $global_sp;
			$has_fs  = true;
		}

		$agent_body = trim( $agent_body );
		if ( $agent_body !== '' ) {
			if ( $has_fs ) {
				$parts[] = "\n\n---\n## Agent Role\n\n" . $agent_body;
			} else {
				$parts[] = $agent_body;
			}
			$has_fs = true;
		}

		$agents_md = self::find_agents_md();
		if ( $agents_md !== null && $agents_md !== '' ) {
			$parts[] = "\n\n---\n## Project Guidelines\n\n" . $agents_md;
		}

		return trim( implode( '', $parts ) );
	}

	// -------------------------------------------------------------------------
	// API key resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the OpenRouter API key.
	 *
	 * Priority: constant → env → WordPress Connectors (core).
	 */
	public static function resolve_api_key( object $agent ): string {
		if ( defined( 'OPENROUTER_API_KEY' ) && OPENROUTER_API_KEY !== '' ) {
			return (string) OPENROUTER_API_KEY;
		}

		$env_key = getenv( 'OPENROUTER_API_KEY' );
		if ( $env_key !== false && $env_key !== '' ) {
			return $env_key;
		}

		// WordPress Connectors API (core) — stored at Settings → Connectors.
		return get_option( 'connectors_ai_openrouter_api_key', '' );
	}

	// -------------------------------------------------------------------------
	// YAML frontmatter helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse YAML frontmatter from a Markdown string.
	 *
	 * Only supports simple string/number/boolean scalars (no nested or array
	 * values in PoC).
	 *
	 * @param string $raw Raw file contents.
	 * @return array{frontmatter:array,body:string}
	 */
	public static function parse_frontmatter( string $raw ): array {
		$frontmatter = [];
		$body        = $raw;

		if ( strpos( trim( $raw ), '---' ) !== 0 ) {
			return [ 'frontmatter' => $frontmatter, 'body' => $body ];
		}

		$parts = explode( '---', $raw, 3 );
		if ( count( $parts ) < 3 ) {
			return [ 'frontmatter' => $frontmatter, 'body' => $raw ];
		}

		$yaml_block = $parts[1];
		$body       = $parts[2];

		foreach ( explode( "\n", $yaml_block ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, ':' ) === false ) {
				continue;
			}

			[ $key, $value ] = explode( ':', $line, 2 );
			$key   = trim( $key );
			$value = trim( $value );

			if ( ( strpos( $value, '"' ) === 0 && strrpos( $value, '"' ) === strlen( $value ) - 1 )
				|| ( strpos( $value, "'" ) === 0 && strrpos( $value, "'" ) === strlen( $value ) - 1 )
			) {
				$value = substr( $value, 1, -1 );
			}

			if ( $value === 'true' ) {
				$value = true;
			} elseif ( $value === 'false' ) {
				$value = false;
			} elseif ( is_numeric( $value ) ) {
				$value = strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
			}

			$frontmatter[ $key ] = $value;
		}

		return [ 'frontmatter' => $frontmatter, 'body' => $body ];
	}

	/** Strip YAML frontmatter, return body only. */
	public static function strip_frontmatter( string $raw ): string {
		$parsed = self::parse_frontmatter( $raw );
		return trim( $parsed['body'] );
	}
}
