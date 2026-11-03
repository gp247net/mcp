<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\OrderTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * search_orders tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-order-read
 */
#[Name('search_orders')]
#[Description('Search orders by status, payment status, shipping status, date range (Y-m-d) and keyword (order id, buyer name or e-mail). Status ids come from store_info. Paginated, max 50 per page.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchOrdersTool extends Gp247Tool
{
    protected const TOOL = 'search_orders';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->integer()->description('Order status id'),
            'payment_status' => $schema->integer(),
            'shipping_status' => $schema->integer(),
            'from' => $schema->string()->format('date'),
            'to' => $schema->string()->format('date'),
            'keyword' => $schema->string()->max(100),
            'sort' => $schema->string()->enum(['created_at', 'total', 'id', 'status']),
            'direction' => $schema->string()->enum(['asc', 'desc']),
            'page' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'status' => 'nullable|integer',
            'payment_status' => 'nullable|integer',
            'shipping_status' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100',
            'sort' => 'nullable|string|max:20',
            'direction' => 'nullable|in:asc,desc',
        ] + self::pagingRules();
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(OrderTools::class)->search($arguments, $context->storeId);
    }
}
