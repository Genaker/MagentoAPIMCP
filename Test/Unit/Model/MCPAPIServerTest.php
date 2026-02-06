<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Unit\Model;

use Genaker\McpServer\Model\MCPAPIServer;
use Genaker\McpServer\Model\SwaggerToolGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MCPAPIServerTest extends TestCase
{
    private MCPAPIServer $server;
    private SwaggerToolGenerator|MockObject $toolGenerator;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->toolGenerator = $this->createMock(SwaggerToolGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->server = new MCPAPIServer(
            $this->toolGenerator,
            $this->logger
        );
    }

    public function testServerHasToolGenerator(): void
    {
        // Verify the toolGenerator is properly injected
        $reflection = new \ReflectionClass($this->server);
        $property = $reflection->getProperty('toolGenerator');
        $property->setAccessible(true);
        $this->assertSame($this->toolGenerator, $property->getValue($this->server));
        
        // Verify logger is injected
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertSame($this->logger, $loggerProperty->getValue($this->server));
    }

    public function testConstructor(): void
    {
        $server = new MCPAPIServer(
            $this->toolGenerator,
            $this->logger
        );

        $this->assertInstanceOf(MCPAPIServer::class, $server);
    }
}
