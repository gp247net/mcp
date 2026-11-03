<?php

namespace GP247\Mcp\Mcp\Tools;

use GP247\Mcp\Services\AuditLog;
use GP247\Mcp\Services\McpContext;
use GP247\Mcp\Services\ToolAuthorizer;
use GP247\Mcp\Services\ToolException;
use GP247\Mcp\Support\SafeReport;
use GP247\Mcp\Tools\ToolCatalog;
use GP247\Mcp\Tools\ToolDefinition;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Thin adapter between laravel/mcp and the plugin's handlers (ADR-plugin-mcp-server-protocol-library):
 * authorization (registration + re-check per call), validation, audit, error mapping.
 * Subclasses only declare the schema, rules and the handler call.
 *
 * @aidlc-unit plugin-mcp-server
 * @aidlc-story US-plugin-mcp-server-rbac-tools
 * @aidlc-story US-plugin-mcp-server-audit-log
 * @aidlc-adr ADR-plugin-mcp-server-protocol-library
 */
abstract class Gp247Tool extends Tool
{
    /** Catalog name of the tool (must match the #[Name] attribute). */
    protected const TOOL = '';

    /** Argument holding the record id for screens with "{id}"; null = none. */
    protected const RECORD_ARGUMENT = null;

    /**
     * laravel/mcp hides the tool from tools/list AND refuses tools/call when this is false.
     */
    public function shouldRegister(): bool
    {
        $context = McpContext::current();

        return $context !== null
            && app(ToolAuthorizer::class)->allows($context->user, $context->token, $this->definition());
    }

    /**
     * Run the tool for the current MCP request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $context = McpContext::current();
        $arguments = $request->all();
        $audit = app(AuditLog::class);
        if ($context === null) {
            return Response::error((string) trans('gp247-mcp::lang.error_denied'));
        }

        // WHY again: the record id is known only now (screens with "{id}"), and permissions may
        // have changed since the client listed the tools.
        if (!app(ToolAuthorizer::class)->allows($context->user, $context->token, $this->definition(), $this->recordId($arguments))) {
            $audit->toolCall($context->user, $context->token, static::TOOL, $arguments, AuditLog::DENIED);

            return Response::error((string) trans('gp247-mcp::lang.error_denied'));
        }

        try {
            $data = $this->run($request->validate($this->rules()), $context);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();
            $audit->toolCall($context->user, $context->token, static::TOOL, $arguments, AuditLog::ERROR, $message);

            return Response::error($message);
        } catch (ToolException $e) {
            $audit->toolCall($context->user, $context->token, static::TOOL, $arguments, AuditLog::ERROR, $e->getMessage());

            return Response::error($e->getMessage());
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
            $audit->toolCall($context->user, $context->token, static::TOOL, $arguments, AuditLog::ERROR, 'internal');

            return Response::error((string) trans('gp247-mcp::lang.error_internal'));
        }

        $audit->toolCall($context->user, $context->token, static::TOOL, $arguments, AuditLog::OK);

        return Response::structured($data);
    }

    /**
     * Catalog definition of this tool.
     */
    protected function definition(): ToolDefinition
    {
        return ToolCatalog::get(static::TOOL);
    }

    /**
     * Record id targeted by the call (for screens with "{id}"); null by default.
     *
     * @param array<string, mixed> $arguments
     */
    protected function recordId(array $arguments): ?string
    {
        $key = static::RECORD_ARGUMENT;

        return $key !== null && isset($arguments[$key]) && is_scalar($arguments[$key]) ? (string) $arguments[$key] : null;
    }

    /**
     * Validation rules of the arguments.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * Execute the tool with validated arguments.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> Structured result.
     */
    abstract protected function run(array $arguments, McpContext $context): array;

    /**
     * Rules shared by paged list tools.
     *
     * @return array<string, mixed>
     */
    protected static function pagingRules(): array
    {
        return [
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1',
        ];
    }
}
