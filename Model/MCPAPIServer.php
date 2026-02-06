<?php
/**
 * MCP API Server - wraps php-mcp/server with Magento Swagger tools
 */

namespace Genaker\McpServer\Model;

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\LoggerInterface;

class MCPAPIServer
{
    private ?Server $server = null;

    public function __construct(
        private readonly SwaggerToolGenerator $toolGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Build and start the MCP server via stdio transport
     */
    public function run(): void
    {
        $toolData = $this->toolGenerator->generateTools();

        $builder = Server::make()
            ->withServerInfo('Magento MCP Server', '1.0.0')
            ->withLogger($this->logger);

        // Register each Swagger endpoint as an MCP tool
        foreach ($toolData as $item) {
            $toolName = $item['name'];

            $builder = $builder->withTool(
                function (array $arguments = []) use ($toolName) {
                    return $this->toolGenerator->executeApiCall($toolName, $arguments);
                },
                name: $toolName,
                description: $item['description'],
                inputSchema: $item['inputSchema']
            );
        }

        $this->server = $builder->build();

        fwrite(STDERR, "Magento MCP Server ready (" . count($toolData) . " tools)\n");

        $transport = new StdioServerTransport();
        $this->server->listen($transport);
    }
}
