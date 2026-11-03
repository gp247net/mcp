<?php

namespace GP247\Mcp;

use GP247\Mcp\Commands\McpInstall;
use GP247\Mcp\Commands\McpUninstall;
use GP247\Mcp\Commands\McpUpdate;
use GP247\Mcp\Install\Installer;
use GP247\Mcp\Support\Integrations;
use GP247\Mcp\Support\Routes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider of gp247/mcp, the MCP server shipped with S-Cart (replaces the
 * McpServer plugin). Data lives in admin_config / admin_menu / admin_permission and is
 * created by gp247:mcp-install / gp247:mcp-update; nothing here writes to the database.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-story US-plugin-mcp-server-plugin-migration
 * @aidlc-adr ADR-plugin-mcp-server-package-distribution
 */
class McpServiceProvider extends ServiceProvider
{
    /** Single source of the package version (MCP server info + installed-data marker). */
    public const VERSION = '2.0.1';

    /** Key of the McpServer plugin this package replaces. */
    public const LEGACY_PLUGIN_KEY = 'McpServer';

    /**
     * Boot the package.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/Lang', 'gp247-mcp');
        $this->loadViewsFrom(__DIR__ . '/Views', 'gp247-mcp');

        if ($this->app->runningInConsole()) {
            $this->commands([McpInstall::class, McpUpdate::class, McpUninstall::class]);
        }

        if (!self::platformInstalled()) {
            return;
        }

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::addNamespace('gp247-mcp', classNamespace: 'GP247\\Mcp\\Livewire');
        }

        // WHY booted(): the core loads its helpers and the plugin providers during its own boot;
        // SandboxDemo's config and the CheckIP middleware alias exist only after that.
        $this->app->booted(function (): void {
            // One URL, one owner: until gp247:mcp-update moves the plugin's data, the old
            // plugin keeps serving api/core/mcp with its own limiter (US-plugin-mcp-server-plugin-migration).
            if (self::legacyPluginServes()) {
                return;
            }
            Integrations::register();
            Routes::registerRateLimiters();

            if ($this->app->routesAreCached() || !class_exists(\Laravel\Mcp\Server::class)) {
                return;
            }
            Routes::register();
        });
    }

    /**
     * Whether the GP247 platform is installed (same marker the core gates its runtime on).
     */
    public static function platformInstalled(): bool
    {
        return defined('GP247_ADMIN_PREFIX')
            && (!defined('GP247_ACTIVE') || GP247_ACTIVE == 1)
            && Storage::disk('local')->exists('gp247-installed.txt');
    }

    /**
     * Whether the McpServer plugin is still installed and active on this site.
     */
    public static function legacyPluginActive(): bool
    {
        return function_exists('gp247_extension_check_active')
            && is_dir(app_path('GP247/Plugins/' . self::LEGACY_PLUGIN_KEY))
            && gp247_extension_check_active('Plugins', self::LEGACY_PLUGIN_KEY);
    }

    /**
     * Whether the old plugin, not this package, owns the MCP routes: only while the plugin is active
     * AND the package data was never installed. A plugin re-installed after the move never takes
     * MCP back (its uninstall would revoke the package's tokens).
     */
    public static function legacyPluginServes(): bool
    {
        if (!self::legacyPluginActive()) {
            return false;
        }
        try {
            return Installer::installedVersion() === null;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Whether the McpServer plugin is registered as installed (its row exists, active or not).
     * Uninstalling a registered plugin runs its own uninstall(), which revokes every MCP token.
     */
    public static function legacyPluginInstalled(): bool
    {
        return function_exists('gp247_extension_check_installed')
            && gp247_extension_check_installed('Plugins', self::LEGACY_PLUGIN_KEY);
    }

    /**
     * Safe way to remove a leftover McpServer plugin folder, or null when there is nothing to do:
     * "purge" when the plugin is no longer registered (gp247:ext-uninstall --purge only deletes
     * files), "disable" when it was installed again (uninstalling it would revoke MCP tokens).
     *
     * @aidlc-story US-plugin-mcp-server-plugin-migration
     */
    public static function legacyPluginCleanup(): ?string
    {
        if (!self::legacyPluginFolderPresent() || self::legacyPluginServes()) {
            return null;
        }

        return self::legacyPluginInstalled() ? 'disable' : 'purge';
    }

    /**
     * Whether the McpServer plugin folder is still on disk (shown as a cleanup hint once the
     * data has moved to the package).
     */
    public static function legacyPluginFolderPresent(): bool
    {
        return is_dir(app_path('GP247/Plugins/' . self::LEGACY_PLUGIN_KEY));
    }
}
