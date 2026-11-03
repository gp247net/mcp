<?php

namespace GP247\Mcp\Services;

use GP247\Mcp\Support\AdminPrefix;
use GP247\Mcp\Support\SafeReport;
use GP247\Mcp\Support\Settings;
use GP247\Mcp\Tools\ToolDefinition;
use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Infrastructure\EloquentAdminUserAdapter;
use GP247\Core\Models\AdminUser;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Five-layer authorization of an MCP tool call (intersection, deny by default):
 * L0 site switch · L1 MCP permission + active account · L2 token level/allowlist ·
 * L3 admin screen permission (same Layer-2 use case as the Livewire screens, so view.all,
 * administrator and the action fence behave exactly as on the admin) · L4 is applied by the
 * tool on the records it reads (StoreContext).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
class ToolAuthorizer
{
    /** Admin path (under the admin prefix) of the token screen; its GET permission = "may use MCP". */
    public const USE_SCREEN = 'mcp/tokens';

    /**
     * Whether the user may use MCP at all (L1).
     */
    public function mayUseMcp(AdminUser $user): bool
    {
        if ((int) $user->status !== 1) {
            return false;
        }

        return $this->screenAllows($user, AdminPrefix::path(self::USE_SCREEN), false);
    }

    /**
     * Whether this user + token may list/call the tool (L0–L3).
     *
     * @param string|null $recordId Record the call targets, for screens with "{id}".
     */
    public function allows(AdminUser $user, ?PersonalAccessToken $token, ToolDefinition $tool, ?string $recordId = null): bool
    {
        if (!$tool->available() || !Settings::toolEnabled($tool->name)) {
            return false;
        }
        if (!McpToken::isMcp($token) || ($tool->isWrite() && !McpToken::canWrite($token))) {
            return false;
        }
        $allowlist = McpToken::allowedTools($token);
        if ($allowlist !== [] && !in_array($tool->name, $allowlist, true)) {
            return false;
        }
        if (!$this->mayUseMcp($user)) {
            return false;
        }
        if ($tool->usesEventScreens()) {
            return $this->readableEventTypes($user) !== [];
        }
        $screen = $tool->screenUri($recordId);

        return $screen === null || $this->screenAllows($user, $screen, $tool->isWrite());
    }

    /**
     * Event types of the core event log the user may read — those whose admin screen the user
     * may open, the same rule as GET api/v2/core/events (L3 of recent_changes).
     *
     * @return string[]
     *
     * @aidlc-story US-plugin-mcp-server-recent-changes-read
     */
    public function readableEventTypes(AdminUser $user): array
    {
        try {
            return (array) \GP247\Core\Api\Sync\EventType::readableBy($user);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            return [];
        }
    }

    /**
     * Run the admin Layer-2 decision for a screen path.
     */
    private function screenAllows(AdminUser $user, string $screenUri, bool $mutating): bool
    {
        try {
            // WHY 'render' / 'mcpWrite': the use case maps read-only Livewire hooks to GET and
            // anything else to POST — the same split the admin screens use.
            return app(AuthorizeAdminAction::class)
                ->authorize(new EloquentAdminUserAdapter($user), $screenUri, $mutating ? 'mcpWrite' : 'render')
                ->isAllowed();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            return false;
        }
    }
}
