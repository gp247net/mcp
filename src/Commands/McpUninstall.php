<?php

namespace GP247\Mcp\Commands;

use GP247\Core\Console\GP247Command;
use GP247\Mcp\Install\Installer;
use Throwable;

/**
 * Remove the gp247/mcp data (settings, menu, permissions) and revoke every MCP token, before
 * `composer remove gp247/mcp`. Asks for confirmation; --force for scripts.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-package-install
 * @aidlc-story US-plugin-mcp-server-access-token
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
class McpUninstall extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:mcp-uninstall {--force : Do not ask for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove gp247/mcp data and revoke every MCP token';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        if (!Installer::platformTablesExist()) {
            return $this->respondFailure('not_installed', 'GP247 is not installed in this database.');
        }

        if (!$this->option('force')) {
            // WHY refuse instead of asking: --json / scripts cannot answer a prompt.
            if ($this->isJson() || !$this->input->isInteractive()) {
                return $this->respondFailure('confirmation_required', 'Refusing to uninstall without confirmation. Pass --force.');
            }
            $this->warn('This removes MCP settings, menu and permissions and revokes every MCP token. Agents lose access immediately.');
            if (!$this->confirm('Continue?', false)) {
                return $this->respondFailure('cancelled', 'Uninstall cancelled.');
            }
        }

        try {
            $revoked = (new Installer)->uninstall();
        } catch (Throwable $e) {
            gp247_report($e->getMessage());

            return $this->respondFailure('mcp_uninstall_failed', 'MCP uninstall failed: ' . $e->getMessage());
        }

        $this->info('- MCP data removed; ' . $revoked . ' MCP token(s) revoked.');

        return $this->respondSuccess(['uninstalled' => true, 'revoked_tokens' => $revoked]);
    }
}
