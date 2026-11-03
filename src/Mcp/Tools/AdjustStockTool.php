<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\StockTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * adjust_stock tool (two steps: preview, then confirm) — 1.1.0.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-stock-adjust-write
 * @aidlc-adr ADR-plugin-mcp-server-write-tool-policy
 */
#[Name('adjust_stock')]
#[Description('Receive (positive delta) or issue (negative delta) stock of one product outside a sale; stock never goes below zero and the sold counter is not touched. It does not set an absolute stock. Two steps: call WITHOUT confirm_code to get a preview (stock before and after) and a confirm_code; show the preview to the user; only after the user agrees, call again with the same product_id and delta plus confirm_code (valid 5 minutes, single use).')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
class AdjustStockTool extends Gp247Tool
{
    protected const TOOL = 'adjust_stock';

    /** Largest absolute delta accepted in one call. */
    public const MAX_DELTA = 1000000;

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->description('Product id')->required(),
            'delta' => $schema->number()->description('Quantity to add (positive) or remove (negative), not 0, up to 4 decimals')->required(),
            'reason' => $schema->string()->max(255)->description('Optional reason, kept in the audit log only'),
            'confirm_code' => $schema->string()->description('Code returned by the preview call'),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'product_id' => 'required|string|max:64',
            'delta' => 'required|numeric|decimal:0,4|not_in:0|between:-' . self::MAX_DELTA . ',' . self::MAX_DELTA,
            'reason' => 'nullable|string|max:255',
            'confirm_code' => 'nullable|string|max:64',
        ];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(StockTools::class)->adjust($arguments, $context->storeId, $context->token->getKey());
    }
}
