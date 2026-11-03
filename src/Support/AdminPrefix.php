<?php

namespace GP247\Mcp\Support;

/**
 * Admin URL prefix (GP247_ADMIN_PREFIX) with the core default as fallback.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */
final class AdminPrefix
{
    /**
     * Prefix without slashes, e.g. "gp247_admin".
     */
    public static function get(): string
    {
        return defined('GP247_ADMIN_PREFIX') ? (string) GP247_ADMIN_PREFIX : 'gp247_admin';
    }

    /**
     * Admin path under the prefix, e.g. path('order') = "gp247_admin/order".
     */
    public static function path(string $path): string
    {
        return self::get() . '/' . ltrim($path, '/');
    }
}
