# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: owner-api.spec.ts >> Supermarket Owner API (Playwright request contexts) >> OWN-SM-08: owner can reject a pending order with valid reason/type
- Location: tests\playwright\supermarket\owner-api.spec.ts:97:3

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 200
Received: 400
```

# Test source

```ts
  8   |   productId: number,
  9   | ): Promise<number> {
  10  |   const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
  11  |     data: {
  12  |       productId,
  13  |       quantity: 1,
  14  |     },
  15  |   });
  16  |   expect([200, 201]).toContain(addResponse.status());
  17  | 
  18  |   const orderResponse = await roleRequests.user.post('/api/v1/user/supermarket/orders', {
  19  |     data: {
  20  |       fulfillmentType: 'delivery',
  21  |       receiveMode: 'immediate',
  22  |       note: 'Owner flow pending order seed',
  23  |     },
  24  |   });
  25  |   expect([200, 201]).toContain(orderResponse.status());
  26  | 
  27  |   const orderBody = await orderResponse.json();
  28  |   const orderId = Number(orderBody?.data?.id);
  29  |   expect(orderId).toBeGreaterThan(0);
  30  | 
  31  |   return orderId;
  32  | }
  33  | 
  34  | test.describe('Supermarket Owner API (Playwright request contexts)', () => {
  35  |   test('OWN-SM-01: store owner dashboard returns KPI payload', async ({ roleRequests }) => {
  36  |     const response = await roleRequests.store_owner.get('/api/v1/store-owner/dashboard');
  37  |     expect(response.status()).toBe(200);
  38  | 
  39  |     const body = await response.json();
  40  |     expect(body?.data?.totalOrders).toBeDefined();
  41  |     expect(body?.data?.completedOrders).toBeDefined();
  42  |     expect(body?.data?.newOrders).toBeDefined();
  43  |     expect(body?.data?.pendingOrders).toBeDefined();
  44  |     expect(body?.data?.totalSales).toBeDefined();
  45  |   });
  46  | 
  47  |   test('OWN-SM-04: order queue is owner-scoped', async ({ roleRequests, seed }) => {
  48  |     const response = await roleRequests.store_owner.get('/api/v1/sm-orders', {
  49  |       params: { perPage: '50' },
  50  |     });
  51  |     expect(response.status()).toBe(200);
  52  | 
  53  |     const body = await response.json();
  54  |     const orderStoreIds = ((body?.data ?? []) as Array<{ storeId?: number; store_id?: number }>).map(
  55  |       (order) => Number(order.storeId ?? order.store_id),
  56  |     );
  57  | 
  58  |     expect(orderStoreIds.length).toBeGreaterThan(0);
  59  |     expect(orderStoreIds).toContain(seed.fixtures.store.owned);
  60  |     expect(orderStoreIds).not.toContain(seed.fixtures.store.other);
  61  |   });
  62  | 
  63  |   test('OWN-SM-05: owner can fetch order details for owned store order', async ({ roleRequests, seed }) => {
  64  |     const orderId = seed.fixtures.orders.tracking;
  65  | 
  66  |     const response = await roleRequests.store_owner.get(`/api/v1/sm-orders/${orderId}`);
  67  |     expect(response.status()).toBe(200);
  68  | 
  69  |     const body = await response.json();
  70  |     expect(Number(body?.data?.id)).toBe(orderId);
  71  |     expect(Array.isArray(body?.data?.items)).toBeTruthy();
  72  |   });
  73  | 
  74  |   test('OWN-SM-06: owner can accept a pending order', async ({ roleRequests, seed }) => {
  75  |     test.fail(true, 'Known backend regression: accept transition returns 400 for freshly pending orders in API mode.');
  76  |     const orderId = await createPendingOrderViaUserFlow(roleRequests, seed.fixtures.products.available);
  77  | 
  78  |     const acceptResponse = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/accept`);
  79  |     expect(acceptResponse.status()).toBe(200);
  80  | 
  81  |     const acceptBody = await acceptResponse.json();
  82  |     expect(acceptBody?.data?.status).toBe('accepted');
  83  | 
  84  |     const showResponse = await roleRequests.store_owner.get(`/api/v1/sm-orders/${orderId}`);
  85  |     expect(showResponse.status()).toBe(200);
  86  |     const showBody = await showResponse.json();
  87  |     expect(showBody?.data?.status).toBe('accepted');
  88  |   });
  89  | 
  90  |   test('OWN-SM-07: accept rejects non-pending order', async ({ roleRequests, seed }) => {
  91  |     const orderId = seed.fixtures.orders.non_ready;
  92  | 
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
> 108 |     expect(rejectResponse.status()).toBe(200);
      |                                     ^ Error: expect(received).toBe(expected) // Object.is equality
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
  193 |     expect(response.status()).toBe(200);
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
```