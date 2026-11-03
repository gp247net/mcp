<?php

namespace GP247\Mcp\Livewire;

use GP247\Mcp\Support\Routes;
use GP247\Mcp\Support\Settings;
use GP247\Mcp\Tools\ToolCatalog;
use GP247\Core\AdminShell\Infrastructure\ConfigForm;

/**
 * Site-wide MCP settings (shared core ConfigForm): endpoint switch, per-tool switches,
 * contact-detail policy, origins and limits. Global scope only.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-story US-plugin-mcp-server-customer-read
 * @aidlc-story US-plugin-mcp-server-connect-guide
 */
class McpSettings extends ConfigForm
{
    protected ?string $permission = 'admin_mcp/settings';

    private const NUMBERS = ['throttle_per_minute', 'token_default_days', 'token_max_days', 'low_stock_threshold', 'max_range_days', 'max_request_kb'];

    /**
     * Make sure every settings row exists (sites updated without re-running converge()).
     */
    public function mount(): void
    {
        Settings::ensureRows();

        parent::mount();
    }

    /**
     * Persist and drop the cached settings so the endpoint sees the change immediately.
     */
    public function save(): void
    {
        parent::save();
        Settings::flush();
    }

    /**
     * admin_config group of the settings rows.
     */
    protected function group(): string
    {
        return Settings::GROUP;
    }

    /**
     * Screen heading.
     */
    protected function heading(): string
    {
        return (string) trans('gp247-mcp::lang.settings_heading');
    }

    /**
     * admin_config keys edited by the screen.
     *
     * @return string[]
     */
    protected function keys(): array
    {
        return array_map(static fn ($name) => Settings::key($name), array_keys(Settings::defaults()));
    }

    /**
     * Field widget per key: toggles, numbers, text for origins.
     *
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        $types = [];
        foreach (array_keys(Settings::defaults()) as $name) {
            if (in_array($name, self::NUMBERS, true)) {
                $types[Settings::key($name)] = 'number';
            } elseif ($name !== 'allowed_origins') {
                $types[Settings::key($name)] = 'toggle';
            }
        }

        return $types;
    }

    /**
     * Grouping of the fields into sections.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sections(): array
    {
        $read = [];
        $write = [];
        foreach (ToolCatalog::all() as $tool) {
            if ($tool->isWrite()) {
                $write[] = Settings::key('tool_' . $tool->name);
            } else {
                $read[] = Settings::key('tool_' . $tool->name);
            }
        }

        return [
            [
                'id' => 'endpoint',
                'title' => trans('gp247-mcp::lang.section_endpoint'),
                'hint' => trans('gp247-mcp::lang.section_endpoint_hint', ['url' => Routes::endpointUrl()]),
                'keys' => [Settings::key('endpoint_enabled'), Settings::key('allowed_origins')],
            ],
            [
                'id' => 'read-tools',
                'title' => trans('gp247-mcp::lang.section_read_tools'),
                'hint' => trans('gp247-mcp::lang.section_read_tools_hint'),
                'keys' => $read,
            ],
            [
                'id' => 'write-tools',
                'title' => trans('gp247-mcp::lang.section_write_tools'),
                'hint' => trans('gp247-mcp::lang.section_write_tools_hint'),
                'keys' => $write,
            ],
            [
                'id' => 'privacy',
                'title' => trans('gp247-mcp::lang.section_privacy'),
                'hint' => trans('gp247-mcp::lang.section_privacy_hint'),
                'keys' => [Settings::key('allow_pii')],
            ],
            [
                'id' => 'limits',
                'title' => trans('gp247-mcp::lang.section_limits'),
                'keys' => array_map(static fn ($name) => Settings::key($name), self::NUMBERS),
            ],
        ];
    }

    /**
     * Placeholder hints.
     *
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return [Settings::key('allowed_origins') => 'https://app.example.com, https://other.example'];
    }
}
