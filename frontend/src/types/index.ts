export type Role = 'owner' | 'admin' | 'seo_manager' | 'editor' | 'viewer';

export type Platform = 'wordpress' | 'shopify' | 'custom';

export type ConnectionStatus = 'connected' | 'disconnected' | 'pending' | 'failed';

export type VerificationStatus = 'unverified' | 'pending' | 'verified' | 'failed';

export interface User {
  id: number;
  name: string;
  email: string;
}

export interface Organization {
  id: number;
  name: string;
  slug: string;
  billing_plan: string;
  role?: Role;
  websites_count?: number;
  members_count?: number;
  created_at: string;
}

export interface OrganizationMember {
  id: number;
  user_id: number;
  name: string;
  email: string;
  role: Role;
  joined_at: string;
}

export interface Website {
  id: number;
  name: string;
  domain: string;
  target_url: string;
  platform: Platform;
  timezone: string;
  preferred_language: string;
  target_country: string;
  connection_status: ConnectionStatus;
  verification_status: VerificationStatus;
  verified_at?: string | null;
  last_crawled_at?: string | null;
  last_audited_at?: string | null;
  health_score: number;
  settings?: Record<string, unknown>;
  is_archived: boolean;
  created_at: string;
  updated_at: string;
  verification?: {
    token: string;
    status: VerificationStatus;
    meta_tag: string;
    dns_txt: string;
    file_token: {
      filename: string;
      content: string;
    };
  };
}

export interface WebsiteIntegration {
  id: number;
  website_id: number;
  provider: 'wordpress_rest' | 'shopify_graphql' | 'gsc_oauth';
  status: ConnectionStatus;
  last_error?: string | null;
  last_synced_at?: string | null;
  metadata?: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

export interface AuditLog {
  id: number;
  action: string;
  user?: {
    id: number;
    name: string;
    email: string;
  } | null;
  auditable_type?: string | null;
  auditable_id?: number | null;
  old_values?: Record<string, unknown> | null;
  new_values?: Record<string, unknown> | null;
  ip_address?: string | null;
  created_at: string;
}

export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  meta?: {
    current_page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
  };
  error?: {
    code: string;
    message: string;
    details?: Record<string, string[]>;
  };
  timestamp?: string;
}
