# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: owner-restaurant-flows.spec.ts >> Owner Restaurant Flows (API-first, seeded) >> OWN-P0 order handling uses seeded pending orders
- Location: tests\playwright\specs\owner-restaurant-flows.spec.ts:61:3

# Error details

```
Error: owner accept order: expected status in [200], got 500. Response body: {
    "message": "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'dllni.activity_log' doesn't exist (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: dllni, SQL: insert into `activity_log` (`log_name`, `attribute_changes`, `properties`, `causer_id`, `causer_type`, `description`, `updated_at`, `created_at`) values (orders, [], {\"restaurant_id\":11,\"order_id\":52}, 473, user, \u0642\u0628\u0644 \u0627\u0644\u0637\u0644\u0628 \u0631\u0642\u0645 #ORD-AGA7QPOH-6392, 2026-05-06 19:03:56, 2026-05-06 19:03:56))",
    "exception": "Illuminate\\Database\\QueryException",
    "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php",
    "line": 838,
    "trace": [
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php",
            "line": 794,
            "function": "runQueryCallback",
            "class": "Illuminate\\Database\\Connection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\MySqlConnection.php",
            "line": 42,
            "function": "run",
            "class": "Illuminate\\Database\\Connection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Processors\\MySqlProcessor.php",
            "line": 35,
            "function": "insert",
            "class": "Illuminate\\Database\\MySqlConnection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 4170,
            "function": "processInsertGetId",
            "class": "Illuminate\\Database\\Query\\Processors\\MySqlProcessor",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php",
            "line": 2237,
            "function": "insertGetId",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Model.php",
            "line": 1436,
            "function": "__call",
            "class": "Illuminate\\Database\\Eloquent\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Model.php",
            "line": 1401,
            "function": "insertAndSetId",
            "class": "Illuminate\\Database\\Eloquent\\Model",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Model.php",
            "line": 1240,
            "function": "performInsert",
            "class": "Illuminate\\Database\\Eloquent\\Model",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\spatie\\laravel-activitylog\\src\\Actions\\LogActivityAction.php",
            "line": 78,
            "function": "save",
            "class": "Illuminate\\Database\\Eloquent\\Model",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\spatie\\laravel-activitylog\\src\\Actions\\LogActivityAction.php",
            "line": 39,
            "function": "save",
            "class": "Spatie\\Activitylog\\Actions\\LogActivityAction",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\spatie\\laravel-activitylog\\src\\Support\\ActivityLogger.php",
            "line": 171,
            "function": "execute",
            "class": "Spatie\\Activitylog\\Actions\\LogActivityAction",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\app\\Services\\ActivityLogService.php",
            "line": 106,
            "function": "log",
            "class": "Spatie\\Activitylog\\Support\\ActivityLogger",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\Modules\\Resturants\\app\\Http\\Controllers\\API\\OrderAcceptController.php",
            "line": 30,
            "function": "logOrderAccepted",
            "class": "App\\Services\\ActivityLogService",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\ControllerDispatcher.php",
            "line": 46,
            "function": "__invoke",
            "class": "Modules\\Resturants\\Http\\Controllers\\API\\OrderAcceptController",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php",
            "line": 265,
            "function": "dispatch",
            "class": "Illuminate\\Routing\\ControllerDispatcher",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php",
            "line": 211,
            "function": "runController",
            "class": "Illuminate\\Routing\\Route",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 822,
            "function": "run",
            "class": "Illuminate\\Routing\\Route",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 180,
            "function": "{closure:Illuminate\\Routing\\Router::runRouteWithinStack():821}",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Middleware\\SubstituteBindings.php",
            "line": 50,
            "function": "{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Routing\\Middleware\\SubstituteBindings",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Auth\\Middleware\\Authenticate.php",
            "line": 63,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Auth\\Middleware\\Authenticate",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 137,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 821,
            "function": "then",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 800,
            "function": "runRouteWithinStack",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 764,
            "function": "runRoute",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 753,
            "function": "dispatchToRoute",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 200,
            "function": "dispatch",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 180,
            "function": "{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\livewire\\livewire\\src\\Features\\SupportDisablingBackButtonCache\\DisableBackButtonCacheMiddleware.php",
            "line": 19,
            "function": "{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Livewire\\Features\\SupportDisablingBackButtonCache\\DisableBackButtonCacheMiddleware",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest.php",
            "line": 21,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull.php",
            "line": 31,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest.php",
            "line": 21,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TrimStrings.php",
            "line": 51,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TrimStrings",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\ValidatePostSize.php",
            "line": 27,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\ValidatePostSize",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance.php",
            "line": 109,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\HandleCors.php",
            "line": 74,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\HandleCors",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\TrustProxies.php",
            "line": 58,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\TrustProxies",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\InvokeDeferredCallbacks.php",
            "line": 22,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\InvokeDeferredCallbacks",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\ValidatePathEncoding.php",
            "line": 26,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\ValidatePathEncoding",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 137,
            "function": "{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 175,
            "function": "then",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 144,
            "function": "sendRequestThroughRouter",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php",
            "line": 1220,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\public\\index.php",
            "line": 22,
            "function": "handleRequest",
            "class": "Illuminate\\Foundation\\Application",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\Dllni\\Dllni_backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\resources\\server.php",
            "line": 23,
            "function": "require_once"
        }
    ]
}
```

# Test source

```ts
  1   | ﻿import { APIRequestContext, APIResponse } from '@playwright/test';
  2   | 
  3   | export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  4   | 
  5   | type QueryParams = Record<string, unknown>;
  6   | type Headers = Record<string, string>;
  7   | 
  8   | export type ApiCallOptions = {
  9   |   params?: QueryParams;
  10  |   data?: unknown;
  11  |   headers?: Headers;
  12  | };
  13  | 
  14  | export type ApiCallResult<T = unknown> = {
  15  |   response: APIResponse;
  16  |   status: number;
  17  |   ok: boolean;
  18  |   url: string;
  19  |   text: string;
  20  |   body: T | null;
  21  | };
  22  | 
  23  | export async function callApi<T = unknown>(
  24  |   context: APIRequestContext,
  25  |   method: HttpMethod,
  26  |   url: string,
  27  |   options: ApiCallOptions = {},
  28  | ): Promise<ApiCallResult<T>> {
  29  |   const requestOptions = {
  30  |     params: options.params,
  31  |     data: options.data,
  32  |     headers: options.headers,
  33  |   };
  34  | 
  35  |   let response: APIResponse;
  36  | 
  37  |   switch (method) {
  38  |     case 'GET':
  39  |       response = await context.get(url, requestOptions);
  40  |       break;
  41  |     case 'POST':
  42  |       response = await context.post(url, requestOptions);
  43  |       break;
  44  |     case 'PUT':
  45  |       response = await context.put(url, requestOptions);
  46  |       break;
  47  |     case 'PATCH':
  48  |       response = await context.patch(url, requestOptions);
  49  |       break;
  50  |     case 'DELETE':
  51  |       response = await context.delete(url, requestOptions);
  52  |       break;
  53  |     default:
  54  |       throw new Error(`Unsupported method: ${String(method)}`);
  55  |   }
  56  | 
  57  |   const text = await response.text();
  58  |   let body: T | null = null;
  59  | 
  60  |   if (text.trim().length > 0) {
  61  |     try {
  62  |       body = JSON.parse(text) as T;
  63  |     } catch {
  64  |       body = null;
  65  |     }
  66  |   }
  67  | 
  68  |   return {
  69  |     response,
  70  |     status: response.status(),
  71  |     ok: response.ok(),
  72  |     url,
  73  |     text,
  74  |     body,
  75  |   };
  76  | }
  77  | 
  78  | export function expectStatus(result: ApiCallResult, allowed: number[], label: string): void {
  79  |   if (allowed.includes(result.status)) {
  80  |     return;
  81  |   }
  82  | 
> 83  |   throw new Error(
      |         ^ Error: owner accept order: expected status in [200], got 500. Response body: {
  84  |     `${label}: expected status in [${allowed.join(', ')}], got ${result.status}. Response body: ${result.text}`,
  85  |   );
  86  | }
  87  | 
  88  | export function asRecord(value: unknown): Record<string, unknown> | null {
  89  |   if (!value || typeof value !== 'object' || Array.isArray(value)) {
  90  |     return null;
  91  |   }
  92  | 
  93  |   return value as Record<string, unknown>;
  94  | }
  95  | 
  96  | export function dataArray(body: unknown): Record<string, unknown>[] {
  97  |   const root = asRecord(body);
  98  |   if (!root) {
  99  |     return [];
  100 |   }
  101 | 
  102 |   const data = root.data;
  103 |   if (!Array.isArray(data)) {
  104 |     return [];
  105 |   }
  106 | 
  107 |   return data.filter((item): item is Record<string, unknown> => !!asRecord(item));
  108 | }
  109 | 
  110 | export function dataObject(body: unknown): Record<string, unknown> | null {
  111 |   const root = asRecord(body);
  112 |   if (!root) {
  113 |     return null;
  114 |   }
  115 | 
  116 |   return asRecord(root.data);
  117 | }
  118 | 
  119 | export function firstNumericId(items: Record<string, unknown>[], key = 'id'): number | null {
  120 |   for (const item of items) {
  121 |     const value = item[key];
  122 |     if (typeof value === 'number' && Number.isFinite(value)) {
  123 |       return value;
  124 |     }
  125 | 
  126 |     if (typeof value === 'string') {
  127 |       const parsed = Number.parseInt(value, 10);
  128 |       if (Number.isFinite(parsed)) {
  129 |         return parsed;
  130 |       }
  131 |     }
  132 |   }
  133 | 
  134 |   return null;
  135 | }
  136 | 
  137 | export function numericFromPath(value: unknown, path: string): number | null {
  138 |   const segments = path.split('.').filter(Boolean);
  139 |   let current: unknown = value;
  140 | 
  141 |   for (const segment of segments) {
  142 |     if (Array.isArray(current)) {
  143 |       const index = Number.parseInt(segment, 10);
  144 |       if (!Number.isFinite(index) || index < 0 || index >= current.length) {
  145 |         return null;
  146 |       }
  147 |       current = current[index];
  148 |       continue;
  149 |     }
  150 | 
  151 |     const obj = asRecord(current);
  152 |     if (!obj || !(segment in obj)) {
  153 |       return null;
  154 |     }
  155 | 
  156 |     current = obj[segment];
  157 |   }
  158 | 
  159 |   if (typeof current === 'number' && Number.isFinite(current)) {
  160 |     return current;
  161 |   }
  162 | 
  163 |   if (typeof current === 'string') {
  164 |     const parsed = Number.parseInt(current, 10);
  165 |     if (Number.isFinite(parsed)) {
  166 |       return parsed;
  167 |     }
  168 |   }
  169 | 
  170 |   return null;
  171 | }
  172 | 
  173 | export function extractOrderId(body: unknown): number | null {
  174 |   const direct = numericFromPath(body, 'data.id');
  175 |   if (direct !== null) {
  176 |     return direct;
  177 |   }
  178 | 
  179 |   const nested = numericFromPath(body, 'data.order.id');
  180 |   if (nested !== null) {
  181 |     return nested;
  182 |   }
  183 | 
```