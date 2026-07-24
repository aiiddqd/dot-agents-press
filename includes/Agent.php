<?php
/**
 * Agent data-layer — CRUD helpers around the `dap_agents` table.
 *
 * @package Dot_Agents_Press
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all database interactions for agents.
 *
 * Table schema
 * ------------
 * id            INT UNSIGNED AUTO_INCREMENT
 * name          VARCHAR(200) NOT NULL
 * slug          VARCHAR(200) NOT NULL UNIQUE
 * description   TEXT
 * system_prompt TEXT
 * provider      VARCHAR(50)  DEFAULT 'openai'   (openai | anthropic | custom)
 * model         VARCHAR(100) DEFAULT 'gpt-4o'
 * temperature   DECIMAL(3,2) DEFAULT 0.70
 * api_key       TEXT                             (encrypted, provider-specific override)
 * welcome_msg   TEXT                             (first assistant bubble)
 * enabled       TINYINT(1)   DEFAULT 1
 * created_at    DATETIME
 * updated_at    DATETIME
 */
class Agent {

	/** @var string Unprefixed table name. */
	public const TABLE = 'dap_agents';

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Creates / upgrades the database table.
	 * Safe to call multiple times (dbDelta handles upgrades).
	 */
	public function install(): void {
		global $wpdb;

		$table      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			name          VARCHAR(200) NOT NULL,
			slug          VARCHAR(200) NOT NULL,
			description   TEXT         NOT NULL DEFAULT '',
			system_prompt TEXT         NOT NULL DEFAULT '',
			provider      VARCHAR(50)  NOT NULL DEFAULT 'openai',
			model         VARCHAR(100) NOT NULL DEFAULT 'gpt-4o',
			temperature   DECIMAL(3,2) NOT NULL DEFAULT 0.70,
			api_key       TEXT         NOT NULL DEFAULT '',
			welcome_msg   TEXT         NOT NULL DEFAULT '',
			enabled       TINYINT(1)   NOT NULL DEFAULT 1,
			created_at    DATETIME     NOT NULL,
			updated_at    DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'dap_db_version', DAP_VERSION );
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Returns a single agent row or null.
	 *
	 * @param int|string $id_or_slug Numeric ID or slug string.
	 * @return object|null
	 */
	public function get( int|string $id_or_slug ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( is_numeric( $id_or_slug ) ) {
			return $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id_or_slug )
			) ?: null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $id_or_slug )
		) ?: null;
	}

	/**
	 * Returns all agents, optionally filtered.
	 *
	 * @param array{enabled?:bool,search?:string,per_page?:int,offset?:int} $args
	 * @return object[]
	 */
	public function get_all( array $args = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = '1=1';
		$values = [];

		if ( isset( $args['enabled'] ) ) {
			$where   .= ' AND enabled = %d';
			$values[] = (int) $args['enabled'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$offset   = isset( $args['offset'] )   ? max( 0, (int) $args['offset'] )   : 0;

		$query = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d";

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $query, $values ) ) ?: [];
	}

	/** Returns the total number of agents (used for pagination). */
	public function count( array $args = [] ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = '1=1';
		$values = [];

		if ( isset( $args['enabled'] ) ) {
			$where   .= ' AND enabled = %d';
			$values[] = (int) $args['enabled'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $query, $values ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $query );
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Inserts a new agent.
	 *
	 * @param array $data Associative array of column → value pairs.
	 * @return int|WP_Error New agent ID or WP_Error on failure.
	 */
	public function create( array $data ): int|\WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$data = $this->sanitize( $data );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['updated_at'] = $data['created_at'];

		if ( ! empty( $data['api_key'] ) ) {
			$data['api_key'] = $this->encrypt( $data['api_key'] );
		}

		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', __( 'Could not create agent.', 'dot-agents-press' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing agent.
	 *
	 * @param int   $id   Agent ID.
	 * @param array $data Fields to update.
	 * @return bool|\WP_Error
	 */
	public function update( int $id, array $data ): bool|\WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$data = $this->sanitize( $data, $id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		if ( array_key_exists( 'api_key', $data ) ) {
			$data['api_key'] = ! empty( $data['api_key'] )
				? $this->encrypt( $data['api_key'] )
				: '';
		}

		$result = $wpdb->update( $table, $data, [ 'id' => $id ] );

		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Could not update agent.', 'dot-agents-press' ) );
		}

		return true;
	}

	/**
	 * Deletes an agent.
	 *
	 * @param int $id Agent ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	// -------------------------------------------------------------------------
	// API key helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the decrypted API key for the given agent, falling back to the
	 * global key stored in plugin settings.
	 *
	 * @param object $agent Agent row object.
	 * @return string
	 */
	public function resolve_api_key( object $agent ): string {
		if ( ! empty( $agent->api_key ) ) {
			return $this->decrypt( $agent->api_key );
		}

		// Check wp-config.php constants first (highest priority).
		$const_map = [
			'openai'    => 'DAP_OPENAI_API_KEY',
			'anthropic' => 'DAP_ANTHROPIC_API_KEY',
		];
		$const_name = $const_map[ $agent->provider ] ?? '';
		if ( $const_name && defined( $const_name ) && constant( $const_name ) !== '' ) {
			return (string) constant( $const_name );
		}

		// Fall back to the key stored in plugin settings.
		$settings = get_option( 'dap_settings', [] );
		$key_map  = [
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
		];

		$setting_key = $key_map[ $agent->provider ] ?? '';
		return ! empty( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : '';
	}

	// -------------------------------------------------------------------------
	// Sanitize / validate
	// -------------------------------------------------------------------------

	/**
	 * Sanitizes and validates agent data for create/update.
	 *
	 * @param array    $raw Raw input.
	 * @param int|null $exclude_id Exclude this ID when checking slug uniqueness.
	 * @return array|\WP_Error
	 */
	private function sanitize( array $raw, ?int $exclude_id = null ): array|\WP_Error {
		$data = [];

		if ( isset( $raw['name'] ) ) {
			$data['name'] = sanitize_text_field( $raw['name'] );
			if ( empty( $data['name'] ) ) {
				return new \WP_Error( 'invalid_name', __( 'Agent name is required.', 'dot-agents-press' ) );
			}
		}

		if ( isset( $raw['slug'] ) ) {
			$data['slug'] = sanitize_title( $raw['slug'] );
		} elseif ( isset( $data['name'] ) ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		if ( isset( $data['slug'] ) ) {
			if ( $this->slug_exists( $data['slug'], $exclude_id ) ) {
				return new \WP_Error( 'duplicate_slug', __( 'An agent with that slug already exists.', 'dot-agents-press' ) );
			}
		}

		if ( isset( $raw['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $raw['description'] );
		}

		if ( isset( $raw['system_prompt'] ) ) {
			$data['system_prompt'] = sanitize_textarea_field( $raw['system_prompt'] );
		}

		if ( isset( $raw['welcome_msg'] ) ) {
			$data['welcome_msg'] = sanitize_textarea_field( $raw['welcome_msg'] );
		}

		$allowed_providers = [ 'openai', 'anthropic', 'custom' ];
		if ( isset( $raw['provider'] ) ) {
			$data['provider'] = in_array( $raw['provider'], $allowed_providers, true )
				? $raw['provider']
				: 'openai';
		}

		if ( isset( $raw['model'] ) ) {
			$data['model'] = sanitize_text_field( $raw['model'] );
		}

		if ( isset( $raw['temperature'] ) ) {
			$temp = (float) $raw['temperature'];
			$data['temperature'] = max( 0.0, min( 2.0, $temp ) );
		}

		if ( isset( $raw['api_key'] ) ) {
			$data['api_key'] = sanitize_text_field( $raw['api_key'] );
		}

		if ( isset( $raw['enabled'] ) ) {
			$data['enabled'] = (int) (bool) $raw['enabled'];
		}

		return $data;
	}

	/** Returns true if the given slug is already in use (optionally ignoring one ID). */
	private function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $exclude_id ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND id != %d LIMIT 1",
					$slug,
					$exclude_id
				)
			);
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug )
		);
	}

	// -------------------------------------------------------------------------
	// Encryption helpers (light-weight, uses WordPress AUTH_KEY as cipher key)
	// -------------------------------------------------------------------------

	private function encrypt( string $plain ): string {
		if ( empty( $plain ) ) {
			return '';
		}
		$key    = $this->cipher_key();
		$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$enc    = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . $enc );
	}

	private function decrypt( string $cipher ): string {
		if ( empty( $cipher ) ) {
			return '';
		}
		try {
			$key     = $this->cipher_key();
			$decoded = base64_decode( $cipher, true );
			if ( false === $decoded ) {
				return '';
			}
			$iv_len  = openssl_cipher_iv_length( 'AES-256-CBC' );
			$iv      = substr( $decoded, 0, $iv_len );
			$enc     = substr( $decoded, $iv_len );
			$plain   = openssl_decrypt( $enc, 'AES-256-CBC', $key, 0, $iv );
			return ( false === $plain ) ? '' : $plain;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private function cipher_key(): string {
		$raw = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'dot-agents-press-fallback-key';
		return substr( hash( 'sha256', $raw, true ), 0, 32 );
	}
}
