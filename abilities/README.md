# Abilities System

This directory contains all abilities for the dot-agents-press plugin. Each ability is a PHP class that extends `AbilityAbstract` and implements a specific capability.

## Structure

- `base.php` — Abstract base class that all abilities extend
- `registry.php` — Auto-discovery and registration system
- `*.php` — Individual ability implementations (one file per ability)

## Naming Convention

- **Filename** must match the ability slug (without `dot-agents-press/` prefix)
  - Example: `dot-agents-press/read-file` → `read-file.php`
- **Class name** is auto-derived from filename
  - Example: `read-file.php` → `ReadFile` class in `DotAgentsPress\Abilities` namespace

## Creating a New Ability

1. Create a new file in this directory: `{slug}.php`
2. Extend `AbilityAbstract` and implement required methods:

```php
<?php

namespace DotAgentsPress\Abilities;

class MyAbility extends AbilityAbstract {

    public function __construct() {
        $this->name        = 'dot-agents-press/my-ability';
        $this->label       = 'My Ability';
        $this->description = 'Description of what this ability does';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'param1' => [
                    'type'        => 'string',
                    'description' => 'First parameter',
                ],
            ],
            'required' => [ 'param1' ],
        ];
    }

    public function execute( array $args ) {
        $param1 = isset( $args['param1'] ) ? (string) $args['param1'] : '';

        if ( empty( $param1 ) ) {
            return new \WP_Error( 'invalid_input', 'param1 is required' );
        }

        // Your logic here
        return [
            'result' => 'success',
            'data'   => $param1,
        ];
    }
}
```

3. The ability will be automatically discovered and registered on plugin load.

## Available Abilities

### dot-agents-press/read-file
Read file contents from allowed directories.

**Input:**
- `file_path` (string, required) — Path to the file

**Output:**
- `content` (string) — File contents
- `size` (integer) — File size in bytes

### dot-agents-press/list-directory
List files and directories in allowed paths.

**Input:**
- `directory` (string, required) — Path to the directory
- `recursive` (boolean, default: false) — Whether to list recursively
- `max_depth` (integer, default: 3) — Maximum recursion depth

**Output:**
- `items` (array) — Array of file/directory objects
- `count` (integer) — Number of items

### dot-agents-press/discover-skills
Find and list all SKILL.md files in the project.

**Input:**
- `directories` (array, optional) — Directories to search

**Output:**
- `skills` (array) — Array of skill objects
- `count` (integer) — Number of skills found

### dot-agents-press/execute-command
Execute whitelisted shell commands.

**Input:**
- `command` (string, required) — Shell command to execute
- `timeout` (integer, default: 30) — Command timeout in seconds

**Output:**
- `output` (string) — Command output
- `exit_code` (integer) — Exit code

## Security

All abilities enforce:
- **Path validation** — All file paths are normalized and checked against an allowlist
- **Permission checks** — By default, only users with `manage_options` capability can execute abilities
- **Command whitelist** — Shell commands are checked against a whitelist (filterable via `dap_ability_command_whitelist`)
- **Error handling** — All errors return `WP_Error` with stable error codes

## Extending Abilities

### Custom Allowed Directories

Override the `get_allowed_dirs()` method or use the `dap_ability_allowed_dirs` filter:

```php
add_filter( 'dap_ability_allowed_dirs', function( $dirs ) {
    $dirs[] = '/custom/path/';
    return $dirs;
} );
```

### Custom Command Whitelist

Use the `dap_ability_command_whitelist` filter:

```php
add_filter( 'dap_ability_command_whitelist', function( $whitelist ) {
    $whitelist[] = 'custom-command ';
    return $whitelist;
} );
```

### Custom Permissions

Override `check_permission()` in your ability class:

```php
public function check_permission(): bool {
    return current_user_can( 'edit_posts' );
}
```

## Testing

All abilities are automatically registered when the plugin loads. To test:

```bash
wp dap chat "Use the read-file ability to read /path/to/file.txt"
```

The agent will use the registered ability to execute the request.
