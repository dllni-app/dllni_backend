# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: user-api.spec.ts >> Supermarket User API (Playwright request contexts) >> USR-SM-07/08: user can fetch product details and compare alternatives
- Location: tests\playwright\supermarket\user-api.spec.ts:69:3

# Error details

```
Error: expect(received).toContain(expected) // indexOf

Expected value: 443
Received array: []
```

# Test source

```ts
  1   | import { expect, test } from './fixtures';
  2   | 
  3   | test.describe('Supermarket User API (Playwright request contexts)', () => {
  4   |   test('USR-SM-01: guest can fetch featured offers', async ({ roleRequests }) => {
  5   |     const response = await roleRequests.guest.get('/api/v1/user/supermarket/home/featured-offers');
  6   |     expect(response.status()).toBe(200);
  7   | 
  8   |     const body = await response.json();
  9   |     expect(Array.isArray(body?.offers)).toBeTruthy();
  10  |   });
  11  | 
  12  |   test('USR-SM-02: guest can fetch nearby stores', async ({ roleRequests }) => {
  13  |     const response = await roleRequests.guest.get('/api/v1/user/supermarket/home/nearby-stores', {
  14  |       params: {
  15  |         limit: '10',
  16  |       },
  17  |     });
  18  | 
  19  |     expect(response.status()).toBe(200);
  20  |     const body = await response.json();
  21  |     expect(Array.isArray(body?.stores)).toBeTruthy();
  22  |   });
  23  | 
  24  |   test('USR-SM-03/04: guest can browse stores and search by text', async ({ roleRequests, seed }) => {
  25  |     const browseResponse = await roleRequests.guest.get('/api/v1/user/supermarket/stores', {
  26  |       params: {
  27  |         page: '1',
  28  |         perPage: '20',
  29  |       },
  30  |     });
  31  |     expect(browseResponse.status()).toBe(200);
  32  |     const browseBody = await browseResponse.json();
  33  |     expect(Array.isArray(browseBody?.data)).toBeTruthy();
  34  |     expect(browseBody?.meta).toBeDefined();
  35  | 
  36  |     const searchResponse = await roleRequests.guest.get('/api/v1/user/supermarket/stores', {
  37  |       params: {
  38  |         search: 'PW Supermarket Store',
  39  |         page: '1',
  40  |         perPage: '20',
  41  |       },
  42  |     });
  43  |     expect(searchResponse.status()).toBe(200);
  44  |     const searchBody = await searchResponse.json();
  45  |     const storeIds = ((searchBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  46  |     expect(storeIds).toContain(seed.fixtures.store.owned);
  47  |   });
  48  | 
  49  |   test('USR-SM-06: guest product search responds with catalog contract', async ({
  50  |     roleRequests,
  51  |     seed,
  52  |   }) => {
  53  |     const response = await roleRequests.guest.get('/api/v1/user/supermarket/products/search', {
  54  |       params: {
  55  |         search: 'PW Available Milk',
  56  |         page: '1',
  57  |         perPage: '20',
  58  |       },
  59  |     });
  60  | 
  61  |     expect(response.status()).toBe(200);
  62  |     const body = await response.json();
  63  |     const ids = ((body?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  64  |     expect(ids).not.toContain(seed.fixtures.products.unavailable);
  65  |     expect(Array.isArray(body?.data)).toBeTruthy();
  66  |     expect(body?.meta).toBeDefined();
  67  |   });
  68  | 
  69  |   test('USR-SM-07/08: user can fetch product details and compare alternatives', async ({
  70  |     roleRequests,
  71  |     seed,
  72  |   }) => {
  73  |     const productId = seed.fixtures.products.available;
  74  | 
  75  |     const showResponse = await roleRequests.user.get(`/api/v1/user/supermarket/products/${productId}`);
  76  |     expect(showResponse.status()).toBe(200);
  77  |     const showBody = await showResponse.json();
  78  |     expect(Number(showBody?.data?.id)).toBe(productId);
  79  |     expect(showBody?.data?.store).toBeDefined();
  80  |     expect(showBody?.data?.finalPrice).toBeDefined();
  81  | 
  82  |     const compareResponse = await roleRequests.user.get(`/api/v1/user/supermarket/products/${productId}/compare`, {
  83  |       params: {
  84  |         page: '1',
  85  |         perPage: '20',
  86  |       },
  87  |     });
  88  |     expect(compareResponse.status()).toBe(200);
  89  |     const compareBody = await compareResponse.json();
  90  |     const comparedIds = ((compareBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
> 91  |     expect(comparedIds).toContain(seed.fixtures.products.similar);
      |                         ^ Error: expect(received).toContain(expected) // indexOf
  92  |     expect(comparedIds).not.toContain(productId);
  93  |   });
  94  | 
  95  |   test('USR-SM-09/10: user can favorite and unfavorite a store', async ({ roleRequests, seed }) => {
  96  |     const storeId = seed.fixtures.store.owned;
  97  | 
  98  |     const addResponse = await roleRequests.user.post(`/api/v1/user/favorites/supermarket/stores/${storeId}`);
  99  |     expect([200, 201]).toContain(addResponse.status());
  100 | 
  101 |     const listAfterAdd = await roleRequests.user.get('/api/v1/user/favorites/supermarket/stores');
  102 |     expect(listAfterAdd.status()).toBe(200);
  103 |     const listAfterAddBody = await listAfterAdd.json();
  104 |     const addedIds = ((listAfterAddBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  105 |     expect(addedIds).toContain(storeId);
  106 | 
  107 |     const removeResponse = await roleRequests.user.delete(`/api/v1/user/favorites/supermarket/stores/${storeId}`);
  108 |     expect([200, 204]).toContain(removeResponse.status());
  109 | 
  110 |     const listAfterRemove = await roleRequests.user.get('/api/v1/user/favorites/supermarket/stores');
  111 |     expect(listAfterRemove.status()).toBe(200);
  112 |     const listAfterRemoveBody = await listAfterRemove.json();
  113 |     const removedIds = ((listAfterRemoveBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  114 |     expect(removedIds).not.toContain(storeId);
  115 |   });
  116 | 
  117 |   test('USR-SM-11/12: user can favorite and unfavorite a product', async ({ roleRequests, seed }) => {
  118 |     const productId = seed.fixtures.products.available;
  119 | 
  120 |     const addResponse = await roleRequests.user.post(`/api/v1/user/favorites/supermarket/products/${productId}`);
  121 |     expect([200, 201]).toContain(addResponse.status());
  122 | 
  123 |     const listAfterAdd = await roleRequests.user.get('/api/v1/user/favorites/supermarket/products');
  124 |     expect(listAfterAdd.status()).toBe(200);
  125 |     const listAfterAddBody = await listAfterAdd.json();
  126 |     const addedIds = ((listAfterAddBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  127 |     expect(addedIds).toContain(productId);
  128 | 
  129 |     const removeResponse = await roleRequests.user.delete(`/api/v1/user/favorites/supermarket/products/${productId}`);
  130 |     expect([200, 204]).toContain(removeResponse.status());
  131 | 
  132 |     const listAfterRemove = await roleRequests.user.get('/api/v1/user/favorites/supermarket/products');
  133 |     expect(listAfterRemove.status()).toBe(200);
  134 |     const listAfterRemoveBody = await listAfterRemove.json();
  135 |     const removedIds = ((listAfterRemoveBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
  136 |     expect(removedIds).not.toContain(productId);
  137 |   });
  138 | 
  139 |   test('USR-SM-13: user cart show returns empty list on fresh seed', async ({ roleRequests }) => {
  140 |     const showResponse = await roleRequests.user.get('/api/v1/user/supermarket/cart');
  141 |     expect(showResponse.status()).toBe(200);
  142 | 
  143 |     const showBody = await showResponse.json();
  144 |     const items = (showBody?.data?.items ?? []) as unknown[];
  145 |     expect(items).toHaveLength(0);
  146 |   });
  147 | 
  148 |   test('USR-SM-14/15/16: cart add, update, and delete lifecycle works', async ({ roleRequests, seed }) => {
  149 |     const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
  150 |       data: {
  151 |         productId: seed.fixtures.products.available,
  152 |         quantity: 2,
  153 |       },
  154 |     });
  155 |     expect([200, 201]).toContain(addResponse.status());
  156 | 
  157 |     const addBody = await addResponse.json();
  158 |     const itemId = Number(addBody?.data?.items?.[0]?.id);
  159 |     expect(itemId).toBeGreaterThan(0);
  160 | 
  161 |     const updateResponse = await roleRequests.user.patch(`/api/v1/user/supermarket/cart/items/${itemId}`, {
  162 |       data: { quantity: 3 },
  163 |     });
  164 |     expect(updateResponse.status()).toBe(200);
  165 |     const updateBody = await updateResponse.json();
  166 |     expect(Number(updateBody?.data?.items?.[0]?.quantity)).toBe(3);
  167 | 
  168 |     const deleteResponse = await roleRequests.user.delete(`/api/v1/user/supermarket/cart/items/${itemId}`);
  169 |     expect([200, 204]).toContain(deleteResponse.status());
  170 | 
  171 |     const showResponse = await roleRequests.user.get('/api/v1/user/supermarket/cart');
  172 |     expect(showResponse.status()).toBe(200);
  173 |     const showBody = await showResponse.json();
  174 |     const items = (showBody?.data?.items ?? []) as unknown[];
  175 |     expect(items).toHaveLength(0);
  176 |   });
  177 | 
  178 |   test('USR-SM-19/20: user can place an order and fetch tracking payload', async ({ roleRequests, seed }) => {
  179 |     const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
  180 |       data: {
  181 |         productId: seed.fixtures.products.available,
  182 |         quantity: 1,
  183 |       },
  184 |     });
  185 |     expect([200, 201]).toContain(addResponse.status());
  186 | 
  187 |     const orderResponse = await roleRequests.user.post('/api/v1/user/supermarket/orders', {
  188 |       data: {
  189 |         fulfillmentType: 'delivery',
  190 |         receiveMode: 'immediate',
  191 |         note: 'Playwright API-first order',
```