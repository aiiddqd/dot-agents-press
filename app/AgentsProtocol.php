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
 * read agents.md, system-prompt.md, and the legacy AGENTS.md.
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

	/**
	 * Reads the .agents/system-prompt.md file if it exists.
	 *
	 * @return string|null File contents or null if not found/readable.
	 */
	public static function find_system_prompt(): ?string {
		$path = self::get_agents_dir() . 'system-prompt.md';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$contents = @file_get_contents( $path );

		return $contents !== false ? trim( $contents ) : null;
	}

	/**
	 * Reads the .agents/agents.md file, falling back to ../AGENTS.md (legacy).
	 *
	 * @return string|null File contents or null if not found/readable.
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
					return trim( $contents );
				}
			}
		}

		return null;
	}

	/**
	 * Builds the final system prompt by merging (in order):
	 * 1. .agents/system-prompt.md                 (primary system prompt)
	 * 2. .agents/agents.md (or ../AGENTS.md)      (project guidelines)
	 * 3. DB-stored system_prompt                   (agent-specific context)
	 *
	 * @param string $db_prompt The system_prompt from the database record.
	 * @return string Merged system prompt.
	 */
	public static function build_system_prompt( string $db_prompt ): string {
		$parts   = [];
		$has_fs  = false;

		$system_prompt = self::find_system_prompt();
		if ( $system_prompt !== null ) {
			$parts[] = $system_prompt;
			$has_fs  = true;
		}

		$agents_md = self::find_agents_md();
		if ( $agents_md !== null ) {
			$parts[] = "\n\n---\n## Project Guidelines\n\n" . $agents_md;
			$has_fs  = true;
		}

		$db_prompt = trim( $db_prompt );
		if ( $has_fs && $db_prompt !== '' ) {
			// When FS prompt exists, DB prompt is appended as agent-specific context.
			$parts[] = "\n\n---\n## Agent-Specific Context\n\n" . $db_prompt;
		} elseif ( $db_prompt !== '' ) {
			// No FS prompt — DB prompt is the sole system prompt.
			$parts[] = $db_prompt;
		}

		return trim( implode( '', $parts ) );
	}
}
