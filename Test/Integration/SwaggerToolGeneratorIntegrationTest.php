<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Integration;

use Genaker\McpServer\Model\SwaggerToolGenerator;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class SwaggerToolGeneratorIntegrationTest extends TestCase
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
     * Complete integration test: Swagger discovery, tool generation, metadata, and error handling
     */
    public function testCompleteSwaggerFlow(): void
    {
        $scopeConfig = self::$objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $swaggerEnabled = $scopeConfig->getValue('webapi/swagger/enable');
        
        if (!$swaggerEnabled) {
            // Test error handling when Swagger is disabled
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Swagger is not enabled');
            $this->generator->discoverSwaggerSchema();
            return;
        }

        // Test schema discovery
        try {
            $schema = $this->generator->discoverSwaggerSchema();
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->markTestSkipped('Cannot connect to Magento API endpoint (connection refused - expected in test environment): ' . $e->getMessage());
            return;
        }
        
        $this->assertIsArray($schema, 'Schema should be an array');
        $this->assertArrayHasKey('paths', $schema, 'Schema should have paths key');
        $this->assertGreaterThan(0, count($schema['paths']), 'Schema should have at least one path');
        
        // Test tool generation
        $tools = $this->generator->generateTools();
        $this->assertIsArray($tools, 'Tools should be array');
        $this->assertGreaterThan(0, count($tools), 'Should generate tools');
        
        // Test sample tools structure (first 3)
        $sampleTools = array_slice($tools, 0, 3);
        foreach ($sampleTools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertEquals('object', $tool['inputSchema']['type']);
        }
        
        // Test metadata retrieval for one tool
        $firstTool = $sampleTools[0];
        $metadata = $this->generator->getToolMetadata($firstTool['name']);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('path', $metadata);
        $this->assertArrayHasKey('method', $metadata);
        $this->assertNotEmpty($metadata['path']);
        
        // Test path parameters if present
        if (!empty($metadata['pathParams'])) {
            foreach ($metadata['pathParams'] as $param) {
                $this->assertArrayHasKey($param, $firstTool['inputSchema']['properties']);
                $this->assertContains($param, $firstTool['inputSchema']['required']);
            }
        }
        
        // Test error handling for invalid tool
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No metadata for tool: invalid_tool_name_xyz');
        $this->generator->executeApiCall('invalid_tool_name_xyz', []);
    }
}
