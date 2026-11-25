export interface KiTrendPoint {
  period: string;
  volume: number;
  type?: string;
}

export interface KiSerpTopUrl {
  position: number;
  domain: string;
  url: string;
  title: string;
  snippet?: string;
}

export interface KiKeyword {
  id: number;
  organization_id: number;
  website_id: number;
  keyword: string;
  normalized_keyword: string;
  country: string;
  language: string;
  search_engine: string;
  device: string;
  location: string | null;
  discovery_source: string;
  question_category: string | null;
  is_long_tail: boolean;
  word_count: number;
  search_volume: number | null;
  keyword_difficulty: number | null;
  cpc: number | null;
  paid_competition: number | null;
  competition_level: string | null;
  search_intent: 'informational' | 'navigational' | 'commercial' | 'transactional' | null;
  intent_source: 'provider' | 'rule_based' | 'ai_inferred' | 'manual' | null;
  intent_confidence: number | null;
  trend_data: KiTrendPoint[] | null;
  trend_direction: 'rising' | 'declining' | 'stable' | 'seasonal' | 'unavailable' | null;
  serp_features: string[] | null;
  serp_top_urls: KiSerpTopUrl[] | null;
  current_position: number | null;
  previous_position: number | null;
  ranking_url: string | null;
  gsc_clicks: number | null;
  gsc_impressions: number | null;
  gsc_ctr: number | null;
  competitor_coverage_count: number;
  opportunity_score: number | null;
  relevance_score: number | null;
  ranking_potential: string | null;
  opportunity_category: string | null;
  recommended_priority: string | null;
  opportunity_reason: string | null;
  data_confidence: 'high' | 'medium' | 'low';
  data_source: string;
  metric_classification: string;
  is_saved: boolean;
  is_monitored: boolean;
  saved_recommendation_id: number | null;
  saved_brief_id: number | null;
  last_updated_at: string | null;
  created_at: string;
}

export interface KiClusterMembership {
  id: number;
  cluster_id: number;
  keyword_id: number;
  is_primary: boolean;
  similarity_score: number;
  keyword?: KiKeyword;
}

export interface KiCluster {
  id: number;
  website_id: number;
  name: string;
  primary_keyword: string;
  clustering_method: 'semantic' | 'serp' | 'manual';
  search_intent: string | null;
  keyword_count: number;
  combined_search_volume: number | null;
  avg_difficulty: number | null;
  opportunity_score: number;
  suggested_pillar_page: string | null;
  suggested_supporting_content: Array<{
    keyword: string;
    suggested_format: string;
    search_intent?: string;
  }> | null;
  is_saved: boolean;
  sent_to_content_studio_brief_id: number | null;
  memberships?: KiClusterMembership[];
  created_at: string;
}

export interface KiCannibalizationFinding {
  id: number;
  keyword: string;
  competing_urls: Array<{
    url: string;
    clicks: number;
    impressions: number;
    avg_position: number;
    ctr?: number;
    impression_share_pct?: number;
  }>;
  total_impressions: number;
  total_clicks: number;
  best_position: number | null;
  position_spread: number;
  confidence: 'high' | 'medium' | 'low';
  evidence_summary: string;
  suggested_resolution: string;
  status: 'open' | 'acknowledged' | 'resolved' | 'dismissed';
  detected_at: string;
}

export interface KiAutomationRun {
  id: number;
  workflow_id: number;
  trigger_type: string;
  status: 'running' | 'completed' | 'failed';
  keywords_discovered: number;
  new_keywords: number;
  clusters_updated: number;
  opportunities_found: number;
  alerts_created: number;
  content_recommendations_created: number;
  duration_ms: number;
  summary: Record<string, unknown> | null;
  error_message: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}

export interface KiAutomationWorkflow {
  id: number;
  name: string;
  status: 'active' | 'paused';
  seed_keywords: string[];
  business_category: string | null;
  target_country: string;
  target_language: string;
  discovery_sources: string[] | null;
  schedule_frequency: 'daily' | 'weekly' | 'monthly' | 'custom';
  custom_interval_hours: number | null;
  auto_cluster: boolean;
  auto_monitor_rank: boolean;
  auto_content_recommendations: boolean;
  notify_on_opportunities: boolean;
  min_opportunity_score: number;
  last_run_at: string | null;
  next_run_at: string | null;
  total_keywords_discovered: number;
  total_opportunities_found: number;
  last_error: string | null;
  runs?: KiAutomationRun[];
}

export interface KiAlert {
  id: number;
  workflow_id: number | null;
  keyword_id: number | null;
  alert_type: string;
  severity: 'info' | 'high' | 'critical';
  title: string;
  message: string;
  evidence: Record<string, unknown> | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

export interface KiProviderStatus {
  has_volume_provider: boolean;
  has_serp_provider: boolean;
  has_gsc: boolean;
  data_notice: string;
  providers: Array<{
    name: string;
    label: string;
    configured: boolean;
    classification: string;
    capabilities: string[];
  }>;
}

export interface KiSeedRun {
  id: number;
  seed_text: string;
  seed_type: string;
  country: string;
  language: string;
  sources_used: string[] | null;
  discovered_count: number;
  last_researched_at: string | null;
  created_at: string;
}

export interface KiOverviewData {
  metrics: {
    total_researched_keywords: number;
    total_saved_keywords: number;
    total_clusters: number;
    high_opportunity_keywords: number;
    avg_keyword_difficulty: number | null;
    total_estimated_search_volume: number | null;
    new_keyword_opportunities_7d: number;
    question_keywords_count: number;
    long_tail_keywords_count: number;
    cannibalization_open_count: number;
    active_automations_count: number;
    unread_alerts_count: number;
  };
  intent_breakdown: {
    informational: number;
    navigational: number;
    commercial: number;
    transactional: number;
  };
  recent_research_activity: KiSeedRun[];
  active_automation_workflows: KiAutomationWorkflow[];
  top_opportunities: KiKeyword[];
  provider_status: KiProviderStatus;
}

export const INTENT_STYLES: Record<string, { label: string; badge: string }> = {
  informational: { label: 'Informational', badge: 'bg-sky-500/15 text-sky-400 border-sky-500/30' },
  navigational: { label: 'Navigational', badge: 'bg-violet-500/15 text-violet-400 border-violet-500/30' },
  commercial: { label: 'Commercial', badge: 'bg-amber-500/15 text-amber-400 border-amber-500/30' },
  transactional: { label: 'Transactional', badge: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' },
};

export const OPP_CATEGORY_STYLES: Record<string, { label: string; badge: string }> = {
  high_opportunity: { label: 'High Opportunity', badge: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' },
  content_gap: { label: 'Content Gap', badge: 'bg-cyan-500/15 text-cyan-400 border-cyan-500/30' },
  existing_strength: { label: 'Existing Strength', badge: 'bg-indigo-500/15 text-indigo-400 border-indigo-500/30' },
  medium_opportunity: { label: 'Medium Opportunity', badge: 'bg-amber-500/15 text-amber-400 border-amber-500/30' },
  high_competition: { label: 'High Competition', badge: 'bg-rose-500/15 text-rose-400 border-rose-500/30' },
  low_opportunity: { label: 'Low Opportunity', badge: 'bg-zinc-800 text-zinc-400 border-zinc-700' },
};

export const COUNTRIES = [
  { code: 'US', label: 'United States (US)' },
  { code: 'GB', label: 'United Kingdom (GB)' },
  { code: 'CA', label: 'Canada (CA)' },
  { code: 'AU', label: 'Australia (AU)' },
  { code: 'BD', label: 'Bangladesh (BD)' },
  { code: 'IN', label: 'India (IN)' },
  { code: 'DE', label: 'Germany (DE)' },
  { code: 'FR', label: 'France (FR)' },
  { code: 'AE', label: 'UAE (AE)' },
  { code: 'SG', label: 'Singapore (SG)' },
];

export const LANGUAGES = [
  { code: 'en', label: 'English (en)' },
  { code: 'bn', label: 'Bengali (bn)' },
  { code: 'es', label: 'Spanish (es)' },
  { code: 'fr', label: 'French (fr)' },
  { code: 'de', label: 'German (de)' },
  { code: 'hi', label: 'Hindi (hi)' },
  { code: 'ar', label: 'Arabic (ar)' },
];

export const DISCOVERY_SOURCES = [
  { id: 'related', label: 'Related Keywords' },
  { id: 'suggestions', label: 'Keyword Suggestions' },
  { id: 'long_tail', label: 'Long-tail Keywords' },
  { id: 'questions', label: 'Question-based Keywords' },
  { id: 'autocomplete', label: 'Autocomplete Suggestions' },
  { id: 'search_related', label: 'Search-related Queries' },
  { id: 'competitor_keywords', label: 'Competitor Keywords' },
  { id: 'gsc_queries', label: 'GSC Search Queries' },
  { id: 'keyword_gap', label: 'Keyword Gap Opportunities' },
  { id: 'topic_ideas', label: 'Topic-based Ideas' },
];

export function formatNullableNumber(val: number | null | undefined, prefix = ''): string {
  if (val === null || val === undefined || Number.isNaN(val)) {
    return 'Data Unavailable';
  }
  return `${prefix}${val.toLocaleString()}`;
}
