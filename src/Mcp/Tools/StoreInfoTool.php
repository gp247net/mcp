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
 * store_info tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-sales-summary
 */
#[Name('store_info')]
#[Description('Store identity (name, base currency, timezone, locale) and the code tables of order, payment and shipping statuses. Call this first to learn the status ids used by other tools.')]
#[IsReadOnly]
#[IsIdempotent]
class StoreInfoTool extends Gp247Tool
{
    protected const TOOL = 'store_info';

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
        return app(SalesTools::class)->storeInfo($context->storeId);
    }
}
