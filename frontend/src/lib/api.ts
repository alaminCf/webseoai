import { ApiResponse } from '@/types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8001/api/v1';

class ApiClient {
  private token: string | null = null;
  private organizationId: number | null = null;

  constructor() {
    if (typeof window !== 'undefined') {
      this.token = localStorage.getItem('web_seo_token');
      const savedOrg = localStorage.getItem('web_seo_current_org');
      if (savedOrg) {
        this.organizationId = parseInt(savedOrg, 10);
      }
    }
  }

  public setToken(token: string | null) {
    this.token = token;
    if (typeof window !== 'undefined') {
      if (token) {
        localStorage.setItem('web_seo_token', token);
      } else {
        localStorage.removeItem('web_seo_token');
      }
    }
  }

  public getToken(): string | null {
    if (!this.token && typeof window !== 'undefined') {
      this.token = localStorage.getItem('web_seo_token');
    }
    return this.token;
  }

  public setOrganizationId(orgId: number | null) {
    this.organizationId = orgId;
    if (typeof window !== 'undefined') {
      if (orgId) {
        localStorage.setItem('web_seo_current_org', orgId.toString());
      } else {
        localStorage.removeItem('web_seo_current_org');
      }
    }
  }

  public getOrganizationId(): number | null {
    if (!this.organizationId && typeof window !== 'undefined') {
      const saved = localStorage.getItem('web_seo_current_org');
      if (saved) {
        this.organizationId = parseInt(saved, 10);
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
    const url = `${API_BASE_URL}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;

    const headers = {
      ...this.getHeaders(),
      ...(options.headers as Record<string, string>),
    };

    try {
      const res = await fetch(url, {
        ...options,
        headers,
      });

      const data: ApiResponse<T> = await res.json().catch(() => ({
        success: false,
        error: {
          code: 'NETWORK_OR_PARSING_ERROR',
          message: `Server returned HTTP ${res.status} with invalid JSON.`,
        },
      }));

      return data;
    } catch (err: unknown) {
      return {
        success: false,
        error: {
          code: 'FETCH_FAILED',
          message: err instanceof Error ? err.message : 'Could not connect to the backend server.',
        },
      };
    }
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
}

export const api = new ApiClient();
