import { APIRequestContext, APIResponse } from '@playwright/test';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

type QueryParams = Record<string, unknown>;
type Headers = Record<string, string>;

export type ApiCallOptions = {
  params?: QueryParams;
  data?: unknown;
  headers?: Headers;
};

export type ApiCallResult<T = unknown> = {
  response: APIResponse;
  status: number;
  ok: boolean;
  url: string;
  text: string;
  body: T | null;
};

export async function callApi<T = unknown>(
  context: APIRequestContext,
  method: HttpMethod,
  url: string,
  options: ApiCallOptions = {},
): Promise<ApiCallResult<T>> {
  const requestOptions = {
    params: options.params,
    data: options.data,
    headers: options.headers,
  };

  let response: APIResponse;

  switch (method) {
    case 'GET':
      response = await context.get(url, requestOptions);
      break;
    case 'POST':
      response = await context.post(url, requestOptions);
      break;
    case 'PUT':
      response = await context.put(url, requestOptions);
      break;
    case 'PATCH':
      response = await context.patch(url, requestOptions);
      break;
    case 'DELETE':
      response = await context.delete(url, requestOptions);
      break;
    default:
      throw new Error(`Unsupported method: ${String(method)}`);
  }

  const text = await response.text();
  let body: T | null = null;

  if (text.trim().length > 0) {
    try {
      body = JSON.parse(text) as T;
    } catch {
      body = null;
    }
  }

  return {
    response,
    status: response.status(),
    ok: response.ok(),
    url,
    text,
    body,
  };
}

export function expectStatus(result: ApiCallResult, allowed: number[], label: string): void {
  if (allowed.includes(result.status)) {
    return;
  }

  throw new Error(
    `${label}: expected status in [${allowed.join(', ')}], got ${result.status}. Response body: ${result.text}`,
  );
}

export function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
}

export function dataArray(body: unknown): Record<string, unknown>[] {
  const root = asRecord(body);
  if (!root) {
    return [];
  }

  const data = root.data;
  if (!Array.isArray(data)) {
    return [];
  }

  return data.filter((item): item is Record<string, unknown> => !!asRecord(item));
}

export function dataObject(body: unknown): Record<string, unknown> | null {
  const root = asRecord(body);
  if (!root) {
    return null;
  }

  return asRecord(root.data);
}

export function firstNumericId(items: Record<string, unknown>[], key = 'id'): number | null {
  for (const item of items) {
    const value = item[key];
    if (typeof value === 'number' && Number.isFinite(value)) {
      return value;
    }

    if (typeof value === 'string') {
      const parsed = Number.parseInt(value, 10);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }
  }

  return null;
}

export function numericFromPath(value: unknown, path: string): number | null {
  const segments = path.split('.').filter(Boolean);
  let current: unknown = value;

  for (const segment of segments) {
    if (Array.isArray(current)) {
      const index = Number.parseInt(segment, 10);
      if (!Number.isFinite(index) || index < 0 || index >= current.length) {
        return null;
      }
      current = current[index];
      continue;
    }

    const obj = asRecord(current);
    if (!obj || !(segment in obj)) {
      return null;
    }

    current = obj[segment];
  }

  if (typeof current === 'number' && Number.isFinite(current)) {
    return current;
  }

  if (typeof current === 'string') {
    const parsed = Number.parseInt(current, 10);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
}

export function extractOrderId(body: unknown): number | null {
  const direct = numericFromPath(body, 'data.id');
  if (direct !== null) {
    return direct;
  }

  const nested = numericFromPath(body, 'data.order.id');
  if (nested !== null) {
    return nested;
  }

  const listFirst = numericFromPath(body, 'data.0.id');
  if (listFirst !== null) {
    return listFirst;
  }

  return null;
}

export function pickStatusLabel(body: unknown): string | null {
  const fromStatus = pickStringByPaths(body, ['data.status', 'data.order.status']);
  if (fromStatus) {
    return fromStatus;
  }

  return pickStringByPaths(body, ['status', 'message']);
}

export function pickStringByPaths(value: unknown, paths: string[]): string | null {
  for (const path of paths) {
    const current = valueAtPath(value, path);
    if (typeof current === 'string' && current.trim().length > 0) {
      return current;
    }
  }

  return null;
}

export function valueAtPath(value: unknown, path: string): unknown {
  const segments = path.split('.').filter(Boolean);
  let current: unknown = value;

  for (const segment of segments) {
    if (Array.isArray(current)) {
      const index = Number.parseInt(segment, 10);
      if (!Number.isFinite(index) || index < 0 || index >= current.length) {
        return undefined;
      }
      current = current[index];
      continue;
    }

    const obj = asRecord(current);
    if (!obj) {
      return undefined;
    }

    current = obj[segment];
  }

  return current;
}
