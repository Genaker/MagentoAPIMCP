<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Integration;

use Genaker\McpServer\Model\MCPAPIServer;
use Genaker\McpServer\Model\SwaggerToolGenerator;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class MCPAPIServerIntegrationTest extends TestCase
{
    private static $objectManager;
    private MCPAPIServer $server;

    public static function setUpBeforeClass(): void
    {
        require __DIR__ . '/../../../../../../app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        
        try {
            $appState = self::$objectManager->get(State::class);
            $appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // Area code already set
        }
    }

    protected function setUp(): void
    {
        $this->server = self::$objectManager->get(MCPAPIServer::class);
    }

    /**
     * Test server initialization, dependencies, and tool generation capability
     */
    public function testServerInitializationAndDependencies(): void
    {
        // Verify server is instantiated correctly
        $this->assertInstanceOf(MCPAPIServer::class, $this->server, 'Server should be MCPAPIServer instance');
        
        // Verify dependencies via reflection
        $reflection = new \ReflectionClass($this->server);
        
        // Verify tool generator is injected
        $toolGeneratorProperty = $reflection->getProperty('toolGenerator');
        $toolGeneratorProperty->setAccessible(true);
        $toolGenerator = $toolGeneratorProperty->getValue($this->server);
        $this->assertInstanceOf(SwaggerToolGenerator::class, $toolGenerator, 'Should have SwaggerToolGenerator');
        
        // Verify logger is injected
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $logger = $loggerProperty->getValue($this->server);
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $logger, 'Should have logger');
        
        // Verify server property exists
        $serverProperty = $reflection->getProperty('server');
        $serverProperty->setAccessible(true);
        $this->assertNull($serverProperty->getValue($this->server), 'Server should be null before run()');
        
        // Test tool generation capability (if Swagger enabled)
        $scopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        if ($scopeConfig->getValue('webapi/swagger/enable')) {
            try {
                $tools = $toolGenerator->generateTools();
                $this->assertIsArray($tools, 'Tools should be array');
                $this->assertGreaterThan(0, count($tools), 'Should generate tools when Swagger enabled');
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                // Connection errors are acceptable in test environment
                $this->markTestSkipped('Cannot connect to Magento API endpoint: ' . $e->getMessage());
                return;
            }
        }
    }
}
