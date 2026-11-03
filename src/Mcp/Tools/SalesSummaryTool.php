<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\SalesTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * sales_summary tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-sales-summary
 */
#[Name('sales_summary')]
#[Description('Sales overview for a date range (default: last 30 days): revenue per currency (Done orders), order counts by state and the best-selling products. Currencies are never added together.')]
#[IsReadOnly]
#[IsIdempotent]
class SalesSummaryTool extends Gp247Tool
{
    protected const TOOL = 'sales_summary';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date, Y-m-d'),
            'to' => $schema->string()->format('date')->description('End date, Y-m-d (inclusive)'),
            'top' => $schema->integer()->min(1)->max(20)->description('Number of best-selling products (default 5)'),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'top' => 'nullable|integer|min:1|max:20'];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(SalesTools::class)->summary($arguments, $context->storeId);
    }
}
