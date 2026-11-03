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
 * search_products tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-catalog-read
 */
#[Name('search_products')]
#[Description('Search products by SKU, alias or name, optionally by category and status. Returns id, SKU, name, price, stock and status, paginated (max 50 per page).')]
#[IsReadOnly]
#[IsIdempotent]
class SearchProductsTool extends Gp247Tool
{
    protected const TOOL = 'search_products';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->max(100)->description('Part of the SKU, alias or name'),
            'category_id' => $schema->string()->description('Category id'),
            'status' => $schema->integer()->description('1 = active, 0 = inactive'),
            'sort' => $schema->string()->enum(['created_at', 'price', 'stock', 'sku', 'sold']),
            'direction' => $schema->string()->enum(['asc', 'desc']),
            'page' => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'keyword' => 'nullable|string|max:100',
            'category_id' => 'nullable|string|max:64',
            'status' => 'nullable|integer',
            'sort' => 'nullable|string|max:20',
            'direction' => 'nullable|in:asc,desc',
        ] + self::pagingRules();
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(CatalogTools::class)->search($arguments, $context->storeId);
    }
}
