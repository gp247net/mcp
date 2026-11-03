<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Tools\Handlers\CustomerTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * get_customer tool.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-customer-read
 */
#[Name('get_customer')]
#[Description('One customer profile with order count and spending on Done orders per currency. Contact details may be masked by the site owner.')]
#[IsReadOnly]
#[IsIdempotent]
class GetCustomerTool extends Gp247Tool
{
    protected const TOOL = 'get_customer';

    /** Input schema. */
    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->string()->description('Customer id')->required()];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return ['customer_id' => 'required|string|max:64'];
    }

    /** @return array<string, mixed> */
    protected function run(array $arguments, McpContext $context): array
    {
        return app(CustomerTools::class)->get((string) $arguments['customer_id'], $context->storeId);
    }
}
