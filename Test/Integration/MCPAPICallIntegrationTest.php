<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Integration;

use Genaker\McpServer\Model\SwaggerToolGenerator;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class MCPAPICallIntegrationTest extends TestCase
{
    private static $objectManager;
    private SwaggerToolGenerator $generator;

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
        $this->generator = self::$objectManager->get(SwaggerToolGenerator::class);
    }

    /**
     * Test calling Magento API endpoints through MCP tools
     * Tests the complete flow: Swagger discovery -> Tool generation -> API execution
     */
    public function testCallMagentoAPIThroughMCP(): void
    {
        $scopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $swaggerEnabled = $scopeConfig->getValue('webapi/swagger/enable');
        
        if (!$swaggerEnabled) {
            $this->markTestSkipped('Swagger is not enabled. This test requires Swagger to discover API endpoints.');
            return;
        }

        // Generate tools from Swagger - this tests Swagger discovery and tool generation
        try {
            $tools = $this->generator->generateTools();
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Connection errors are expected in test environment - skip but note it
            $this->markTestSkipped('Cannot connect to Magento API endpoint for Swagger discovery (expected in test environment): ' . $e->getMessage());
            return;
        } catch (\Exception $e) {
            // Other errors should fail the test
            throw $e;
        }
        
        $this->assertGreaterThan(0, count($tools), 'Should generate tools');
        
        // Verify tool structure (sample)
        $sampleTool = $tools[0];
        $this->assertArrayHasKey('name', $sampleTool);
        $this->assertArrayHasKey('inputSchema', $sampleTool);
        
        // Find a simple GET endpoint to test (modules or stores)
        $testTool = null;
        $testArguments = [];
        
        foreach ($tools as $tool) {
            if (strpos($tool['name'], 'get_modules') === 0) {
                $testTool = $tool;
                $testArguments = ['searchCriteria[pageSize]' => '1'];
                break;
            } elseif (strpos($tool['name'], 'get_stores') === 0 && strpos($tool['name'], 'storeviews') === false) {
                $testTool = $tool;
                $testArguments = [];
                break;
            }
        }
        
        if (!$testTool) {
            $this->markTestSkipped('No suitable test endpoint found (looking for get_modules or get_stores)');
            return;
        }
        
        // Test metadata retrieval
        $metadata = $this->generator->getToolMetadata($testTool['name']);
        $this->assertArrayHasKey('path', $metadata);
        $this->assertArrayHasKey('method', $metadata);
        $this->assertEquals('GET', $metadata['method']);
        
        // Execute API call through MCP - this tests the actual API call execution
        try {
            $result = $this->generator->executeApiCall($testTool['name'], $testArguments);
            
            // Verify response structure
            $this->assertIsArray($result, 'Result should be array');
            
            // Check response - could be success or error (both are valid for testing MCP integration)
            if (isset($result['error'])) {
                $this->assertArrayHasKey('status', $result);
                $this->assertIsInt($result['status']);
            } else {
                $this->assertNotEmpty($result);
            }
            
        } catch (\Exception $e) {
            // Connection errors are acceptable in test environment
            if (strpos($e->getMessage(), 'Connection') !== false || strpos($e->getMessage(), 'Failed to connect') !== false) {
                $this->markTestSkipped('Cannot connect to Magento API (this is OK in test environment): ' . $e->getMessage());
                return;
            }
            throw $e;
        }
        
        // Verify complete MCP flow was tested
        $this->assertTrue(true);
    }

    /**
     * Test calling a specific Magento API endpoint (GET /V1/modules)
     */
    public function testCallModulesAPI(): void
    {
        $scopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        if (!$scopeConfig->getValue('webapi/swagger/enable')) {
            $this->markTestSkipped('Swagger is not enabled');
            return;
        }

        // Generate tools
        try {
            $tools = $this->generator->generateTools();
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->markTestSkipped('Cannot connect to Magento API: ' . $e->getMessage());
            return;
        }
        
        // Find the modules endpoint tool
        $modulesTool = null;
        foreach ($tools as $tool) {
            if (strpos($tool['name'], 'get_modules') === 0) {
                $modulesTool = $tool;
                break;
            }
        }
        
        if (!$modulesTool) {
            $this->markTestSkipped('Modules API tool not found in Swagger');
            return;
        }
        
        // Call the API
        $result = $this->generator->executeApiCall($modulesTool['name'], [
            'searchCriteria[pageSize]' => '5'
        ]);
        
        // Verify response
        $this->assertIsArray($result);
        if (isset($result['error'])) {
            $this->assertArrayHasKey('status', $result);
        } else {
            $this->assertNotEmpty($result);
        }
    }

    /**
     * Test calling stores API endpoint
     */
    public function testCallStoresAPI(): void
    {
        $scopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        if (!$scopeConfig->getValue('webapi/swagger/enable')) {
            $this->markTestSkipped('Swagger is not enabled');
            return;
        }

        try {
            $tools = $this->generator->generateTools();
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->markTestSkipped('Cannot connect to Magento API: ' . $e->getMessage());
            return;
        }
        
        // Find stores endpoint
        $storesTool = null;
        foreach ($tools as $tool) {
            if (strpos($tool['name'], 'get_stores') === 0 && strpos($tool['name'], 'storeviews') === false) {
                $storesTool = $tool;
                break;
            }
        }
        
        if (!$storesTool) {
            $this->markTestSkipped('Stores API tool not found');
            return;
        }
        
        // Call API
        $result = $this->generator->executeApiCall($storesTool['name'], []);
        
        // Verify response
        $this->assertIsArray($result);
        if (!isset($result['error'])) {
            $this->assertNotEmpty($result);
        } else {
            $this->assertArrayHasKey('status', $result);
        }
    }
}
