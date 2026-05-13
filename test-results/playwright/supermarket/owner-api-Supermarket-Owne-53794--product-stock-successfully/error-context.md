# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: owner-api.spec.ts >> Supermarket Owner API (Playwright request contexts) >> OWN-SM-19: owner can set product stock successfully
- Location: tests\playwright\supermarket\owner-api.spec.ts:184:3

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 200
Received: 400
```

# Test source

```ts
  93  |     const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/accept`);
  94  |     expect([400, 422]).toContain(response.status());
  95  |   });
  96  | 
  97  |   test('OWN-SM-08: owner can reject a pending order with valid reason/type', async ({ roleRequests, seed }) => {
  98  |     test.fail(true, 'Known backend regression: reject transition returns 400 for freshly pending orders in API mode.');
  99  |     const orderId = await createPendingOrderViaUserFlow(roleRequests, seed.fixtures.products.available);
  100 | 
  101 |     const rejectResponse = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/reject`, {
  102 |       data: {
  103 |         reason: 'Out of stock for this item',
  104 |         rejectionType: 'out_of_stock',
  105 |       },
  106 |     });
  107 | 
  108 |     expect(rejectResponse.status()).toBe(200);
  109 |     const body = await rejectResponse.json();
  110 |     expect(body?.data?.status).toBe('cancelled');
  111 |     expect(body?.data?.cancellationReason).toBe('Out of stock for this item');
  112 |   });
  113 | 
  114 |   test('OWN-SM-09: reject validates missing reason payload', async ({ roleRequests, seed }) => {
  115 |     const orderId = seed.fixtures.orders.pending_reject;
  116 | 
  117 |     const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/reject`, {
  118 |       data: {
  119 |         rejectionType: 'out_of_stock',
  120 |       },
  121 |     });
  122 | 
  123 |     expect(response.status()).toBe(422);
  124 |     const body = await response.json();
  125 |     expect(body?.errors?.reason?.[0]).toBeTruthy();
  126 |   });
  127 | 
  128 |   test('OWN-SM-10: owner can hand over ready-for-pickup order to courier', async ({ roleRequests, seed }) => {
  129 |     const orderId = seed.fixtures.orders.ready_for_pickup;
  130 | 
  131 |     const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/courier-handover`);
  132 |     expect(response.status()).toBe(200);
  133 | 
  134 |     const body = await response.json();
  135 |     expect(body?.data?.status).toBe('picked_up');
  136 |     expect(body?.data?.pickedUpAt).toBeTruthy();
  137 |   });
  138 | 
  139 |   test('OWN-SM-11: handover rejects non-ready orders', async ({ roleRequests, seed }) => {
  140 |     const orderId = seed.fixtures.orders.non_ready;
  141 | 
  142 |     const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/courier-handover`);
  143 |     expect([400, 422]).toContain(response.status());
  144 |   });
  145 | 
  146 |   test('OWN-SM-12: hourly count returns weekly status buckets', async ({ roleRequests }) => {
  147 |     const response = await roleRequests.store_owner.get('/api/v1/sm-orders/hourly-count');
  148 |     expect(response.status()).toBe(200);
  149 | 
  150 |     const body = await response.json();
  151 |     expect(body?.data?.saturday).toBeDefined();
  152 |     expect(body?.data?.wednesday).toBeDefined();
  153 |   });
  154 | 
  155 |   test('OWN-SM-16: owner can search master products by prefix', async ({ roleRequests }) => {
  156 |     const response = await roleRequests.store_owner.get('/api/v1/store-owner/master-products/search', {
  157 |       params: {
  158 |         index: 'PW Master',
  159 |         page: '1',
  160 |         perPage: '10',
  161 |       },
  162 |     });
  163 |     expect(response.status()).toBe(200);
  164 | 
  165 |     const body = await response.json();
  166 |     expect(Array.isArray(body?.data)).toBeTruthy();
  167 |     expect(body?.meta).toBeDefined();
  168 |   });
  169 | 
  170 |   test('OWN-SM-18: low-stock endpoint includes seeded low stock product', async ({ roleRequests, seed }) => {
  171 |     const response = await roleRequests.store_owner.get('/api/v1/store-owner/products/low-stock');
  172 |     expect(response.status()).toBe(200);
  173 | 
  174 |     const body = await response.json();
  175 |     expect(body?.success).toBe(true);
  176 |     expect(Number(body?.data?.total)).toBeGreaterThan(0);
  177 | 
  178 |     const ids = ((body?.data?.products ?? []) as Array<{ product_id?: number; productId?: number }>).map((item) =>
  179 |       Number(item.product_id ?? item.productId),
  180 |     );
  181 |     expect(ids).toContain(seed.fixtures.products.low_stock);
  182 |   });
  183 | 
  184 |   test('OWN-SM-19: owner can set product stock successfully', async ({ roleRequests, seed }) => {
  185 |     const productId = seed.fixtures.products.available;
  186 | 
  187 |     const response = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
  188 |       data: {
  189 |         quantity: 18,
  190 |         operation: 'SET',
  191 |       },
  192 |     });
> 193 |     expect(response.status()).toBe(200);
      |                               ^ Error: expect(received).toBe(expected) // Object.is equality
  194 | 
  195 |     const body = await response.json();
  196 |     expect(body?.success).toBe(true);
  197 |     expect(Number(body?.data?.product_id)).toBe(productId);
  198 |     expect(Number(body?.data?.stock_quantity)).toBe(18);
  199 |   });
  200 | 
  201 |   test('OWN-SM-20: stock update validates bad operation', async ({ roleRequests, seed }) => {
  202 |     const productId = seed.fixtures.products.available;
  203 | 
  204 |     const response = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
  205 |       data: {
  206 |         quantity: 1,
  207 |         operation: 'BAD_OP',
  208 |       },
  209 |     });
  210 | 
  211 |     expect(response.status()).toBe(422);
  212 |     const body = await response.json();
  213 |     expect(body?.errors?.operation?.[0]).toBeTruthy();
  214 |   });
  215 | 
  216 |   test('OWN-SM-21: owner can show and update store profile', async ({ roleRequests }) => {
  217 |     const showResponse = await roleRequests.store_owner.get('/api/v1/store-owner/store');
  218 |     expect(showResponse.status()).toBe(200);
  219 | 
  220 |     const showBody = await showResponse.json();
  221 |     const storeId = Number(showBody?.data?.id);
  222 |     expect(storeId).toBeGreaterThan(0);
  223 | 
  224 |     const updateResponse = await roleRequests.store_owner.put('/api/v1/store-owner/store', {
  225 |       data: {
  226 |         name: `PW Updated Store ${storeId}`,
  227 |         description: 'Updated from Playwright API-first owner suite',
  228 |       },
  229 |     });
  230 |     expect(updateResponse.status()).toBe(200);
  231 | 
  232 |     const updateBody = await updateResponse.json();
  233 |     expect(updateBody?.data?.name).toContain('PW Updated Store');
  234 |     expect(updateBody?.data?.description).toBe('Updated from Playwright API-first owner suite');
  235 |   });
  236 | 
  237 |   test('OWN-SM-22: owner can show and update operating hours', async ({ roleRequests }) => {
  238 |     const showResponse = await roleRequests.store_owner.get('/api/v1/store-owner/store/operating-hours');
  239 |     expect(showResponse.status()).toBe(200);
  240 | 
  241 |     const showBody = await showResponse.json();
  242 |     expect(Array.isArray(showBody?.data?.dailyHours)).toBeTruthy();
  243 |     expect((showBody?.data?.dailyHours ?? []).length).toBe(7);
  244 | 
  245 |     const updateResponse = await roleRequests.store_owner.put('/api/v1/store-owner/store/operating-hours', {
  246 |       data: {
  247 |         isTemporarilyClosed: false,
  248 |         dailyHours: [
  249 |           {
  250 |             dayOfWeek: 'monday',
  251 |             isEnabled: true,
  252 |             timeSlots: [
  253 |               {
  254 |                 startTime: '09:00 AM',
  255 |                 endTime: '05:00 PM',
  256 |               },
  257 |             ],
  258 |           },
  259 |         ],
  260 |       },
  261 |     });
  262 |     expect(updateResponse.status()).toBe(200);
  263 | 
  264 |     const updateBody = await updateResponse.json();
  265 |     const monday = ((updateBody?.data?.dailyHours ?? []) as Array<{ dayOfWeek: string; isEnabled: boolean }>).find(
  266 |       (row) => row.dayOfWeek === 'monday',
  267 |     );
  268 |     expect(monday?.isEnabled).toBe(true);
  269 |   });
  270 | 
  271 |   test('OWN-SM-23: owner can fetch permissions and manage employee status', async ({ roleRequests, seed }) => {
  272 |     const permissionsResponse = await roleRequests.store_owner.get('/api/v1/store-owner/permissions');
  273 |     expect(permissionsResponse.status()).toBe(200);
  274 | 
  275 |     const permissionsBody = await permissionsResponse.json();
  276 |     const permissionIds = ((permissionsBody?.data?.permissions ?? []) as Array<{ id: number }>)
  277 |       .slice(0, 2)
  278 |       .map((item) => item.id);
  279 | 
  280 |     const email = `pw-staff-${seed.runId}@example.test`;
  281 |     const createResponse = await roleRequests.store_owner.post('/api/v1/store-owner/employees', {
  282 |       data: {
  283 |         name: 'PW Staff Member',
  284 |         email,
  285 |         phone: '+963955000333',
  286 |         isActive: true,
  287 |         permissionIds,
  288 |       },
  289 |     });
  290 |     expect(createResponse.status()).toBe(201);
  291 | 
  292 |     const createBody = await createResponse.json();
  293 |     const staffId = Number(createBody?.data?.id);
```