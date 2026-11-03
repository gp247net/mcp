<?php

namespace GP247\Mcp\Services;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Reads MCP abilities from a Sanctum token.
 *
 * Abilities are matched literally: Sanctum's can() treats "*" as "everything", which would let a
 * generic API token reach MCP — MCP tokens must carry the explicit "mcp:*" abilities.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-access-token
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
final class McpToken
{
    public const READ = 'mcp:read';
    public const WRITE = 'mcp:write';
    public const TOOL_PREFIX = 'mcp:tool:';

    /**
     * @return string[] Abilities stored on the token.
     */
    public static function abilities(?PersonalAccessToken $token): array
    {
        if ($token === null) {
            return [];
        }

        return array_values(array_filter((array) $token->abilities, 'is_string'));
    }

    /** True when the token is an MCP token (explicit mcp:read). */
    public static function isMcp(?PersonalAccessToken $token): bool
    {
        return in_array(self::READ, self::abilities($token), true);
    }

    /** True when the token may call write tools. */
    public static function canWrite(?PersonalAccessToken $token): bool
    {
        return in_array(self::WRITE, self::abilities($token), true);
    }

    /**
     * Tool allowlist of the token; empty array = every tool of its level.
     *
     * @return string[]
     */
    public static function allowedTools(?PersonalAccessToken $token): array
    {
        $tools = [];
        foreach (self::abilities($token) as $ability) {
            if (str_starts_with($ability, self::TOOL_PREFIX)) {
                $tools[] = substr($ability, strlen(self::TOOL_PREFIX));
            }
        }

        return $tools;
    }
}
