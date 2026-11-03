<?php

namespace GP247\Mcp\Services;

use GP247\Core\Models\AdminUser;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is calling and in which store scope — built once per MCP request by the edge guard.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */
final class McpContext
{
    /**
     * @param AdminUser           $user    Token owner (acting admin).
     * @param PersonalAccessToken $token   Token used for this request.
     * @param string              $storeId Resolved store scope (ROOT = every store on a partitioned site).
     */
    public function __construct(
        public readonly AdminUser $user,
        public readonly PersonalAccessToken $token,
        public readonly string $storeId,
    ) {
    }

    /**
     * Context of the current request, or null outside an authenticated MCP request.
     */
    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
