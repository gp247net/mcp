<?php

namespace GP247\Mcp\Services;

/**
 * Expected, user-facing tool failure (not found, invalid input, refused by a business rule).
 * The message is safe to return to the MCP client.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 */
class ToolException extends \RuntimeException
{
    /**
     * Build an exception from a gp247-mcp language key.
     *
     * @param string $key     Key under gp247-mcp::lang.
     * @param array  $replace Replacements for the translation.
     */
    public static function trans(string $key, array $replace = []): self
    {
        return new self((string) trans('gp247-mcp::lang.' . $key, $replace));
    }

    /**
     * The generic "not found" answer, identical whether the record is missing or out of scope.
     */
    public static function notFound(): self
    {
        return self::trans('error_not_found');
    }
}
