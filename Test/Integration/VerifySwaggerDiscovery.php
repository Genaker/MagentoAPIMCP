<?php
/**
 * Integration test script to verify Swagger discovery works
 * Run: php app/code/Genaker/McpServer/Test/Integration/VerifySwaggerDiscovery.php
 */

use Magento\Framework\App\Bootstrap;
use Genaker\McpServer\Model\SwaggerToolGenerator;

require __DIR__ . '/../../../../../../app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

try {
    /** @var SwaggerToolGenerator $generator */
    $generator = $objectManager->get(SwaggerToolGenerator::class);
    
    echo "Testing Swagger schema discovery...\n";
    
    // Check if Swagger is enabled
    $scopeConfig = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
    $swaggerEnabled = $scopeConfig->getValue('webapi/swagger/enable');
    
    if (!$swaggerEnabled) {
        echo "⚠️  Swagger is not enabled. Enable it with:\n";
        echo "   bin/magento config:set webapi/swagger/enable 1\n";
        echo "   bin/magento cache:flush\n";
        exit(1);
    }
    
    echo "✓ Swagger is enabled\n";
    
    // Try to discover schema
    try {
        $schema = $generator->discoverSwaggerSchema();
        echo "✓ Swagger schema discovered successfully\n";
        echo "  Found " . count($schema['paths'] ?? []) . " API endpoints\n";
    } catch (\Exception $e) {
        echo "✗ Failed to discover Swagger schema: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Generate tools
    echo "\nGenerating MCP tools from Swagger schema...\n";
    $tools = $generator->generateTools();
    echo "✓ Generated " . count($tools) . " MCP tools\n";
    
    // Show first few tools as examples
    if (count($tools) > 0) {
        echo "\nSample tools:\n";
        foreach (array_slice($tools, 0, 5) as $tool) {
            echo "  - {$tool['name']}: {$tool['description']}\n";
        }
        if (count($tools) > 5) {
            echo "  ... and " . (count($tools) - 5) . " more\n";
        }
    }
    
    echo "\n✅ All tests passed!\n";
    exit(0);
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
