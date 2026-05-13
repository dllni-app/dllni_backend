import { APIRequestContext, APIResponse } from '@playwright/test';

export type CleaningOrderPayload = {
  propertyType: string;
  propertyDetails: {
    address: string;
    location_name: string;
    rooms: number;
    bedrooms: number;
    bathrooms: number;
    kitchens?: number;
    living_room_size: 'small' | 'medium' | 'large';
  };
  scheduledDate: string;
  scheduledTime: string;
  addressLatitude: number;
  addressLongitude: number;
  preferredWorkerId?: number | null;
  termsAccepted: boolean;
};

export type ApiCallResult<TBody = unknown> = {
  response: APIResponse;
  body: TBody;
};

export function futureDate(daysAhead = 1): string {
  const value = new Date();
  value.setHours(12, 0, 0, 0);
  value.setDate(value.getDate() + daysAhead);
  return value.toISOString().slice(0, 10);
}

export function buildEstimatePayload(): Record<string, unknown> {
  return {
    propertyType: 'apartment',
    propertyDetails: {
      rooms: 3,
      bedrooms: 2,
      bathrooms: 1,
      living_room_size: 'medium',
    },
    addressLatitude: 33.5138,
    addressLongitude: 36.2765,
  };
}

export function buildCreateOrderPayload(
  runId: string,
  overrides: Partial<CleaningOrderPayload> = {},
): CleaningOrderPayload {
  const suffix = `${Date.now()}-${Math.floor(Math.random() * 10_000)}`;

  return {
    propertyType: 'apartment',
    propertyDetails: {
      address: `Playwright cleaning address ${runId} ${suffix}`,
      location_name: `Playwright Home ${runId}`,
      rooms: 3,
      bedrooms: 2,
      bathrooms: 1,
      kitchens: 1,
      living_room_size: 'medium',
      ...overrides.propertyDetails,
    },
    scheduledDate: overrides.scheduledDate ?? futureDate(2),
    scheduledTime: overrides.scheduledTime ?? '10:00',
    addressLatitude: overrides.addressLatitude ?? 33.5138,
    addressLongitude: overrides.addressLongitude ?? 36.2765,
    preferredWorkerId:
      overrides.preferredWorkerId !== undefined ? overrides.preferredWorkerId : null,
    termsAccepted: overrides.termsAccepted ?? true,
    ...overrides,
  };
}

export function extractBooking(body: unknown): Record<string, unknown> | null {
  if (!body || typeof body !== 'object') {
    return null;
  }

  const candidate = body as Record<string, unknown>;
  if (candidate.order && typeof candidate.order === 'object') {
    return candidate.order as Record<string, unknown>;
  }
  if (candidate.data && typeof candidate.data === 'object') {
    return candidate.data as Record<string, unknown>;
  }

  return null;
}

export function bookingStatus(body: unknown): string | undefined {
  const booking = extractBooking(body);
  if (!booking) {
    return undefined;
  }

  const status = booking.status;
  return typeof status === 'string' ? status : undefined;
}

async function parseJson(response: APIResponse): Promise<unknown> {
  const raw = await response.text();
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return { raw };
  }
}

export class CleaningApiClient {
  public constructor(private readonly request: APIRequestContext) {}

  public async estimatePrice(payload = buildEstimatePayload()): Promise<ApiCallResult> {
    return this.post('/api/v1/user/cleaning/orders/estimate-price', payload);
  }

  public async estimateSize(payload = buildEstimatePayload()): Promise<ApiCallResult> {
    return this.post('/api/v1/user/cleaning/orders/estimate-size', payload);
  }

  public async createOrder(
    runId: string,
    overrides: Partial<CleaningOrderPayload> = {},
  ): Promise<ApiCallResult> {
    const payload = buildCreateOrderPayload(runId, overrides);
    return this.post('/api/v1/user/cleaning/orders', payload);
  }

  public async listUserOrders(
    params: Record<string, string> = { perPage: '50' },
  ): Promise<ApiCallResult> {
    return this.get('/api/v1/user/cleaning/orders', params);
  }

  public async showUserOrder(orderId: number): Promise<ApiCallResult> {
    return this.get(`/api/v1/user/cleaning/orders/${orderId}`);
  }

  public async patchUserOrder(orderId: number, data: Record<string, unknown>): Promise<ApiCallResult> {
    return this.patch(`/api/v1/user/cleaning/orders/${orderId}`, data);
  }

  public async cancelUserOrder(orderId: number, reason?: string): Promise<ApiCallResult> {
    return this.post(`/api/v1/user/cleaning/orders/${orderId}/cancel`, reason ? { reason } : {});
  }

  public async confirmStartVerification(orderId: number, code: string): Promise<ApiCallResult> {
    return this.post(`/api/v1/user/cleaning/orders/${orderId}/start-verification/confirm`, { code });
  }

  public async confirmCompletion(orderId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/user/cleaning/orders/${orderId}/completion/confirm`, {});
  }

  public async rejectCompletion(orderId: number, reason?: string): Promise<ApiCallResult> {
    return this.post(
      `/api/v1/user/cleaning/orders/${orderId}/completion/reject`,
      reason ? { reason } : {},
    );
  }

  public async requestCompletionExtension(
    orderId: number,
    additionalMinutes?: number,
  ): Promise<ApiCallResult> {
    return this.post(
      `/api/v1/user/cleaning/orders/${orderId}/completion/extend-time`,
      additionalMinutes ? { additionalMinutes } : {},
    );
  }

  public async previousWorkers(): Promise<ApiCallResult> {
    return this.get('/api/v1/user/cleaning/orders/previous-workers');
  }

  public async postReview(orderId: number, payload: Record<string, unknown>): Promise<ApiCallResult> {
    return this.post(`/api/v1/user/cleaning/orders/${orderId}/review`, payload);
  }

  public async listCleaningBookings(
    params: Record<string, string> = { perPage: '50' },
  ): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning-bookings', params);
  }

  public async showCleaningBooking(bookingId: number): Promise<ApiCallResult> {
    return this.get(`/api/v1/cleaning-bookings/${bookingId}`);
  }

  public async acceptBooking(bookingId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/accept`, {});
  }

  public async rejectBooking(bookingId: number, reason?: string): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/reject`, reason ? { reason } : {});
  }

  public async startTravel(bookingId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/start-travel`, {});
  }

  public async postLocation(
    bookingId: number,
    latitude = 33.5138,
    longitude = 36.2765,
  ): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/location`, {
      latitude,
      longitude,
    });
  }

  public async arrive(bookingId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/arrive`, {});
  }

  public async securityCode(bookingId: number): Promise<ApiCallResult> {
    return this.get(`/api/v1/cleaning-bookings/${bookingId}/security-code`);
  }

  public async startWork(bookingId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/start-work`, {});
  }

  public async complete(bookingId: number): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/complete`, {});
  }

  public async cancelBooking(bookingId: number, reason?: string): Promise<ApiCallResult> {
    return this.post(`/api/v1/cleaning-bookings/${bookingId}/cancel`, reason ? { reason } : {});
  }

  public async listTimeWarnings(
    params: Record<string, string> = { perPage: '50' },
  ): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning-time-warnings', params);
  }

  public async acceptTimeWarning(
    warningId: number,
    additionalMinutes?: number,
  ): Promise<ApiCallResult> {
    return this.post(
      `/api/v1/cleaning-time-warnings/${warningId}/accept`,
      additionalMinutes ? { additionalMinutes } : {},
    );
  }

  public async rejectTimeWarning(warningId: number, message?: string): Promise<ApiCallResult> {
    return this.post(
      `/api/v1/cleaning-time-warnings/${warningId}/reject`,
      message ? { message } : {},
    );
  }

  public async workerHomepage(): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning/worker/homepage');
  }

  public async workerProfile(): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning/worker/profile');
  }

  public async workerStatistics(): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning/worker/statistics');
  }

  public async updateWorkerProfile(payload: Record<string, unknown>): Promise<ApiCallResult> {
    return this.put('/api/v1/cleaning/worker/account/profile', payload);
  }

  public async getWorkerWorkAreas(): Promise<ApiCallResult> {
    return this.get('/api/v1/cleaning/worker/account/work-areas');
  }

  public async updateWorkerWorkAreas(payload: Record<string, unknown>): Promise<ApiCallResult> {
    return this.put('/api/v1/cleaning/worker/account/work-areas', payload);
  }

  public async updateWorkerAvailability(
    workerId: number,
    payload: Record<string, unknown>,
  ): Promise<ApiCallResult> {
    return this.put(`/api/v1/workers/${workerId}`, payload);
  }

  public async broadcastAuth(
    channelName: string,
    socketId = '9999.9999',
  ): Promise<ApiCallResult> {
    const response = await this.request.post('/broadcasting/auth', {
      form: {
        channel_name: channelName,
        socket_id: socketId,
      },
    });

    const body = await parseJson(response);
    return { response, body };
  }

  private async get(path: string, params?: Record<string, string>): Promise<ApiCallResult> {
    const response = await this.request.get(path, { params });
    return { response, body: await parseJson(response) };
  }

  private async post(
    path: string,
    data?: Record<string, unknown> | CleaningOrderPayload,
    params?: Record<string, string>,
  ): Promise<ApiCallResult> {
    const response = await this.request.post(path, { data, params });
    return { response, body: await parseJson(response) };
  }

  private async patch(path: string, data?: Record<string, unknown>): Promise<ApiCallResult> {
    const response = await this.request.patch(path, { data });
    return { response, body: await parseJson(response) };
  }

  private async put(path: string, data?: Record<string, unknown>): Promise<ApiCallResult> {
    const response = await this.request.put(path, { data });
    return { response, body: await parseJson(response) };
  }
}

export class CleaningFlowHarness {
  public readonly userApi: CleaningApiClient;
  public readonly workerApi: CleaningApiClient;

  public constructor(
    userRequest: APIRequestContext,
    workerRequest: APIRequestContext,
    private readonly runId: string,
  ) {
    this.userApi = new CleaningApiClient(userRequest);
    this.workerApi = new CleaningApiClient(workerRequest);
  }

  public async createPendingOrder(): Promise<{ orderId: number; body: unknown }> {
    const created = await this.userApi.createOrder(this.runId);
    const booking = extractBooking(created.body);

    if (!booking || typeof booking.id !== 'number') {
      throw new Error(`Failed to create order. Response: ${JSON.stringify(created.body)}`);
    }

    return { orderId: booking.id, body: created.body };
  }

  public async moveToAwaitingStartVerification(orderId: number): Promise<void> {
    await this.workerApi.acceptBooking(orderId);
    await this.workerApi.startTravel(orderId);
    await this.workerApi.postLocation(orderId);
    await this.workerApi.arrive(orderId);
  }

  public async moveToInProgress(orderId: number): Promise<string> {
    await this.moveToAwaitingStartVerification(orderId);
    const codeResult = await this.workerApi.securityCode(orderId);
    const code = (codeResult.body as Record<string, unknown> | null)?.data as
      | Record<string, unknown>
      | undefined;
    const securityCode = typeof code?.securityCode === 'string' ? code.securityCode : null;

    if (!securityCode) {
      throw new Error(`Could not issue security code. Response: ${JSON.stringify(codeResult.body)}`);
    }

    await this.userApi.confirmStartVerification(orderId, securityCode);
    return securityCode;
  }

  public async moveToAwaitingCustomerCompletion(orderId: number): Promise<void> {
    await this.moveToInProgress(orderId);
    await this.workerApi.complete(orderId);
  }
}
