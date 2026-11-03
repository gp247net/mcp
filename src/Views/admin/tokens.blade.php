{{--
    MCP tokens screen (TailAdmin): KPI row, create form (left) + token list (right), connection guide with
    per-client tabs and copy buttons. Only class combinations present in the prebuilt admin bundle are used
    (gp247.md §3a); data-testid values are a contract with the Feature-Tests and E2E.

    @aidlc-unit plugin-mcp-server
    @aidlc-story US-plugin-mcp-server-access-token
    @aidlc-story US-plugin-mcp-server-connect-guide
--}}
@php($l = 'gp247-mcp::lang.')
@php($code = 'max-h-80 overflow-y-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600')
@php($copyBtn = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-blue-600 hover:bg-gray-100 dark:text-blue-400 dark:hover:bg-gray-700')
<div class="space-y-6"
    x-data="{
        copied: null,
        copy(id) {
            const el = document.getElementById(id);
            if (!el) { return; }
            const done = () => { this.copied = id; setTimeout(() => { if (this.copied === id) { this.copied = null; } }, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(el.innerText).then(done);
                return;
            }
            const range = document.createRange();
            range.selectNodeContents(el);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
            done();
        }
    }">

    {{-- Intro + settings shortcut --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'subtitle') }}</p>
        @if ($settingsUrl)
            <x-gp247::button variant="secondary" size="sm" :href="$settingsUrl" data-testid="plugin-mcp-server-settings-link">
                <i class="fas fa-gear"></i> {{ gp247_language_render($l.'settings_heading') }}
            </x-gp247::button>
        @endif
    </div>

    @if ($legacyPluginFolder)
        <x-gp247::alert type="info" data-testid="plugin-mcp-server-legacy-plugin">
            {{ gp247_language_render($l.'legacy_plugin_folder') }}
        </x-gp247::alert>
    @endif

    @unless ($endpointEnabled)
        <x-gp247::alert type="warning" data-testid="plugin-mcp-server-endpoint-disabled">
            {{ gp247_language_render($l.'endpoint_disabled') }}
        </x-gp247::alert>
    @endunless

    {{-- KPI row --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-gp247::stat-card :label="gp247_language_render($l.'stat_endpoint')"
            :value="gp247_language_render($l.($endpointEnabled ? 'stat_endpoint_on' : 'stat_endpoint_off'))"
            icon="fas fa-plug" :color="$endpointEnabled ? 'emerald' : 'amber'" data-testid="plugin-mcp-server-stat-endpoint" />
        <x-gp247::stat-card :label="gp247_language_render($l.'stat_active')" :value="$stats['active']"
            icon="fas fa-key" color="sky" data-testid="plugin-mcp-server-stat-active" />
        <x-gp247::stat-card :label="gp247_language_render($l.'stat_expiring')" :value="$stats['expiring'] . ' / ' . $stats['expired']"
            icon="fas fa-hourglass-half" color="amber" data-testid="plugin-mcp-server-stat-expiring" />
        <x-gp247::stat-card :label="gp247_language_render($l.'stat_tools')"
            :value="gp247_language_render($l.'stat_tools_value', ['read' => $stats['readTools'], 'write' => $stats['writeTools']])"
            icon="fas fa-screwdriver-wrench" color="emerald" data-testid="plugin-mcp-server-stat-tools" />
    </div>

    {{-- Freshly created token: shown once --}}
    @if ($plainToken)
        <x-gp247::alert type="success" :title="gp247_language_render($l.'token_created_title')">
            <p class="text-sm">{{ gp247_language_render($l.'token_created_hint') }}</p>
            <div class="mt-2 flex flex-wrap items-start gap-2">
                <div id="mcp-plain-token" class="{{ $code }} min-w-0 flex-1" data-testid="plugin-mcp-server-token-plain">{{ $plainToken }}</div>
                <x-gp247::button size="sm" x-on:click="copy('mcp-plain-token')" data-testid="plugin-mcp-server-token-copy">
                    <i class="fas fa-copy"></i>
                    <span x-text="copied === 'mcp-plain-token' ? @js(gp247_language_render($l.'copied')) : @js(gp247_language_render($l.'copy'))">{{ gp247_language_render($l.'copy') }}</span>
                </x-gp247::button>
            </div>
            <div class="mt-3">
                <x-gp247::button size="sm" variant="secondary" wire:click="dismissToken" data-testid="plugin-mcp-server-token-dismiss">{{ gp247_language_render($l.'token_copied') }}</x-gp247::button>
            </div>
        </x-gp247::alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Create --}}
        <x-gp247::card :title="gp247_language_render($l.'create_title')" :subtitle="gp247_language_render($l.'create_subtitle')">
            <form wire:submit="create" class="space-y-5" data-testid="plugin-mcp-server-token-form">
                <x-gp247::input :label="gp247_language_render($l.'token_name')" name="name" wire:model="name"
                    :error="$errors->first('name')" data-testid="plugin-mcp-server-token-name" />

                <div>
                    <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ gp247_language_render($l.'token_level') }}</span>
                    <div class="grid grid-cols-2 gap-3" data-testid="plugin-mcp-server-token-level">
                        @foreach (['read' => 'fas fa-eye', 'write' => 'fas fa-pen-to-square'] as $option => $icon)
                            @php($disabled = $option === 'write' && !$canWrite)
                            <label @class([
                                'flex cursor-pointer flex-col gap-1 rounded-lg border p-3 transition',
                                'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-500/10' => $level === $option,
                                'border-gray-200 hover:border-gray-300 dark:border-gray-700' => $level !== $option,
                                'cursor-not-allowed opacity-70' => $disabled,
                            ])>
                                <span class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                    <input type="radio" value="{{ $option }}" wire:model.live="level" @disabled($disabled)
                                        data-testid="plugin-mcp-server-token-level-{{ $option }}">
                                    <i class="{{ $icon }} text-gray-500 dark:text-gray-400"></i>
                                    {{ gp247_language_render($l.'level_'.$option) }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ gp247_language_render($l.($disabled ? 'level_write_off' : 'level_'.$option.'_hint')) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('level')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <x-gp247::input type="number" :label="gp247_language_render($l.'token_days', ['max' => $maxDays])" name="days" wire:model="days"
                    :error="$errors->first('days')" data-testid="plugin-mcp-server-token-days" />

                <details class="rounded-lg border border-gray-200 dark:border-gray-700" @if ($tools !== [] || $errors->has('tools.*')) open @endif>
                    <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ gp247_language_render($l.'token_tools') }}
                        @if ($tools !== [])
                            <x-gp247::badge color="blue">{{ count($tools) }}</x-gp247::badge>
                        @endif
                    </summary>
                    <div class="space-y-3 border-t border-gray-200 px-3 py-3 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'token_tools_hint') }}</p>
                        @foreach ($toolGroups as $group => $options)
                            @continue($options === [])
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'tools_group_'.$group) }}</p>
                                <div class="space-y-1">
                                    @foreach ($options as $toolName => $toolLabel)
                                        <label class="flex items-start gap-2 rounded-md px-2 py-1 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <input type="checkbox" class="mt-0.5" wire:model="tools" value="{{ $toolName }}" data-testid="plugin-mcp-server-token-tool-{{ $toolName }}">
                                            <span class="min-w-0">
                                                <span class="block text-sm text-gray-800 dark:text-gray-100">{{ $toolLabel }}</span>
                                                <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $toolName }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                        @error('tools.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </details>

                <x-gp247::button type="submit" class="w-full" data-testid="plugin-mcp-server-token-create">
                    <i class="fas fa-plus"></i> {{ gp247_language_render($l.'create') }}
                </x-gp247::button>
                <p class="text-xs text-gray-500 dark:text-gray-400" data-testid="plugin-mcp-server-password-revoke-note">{{ gp247_language_render($l.'password_revoke_note') }}</p>
            </form>
        </x-gp247::card>

        {{-- List --}}
        <div class="xl:col-span-2">
            <x-gp247::card :title="gp247_language_render($l.'list_title')" :subtitle="gp247_language_render($isAdministrator ? 'gp247-mcp::lang.list_subtitle_all' : 'gp247-mcp::lang.list_subtitle_own')">
                @if ($tokens->isEmpty())
                    <div class="flex flex-col items-center gap-2 py-12 text-center" data-testid="plugin-mcp-server-token-empty">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-400">
                            <i class="fas fa-key"></i>
                        </span>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ gp247_language_render($l.'no_tokens') }}</p>
                        <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'no_tokens_hint') }}</p>
                    </div>
                @else
                    <x-gp247::table :headers="array_values(array_filter([
                        gp247_language_render($l.'col_name'),
                        $isAdministrator ? gp247_language_render($l.'col_owner') : null,
                        gp247_language_render($l.'col_level'),
                        gp247_language_render($l.'col_expires'),
                        gp247_language_render($l.'col_last_used'),
                        '',
                    ]))">
                        @foreach ($tokens as $token)
                            @php($expired = $token->expires_at && $token->expires_at->isPast())
                            @php($expiringSoon = !$expired && $token->expires_at && $token->expires_at->lte(now()->addDays(7)))
                            <tr wire:key="mcp-token-{{ $token->id }}" data-testid="plugin-mcp-server-token-row-{{ $token->id }}"
                                @class(['align-top hover:bg-gray-50 dark:hover:bg-gray-700/50', 'opacity-70' => $expired])>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $token->name }}</div>
                                    @if ($tokenTools($token))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($tokenTools($token) as $toolName)
                                                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 font-mono text-xs text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-700/50 dark:text-gray-300 dark:ring-gray-600">{{ $toolName }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'all_tools') }}</div>
                                    @endif
                                </td>
                                @if ($isAdministrator)
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $token->tokenable->username ?? '—' }}</td>
                                @endif
                                <td class="px-4 py-3">
                                    <x-gp247::badge :color="$tokenLevel($token) === 'write' ? 'amber' : 'blue'">{{ gp247_language_render($l.'level_'.$tokenLevel($token)) }}</x-gp247::badge>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                    @if ($token->expires_at)
                                        <div>{{ $token->expires_at->format('Y-m-d') }}</div>
                                        @if ($expired)
                                            <x-gp247::badge color="red">{{ gp247_language_render($l.'expired') }}</x-gp247::badge>
                                        @else
                                            <div @class(['text-xs', 'text-amber-600 dark:text-amber-400' => $expiringSoon, 'text-gray-500 dark:text-gray-400' => !$expiringSoon])>{{ $token->expires_at->diffForHumans() }}</div>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                    @if ($token->last_used_at)
                                        <span title="{{ $token->last_used_at->format('Y-m-d H:i') }}">{{ $token->last_used_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'never_used') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-gp247::button size="sm" variant="danger" wire:click="revoke({{ $token->id }})"
                                        wire:confirm="{{ gp247_language_render($l.'revoke_confirm') }}"
                                        data-testid="plugin-mcp-server-token-revoke-{{ $token->id }}">
                                        <i class="fas fa-ban"></i> {{ gp247_language_render($l.'revoke') }}
                                    </x-gp247::button>
                                </td>
                            </tr>
                        @endforeach
                    </x-gp247::table>
                @endif
            </x-gp247::card>
        </div>
    </div>

    {{-- Connection guide --}}
    <x-gp247::card :title="gp247_language_render($l.'guide_title')" :subtitle="gp247_language_render($l.'guide_hint')">
        <div class="space-y-5">
            <div>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ gp247_language_render($l.'endpoint_url') }}</span>
                    <button type="button" class="{{ $copyBtn }}" x-on:click="copy('mcp-endpoint-url')">
                        <i class="fas fa-copy"></i>
                        <span x-text="copied === 'mcp-endpoint-url' ? @js(gp247_language_render($l.'copied')) : @js(gp247_language_render($l.'copy'))">{{ gp247_language_render($l.'copy') }}</span>
                    </button>
                </div>
                <div id="mcp-endpoint-url" class="{{ $code }}" data-testid="plugin-mcp-server-endpoint-url">{{ $endpointUrl }}</div>
            </div>

            @unless ($https)
                <x-gp247::alert type="warning" data-testid="plugin-mcp-server-no-https">{{ gp247_language_render($l.'no_https') }}</x-gp247::alert>
            @endunless

            <x-gp247::tabs :tabs="[
                'claude-code' => 'Claude Code',
                'cursor' => 'Cursor',
                'vscode' => 'VS Code',
                'claude-desktop' => 'Claude Desktop / claude.ai',
            ]">
                @foreach (['claude-code' => 'Terminal', 'cursor' => 'mcp.json', 'vscode' => '.vscode/mcp.json'] as $client => $where)
                    <div x-show="tab === @js($client)" x-cloak>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $where }}</span>
                            <button type="button" class="{{ $copyBtn }}" x-on:click="copy('mcp-snippet-{{ $client }}')">
                                <i class="fas fa-copy"></i>
                                <span x-text="copied === 'mcp-snippet-{{ $client }}' ? @js(gp247_language_render($l.'copied')) : @js(gp247_language_render($l.'copy'))">{{ gp247_language_render($l.'copy') }}</span>
                            </button>
                        </div>
                        <div id="mcp-snippet-{{ $client }}" class="{{ $code }}" data-testid="plugin-mcp-server-snippet-{{ $client }}">{{ $snippets[$client] }}</div>
                    </div>
                @endforeach
                <div x-show="tab === 'claude-desktop'" x-cloak>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render($l.'guide_claude_desktop') }}</p>
                </div>
            </x-gp247::tabs>

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" wire:click="checkConnection" wire:loading.attr="disabled" data-testid="plugin-mcp-server-check">
                    <i class="fas fa-stethoscope"></i> {{ gp247_language_render($l.'check') }}
                </x-gp247::button>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'check_hint') }}</span>
            </div>
            @if ($check)
                @if ($check['status'] === 'ok')
                    <x-gp247::alert type="success" data-testid="plugin-mcp-server-check-ok">{{ gp247_language_render($l.'check_ok') }}</x-gp247::alert>
                @elseif ($check['status'] === 'header_missing')
                    <x-gp247::alert type="error" data-testid="plugin-mcp-server-check-header-missing">{{ gp247_language_render($l.'check_header_missing') }}</x-gp247::alert>
                @else
                    <x-gp247::alert type="warning" data-testid="plugin-mcp-server-check-unreachable">{{ gp247_language_render($l.'check_unreachable') }}</x-gp247::alert>
                @endif
            @endif
        </div>
    </x-gp247::card>
</div>
