# 🛒 Magento MCP Server Extension

A **Magento 2 extension** that provides a Model Context Protocol (MCP) server, automatically discovering REST API endpoints from Swagger schema and exposing them as MCP tools for AI assistants.

**Inspired by** [Sylius MCP Server Plugin](https://docs.sylius.com/the-book/ai-conversational-commerce) and uses the official [`php-mcp/server`](https://github.com/php-mcp/server) package.

## ✨ Features

🚀 **Dynamic API Integration**
- **Auto-discovers ALL API endpoints** from Magento Swagger schema
- **Complete API coverage** - every endpoint becomes an available MCP tool
- **Self-updating** - new API endpoints become available automatically
- **Zero hardcoded API calls** - everything generated at runtime

🔧 **Magento Native Integration**
- **Built as Magento 2 module** - uses Magento's dependency injection
- **Uses Magento configuration** - reads from Magento config
- **Magento CLI command** - `bin/magento mcp:server`
- **Proper logging** - uses Magento's logger

🛒 **Complete Magento Coverage**
- **Product Catalog** - Products, categories, attributes
- **Order Management** - Orders, invoices, shipments
- **Customer Management** - Customers, addresses, groups
- **Inventory** - Stock levels, sources
- **CMS** - Pages, blocks
- **And more** - All Magento REST API endpoints

## 🚀 Installation

### 1. Install Module

```bash
cd /path/to/magento
composer require genaker/module-mcp-server php-mcp/server
php bin/magento module:enable Genaker_McpServer
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### 2. Enable Swagger in Magento

```bash
php bin/magento config:set webapi/swagger/enable 1
php bin/magento cache:flush
```

Or in Admin:
**Stores → Configuration → Services → Magento Web API → Swagger** → Enable

### 3. Test MCP Server

**Stdio Transport (default):**
```bash
php bin/magento mcp:server
```

**HTTP Transport (like Sylius):**
```bash
php bin/magento mcp:server --transport=http --port=8080
```

The server will:
1. Discover Swagger schema from Magento
2. Generate tools from all available endpoints
3. Expose them via MCP protocol (stdio or HTTP)

## 🔧 Usage with Claude Desktop

**Stdio Transport (recommended):**
```json
{
  "mcpServers": {
    "magento": {
      "command": "php",
      "args": ["/path/to/magento/bin/magento", "mcp:server"],
      "env": {}
    }
  }
}
```

**HTTP Transport:**
```json
{
  "mcpServers": {
    "magento": {
      "command": "php",
      "args": ["/path/to/magento/bin/magento", "mcp:server", "--transport=http", "--port=8080"],
      "env": {}
    }
  }
}
```

Then use URL: `http://localhost:8080/mcp`

## 🔧 Usage with Claude Code

Create `.mcp.json` in your project root:

**Stdio:**
```json
{
  "mcpServers": {
    "magento": {
      "command": "php",
      "args": ["/path/to/magento/bin/magento", "mcp:server"],
      "env": {},
      "description": "Dynamic Magento 2 API integration"
    }
  }
}
```

**HTTP:**
```json
{
  "mcpServers": {
    "magento": {
      "command": "php",
      "args": ["/path/to/magento/bin/magento", "mcp:server", "--transport=http"],
      "env": {},
      "description": "Dynamic Magento 2 API integration via HTTP"
    }
  }
}
```

## 🛠️ Available Tools

The server automatically generates tools from your Magento API schema:

### Product Management
- `get_products` - List products
- `get_products_sku` - Get product by SKU
- `put_products_sku` - Update product
- `post_products` - Create product

### Order Management
- `get_orders` - List orders
- `get_orders_id` - Get order details
- `post_orders` - Create order
- `post_orders_id_invoice` - Create invoice

### Customer Management
- `get_customers` - List customers
- `get_customers_id` - Get customer details
- `post_customers` - Create customer
- `put_customers_id` - Update customer

### And Many More...

All endpoints from your Magento REST API are automatically available as MCP tools!

## 🔍 How It Works

### Architecture Overview

The Magento MCP Server follows a **dynamic discovery pattern** that automatically converts Magento REST API endpoints into MCP tools:

```
┌─────────────────┐
│  MCP Client     │  (Claude, Cursor, etc.)
│  (AI Assistant) │
└────────┬────────┘
         │ JSON-RPC 2.0
         │ (tools/list, tools/call)
         ▼
┌─────────────────────────────────┐
│     MCPAPIServer                │
│  (MCP Protocol Handler)          │
│  - Uses php-mcp/server           │
│  - Exposes tools via stdio/HTTP  │
└────────┬─────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│  SwaggerToolGenerator           │
│  - Discovers Swagger schema      │
│  - Generates tool definitions    │
│  - Executes API calls            │
└────────┬─────────────────────────┘
         │
         ├─────────────────┬─────────────────┐
         ▼                 ▼                 ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│   Swagger    │  │   WebAPI     │  │   HTTP      │
│   Discovery  │  │   Config     │  │   Client    │
│   (HTTP)     │  │   (Fallback) │  │   (Guzzle)  │
└──────────────┘  └──────────────┘  └──────────────┘
```

### Step-by-Step Flow

#### 1. **Server Initialization** (`MCPAPIServer::run()`)

When you run `bin/magento mcp:server`:

1. **Bootstrap**: Magento initializes, sets area code to `AREA_GLOBAL`
2. **Tool Generation**: Calls `SwaggerToolGenerator::generateTools()`
3. **Server Setup**: Creates `php-mcp/server` instance with all discovered tools
4. **Transport**: Starts stdio transport (reads JSON-RPC from stdin, writes to stdout)

#### 2. **Swagger Schema Discovery** (`SwaggerToolGenerator::discoverSwaggerSchema()`)

The system tries multiple methods to discover API endpoints:

**Primary Method - HTTP Swagger Endpoints:**
```
1. GET /rest/default/schema?services=all  (Magento 2.4+ preferred)
2. GET /rest/all/schema?services=all
3. GET /rest/default/swagger              (legacy)
4. GET /rest/all/swagger                  (legacy)
```

**Fallback Method - Internal WebAPI Config:**
If HTTP requests fail (e.g., in test environments), the system:
1. Uses `Magento\Webapi\Model\Config::getServices()`
2. Extracts routes from webapi configuration
3. Builds Swagger-like schema from 400+ routes
4. Converts routes like `/V1/modules` → `/rest/V1/modules`

**Key Features:**
- **HTTPS → HTTP conversion**: Automatically converts HTTPS to HTTP for local/test domains (`.test`, `.local`, `localhost`)
- **SSL certificate bypass**: Accepts self-signed certificates for development
- **Connection timeout**: Fast failure (2s connect, 5s total) to enable fallback

#### 3. **Tool Generation** (`SwaggerToolGenerator::generateTools()`)

For each API endpoint in the Swagger schema:

**Input:** Swagger path + method
```json
{
  "paths": {
    "/rest/V1/products/{sku}": {
      "get": {
        "summary": "Get product by SKU",
        "parameters": [...]
      }
    }
  }
}
```

**Process:**
1. **Extract path parameters**: Regex `/\{(\w+)\}/` finds `{sku}` → `['sku']`
2. **Generate tool name**: 
   - Remove `/rest/V1/` prefix
   - Remove `{param}` placeholders
   - Convert to lowercase: `get_products`
3. **Build input schema**:
   - Path params → required string properties
   - Query/body params → optional properties with types
   - Map Swagger types (`integer`, `string`, etc.) to JSON Schema types

**Output:** MCP Tool Definition
```json
{
  "name": "get_products",
  "description": "Get product by SKU",
  "inputSchema": {
    "type": "object",
    "properties": {
      "sku": {
        "type": "string",
        "description": "Path parameter: sku"
      }
    },
    "required": ["sku"]
  }
}
```

**Metadata Storage:**
Each tool stores metadata for execution:
```php
[
  'path' => '/rest/V1/products/{sku}',
  'method' => 'GET',
  'pathParams' => ['sku']
]
```

#### 4. **Tool Registration** (`MCPAPIServer::run()`)

For each generated tool:
```php
$builder->withTool(
    function (array $arguments) use ($toolName) {
        return $this->toolGenerator->executeApiCall($toolName, $arguments);
    },
    name: $toolName,
    description: $description,
    inputSchema: $inputSchema
);
```

The `php-mcp/server` package:
- Registers tools in its internal registry
- Exposes them via `tools/list` MCP method
- Handles JSON-RPC 2.0 protocol

#### 5. **API Call Execution** (`SwaggerToolGenerator::executeApiCall()`)

When an MCP client calls a tool:

**Input:**
```json
{
  "name": "get_products",
  "arguments": {"sku": "test-product"}
}
```

**Process:**
1. **Retrieve metadata**: Look up tool metadata (path, method, pathParams)
2. **Replace path parameters**: 
   - `/rest/V1/products/{sku}` + `{"sku": "test-product"}`
   - → `/rest/V1/products/test-product`
3. **Prepare request**:
   - GET requests → query parameters
   - POST/PUT/PATCH → JSON body
4. **Execute HTTP call**: Uses Guzzle HTTP client
5. **Return response**: JSON decoded response or error structure

**Output:**
```json
{
  "id": 1,
  "sku": "test-product",
  "name": "Test Product",
  ...
}
```

Or on error:
```json
{
  "error": "Client error: 404",
  "status": 404,
  "detail": {...}
}
```

### Key Design Decisions

1. **Dynamic Discovery**: No hardcoded endpoints - everything discovered at runtime
2. **Fallback Mechanism**: Works even when HTTP isn't available (uses internal config)
3. **Path Parameter Extraction**: Automatically identifies `{param}` in URLs
4. **Type Mapping**: Converts Swagger types to JSON Schema for MCP compatibility
5. **Error Handling**: Returns structured errors instead of throwing exceptions
6. **SSL Flexibility**: Accepts invalid certificates for development environments

### Example: Complete Flow

**1. Client requests tool list:**
```json
{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}
```

**2. Server responds:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [
      {
        "name": "get_products",
        "description": "Get product by SKU",
        "inputSchema": {...}
      },
      ...
    ]
  }
}
```

**3. Client calls tool:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "get_products",
    "arguments": {"sku": "test-123"}
  }
}
```

**4. Server executes:**
- Looks up metadata for `get_products`
- Replaces `{sku}` in path: `/rest/V1/products/{sku}` → `/rest/V1/products/test-123`
- Makes HTTP GET request
- Returns product data

**5. Server responds:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [{
      "type": "text",
      "text": "{\"id\":1,\"sku\":\"test-123\",...}"
    }]
  }
}
```

## 📖 Usage Examples

### Query Products

```json
{
  "name": "get_products",
  "arguments": {
    "searchCriteria[filterGroups][0][filters][0][field]": "name",
    "searchCriteria[filterGroups][0][filters][0][value]": "%shirt%",
    "searchCriteria[pageSize]": 10
  }
}
```

### Get Order Details

```json
{
  "name": "get_orders_id",
  "arguments": {
    "id": "000000001"
  }
}
```

## ⚙️ Configuration

The module uses Magento's native configuration:

**Stores → Configuration → Services → Magento Web API → Swagger**

- **Enable Swagger**: Must be enabled
- **Swagger Path**: Default is `/rest/default/swagger`

## 🐛 Troubleshooting

### Swagger Not Found

Enable Swagger:
```bash
php bin/magento config:set webapi/swagger/enable 1
php bin/magento cache:flush
```

### Module Not Found

Check module status:
```bash
php bin/magento module:status Genaker_McpServer
```

### Test Swagger Endpoint

```bash
curl https://your-magento-store.com/rest/default/swagger
```

## 📋 Requirements

- **Magento 2.4+**
- **PHP 8.1+**
- **Swagger enabled** in Magento configuration

## 📄 License

MIT License

---

**Transform your Magento data into AI-accessible insights**
