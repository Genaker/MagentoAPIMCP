<?php
/**
 * Generates MCP tools dynamically from Magento Swagger schema
 */

namespace Genaker\McpServer\Model;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SwaggerToolGenerator
{
    private const XML_PATH_SWAGGER_ENABLED = 'webapi/swagger/enable';

    private ?Client $httpClient = null;
    private string $baseUrl = '';
    private array $swaggerSchema = [];
    private array $toolMetadata = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    private function initializeClient(): void
    {
        if ($this->httpClient) {
            return;
        }

        $this->baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        
        // For local/test environments, convert HTTPS to HTTP and use localhost
        $baseUrl = $this->baseUrl;
        if (strpos($baseUrl, 'https://') === 0) {
            // Check if it's a local/test domain
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if ($host && (
                strpos($host, '.test') !== false ||
                strpos($host, '.local') !== false ||
                strpos($host, 'localhost') !== false ||
                strpos($host, '127.0.0.1') !== false ||
                in_array($host, ['app.genaker.test', 'localhost', '127.0.0.1'])
            )) {
                // Convert HTTPS to HTTP for local domains
                $baseUrl = str_replace('https://', 'http://', $baseUrl);
            }
        }
        
        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 30,
            'verify' => false, // Disable SSL certificate verification
            'http_errors' => false, // Don't throw exceptions on HTTP errors
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_VERBOSE => false,
            ],
        ]);
    }

    /**
     * Discover Swagger schema from Magento
     */
    public function discoverSwaggerSchema(): array
    {
        $this->initializeClient();

        if (!$this->scopeConfig->getValue(self::XML_PATH_SWAGGER_ENABLED)) {
            throw new \RuntimeException(
                'Swagger is not enabled. Run: bin/magento config:set webapi/swagger/enable 1'
            );
        }

        $swaggerPaths = [
            '/rest/default/schema?services=all',
            '/rest/all/schema?services=all',
            '/rest/default/swagger',
            '/rest/all/swagger',
        ];

        foreach ($swaggerPaths as $path) {
            try {
                // Try to use Magento's internal webapi if HTTP fails
                $response = $this->httpClient->get($path, [
                    'headers' => ['Accept' => 'application/json'],
                    'connect_timeout' => 2,
                    'timeout' => 5,
                ]);

                $schema = json_decode($response->getBody()->getContents(), true);
                if ($schema && isset($schema['paths'])) {
                    $this->swaggerSchema = $schema;
                    $this->logger->info('Swagger schema discovered', [
                        'path' => $path,
                        'endpoints' => count($schema['paths']),
                    ]);
                    return $schema;
                }
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                // Connection refused - try next path or use internal method
                continue;
            } catch (RequestException $e) {
                // Other HTTP errors - try next path
                continue;
            }
        }

        // If HTTP failed, try using Magento's internal webapi framework
        return $this->discoverSwaggerSchemaInternal();
    }

    /**
     * Discover Swagger schema using Magento's internal Swagger generator
     */
    private function discoverSwaggerSchemaInternal(): array
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            
            // Try to use Magento's Swagger generator directly
            if (class_exists(\Magento\Swagger\Api\Data\SchemaInterface::class)) {
                $swaggerSchema = $objectManager->get(\Magento\Swagger\Api\Data\SchemaInterface::class);
                if ($swaggerSchema && method_exists($swaggerSchema, 'getSchema')) {
                    $schema = $swaggerSchema->getSchema();
                    if ($schema && isset($schema['paths'])) {
                        $this->swaggerSchema = $schema;
                        $this->logger->info('Swagger schema discovered via internal Swagger generator', [
                            'endpoints' => count($schema['paths']),
                        ]);
                        return $schema;
                    }
                }
            }
            
            // Fallback: Try to get schema via webapi config
            $webapiConfig = $objectManager->get(\Magento\Webapi\Model\Config::class);
            $services = $webapiConfig->getServices();
            
            if (empty($services) || !isset($services['routes'])) {
                throw new \RuntimeException('No webapi routes found');
            }
            
            $routes = $services['routes'];
            
            // Build minimal Swagger schema from webapi routes
            $schema = [
                'openapi' => '3.0.0',
                'info' => [
                    'title' => 'Magento 2 REST API',
                    'version' => '1.0.0',
                ],
                'paths' => [],
            ];
            
            // Extract routes - structure: routes[routePath][method] = routeData
            foreach ($routes as $routePath => $methods) {
                if (!is_array($methods)) {
                    continue;
                }
                
                foreach ($methods as $httpMethod => $routeData) {
                    $httpMethod = strtolower($httpMethod);
                    // Route path already includes /V1, so just prepend /rest
                    $path = '/rest' . $routePath;
                    
                    if (!isset($schema['paths'][$path])) {
                        $schema['paths'][$path] = [];
                    }
                    
                    $schema['paths'][$path][$httpMethod] = [
                        'summary' => $routeData['description'] ?? $routeData['service']['class'] ?? $routePath,
                        'operationId' => ($routeData['service']['class'] ?? 'api') . '_' . $httpMethod,
                        'parameters' => [],
                    ];
                }
            }
            
            if (!empty($schema['paths'])) {
                $this->swaggerSchema = $schema;
                $this->logger->info('Swagger schema discovered via internal webapi config', [
                    'endpoints' => count($schema['paths']),
                ]);
                return $schema;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to discover Swagger via internal method: ' . $e->getMessage());
        }

        throw new \RuntimeException('Swagger schema not found. Check Magento API configuration.');
    }

    /**
     * Generate MCP tools from Swagger schema
     *
     * @return array Each item: ['name' => string, 'description' => string, 'inputSchema' => array]
     */
    public function generateTools(): array
    {
        if (empty($this->swaggerSchema)) {
            $this->discoverSwaggerSchema();
        }

        $tools = [];
        $this->toolMetadata = [];

        foreach ($this->swaggerSchema['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $method = strtolower($method);
                if (!in_array($method, ['get', 'post', 'put', 'delete', 'patch'])) {
                    continue;
                }

                $toolName = $this->generateToolName($path, $method);
                $description = $operation['summary']
                    ?? $operation['description']
                    ?? "{$method} {$path}";

                // Extract path parameters from the URL pattern
                preg_match_all('/\{(\w+)\}/', $path, $pathParamMatches);
                $pathParams = $pathParamMatches[1] ?? [];

                $properties = [];
                $required = [];

                // Add path parameters first
                foreach ($pathParams as $paramName) {
                    $properties[$paramName] = [
                        'type' => 'string',
                        'description' => "Path parameter: {$paramName}",
                    ];
                    $required[] = $paramName;
                }

                // Add query/body parameters from Swagger
                foreach ($operation['parameters'] ?? [] as $param) {
                    $name = $param['name'] ?? '';
                    if (!$name || in_array($name, $pathParams)) {
                        continue;
                    }

                    $paramType = $param['schema']['type'] ?? $param['type'] ?? 'string';
                    $properties[$name] = [
                        'type' => $this->mapSwaggerType($paramType),
                        'description' => $param['description'] ?? '',
                    ];

                    if ($param['required'] ?? false) {
                        $required[] = $name;
                    }
                }

                $tools[] = [
                    'name' => $toolName,
                    'description' => $description,
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => $properties ?: new \stdClass(),
                        'required' => $required,
                    ],
                ];

                $this->toolMetadata[$toolName] = [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'pathParams' => $pathParams,
                ];
            }
        }

        $this->logger->info('Generated ' . count($tools) . ' MCP tools from Swagger schema');
        return $tools;
    }

    /**
     * Get metadata for a tool (path, method, pathParams)
     */
    public function getToolMetadata(string $toolName): ?array
    {
        return $this->toolMetadata[$toolName] ?? null;
    }

    /**
     * Execute Magento API call
     */
    public function executeApiCall(string $toolName, array $arguments): array
    {
        $this->initializeClient();

        $metadata = $this->getToolMetadata($toolName);
        if (!$metadata) {
            throw new \RuntimeException("No metadata for tool: {$toolName}");
        }

        $path = $metadata['path'];
        $method = $metadata['method'];
        $pathParams = $metadata['pathParams'];

        // Replace path parameters and remove them from arguments
        foreach ($pathParams as $paramName) {
            if (isset($arguments[$paramName])) {
                $path = str_replace('{' . $paramName . '}', $arguments[$paramName], $path);
                unset($arguments[$paramName]);
            }
        }

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($method === 'GET') {
            $options['query'] = $arguments;
        } else {
            $options['json'] = $arguments;
        }

        try {
            $response = $this->httpClient->request($method, $path, $options);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Connection errors - return error response instead of throwing
            return [
                'error' => $e->getMessage(),
                'detail' => 'Connection refused - API endpoint not accessible',
                'status' => 0,
            ];
        } catch (RequestException $e) {
            $errorBody = $e->getResponse()?->getBody()?->getContents();
            return [
                'error' => $e->getMessage(),
                'detail' => $errorBody ? json_decode($errorBody, true) : null,
                'status' => $e->getResponse()?->getStatusCode() ?? 0,
            ];
        }
    }

    /**
     * Generate a clean tool name from API path and method
     */
    private function generateToolName(string $path, string $method): string
    {
        // Remove /rest/V1/ or /rest/default/V1/ prefix
        $path = preg_replace('#^/rest(/\w+)?/V\d+/#', '', $path);
        $path = trim($path, '/');

        // Replace {param} with empty, clean up
        $path = preg_replace('/\{[^}]+\}/', '', $path);
        $path = preg_replace('#/+#', '/', trim($path, '/'));

        // Convert slashes to underscores, remove non-alphanumeric
        $name = str_replace('/', '_', $path);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $name = preg_replace('/_+/', '_', trim($name, '_'));

        return strtolower($method) . '_' . strtolower($name);
    }

    private function mapSwaggerType(?string $type): string
    {
        return match ($type) {
            'integer', 'int' => 'integer',
            'number', 'float' => 'number',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }
}
