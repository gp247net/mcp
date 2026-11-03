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
 * list_categories tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-catalog-read
 */
#[Name('list_categories')]
#[Description('All product categories as a flat list with parent ids (parent 0 means top level).')]
#[IsReadOnly]
#[IsIdempotent]
class ListCategoriesTool extends Gp247Tool
{
    protected const TOOL = 'list_categories';

    /** No input. */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(CatalogTools::class)->categories($context->storeId);
    }
}
