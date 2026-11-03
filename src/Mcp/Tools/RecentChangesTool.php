<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Services\ToolAuthorizer;
use GP247\Mcp\Tools\Handlers\ChangeTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * recent_changes tool — events of the core event log — 1.1.0.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-recent-changes-read
 */
#[Name('recent_changes')]
#[Description('What changed in the shop: new orders, order / payment / shipping status changes, stock movements, product and customer updates, oldest first. Default window: the last 24 hours (since = ISO 8601 date-time). Page with after = next_after while has_more is true. Answers enabled=false when the site owner has not turned the event log on.')]
#[IsReadOnly]
#[IsIdempotent]
class RecentChangesTool extends Gp247Tool
{
    protected const TOOL = 'recent_changes';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->format('date-time')->description('Start of the window (default: 24 hours ago)'),
            'types' => $schema->array()->items($schema->string())->description('Event types to keep, e.g. order.created, stock.changed'),
            'after' => $schema->integer()->min(0)->description('next_after of the previous page'),
            'limit' => $schema->integer()->min(1)->max(ChangeTools::MAX_LIMIT),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'since' => 'nullable|string|max:40',
            'types' => 'nullable|array|max:20',
            'types.*' => 'string|max:64',
            'after' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:' . ChangeTools::MAX_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        $readable = app(ToolAuthorizer::class)->readableEventTypes($context->user);

        return app(ChangeTools::class)->recent($arguments, $context->storeId, $readable);
    }
}
