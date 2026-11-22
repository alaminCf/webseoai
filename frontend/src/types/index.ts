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
  provider: 'wordpress_rest' | 'shopify_graphql' | 'custom_webhook' | 'gsc_oauth';
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

export interface SiteChecksSummary {
  robots_txt?: {
    url?: string;
    exists: boolean;
    status_code?: number | null;
    blocks_all?: boolean;
    crawl_delay?: number | null;
    sitemaps_declared?: string[];
    disallow_count?: number;
  };
  sitemap?: {
    found: boolean;
    urls_count: number;
    sitemaps_checked?: string[];
    nested_sitemaps_count?: number;
  };
  https_enforcement?: {
    uses_https: boolean;
    http_redirects_to_https?: boolean | null;
    http_status?: number | null;
  };
  www_consistency?: {
    target_host: string;
    alternate_host: string;
    redirects_cleanly?: boolean | null;
    alternate_status?: number | null;
  };
  js_rendering_mode?: 'auto' | 'always' | 'never';
  headless_available?: boolean;
  spa_detected?: boolean;
  js_rendered_pages?: number;
  waf_blocked_pages?: number;
}

export interface PassedCheck {
  code: string;
  category: 'technical' | 'content' | 'links' | 'indexing';
  label: string;
  detail: string;
}

export interface AuditIssueGroup {
  issue_type: string;
  category: 'technical' | 'content' | 'links' | 'indexing';
  severity: 'critical' | 'warning' | 'info';
  count: number;
  sample_explanation: string;
}

export interface AuditSummaryStats {
  total_pages_crawled?: number;
  indexable_pages?: number;
  status_200_count?: number;
  status_3xx_count?: number;
  status_4xx_count?: number;
  status_5xx_count?: number;
  avg_load_time_ms?: number;
  avg_word_count?: number;
  js_rendered_pages?: number;
  spa_shell_pages?: number;
  waf_blocked_pages?: number;
  passed_checks_count?: number;
  passed_checks?: PassedCheck[];
  site_checks?: SiteChecksSummary;
  issue_groups?: AuditIssueGroup[];
}

export interface CrawlJob {
  id: number;
  website_id?: number;
  organization_id?: number;
  initiated_by?: number;
  status: 'queued' | 'crawling' | 'completed' | 'failed' | 'cancelled';
  max_pages: number;
  max_depth: number;
  concurrency?: number;
  render_js?: 'auto' | 'always' | 'never';
  pages_discovered: number;
  pages_crawled: number;
  pages_failed: number;
  site_checks?: SiteChecksSummary | null;
  started_at?: string | null;
  finished_at?: string | null;
  duration_seconds?: number | null;
  error_message?: string | null;
  logs?: Array<{ timestamp?: string; step?: string; message?: string; context?: Record<string, unknown> | null }>;
  created_at?: string;
}

export interface CrawledPage {
  id: number;
  crawl_job_id: number;
  website_id: number;
  url: string;
  final_url?: string | null;
  depth: number;
  status_code: number;
  load_time_ms: number;
  ttfb_ms?: number | null;
  html_size_bytes?: number;
  content_type?: string | null;
  page_title?: string | null;
  meta_description?: string | null;
  canonical_url?: string | null;
  viewport?: string | null;
  html_lang?: string | null;
  charset?: string | null;
  favicon_url?: string | null;
  open_graph?: {
    title?: string | null;
    description?: string | null;
    image?: string | null;
    type?: string | null;
    url?: string | null;
  } | null;
  twitter_card?: {
    card?: string | null;
    title?: string | null;
    description?: string | null;
    image?: string | null;
  } | null;
  structured_data?: {
    count: number;
    types: string[];
    invalid_blocks: number;
  } | null;
  hreflang_tags?: Array<{ lang: string; href: string }>;
  h1_count?: number;
  h1_tags?: string[];
  headings_tree?: Array<{ level: number; tag: string; text: string }>;
  meta_robots?: string | null;
  x_robots_tag?: string | null;
  meta_refresh?: string | null;
  is_indexable: boolean;
  in_sitemap: boolean;
  is_js_rendered?: boolean;
  is_spa_shell?: boolean;
  is_blocked_by_waf?: boolean;
  word_count?: number;
  images_count?: number;
  images_missing_alt?: number;
  images_without_dimensions?: number;
  images_missing_alt_samples?: string[];
  in_degree: number;
  internal_links_count?: number;
  external_links_count?: number;
  internal_links?: Array<{ url: string; text: string; rel?: string }>;
  external_links?: Array<{ url: string; text: string; rel?: string }>;
  redirect_url?: string | null;
  redirect_hops?: Array<{ from: string; status: number; to: string }>;
  security_headers?: {
    https?: boolean;
    hsts?: boolean;
    x_content_type_options?: boolean;
    x_frame_options?: boolean;
    csp?: boolean;
    referrer_policy?: boolean;
  } | null;
  mixed_content_urls?: string[];
  issues_count?: number;
  error?: string | null;
  created_at?: string;
}

export interface AuditRun {
  id: number;
  website_id: number;
  crawl_job_id: number;
  organization_id: number;
  overall_score: number;
  technical_score: number;
  content_score: number;
  link_score?: number;
  links_score: number;
  indexing_score?: number;
  critical_count: number;
  warning_count: number;
  info_count: number;
  total_issues?: number;
  total_pages_analyzed: number;
  summary_stats?: AuditSummaryStats | null;
  created_at: string;
  website?: Website;
}

export interface AuditIssue {
  id: number;
  audit_run_id: number;
  crawled_page_id?: number | null;
  issue_type: string;
  category: 'technical' | 'content' | 'links' | 'indexing';
  severity: 'critical' | 'warning' | 'info';
  affected_url: string;
  evidence?: Record<string, unknown> | null;
  explanation: string;
  recommended_fix: string;
  current_status: 'open' | 'in_progress' | 'resolved' | 'ignored';
  verification_status: 'unverified' | 'verified' | 'verified_fixed' | 'regression';
  first_seen_at: string;
  last_seen_at: string;
}

export interface AuditComparisonRunSummary {
  id: number;
  score: number;
  technical_score?: number;
  content_score?: number;
  indexing_score?: number;
  links_score?: number;
  critical_count: number;
  warning_count: number;
  info_count: number;
  date: string;
}

export interface AuditComparison {
  before?: AuditComparisonRunSummary;
  after?: AuditComparisonRunSummary;
  run1: AuditComparisonRunSummary;
  run2: AuditComparisonRunSummary;
  score_diff: number;
  resolved_issues_count: number;
  new_issues_count: number;
  resolved_issues: AuditIssue[];
  new_issues: AuditIssue[];
}

export interface ApiResponse<T = unknown> {
  success: boolean;
  message?: string;
  data?: T;
  meta?: {
    current_page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
    scoring_formula?: string;
  };
  error?: {
    code: string;
    message: string;
    details?: Record<string, string[]>;
  };
  timestamp?: string;
}

export interface WebsiteContextProfile {
  id: number;
  website_id: number;
  organization_id: number;
  business_name?: string | null;
  business_description?: string | null;
  target_audience?: string | null;
  products_and_services?: string | null;
  target_countries?: string[] | null;
  target_languages?: string[] | null;
  brand_voice?: string | null;
  business_goals?: string[] | null;
  competitors?: string[] | null;
  approved_at?: string | null;
  approved_by?: number | null;
  approver?: { id: number; name: string; email: string } | null;
  created_at: string;
  updated_at: string;
}

export interface AiRecommendation {
  id: number;
  website_id: number;
  audit_run_id?: number | null;
  audit_issue_id?: number | null;
  crawled_page_id?: number | null;
  title: string;
  category: 'technical' | 'content' | 'links' | 'architecture';
  affected_url: string;
  evidence?: Record<string, unknown> | null;
  recommended_action: string;
  reasoning_summary: string;
  expected_benefit: string;
  confidence_level: 'high' | 'medium' | 'low';
  effort: 'low' | 'medium' | 'high';
  risk_level: 'low' | 'medium' | 'high';
  required_permissions: string;
  verification_procedure: string;
  impact_score: number;
  confidence_score: number;
  effort_score: number;
  risk_score: number;
  priority_score: number;
  status: 'proposed' | 'accepted' | 'in_progress' | 'applied' | 'dismissed';
  created_at: string;
}

export interface ContentBrief {
  id: number;
  website_id: number;
  target_keyword: string;
  secondary_keywords?: string[];
  search_intent: 'informational' | 'commercial' | 'transactional' | 'navigational';
  target_audience?: string;
  content_type: string;
  suggested_title: string;
  suggested_meta_description: string;
  word_count_target: number;
  outline?: Array<{ heading: string; level: number; key_points: string[] }>;
  internal_link_suggestions?: Array<{ anchor: string; target_hint: string }>;
  faq_items?: Array<{ question: string; answer: string }>;
  status: 'draft' | 'in_review' | 'approved' | 'archived';
  approved_at?: string | null;
  creator?: { id: number; name: string };
  approver?: { id: number; name: string };
  created_at: string;
}

export interface ContentRevision {
  id: number;
  content_article_id: number;
  version: number;
  title: string;
  content_body: string;
  change_summary?: string;
  created_at: string;
  editor?: { id: number; name: string };
}

export interface ContentArticle {
  id: number;
  website_id: number;
  content_brief_id?: number | null;
  title: string;
  slug?: string;
  meta_description?: string;
  canonical_url?: string;
  content_body: string;
  word_count: number;
  search_intent: string;
  quality_score: number;
  quality_notes?: Array<{ type: string; severity: string; message: string }>;
  status: 'draft' | 'in_review' | 'approved' | 'ready_for_publish' | 'rejected';
  version: number;
  reviewed_at?: string | null;
  author?: { id: number; name: string };
  reviewer?: { id: number; name: string };
  revisions?: ContentRevision[];
  created_at: string;
  updated_at: string;
}

export interface AiUsageSummary {
  summary: {
    total_tokens: number;
    total_cost_usd: number;
    successful_requests: number;
    failed_requests: number;
  };
  recent_logs: Array<{
    id: number;
    provider: string;
    model: string;
    feature: string;
    tokens: number;
    cost: number;
    duration_ms: number;
    status: string;
    error_message?: string | null;
    created_at: string;
  }>;
}

// Phase 4: Search Performance & Keyword Types
export interface SearchConsoleStatus {
  connected: boolean;
  status: 'connected' | 'disconnected' | 'pending' | 'failed';
  selected_property: string | null;
  properties: Array<{ siteUrl: string; permissionLevel: string }>;
  last_synced_at: string | null;
  last_error: string | null;
  is_sandbox: boolean;
  oauth_mode?: 'live' | 'sandbox';
  redirect_uri?: string;
  latest_sync_log?: {
    id: number;
    rows_synced: number;
    status: string;
    started_at: string;
    completed_at?: string | null;
    metrics_summary?: {
      total_clicks: number;
      total_impressions: number;
      avg_ctr: number;
      avg_position: number;
    };
  } | null;
}

export interface SearchPerformanceData {
  period: {
    start_date: string;
    end_date: string;
    previous_start_date: string;
    previous_end_date: string;
  };
  summary: {
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
    comparison: {
      previous_clicks: number;
      previous_impressions: number;
      previous_ctr: number;
      previous_position: number;
      diff_clicks: number;
      diff_impressions: number;
      diff_ctr: number;
      diff_position: number;
    };
  };
  timeseries: Array<{
    date: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
  }>;
  queries: Array<{
    query: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
  }>;
  pages: Array<{
    page: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
  }>;
  devices: Array<{
    device: string;
    clicks: number;
    impressions: number;
  }>;
  countries: Array<{
    country: string;
    clicks: number;
    impressions: number;
  }>;
}

export interface SearchOpportunity {
  id: number;
  type: 'low_ctr' | 'click_decay' | 'rising_query' | 'keyword_gap';
  target_type: 'page' | 'query';
  target_identifier: string;
  headline: string;
  reasoning: string;
  recommended_action: string;
  impact_score: number;
  metrics_snapshot: Record<string, any>;
  status: 'detected' | 'acknowledged' | 'dismissed' | 'resolved';
  detected_at: string;
}

export interface KeywordResearchItem {
  id: number;
  keyword: string;
  country: string;
  language: string;
  search_volume: number | null;
  difficulty: number | null;
  cpc: number | null;
  intent: 'informational' | 'commercial' | 'transactional' | 'navigational' | string | null;
  competition_level: 'LOW' | 'MEDIUM' | 'HIGH' | string | null;
  provider: string;
  provenance: {
    provider: string;
    fetched_at: string;
    country: string;
    language: string;
    is_stale: boolean;
    missing_metric: boolean;
    provenance_note?: string;
  };
  related_clusters?: Record<string, any> | null;
}

export interface KeywordCluster {
  cluster_name: string;
  intent: string;
  keywords: string[];
}

export interface CmsSnapshot {
  id: number;
  website_id: number;
  cms_provider: string;
  target_type: 'page' | 'post' | 'product' | 'article' | string;
  target_identifier: string;
  snapshot_type: 'pre_change' | 'post_change' | 'manual_backup' | string;
  state_payload: {
    id?: string | number;
    title?: string;
    content?: string;
    meta_title?: string;
    meta_description?: string;
    canonical_url?: string;
    status?: string;
    url?: string;
    modified_at?: string;
    [key: string]: any;
  };
  checksum: string;
  remote_modified_at?: string;
  created_at: string;
}

export interface CmsChangeRequest {
  id: number;
  website_id: number;
  user_id: number;
  approved_by?: number | null;
  idempotency_key: string;
  cms_provider: string;
  action_type: 'update_metadata' | 'update_content' | 'create_draft' | string;
  target_type: 'page' | 'post' | 'product' | 'article' | string;
  target_identifier: string;
  target_url: string;
  proposed_payload: {
    meta_title?: string;
    meta_description?: string;
    canonical_url?: string;
    title?: string;
    content?: string;
    status?: string;
    [key: string]: any;
  };
  status: 'pending_approval' | 'approved' | 'rejected' | 'executing' | 'verified' | 'failed' | 'rolled_back' | string;
  risk_level: 'low' | 'medium' | 'high';
  rejection_reason?: string | null;
  pre_change_snapshot_id?: number | null;
  post_change_snapshot_id?: number | null;
  diff_summary?: Record<string, {
    before: any;
    after: any;
    changed: boolean;
  }> | null;
  verification_details?: {
    verified: boolean;
    matches: Record<string, any>;
    mismatches: Record<string, { expected: any; actual: any }>;
  } | null;
  execution_error?: string | null;
  executed_at?: string | null;
  rolled_back_at?: string | null;
  created_at: string;
  requester?: { id: number; name: string; email: string };
  approver?: { id: number; name: string; email: string };
  pre_change_snapshot?: CmsSnapshot;
  post_change_snapshot?: CmsSnapshot;
}

export interface CmsCapabilities {
  platform: string;
  provider: string;
  capabilities: string[];
  is_read_only: boolean;
}

export interface AutomationRule {
  id: number;
  website_id: number;
  created_by: number;
  name: string;
  description?: string | null;
  trigger_type: 'schedule' | 'crawl_completed' | 'issue_detected' | 'performance_threshold' | 'content_approved' | 'action_failed' | string;
  trigger_config?: Record<string, any> | null;
  action_type: 'full_audit_and_recommend' | 'auto_stage_metadata_fix' | 'publish_approved_content' | 'sync_gsc_and_report' | 'recover_failed_execution' | string;
  action_config?: Record<string, any> | null;
  is_write_capable: boolean;
  require_human_approval: boolean;
  dry_run_mode: boolean;
  status: 'active' | 'paused' | 'disabled';
  max_runs_per_day: number;
  monthly_cost_budget: number;
  current_month_cost: number;
  last_triggered_at?: string | null;
  version: number;
  created_at: string;
  runs_count?: number;
  creator?: { id: number; name: string; email: string };
}

export interface WorkflowRunStep {
  step: string;
  status: 'running' | 'completed' | 'failed' | 'waiting' | 'cancelled';
  timestamp: string;
  data?: Record<string, any>;
  error?: string | null;
}

export interface WorkflowRun {
  id: number;
  website_id: number;
  automation_rule_id?: number | null;
  triggered_by_user_id?: number | null;
  run_uuid: string;
  idempotency_key: string;
  trigger_source: string;
  rule_version: number;
  status: 'queued' | 'running' | 'waiting_for_approval' | 'succeeded' | 'failed' | 'cancelled' | 'partially_failed';
  trigger_payload?: Record<string, any> | null;
  step_results?: WorkflowRunStep[] | null;
  retry_count: number;
  max_retries: number;
  error_details?: string | null;
  cost_incurred: number;
  tokens_consumed: number;
  queued_at?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  created_at: string;
  rule?: AutomationRule;
  triggered_by?: { id: number; name: string; email: string };
  approvals?: WorkflowApproval[];
}

export interface WorkflowApproval {
  id: number;
  website_id: number;
  workflow_run_id: number;
  cms_change_request_id?: number | null;
  title: string;
  description?: string | null;
  risk_level: 'low' | 'medium' | 'high';
  proposed_diff?: Record<string, any> | null;
  status: 'pending' | 'approved' | 'rejected' | 'revoked' | 'expired';
  reviewed_by?: number | null;
  review_notes?: string | null;
  reviewed_at?: string | null;
  expires_at?: string | null;
  created_at: string;
  workflow_run?: WorkflowRun;
  cms_change_request?: CmsChangeRequest;
  reviewer?: { id: number; name: string; email: string };
}

// Phase 7: SaaS, Billing, and Multi-Tenant Client Management
export interface SubscriptionPlan {
  id: string;
  name: string;
  description: string;
  price_monthly: number;
  price_yearly: number;
  max_websites: number;
  max_crawl_pages: number;
  crawl_frequency: string;
  max_ai_tokens_monthly: number;
  max_articles_monthly: number;
  max_team_seats: number;
  supports_cms_write: boolean;
  supports_white_label: boolean;
  supports_webhooks: boolean;
  features: string[];
}

export interface QuotaItem {
  used: number;
  limit: number;
  remaining: number;
  percentage: number;
}

export interface BillingUsage {
  organization_id: number;
  organization_name: string;
  plan: {
    id: string;
    name: string;
    description: string;
    price_monthly: number;
    price_yearly: number;
  };
  subscription: {
    status: 'active' | 'trialing' | 'past_due' | 'canceled' | 'incomplete';
    billing_cycle: 'monthly' | 'yearly';
    current_period_start: string;
    current_period_end: string;
    cancel_at_period_end: boolean;
    card_brand?: string;
    card_last_four?: string;
  };
  quotas: {
    websites: QuotaItem;
    crawl_pages: QuotaItem;
    ai_tokens: QuotaItem;
    articles: QuotaItem;
    team_seats: QuotaItem;
  };
  features: {
    supports_cms_write: boolean;
    supports_white_label: boolean;
    supports_webhooks: boolean;
    crawl_frequency: string;
  };
}

export interface WhiteLabelBranding {
  brand_name: string;
  logo_url: string | null;
  primary_color: string;
  contact_email: string | null;
  footer_text: string;
  remove_platform_branding: boolean;
}

export interface ClientWorkspace {
  id: number;
  organization_id: number;
  name: string;
  company_name?: string | null;
  client_email?: string | null;
  contact_person?: string | null;
  notes?: string | null;
  created_at: string;
  websites?: {
    id: number;
    client_workspace_id: number;
    name: string;
    domain: string;
    platform: string;
    health_score: number;
  }[];
}

export interface ClientApprovalRequest {
  id: number;
  organization_id: number;
  website_id: number;
  client_workspace_id?: number | null;
  requester_id: number;
  approver_email: string;
  title: string;
  description?: string | null;
  request_type: string;
  status: 'pending' | 'approved' | 'rejected' | 'expired';
  token: string;
  metadata?: Record<string, any> | null;
  client_comment?: string | null;
  decided_at?: string | null;
  expires_at?: string | null;
  created_at: string;
  website?: { id: number; name: string; domain: string };
  requester?: { id: number; name: string; email: string };
}

export interface WebsitePermission {
  id: number;
  website_id: number;
  user_id: number;
  can_view: boolean;
  can_edit: boolean;
  can_publish: boolean;
  can_approve: boolean;
  created_at: string;
  user?: { id: number; name: string; email: string };
}



