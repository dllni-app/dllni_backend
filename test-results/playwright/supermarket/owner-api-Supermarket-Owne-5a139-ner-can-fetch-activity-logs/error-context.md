# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: owner-api.spec.ts >> Supermarket Owner API (Playwright request contexts) >> OWN-SM-23: owner can fetch activity logs
- Location: tests\playwright\supermarket\owner-api.spec.ts:316:3

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 200
Received: 400
```

# Test source

```ts
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
  294 |     expect(staffId).toBeGreaterThan(0);
  295 |     expect(createBody?.data?.user?.email).toBe(email);
  296 | 
  297 |     const statusResponse = await roleRequests.store_owner.patch(`/api/v1/store-owner/employees/${staffId}/status`, {
  298 |       data: {
  299 |         isActive: false,
  300 |       },
  301 |     });
  302 |     expect(statusResponse.status()).toBe(200);
  303 | 
  304 |     const statusBody = await statusResponse.json();
  305 |     expect(statusBody?.data?.isActive).toBe(false);
  306 | 
  307 |     const listResponse = await roleRequests.store_owner.get('/api/v1/store-owner/employees');
  308 |     expect(listResponse.status()).toBe(200);
  309 |     const listBody = await listResponse.json();
  310 |     const employeeEmails = ((listBody?.data?.employees ?? []) as Array<{ user?: { email?: string } }>).map(
  311 |       (item) => item.user?.email,
  312 |     );
  313 |     expect(employeeEmails).toContain(email);
  314 |   });
  315 | 
  316 |   test('OWN-SM-23: owner can fetch activity logs', async ({ roleRequests, seed }) => {
  317 |     const productId = seed.fixtures.products.available;
  318 |     const stockUpdateResponse = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
  319 |       data: {
  320 |         quantity: 17,
  321 |         operation: 'SET',
  322 |       },
  323 |     });
> 324 |     expect(stockUpdateResponse.status()).toBe(200);
      |                                          ^ Error: expect(received).toBe(expected) // Object.is equality
  325 | 
  326 |     const logsResponse = await roleRequests.store_owner.get('/api/v1/store-owner/activity-logs', {
  327 |       params: {
  328 |         logName: 'inventory',
  329 |         perPage: '10',
  330 |       },
  331 |     });
  332 |     expect(logsResponse.status()).toBe(200);
  333 | 
  334 |     const logsBody = await logsResponse.json();
  335 |     expect(Array.isArray(logsBody?.data)).toBeTruthy();
  336 |     expect((logsBody?.data ?? []).length).toBeGreaterThan(0);
  337 |   });
  338 | 
  339 |   test('OWN-SM-24: wrong role is blocked from owner endpoints', async ({ roleRequests }) => {
  340 |     const response = await roleRequests.wrong_role.get('/api/v1/store-owner/dashboard');
  341 |     expect(response.status()).toBe(403);
  342 |   });
  343 | 
  344 |   test('OWN-SM-24: missing token is rejected for owner endpoints', async ({ roleRequests }) => {
  345 |     const response = await roleRequests.guest.get('/api/v1/store-owner/dashboard');
  346 |     expect(response.status()).toBe(401);
  347 |   });
  348 | });
  349 | 
```