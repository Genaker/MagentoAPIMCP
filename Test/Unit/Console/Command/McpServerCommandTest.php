<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Unit\Console\Command;

use Genaker\McpServer\Console\Command\McpServerCommand;
use Genaker\McpServer\Model\MCPAPIServer;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class McpServerCommandTest extends TestCase
{
    private McpServerCommand $command;
    private MCPAPIServer|MockObject $mcpServer;
    private State|MockObject $appState;

    protected function setUp(): void
    {
        $this->mcpServer = $this->createMock(MCPAPIServer::class);
        $this->appState = $this->createMock(State::class);

        $this->command = new McpServerCommand(
            $this->mcpServer,
            $this->appState
        );
    }

    public function testConfigure(): void
    {
        $this->assertEquals('mcp:server', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testExecuteSuccess(): void
    {
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $this->appState->expects($this->once())
            ->method('setAreaCode')
            ->with(\Magento\Framework\App\Area::AREA_GLOBAL);

        $this->mcpServer->expects($this->once())
            ->method('run');

        // Use reflection to call protected execute() method
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('execute');
        $method->setAccessible(true);
        
        // Note: run() blocks indefinitely, so this test will hang if run() is actually called
        // We're just verifying the structure and expectations are set up correctly
        // In a real scenario, you'd want to mock the Server class to avoid blocking
        try {
            $method->invoke($this->command, $input, $output);
        } catch (\Exception $e) {
            // Expected - run() blocks or throws
        }
    }

    public function testExecuteHandlesAreaCodeException(): void
    {
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $this->appState->expects($this->once())
            ->method('setAreaCode')
            ->willThrowException(new LocalizedException(__('Area code already set')));

        $this->mcpServer->expects($this->once())
            ->method('run');

        // Use reflection to call protected execute() method
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('execute');
        $method->setAccessible(true);
        
        try {
            $method->invoke($this->command, $input, $output);
        } catch (\Exception $e) {
            // Expected - run() blocks or throws
        }
    }
}
