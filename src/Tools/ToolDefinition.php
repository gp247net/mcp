<?php

namespace GP247\Mcp\Tools;

use GP247\Mcp\Support\AdminPrefix;

/**
 * Static description of one MCP tool: its access level and the admin screen whose
 * permission it borrows (L3 of the authorization model).
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-adr ADR-plugin-mcp-server-token-auth
 */
final class ToolDefinition
{
    public const READ = 'read';
    public const WRITE = 'write';
    /** Screen marker: readable when the user may open the screen of at least one event type (core EventType). */
    public const EVENTS_SCREEN = '*events';

    /**
     * @param string      $name           Tool name exposed to MCP clients.
     * @param string      $level          self::READ or self::WRITE.
     * @param string|null $screen         Admin path relative to the admin prefix (may contain "{id}"); null = no screen check.
     * @param bool        $defaultEnabled Whether the site enables the tool right after install.
     * @param string[]    $requires       Core/shop seams the tool needs: "Class" or "Class::method"; missing ⇒ tool unavailable.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $level,
        public readonly ?string $screen,
        public readonly bool $defaultEnabled,
        public readonly array $requires = [],
    ) {
    }

    /**
     * True for tools that change shop data.
     */
    public function isWrite(): bool
    {
        return $this->level === self::WRITE;
    }

    /**
     * Whether every seam the tool needs exists on this site (a site may update gp247/mcp before
     * gp247/core or gp247/shop); an unavailable tool is hidden like a disabled one.
     *
     * @aidlc-story US-plugin-mcp-server-rbac-tools
     */
    public function available(): bool
    {
        foreach ($this->requires as $seam) {
            [$class, $method] = array_pad(explode('::', $seam, 2), 2, null);
            $class = ltrim((string) $class, '\\');
            if (!class_exists($class) || ($method !== null && !method_exists($class, $method))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the RBAC check is "any readable event type" instead of one admin screen.
     *
     * @aidlc-story US-plugin-mcp-server-recent-changes-read
     */
    public function usesEventScreens(): bool
    {
        return $this->screen === self::EVENTS_SCREEN;
    }

    /**
     * Full admin path used for the RBAC check, or null when the tool has no screen.
     *
     * @param string|null $recordId Record id substituted for "{id}" (a placeholder is used when null).
     */
    public function screenUri(?string $recordId = null): ?string
    {
        if ($this->screen === null || $this->usesEventScreens()) {
            return null;
        }

        return AdminPrefix::path(str_replace('{id}', $recordId ?? '0', $this->screen));
    }
}
