<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\CatalogTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * low_stock_products tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-catalog-read
 */
#[Name('low_stock_products')]
#[Description('Active products whose stock is at or below a threshold (default: the site setting), lowest stock first.')]
#[IsReadOnly]
#[IsIdempotent]
class LowStockProductsTool extends Gp247Tool
{
    protected const TOOL = 'low_stock_products';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->integer()->min(0)->description('Stock threshold'),
            'page' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return ['threshold' => 'nullable|integer|min:0|max:1000000'] + self::pagingRules();
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(CatalogTools::class)->lowStock($arguments, $context->storeId);
    }
}
