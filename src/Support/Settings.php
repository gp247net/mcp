<?php

namespace GP247\Mcp\Support;

use GP247\Mcp\Tools\ToolCatalog;
use GP247\Core\Models\AdminConfig;

/**
 * Site-wide MCP settings stored as admin_config rows (group gp247_mcp, code mcp_config, store GLOBAL).
 *
 * Values are read once per request (scoped container instance) and fall back to safe
 * defaults: endpoint off, write tools off, contact details masked.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */
final class Settings
{
    public const GROUP = 'gp247_mcp';
    public const PREFIX = 'mcp_';
    public const CODE = 'mcp_config';

    /** @var array<string, string> Non-tool settings and their defaults. */
    public const BASE_DEFAULTS = [
        'endpoint_enabled' => '0',
        'allow_pii' => '0',
        'allowed_origins' => '',
        'throttle_per_minute' => '60',
        'token_default_days' => '30',
        'token_max_days' => '365',
        'low_stock_threshold' => '5',
        'max_range_days' => '366',
        'max_request_kb' => '64',
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * Load the stored values merged over the defaults.
     */
    public function __construct()
    {
        $stored = [];
        try {
            $stored = AdminConfig::query()
                ->where('code', self::CODE)
                ->where('store_id', (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0))
                ->pluck('value', 'key')
                ->all();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }

        $values = self::defaults();
        foreach ($stored as $key => $value) {
            if (str_starts_with((string) $key, self::PREFIX)) {
                $values[substr((string) $key, strlen(self::PREFIX))] = (string) $value;
            }
        }
        $this->values = $values;
    }

    /**
     * Defaults for every setting, tool switches included.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $defaults = self::BASE_DEFAULTS;
        foreach (ToolCatalog::all() as $tool) {
            $defaults['tool_' . $tool->name] = $tool->defaultEnabled ? '1' : '0';
        }

        return $defaults;
    }

    /**
     * Instance for the current request.
     */
    public static function current(): self
    {
        $app = app();
        if (!$app->bound(self::class)) {
            // WHY scoped: one DB read per request, reset between requests (Octane/queue safe).
            $app->scoped(self::class);
        }

        return $app->make(self::class);
    }

    /**
     * Drop the cached instance (after a save, and between tests).
     */
    public static function flush(): void
    {
        app()->forgetInstance(self::class);
    }

    /**
     * Raw value of a setting.
     */
    public static function get(string $key): string
    {
        return self::current()->values[$key] ?? (self::defaults()[$key] ?? '');
    }

    /**
     * Boolean value of a switch.
     */
    public static function bool(string $key): bool
    {
        return in_array(strtolower(trim(self::get($key))), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Integer value clamped to [min, max].
     */
    public static function int(string $key, int $min = 1, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    /**
     * Whether the MCP endpoint is switched on.
     */
    public static function endpointEnabled(): bool
    {
        return self::bool('endpoint_enabled');
    }

    /**
     * Whether a tool is switched on for the site (L0).
     */
    public static function toolEnabled(string $tool): bool
    {
        return self::bool('tool_' . $tool);
    }

    /**
     * Whether customer contact details may be sent unmasked.
     */
    public static function allowPii(): bool
    {
        return self::bool('allow_pii');
    }

    /**
     * True when at least one write tool is switched on for the site.
     */
    public static function anyWriteToolEnabled(): bool
    {
        foreach (ToolCatalog::all() as $tool) {
            if ($tool->isWrite() && self::toolEnabled($tool->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extra browser origins allowed besides the site itself.
     *
     * @return string[] Normalised origins (lower case, no trailing slash).
     */
    public static function allowedOrigins(): array
    {
        $origins = [];
        foreach (preg_split('/[\s,]+/', self::get('allowed_origins')) ?: [] as $origin) {
            $origin = strtolower(rtrim(trim($origin), '/'));
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /**
     * Admin_config key of a setting.
     */
    public static function key(string $name): string
    {
        return self::PREFIX . $name;
    }

    /**
     * Create missing settings rows with their defaults (existing values are never overwritten).
     * Used by install/update and by the settings screen of a site updated without converge().
     */
    public static function ensureRows(): void
    {
        $sort = 0;
        foreach (self::defaults() as $name => $default) {
            AdminConfig::query()->firstOrCreate(
                ['group' => self::GROUP, 'key' => self::key($name), 'store_id' => (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0)],
                [
                    'code' => self::CODE,
                    'sort' => ++$sort,
                    'value' => $default,
                    'security' => 0,
                    'detail' => 'gp247-mcp::lang.setting.' . $name,
                ]
            );
        }
        self::flush();
    }
}
