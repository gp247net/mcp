<?php

namespace GP247\Mcp\Support;

/**
 * Soft integrations with other plugins, through their config only (no class import).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-endpoint
 * @aidlc-adr ADR-plugin-mcp-server-endpoint-placement
 */
final class Integrations
{
    private const SANDBOX_KEY = 'Plugins/SandboxDemo';

    /**
     * Declare the MCP endpoint as an admin surface for SandboxDemo, so its DB write guard also
     * covers MCP calls. Runs after every provider booted (SandboxDemo merges its config later
     * than this package).
     */
    public static function register(): void
    {
        $sandbox = config(self::SANDBOX_KEY);
        if (!is_array($sandbox) || !array_key_exists('admin_surface_prefixes', $sandbox)) {
            return;
        }
        $prefixes = array_values((array) $sandbox['admin_surface_prefixes']);
        if (!in_array(Routes::ENDPOINT, $prefixes, true)) {
            $prefixes[] = Routes::ENDPOINT;
            config([self::SANDBOX_KEY . '.admin_surface_prefixes' => $prefixes]);
        }
    }
}
