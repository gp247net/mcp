<?php

/**
 * @aidlc-story US-plugin-mcp-server-access-token
 */

namespace GP247\Mcp\Tests\Feature\Mcp;

use GP247\Mcp\Livewire\TokenManager;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Models\AdminLog;
use GP247\Core\Models\PersonalAccessToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Mcp\Tests\Feature\Mcp\Support\McpServerFixtures;
use GP247\Mcp\Tests\TestCase;

class McpServerAccessTokenTest extends TestCase
{
    use DatabaseTransactions;
    use McpServerFixtures;

    private int $logBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMcpServer();
        $this->logBaseline = (int) AdminLog::max('id');
    }

    private function actAs($user): void
    {
        $this->actingAs($user, 'admin');
    }

    private function tokensOf($user)
    {
        return PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->where('abilities', 'like', '%mcp:read%')
            ->get();
    }

    public function test_an_mcp_user_creates_a_read_token_shown_only_once_and_stored_as_a_hash(): void
    {
        $user = $this->mcpUser();
        $this->actAs($user);

        $component = Livewire::test(TokenManager::class)
            ->set('name', 'Laptop Claude')
            ->set('days', 10)
            ->call('create')
            ->assertHasNoErrors();

        $plain = (string) $component->get('plainToken');
        $token = $this->tokensOf($user)->sole();

        $this->assertNotSame('', $plain);
        $this->assertSame(['mcp:read'], $token->abilities);
        $this->assertSame('Laptop Claude', $token->name);
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $this->assertTrue($token->expires_at->between(now()->addDays(10)->subMinute(), now()->addDays(10)->addMinute()));
        $component->assertSeeHtml('data-testid="plugin-mcp-server-token-plain"');

        $component->call('dismissToken')->assertSet('plainToken', null);
    }

    public function test_the_expiry_cannot_exceed_the_configured_maximum(): void
    {
        $this->mcpSetting('token_max_days', '90');
        $this->actAs($this->mcpUser());

        Livewire::test(TokenManager::class)
            ->set('name', 'Too long')
            ->set('days', 91)
            ->call('create')
            ->assertHasErrors(['days']);
    }

    public function test_a_write_token_needs_a_write_tool_enabled_on_the_site(): void
    {
        $user = $this->mcpUser();
        $this->actAs($user);

        Livewire::test(TokenManager::class)
            ->set('name', 'Writer')
            ->set('level', 'write')
            ->call('create')
            ->assertHasErrors(['level']);

        $this->mcpSetting('tool_update_order_status', '1');

        Livewire::test(TokenManager::class)
            ->set('name', 'Writer')
            ->set('level', 'write')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['mcp:read', 'mcp:write'], $this->tokensOf($user)->sole()->abilities);
    }

    public function test_a_token_can_be_limited_to_named_tools_and_unknown_tools_are_refused(): void
    {
        $user = $this->mcpUser();
        $this->actAs($user);

        Livewire::test(TokenManager::class)
            ->set('name', 'Bad')
            ->set('tools', ['drop_database'])
            ->call('create')
            ->assertHasErrors(['tools.0']);

        Livewire::test(TokenManager::class)
            ->set('name', 'Sales only')
            ->set('tools', ['sales_summary', 'store_info'])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['mcp:read', 'mcp:tool:sales_summary', 'mcp:tool:store_info'],
            $this->tokensOf($user)->sole()->abilities
        );
    }

    public function test_an_admin_without_the_mcp_permission_cannot_open_the_token_screen(): void
    {
        $this->actAs($this->makeAdmin(['GET::' . GP247_ADMIN_PREFIX . '/order']));

        try {
            Livewire::test(TokenManager::class);
            $this->fail('Expected the screen to be denied.');
        } catch (\Throwable $e) {
            $current = $e;
            while ($current !== null && !$current instanceof AuthorizationException) {
                $current = $current->getPrevious();
            }
            $this->assertInstanceOf(AuthorizationException::class, $current, $e::class . ': ' . $e->getMessage());
        }
    }

    public function test_a_user_sees_and_revokes_only_their_own_tokens(): void
    {
        $alice = $this->mcpUser();
        $bob = $this->mcpUser();
        $this->tokenFor($alice);
        $this->tokenFor($bob);
        $bobTokenId = $this->tokensOf($bob)->sole()->id;
        $aliceTokenId = $this->tokensOf($alice)->sole()->id;
        $this->actAs($alice);

        $component = Livewire::test(TokenManager::class)
            ->assertSeeHtml('data-testid="plugin-mcp-server-token-row-' . $aliceTokenId . '"')
            ->assertDontSeeHtml('data-testid="plugin-mcp-server-token-row-' . $bobTokenId . '"');

        $component->call('revoke', $bobTokenId);
        $this->assertCount(1, $this->tokensOf($bob));

        $component->call('revoke', $aliceTokenId);
        $this->assertCount(0, $this->tokensOf($alice));
    }

    public function test_an_administrator_sees_and_revokes_every_mcp_token(): void
    {
        $bob = $this->mcpUser();
        $this->tokenFor($bob);
        $bobTokenId = $this->tokensOf($bob)->sole()->id;
        $this->actAs($this->administrator());

        Livewire::test(TokenManager::class)
            ->assertSeeHtml('data-testid="plugin-mcp-server-token-row-' . $bobTokenId . '"')
            ->call('revoke', $bobTokenId);

        $this->assertCount(0, $this->tokensOf($bob));
    }

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        $this->enableEndpoint();
        $user = $this->mcpUser();
        $token = $this->tokenFor($user);
        $this->mcp($token, 'tools/list')->assertOk();

        $this->actAs($user);
        Livewire::test(TokenManager::class)->call('revoke', $this->tokensOf($user)->sole()->id);

        $this->mcp($token, 'tools/list')->assertStatus(401);
    }

    public function test_changing_the_owner_password_revokes_every_mcp_token_of_that_owner(): void
    {
        $this->enableEndpoint();
        $user = $this->mcpUser();
        $token = $this->tokenFor($user);
        $this->mcp($token, 'tools/list')->assertOk();

        $user->password = \Illuminate\Support\Facades\Hash::make('a-new-password-' . $user->id);
        $user->save();

        $this->assertCount(0, $this->tokensOf($user));
        $this->mcp($token, 'tools/list')->assertStatus(401);
    }

    public function test_the_token_screen_warns_that_a_password_change_revokes_the_tokens(): void
    {
        $this->actAs($this->mcpUser());

        Livewire::test(TokenManager::class)
            ->assertSeeHtml('data-testid="plugin-mcp-server-password-revoke-note"');
    }

    public function test_creating_and_revoking_a_token_is_logged_without_the_token_value(): void
    {
        $user = $this->mcpUser();
        $this->actAs($user);

        $component = Livewire::test(TokenManager::class)->set('name', 'Logged')->call('create');
        $plain = (string) $component->get('plainToken');
        $component->call('revoke', $this->tokensOf($user)->sole()->id);

        $rows = AdminLog::query()->where('id', '>', $this->logBaseline)->where('path', 'like', '%/mcp/tokens%')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(explode('|', $plain, 2)[1], (string) $row->input);
        }
    }
}
