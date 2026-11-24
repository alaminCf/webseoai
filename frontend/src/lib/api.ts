import { ApiResponse } from '@/types';

export function getApiBaseUrl(): string {
  if (process.env.NEXT_PUBLIC_API_URL) {
    return process.env.NEXT_PUBLIC_API_URL;
  }
  if (typeof window !== 'undefined' && !['localhost', '127.0.0.1'].includes(window.location.hostname)) {
    return '/api/v1';
  }
  return 'http://localhost:8001/api/v1';
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = document.cookie.match(new RegExp(`(?:^|; )${escaped}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

function writePersistentCookie(name: string, value: string | null) {
  if (typeof document === 'undefined') return;
  const secure = typeof window !== 'undefined' && window.location.protocol === 'https:' ? '; Secure' : '';
  if (value) {
    // 365-day persistent cookie backup so login stays saved across browser restarts until explicit logout
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000; SameSite=Lax${secure}`;
  } else {
    document.cookie = `${name}=; path=/; max-age=0; SameSite=Lax${secure}`;
  }
}

class ApiClient {
  private token: string | null = null;
  private organizationId: number | null = null;

  constructor() {
    if (typeof window !== 'undefined') {
      const lsToken = localStorage.getItem('web_seo_token');
      const cookieToken = readCookie('web_seo_token');
      this.token = lsToken || cookieToken;
      if (this.token && !lsToken) {
        try {
          localStorage.setItem('web_seo_token', this.token);
        } catch {
          // ignore storage restrictions
        }
      }

      const savedOrg = localStorage.getItem('web_seo_current_org') || readCookie('web_seo_current_org');
      if (savedOrg) {
        const parsed = parseInt(savedOrg, 10);
        if (!Number.isNaN(parsed)) {
          this.organizationId = parsed;
        }
      }
    }
  }

  public setToken(token: string | null) {
    this.token = token;
    if (typeof window !== 'undefined') {
      try {
        if (token) {
          localStorage.setItem('web_seo_token', token);
        } else {
          localStorage.removeItem('web_seo_token');
        }
      } catch {
        // ignore storage restrictions
      }
      writePersistentCookie('web_seo_token', token);
    }
  }

  public getToken(): string | null {
    if (!this.token && typeof window !== 'undefined') {
      this.token = localStorage.getItem('web_seo_token') || readCookie('web_seo_token');
    }
    return this.token;
  }

  public setOrganizationId(orgId: number | null) {
    this.organizationId = orgId;
    if (typeof window !== 'undefined') {
      try {
        if (orgId) {
          localStorage.setItem('web_seo_current_org', orgId.toString());
        } else {
          localStorage.removeItem('web_seo_current_org');
        }
      } catch {
        // ignore storage restrictions
      }
      writePersistentCookie('web_seo_current_org', orgId ? orgId.toString() : null);
    }
  }

  public getOrganizationId(): number | null {
    if (!this.organizationId && typeof window !== 'undefined') {
      const saved = localStorage.getItem('web_seo_current_org') || readCookie('web_seo_current_org');
      if (saved) {
        const parsed = parseInt(saved, 10);
        if (!Number.isNaN(parsed)) {
          this.organizationId = parsed;
        }
      }
    }
    return this.organizationId;
  }

  private getHeaders(): HeadersInit {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    const token = this.getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const orgId = this.getOrganizationId();
    if (orgId) {
      headers['X-Organization-Id'] = orgId.toString();
    }

    return headers;
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
    const url = `${getApiBaseUrl()}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;

    const headers = {
      ...this.getHeaders(),
      ...(options.headers as Record<string, string>),
    };

    const isIdempotent = !options.method || options.method === 'GET';
    const maxAttempts = isIdempotent ? 3 : 1;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      try {
        const res = await fetch(url, {
          ...options,
          headers,
        });

        // Retry on transient serverless/DB cold-start 502/503/504 for GET requests
        if (isIdempotent && attempt < maxAttempts && [502, 503, 504].includes(res.status)) {
          await new Promise((r) => setTimeout(r, attempt * 900));
          continue;
        }

        const data: ApiResponse<T> = await res.json().catch(() => ({
          success: false,
          error: {
            code: res.status === 401 ? 'UNAUTHENTICATED' : 'NETWORK_OR_PARSING_ERROR',
            message: `Server returned HTTP ${res.status} with invalid JSON.`,
          },
        }));

        if (!data.success && !data.error?.code && res.status === 401) {
          data.error = {
            code: 'UNAUTHENTICATED',
            message: data.error?.message || 'Session expired. Please sign in again.',
          };
        }

        return data;
      } catch (err: unknown) {
        if (attempt < maxAttempts) {
          await new Promise((r) => setTimeout(r, attempt * 900));
          continue;
        }
        return {
          success: false,
          error: {
            code: 'FETCH_FAILED',
            message: err instanceof Error ? err.message : 'Could not connect to the backend server.',
          },
        };
      }
    }

    return {
      success: false,
      error: {
        code: 'FETCH_FAILED',
        message: 'Could not connect to the backend server.',
      },
    };
  }

  public get<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'GET' });
  }

  public post<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  public put<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  public patch<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      method: 'PATCH',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  public delete<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { method: 'DELETE' });
  }

  /**
   * Download an authenticated binary/text file (e.g. CSV export) directly from the backend API.
   */
  public async downloadFile(endpoint: string, fallbackFilename: string): Promise<{ ok: boolean; filename?: string; error?: string }> {
    const url = `${getApiBaseUrl()}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
    const headers: Record<string, string> = {
      Accept: 'text/csv, application/octet-stream, application/json',
    };
    const token = this.getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const orgId = this.getOrganizationId();
    if (orgId) headers['X-Organization-Id'] = orgId.toString();

    try {
      const res = await fetch(url, { method: 'GET', headers });
      if (!res.ok) {
        const errJson = await res.json().catch(() => null);
        return {
          ok: false,
          error: errJson?.error?.message || errJson?.message || `Download failed (HTTP ${res.status}).`,
        };
      }

      const blob = await res.blob();
      const disp = res.headers.get('content-disposition') || '';
      const match = disp.match(/filename="?([^"]+)"?/i);
      const filename = match?.[1] || fallbackFilename;

      const blobUrl = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => window.URL.revokeObjectURL(blobUrl), 1500);

      return { ok: true, filename };
    } catch (err: unknown) {
      return {
        ok: false,
        error: err instanceof Error ? err.message : 'Network error downloading report.',
      };
    }
  }
}

export const api = new ApiClient();
