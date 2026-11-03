<?php

namespace GP247\Mcp\Commands;

use GP247\Core\Console\GP247Command;
use GP247\Mcp\Install\Installer;
use GP247\Mcp\McpServiceProvider;
use Throwable;

/**
 * Apply the gp247/mcp data for this package version: upgrade migrations (including the move
 * from the McpServer plugin) then settings / menu / permissions. Idempotent; called by
 * gp247:update (core 3.9+). Never destructive.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-story US-plugin-mcp-server-plugin-migration
 * @aidlc-adr ADR-plugin-mcp-server-package-distribution
 */
class McpUpdate extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:mcp-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply gp247/mcp data for this version (idempotent, keeps tokens and settings)';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        if (!Installer::platformTablesExist()) {
            return $this->respondFailure('not_installed', 'GP247 is not installed in this database. Run php artisan gp247:install first.');
        }

        $before = Installer::installedVersion();
        try {
            // WHY --realpath: the package may live outside base_path()/vendor (path repository).
            $code = $this->runArtisan('migrate', [
                '--path' => dirname(__DIR__) . '/Database/Migrations/upgrade',
                '--realpath' => true,
                '--force' => true,
            ]);
            if ($code !== self::SUCCESS) {
                return $this->respondFailure('mcp_update_failed', 'MCP upgrade migrations failed (exit code ' . $code . ').');
            }
            (new Installer)->converge();
        } catch (Throwable $e) {
            gp247_report($e->getMessage());

            return $this->respondFailure('mcp_update_failed', 'MCP update failed: ' . $e->getMessage());
        }

        $this->info('- MCP data ready (gp247/mcp ' . McpServiceProvider::VERSION . ')');
        $cleanup = McpServiceProvider::legacyPluginCleanup();
        if ($cleanup === 'purge') {
            $this->addWarning('The McpServer plugin folder is still present; remove it with: php artisan gp247:ext-uninstall --type=plugin --key=McpServer --purge');
        } elseif ($cleanup === 'disable') {
            // WHY not ext-uninstall: the plugin is registered again, and its uninstall() revokes every MCP token.
            $this->addWarning('The old McpServer plugin is installed again. Do not uninstall it (that revokes every MCP token): run php artisan gp247:ext-disable --type=plugin --key=McpServer, then delete app/GP247/Plugins/McpServer by hand.');
        }
        if ($this->laravel->routesAreCached()) {
            // WHY: cached routes may still point at the old plugin; gp247:update rebuilds them, a lone run does not.
            $this->addWarning('Routes are cached: run php artisan gp247:cache-rebuild so the MCP screens and endpoint use the new routes.');
        }

        return $this->respondSuccess([
            'version' => McpServiceProvider::VERSION,
            'previous_version' => $before,
            'legacy_plugin_folder' => McpServiceProvider::legacyPluginFolderPresent(),
        ]);
    }
}
