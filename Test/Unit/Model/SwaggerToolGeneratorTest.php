<?php
declare(strict_types=1);

namespace Genaker\McpServer\Test\Unit\Model;

use Genaker\McpServer\Model\SwaggerToolGenerator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SwaggerToolGeneratorTest extends TestCase
{
    private SwaggerToolGenerator $generator;
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreManagerInterface|MockObject $storeManager;
    private LoggerInterface|MockObject $logger;
    private Client|MockObject $httpClient;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->generator = new SwaggerToolGenerator(
            $this->scopeConfig,
            $this->storeManager,
            $this->logger
        );
    }

    public function testDiscoverSwaggerSchemaSuccess(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('webapi/swagger/enable')
            ->willReturn('1');

        $schema = [
            'paths' => [
                '/rest/V1/products' => [
                    'get' => [
                        'summary' => 'Get products',
                        'parameters' => []
                    ]
                ]
            ]
        ];

        // Create a mock HTTP client that will be used after initializeClient()
        $reflection = new \ReflectionClass($this->generator);
        
        // First call initializeClient() to set up baseUrl
        $initializeMethod = $reflection->getMethod('initializeClient');
        $initializeMethod->setAccessible(true);
        $initializeMethod->invoke($this->generator);

        // Now inject mock client
        $clientProperty = $reflection->getProperty('httpClient');
        $clientProperty->setAccessible(true);

        $this->httpClient = $this->createMock(Client::class);
        $responseBody = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $responseBody->method('getContents')
            ->willReturn(json_encode($schema));
        
        $response = $this->createMock(Response::class);
        $response->method('getBody')
            ->willReturn($responseBody);

        $this->httpClient->method('get')
            ->with(
                '/rest/default/schema?services=all',
                $this->callback(function ($options) {
                    return isset($options['headers']['Accept']) &&
                           isset($options['connect_timeout']) &&
                           isset($options['timeout']);
                })
            )
            ->willReturn($response);

        $clientProperty->setValue($this->generator, $this->httpClient);

        $result = $this->generator->discoverSwaggerSchema();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('paths', $result);
    }

    public function testDiscoverSwaggerSchemaSwaggerDisabled(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('webapi/swagger/enable')
            ->willReturn('0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Swagger is not enabled');

        $this->generator->discoverSwaggerSchema();
    }

    public function testGenerateToolName(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateToolName');
        $method->setAccessible(true);

        $result = $method->invoke($this->generator, '/rest/V1/products/{sku}', 'get');
        $this->assertEquals('get_products', $result);

        $result = $method->invoke($this->generator, '/rest/V1/customers/{id}/orders', 'post');
        $this->assertEquals('post_customers_orders', $result);
    }

    public function testMapSwaggerType(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('mapSwaggerType');
        $method->setAccessible(true);

        $this->assertEquals('integer', $method->invoke($this->generator, 'integer'));
        $this->assertEquals('number', $method->invoke($this->generator, 'float'));
        $this->assertEquals('boolean', $method->invoke($this->generator, 'boolean'));
        $this->assertEquals('string', $method->invoke($this->generator, 'string'));
        $this->assertEquals('string', $method->invoke($this->generator, null));
    }

    public function testGenerateToolsWithPathParameters(): void
    {
        $schema = [
            'paths' => [
                '/rest/V1/products/{sku}' => [
                    'get' => [
                        'summary' => 'Get product by SKU',
                        'parameters' => []
                    ]
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->generator);
        $schemaProperty = $reflection->getProperty('swaggerSchema');
        $schemaProperty->setAccessible(true);
        $schemaProperty->setValue($this->generator, $schema);

        $tools = $this->generator->generateTools();

        $this->assertIsArray($tools);
        $this->assertCount(1, $tools);
        $this->assertEquals('get_products', $tools[0]['name']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
        $this->assertArrayHasKey('properties', $tools[0]['inputSchema']);
        // Path parameters are extracted from URL pattern, not from parameters array
        $this->assertArrayHasKey('sku', $tools[0]['inputSchema']['properties']);
        $this->assertContains('sku', $tools[0]['inputSchema']['required']);
    }

    public function testGenerateToolsWithQueryParameters(): void
    {
        $schema = [
            'paths' => [
                '/rest/V1/products' => [
                    'get' => [
                        'summary' => 'Get products',
                        'parameters' => [
                            [
                                'name' => 'searchCriteria',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string']
                            ],
                            [
                                'name' => 'pageSize',
                                'in' => 'query',
                                'required' => true,
                                'schema' => ['type' => 'integer']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->generator);
        $schemaProperty = $reflection->getProperty('swaggerSchema');
        $schemaProperty->setAccessible(true);
        $schemaProperty->setValue($this->generator, $schema);

        $tools = $this->generator->generateTools();

        $this->assertIsArray($tools);
        $this->assertCount(1, $tools);
        $this->assertEquals('get_products', $tools[0]['name']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
        $this->assertArrayHasKey('properties', $tools[0]['inputSchema']);
        $this->assertArrayHasKey('searchCriteria', $tools[0]['inputSchema']['properties']);
        $this->assertArrayHasKey('pageSize', $tools[0]['inputSchema']['properties']);
        $this->assertContains('pageSize', $tools[0]['inputSchema']['required']);
        $this->assertNotContains('searchCriteria', $tools[0]['inputSchema']['required']);
    }

    public function testGetToolMetadata(): void
    {
        $schema = [
            'paths' => [
                '/rest/V1/products/{sku}' => [
                    'get' => [
                        'summary' => 'Get product',
                        'parameters' => []
                    ]
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->generator);
        $schemaProperty = $reflection->getProperty('swaggerSchema');
        $schemaProperty->setAccessible(true);
        $schemaProperty->setValue($this->generator, $schema);

        $this->generator->generateTools();

        $metadata = $this->generator->getToolMetadata('get_products');
        $this->assertIsArray($metadata);
        $this->assertEquals('/rest/V1/products/{sku}', $metadata['path']);
        $this->assertEquals('GET', $metadata['method']);
        $this->assertContains('sku', $metadata['pathParams']);
    }

    public function testGetToolMetadataNotFound(): void
    {
        $metadata = $this->generator->getToolMetadata('nonexistent_tool');
        $this->assertNull($metadata);
    }

    public function testExecuteApiCallWithPathParams(): void
    {
        $schema = [
            'paths' => [
                '/rest/V1/products/{sku}' => [
                    'get' => [
                        'summary' => 'Get product',
                        'parameters' => []
                    ]
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->generator);
        $schemaProperty = $reflection->getProperty('swaggerSchema');
        $schemaProperty->setAccessible(true);
        $schemaProperty->setValue($this->generator, $schema);

        // Initialize client and inject mock
        $initializeMethod = $reflection->getMethod('initializeClient');
        $initializeMethod->setAccessible(true);
        $initializeMethod->invoke($this->generator);

        $clientProperty = $reflection->getProperty('httpClient');
        $clientProperty->setAccessible(true);

        $this->httpClient = $this->createMock(Client::class);
        $responseBody = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $responseBody->method('getContents')
            ->willReturn(json_encode(['id' => 1, 'sku' => 'test-sku']));
        
        $response = $this->createMock(Response::class);
        $response->method('getBody')
            ->willReturn($responseBody);
        $response->method('getStatusCode')
            ->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/V1/products/test-sku', $this->anything())
            ->willReturn($response);

        $clientProperty->setValue($this->generator, $this->httpClient);

        // Generate tools first to populate metadata
        $this->generator->generateTools();

        $result = $this->generator->executeApiCall('get_products', ['sku' => 'test-sku']);

        $this->assertIsArray($result);
        $this->assertEquals('test-sku', $result['sku']);
    }

    public function testExecuteApiCallWithError(): void
    {
        $schema = [
            'paths' => [
                '/rest/V1/products/{sku}' => [
                    'get' => [
                        'summary' => 'Get product',
                        'parameters' => []
                    ]
                ]
            ]
        ];

        $reflection = new \ReflectionClass($this->generator);
        $schemaProperty = $reflection->getProperty('swaggerSchema');
        $schemaProperty->setAccessible(true);
        $schemaProperty->setValue($this->generator, $schema);

        // Initialize client and inject mock
        $initializeMethod = $reflection->getMethod('initializeClient');
        $initializeMethod->setAccessible(true);
        $initializeMethod->invoke($this->generator);

        $clientProperty = $reflection->getProperty('httpClient');
        $clientProperty->setAccessible(true);

        $this->httpClient = $this->createMock(Client::class);
        
        // Create real PSR-7 objects for the exception
        $request = new \GuzzleHttp\Psr7\Request('GET', '/rest/V1/products/nonexistent');
        $response = new \GuzzleHttp\Psr7\Response(
            404,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => 'Product not found'])
        );
        
        // Create a real RequestException
        $exception = new RequestException(
            'Client error: 404',
            $request,
            $response
        );

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $clientProperty->setValue($this->generator, $this->httpClient);

        // Generate tools first to populate metadata
        $this->generator->generateTools();

        $result = $this->generator->executeApiCall('get_products', ['sku' => 'nonexistent']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(404, $result['status']);
    }

    public function testExecuteApiCallWithInvalidTool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No metadata for tool: invalid_tool');

        $this->generator->executeApiCall('invalid_tool', []);
    }
}
