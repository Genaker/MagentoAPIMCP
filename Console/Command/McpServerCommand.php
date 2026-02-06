<?php
/**
 * CLI command: bin/magento mcp:server
 */

namespace Genaker\McpServer\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Genaker\McpServer\Model\MCPAPIServer;
use Magento\Framework\App\State;

class McpServerCommand extends Command
{
    public function __construct(
        private readonly MCPAPIServer $mcpServer,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mcp:server')
            ->setDescription('Start MCP server (stdio transport) for Magento API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        try {
            $this->mcpServer->run();
            return Command::SUCCESS;
        } catch (\Exception $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return Command::FAILURE;
        }
    }
}
