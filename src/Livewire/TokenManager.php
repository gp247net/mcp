<?php

namespace GP247\Mcp\Livewire;

use GP247\Mcp\McpServiceProvider;
use GP247\Mcp\Services\AuditLog;
use GP247\Mcp\Services\ConnectionCheck;
use GP247\Mcp\Services\McpToken;
use GP247\Mcp\Services\TokenService;
use GP247\Mcp\Support\AdminPrefix;
use GP247\Mcp\Support\Routes;
use GP247\Mcp\Support\Settings;
use GP247\Mcp\Tools\ToolCatalog;
use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminUser;
use Illuminate\Validation\Rule;

/**
 * "MCP tokens" screen: create / list / revoke the caller's MCP tokens (administrators see all),
 * plus the connection guide and the server-side connection check.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-access-token
 * @aidlc-story US-plugin-mcp-server-connect-guide
 */
class TokenManager extends GP247AdminComponent
{
    protected ?string $permission = 'admin_mcp/tokens';

    /** Tokens expiring within this many days are flagged on the screen. */
    private const EXPIRING_SOON_DAYS = 7;

    public string $name = '';

    public string $level = 'read';

    public int $days = 30;

    /** @var string[] Optional tool allowlist. */
    public array $tools = [];

    /** Plain token, shown once right after creation. */
    public ?string $plainToken = null;

    /** @var array{status: string, https: bool}|null Result of the last connection check. */
    public ?array $check = null;

    /**
     * Initialise defaults.
     */
    public function mount(): void
    {
        parent::mount();
        $this->days = Settings::int('token_default_days', 1, Settings::int('token_max_days', 1, 3650));
    }

    /**
     * Create a token for the current admin.
     */
    public function create(): void
    {
        $this->authorizeAction('create');
        $max = Settings::int('token_max_days', 1, 3650);
        $this->validate([
            'name' => 'required|string|max:100',
            'level' => 'required|in:read,write',
            'days' => 'required|integer|min:1|max:' . $max,
            'tools' => 'array',
            'tools.*' => ['string', Rule::in(ToolCatalog::names())],
        ]);
        if ($this->level === 'write' && !Settings::anyWriteToolEnabled()) {
            $this->addError('level', gp247_language_render('gp247-mcp::lang.error_no_write_tool'));

            return;
        }

        $user = $this->actor();
        $issued = app(TokenService::class)->issue($user, trim($this->name), $this->level, $this->tools, $this->days);
        $this->plainToken = $issued->plainTextToken;
        app(AuditLog::class)->tokenAction($user, 'token.create', [
            'token_id' => $issued->accessToken->getKey(),
            'name' => $issued->accessToken->name,
            'abilities' => $issued->accessToken->abilities,
            'expires_at' => (string) $issued->accessToken->expires_at,
        ]);
        $this->reset(['name', 'level', 'tools']);
        $this->notify('success', gp247_language_render('gp247-mcp::lang.msg_token_created'));
    }

    /**
     * Revoke a token visible to the current admin.
     */
    public function revoke(int $tokenId): void
    {
        $this->authorizeAction('revoke');
        $user = $this->actor();
        if (!app(TokenService::class)->revoke($user, $tokenId)) {
            $this->notify('error', gp247_language_render('gp247-mcp::lang.msg_token_not_found'));

            return;
        }
        app(AuditLog::class)->tokenAction($user, 'token.revoke', ['token_id' => $tokenId]);
        $this->notify('success', gp247_language_render('gp247-mcp::lang.msg_token_revoked'));
    }

    /**
     * Hide the plain token once the admin has copied it.
     */
    public function dismissToken(): void
    {
        $this->plainToken = null;
    }

    /**
     * Call the site's own probe to detect a stripped Authorization header.
     */
    public function checkConnection(): void
    {
        $this->authorizeAction('checkConnection');
        $this->check = app(ConnectionCheck::class)->run();
    }

    /**
     * Render the screen.
     */
    public function render()
    {
        $user = $this->actor();
        $endpoint = Routes::endpointUrl();
        $tokens = app(TokenService::class)->visibleTo($user);
        $soon = now()->addDays(self::EXPIRING_SOON_DAYS);

        return view('gp247-mcp::admin.tokens', [
            'tokens' => $tokens,
            'isAdministrator' => $user->isAdministrator(),
            'endpointEnabled' => Settings::endpointEnabled(),
            'endpointUrl' => $endpoint,
            'https' => str_starts_with($endpoint, 'https://'),
            'canWrite' => Settings::anyWriteToolEnabled(),
            'toolGroups' => $this->toolGroups(),
            'maxDays' => Settings::int('token_max_days', 1, 3650),
            'snippets' => $this->snippets($endpoint),
            'settingsUrl' => $this->canOpenSettings() ? gp247_route_admin('admin_mcp.settings') : null,
            // WHY administrators only: removing an extension folder is a site-owner task.
            'legacyPluginCleanup' => $user->isAdministrator() ? McpServiceProvider::legacyPluginCleanup() : null,
            'stats' => [
                'active' => $tokens->filter(fn ($t) => !$t->expires_at || $t->expires_at->isFuture())->count(),
                'expiring' => $tokens->filter(fn ($t) => $t->expires_at && $t->expires_at->isFuture() && $t->expires_at->lte($soon))->count(),
                'expired' => $tokens->filter(fn ($t) => $t->expires_at && $t->expires_at->isPast())->count(),
                'readTools' => collect(ToolCatalog::all())->filter(fn ($d) => !$d->isWrite() && Settings::toolEnabled($d->name))->count(),
                'writeTools' => collect(ToolCatalog::all())->filter(fn ($d) => $d->isWrite() && Settings::toolEnabled($d->name))->count(),
            ],
            'tokenLevel' => static fn ($token) => McpToken::canWrite($token) ? 'write' : 'read',
            'tokenTools' => static fn ($token) => McpToken::allowedTools($token),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('gp247-mcp::lang.title'),
        ]);
    }

    /**
     * Tools offered for a token allowlist, grouped by level, with readable labels.
     * Write tools are listed only while the site has them switched on.
     *
     * @return array{read: array<string, string>, write: array<string, string>}
     */
    private function toolGroups(): array
    {
        $groups = ['read' => [], 'write' => []];
        foreach (ToolCatalog::all() as $tool) {
            if ($tool->isWrite() && !Settings::toolEnabled($tool->name)) {
                continue;
            }
            $label = (string) trans('gp247-mcp::lang.setting.tool_' . $tool->name);
            $groups[$tool->isWrite() ? 'write' : 'read'][$tool->name] = trim((string) preg_replace('/^' . preg_quote($tool->name, '/') . '\s*—\s*/u', '', $label));
        }

        return $groups;
    }

    /**
     * Whether the current admin may open the settings screen (the link is hidden otherwise).
     */
    private function canOpenSettings(): bool
    {
        try {
            return app(AuthorizeAdminAction::class)
                ->authorize(app(AdminUserContract::class), AdminPrefix::path(Routes::ADMIN_URI . '/settings'), 'render')
                ->isAllowed();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Client configuration samples; the token is read from an environment variable / prompt,
     * never pasted into the sample.
     *
     * @return array<string, string>
     */
    private function snippets(string $endpoint): array
    {
        $vscode = [
            'servers' => ['gp247' => ['type' => 'http', 'url' => $endpoint, 'headers' => ['Authorization' => 'Bearer ${input:gp247-mcp-token}']]],
            'inputs' => [['id' => 'gp247-mcp-token', 'type' => 'promptString', 'description' => 'GP247 MCP token', 'password' => true]],
        ];
        $cursor = ['mcpServers' => ['gp247' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer ${env:GP247_MCP_TOKEN}']]]];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return [
            'claude-code' => 'claude mcp add --transport http gp247 ' . $endpoint . ' --header "Authorization: Bearer $GP247_MCP_TOKEN"',
            'cursor' => (string) json_encode($cursor, $flags),
            'vscode' => (string) json_encode($vscode, $flags),
        ];
    }

    /**
     * The signed-in admin (the admin guard is required by the screen route).
     */
    private function actor(): AdminUser
    {
        /** @var AdminUser $user */
        $user = admin()->user();

        return $user;
    }
}
