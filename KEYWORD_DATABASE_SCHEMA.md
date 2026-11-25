# KEYWORD DATABASE SCHEMA — Web SEO AI

All tables follow Laravel conventions, enforce tenant isolation (`organization_id`, `website_id`), use proper foreign keys with cascading/null-on-delete rules, and are compatible with both **PostgreSQL (Neon production)** and **SQLite (PHPUnit)**.

## 1. Entity Relationship Overview
- `organizations` (1) ──▶ (N) `keyword_projects`
- `websites` (1) ──▶ (N) `keyword_projects`, `ki_keywords`, `ki_clusters`, `ki_cannibalization_findings`, `ki_automation_workflows`, `ki_alerts`
- `keyword_projects` (1) ──▶ (N) `ki_seed_keywords`, `ki_keywords`
- `ki_keywords` (1) ──▶ (N) `ki_keyword_history` (historical snapshots)
- `ki_clusters` (M) ◀──▶ (N) `ki_keywords` via `ki_cluster_memberships`
- `ki_automation_workflows` (1) ──▶ (N) `ki_automation_runs`
- `ki_keywords` / `ki_clusters` ──▶ existing `ai_recommendations` (SEO Action Plan), `content_briefs` (Content Studio), and `competitor_serp_snapshots` (Rank Checker).

## 2. Table Definitions

### 2.1 `keyword_projects`
Tracks each keyword research campaign/project per website.
| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `organization_id` | foreignId → `organizations` | cascadeOnDelete, indexed |
| `website_id` | foreignId → `websites` | cascadeOnDelete, indexed |
| `created_by_user_id` | foreignId nullable → `users` | nullOnDelete |
| `name` | string(180) | Project name |
| `target_country` | string(4) default `'US'` | ISO-2 country code |
| `target_language` | string(10) default `'en'` | ISO language code |
| `search_engine` | string(30) default `'google'` | `google`, `bing`, etc. |
| `search_location` | string(150) nullable | Optional city/region |
| `device` | string(20) default `'desktop'` | `desktop` / `mobile` |
| `search_network` | string(30) default `'google_search'` | Network |
| `business_name` | string(180) nullable | Optional seed context |
| `business_category` | string(180) nullable | Optional seed context |
| `scoring_weights` | json nullable | Configurable opportunity weights |
| `status` | string(20) default `'active'` | `active`, `archived` |
| `timestamps` | timestamps | |

### 2.2 `ki_seed_keywords`
Stores submitted seed inputs & execution metadata.
| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `organization_id` | foreignId → `organizations` | cascadeOnDelete |
| `website_id` | foreignId → `websites` | cascadeOnDelete |
| `project_id` | foreignId nullable → `keyword_projects` | nullOnDelete |
| `seed_text` | string(255) | Seed phrase / URL / category |
| `seed_type` | string(30) default `'keyword'` | `keyword`, `url`, `business`, `category`, `gsc`, `competitor` |
| `country` | string(4) default `'US'` | |
| `language` | string(10) default `'en'` | |
| `sources_used` | json nullable | Discovery methods executed |
| `discovered_count` | unsignedInteger default `0` | Keywords discovered |
| `last_researched_at` | timestamp nullable | |
| `timestamps` | timestamps | |

### 2.3 `ki_keywords` (Core Keyword Records + Metrics + Intent + Opportunity)
Single deduplicated source of truth per `(website_id, normalized_keyword, country, language)`.
| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | PK |
| `organization_id` | foreignId → `organizations` | cascadeOnDelete |
| `website_id` | foreignId → `websites` | cascadeOnDelete |
| `project_id` | foreignId nullable → `keyword_projects` | nullOnDelete |
| `seed_keyword_id` | foreignId nullable → `ki_seed_keywords` | nullOnDelete |
| `keyword` | string(255) | Display keyword |
| `normalized_keyword` | string(255) | Lowercased trimmed unique key |
| `country` | string(4) default `'US'` | |
| `language` | string(10) default `'en'` | |
| `search_engine` | string(30) default `'google'` | |
| `device` | string(20) default `'desktop'` | |
| `location` | string(150) nullable | |
| `discovery_source` | string(50) | `related`, `suggestions`, `long_tail`, `question`, `autocomplete`, `search_related`, `competitor`, `gsc`, `keyword_gap`, `topic`, `ai_seed` |
| `question_category` | string(20) nullable | `what`, `why`, `how`, `when`, `where`, `which`, `who`, `can`, `is`, `does` |
| `is_long_tail` | boolean default `false` | `word_count >= 4` or high specificity |
| `word_count` | unsignedSmallInteger default `1` | Calculated |
| `search_volume` | unsignedInteger nullable | Strictly provider-supplied (`null` = Data Unavailable) |
| `keyword_difficulty` | decimal(5,2) nullable | 0–100 provider-supplied (`null` = Data Unavailable) |
| `cpc` | decimal(8,2) nullable | USD provider-supplied (`null` = Data Unavailable) |
| `paid_competition` | decimal(5,4) nullable | 0–1 provider-supplied |
| `competition_level` | string(20) nullable | `LOW`, `MEDIUM`, `HIGH` |
| `search_intent` | string(30) nullable | `informational`, `navigational`, `commercial`, `transactional` |
| `intent_source` | string(30) nullable | `provider`, `rule_based`, `ai_inferred` |
| `intent_confidence` | decimal(4,2) nullable | 0.00–1.00 |
| `trend_data` | json nullable | Monthly historical points `[{year, month, search_volume}]` |
| `trend_direction` | string(20) nullable | `rising`, `declining`, `stable`, `seasonal`, `unavailable` |
| `serp_features` | json nullable | Real SERP features |
| `serp_top_urls` | json nullable | Top 10 organic URLs (used for SERP-based clustering) |
| `current_position` | decimal(6,2) nullable | GSC or Rank Checker position |
| `previous_position` | decimal(6,2) nullable | For movement tracking |
| `ranking_url` | text nullable | Current ranking URL |
| `gsc_clicks` | unsignedInteger nullable | First-party GSC 28d clicks |
| `gsc_impressions` | unsignedInteger nullable | First-party GSC 28d impressions |
| `gsc_ctr` | decimal(6,2) nullable | First-party GSC CTR % |
| `competitor_coverage_count` | unsignedSmallInteger default `0` | Tracked competitors ranking |
| `opportunity_score` | decimal(5,2) nullable | 0–100 transparent calculated score |
| `relevance_score` | decimal(5,2) nullable | 0–100 calculated relevance |
| `ranking_potential` | string(30) nullable | `high`, `moderate`, `low`, `insufficient_data` |
| `opportunity_category` | string(30) nullable | `high_opportunity`, `medium_opportunity`, `low_opportunity`, `high_competition`, `existing_strength`, `content_gap` |
| `recommended_priority` | string(20) nullable | `P1`, `P2`, `P3`, `P4` |
| `opportunity_reason` | text nullable | Transparent human-readable rationale |
| `data_confidence` | string(20) default `'low'` | `high`, `medium`, `low` |
| `data_source` | string(60) | e.g. `dataforseo`, `google_autocomplete`, `first_party_gsc`, `serper`, `competitor_gap` |
| `metric_classification` | string(40) | `first_party_gsc`, `third_party_estimated`, `calculated`, `ai_inferred`, `hybrid` |
| `is_saved` | boolean default `false` | User-saved shortlist |
| `is_monitored` | boolean default `false` | Tracked via Rank Checker |
| `saved_recommendation_id` | foreignId nullable → `ai_recommendations` | nullOnDelete |
| `saved_brief_id` | foreignId nullable → `content_briefs` | nullOnDelete |
| `last_updated_at` | timestamp nullable | |
| `timestamps` | timestamps | |
| **Unique Index** | `(website_id, normalized_keyword, country, language)` | Zero duplicate keyword rows |

### 2.4 `ki_keyword_history`
Historical metric & position snapshots per keyword.
- `id`, `organization_id`, `website_id`, `keyword_id` (FK → `ki_keywords`), `search_volume`, `keyword_difficulty`, `cpc`, `position`, `ranking_url`, `gsc_clicks`, `gsc_impressions`, `data_source`, `captured_at`, `timestamps`.

### 2.5 `ki_clusters` & `ki_cluster_memberships`
- **`ki_clusters`:** `id`, `organization_id`, `website_id`, `project_id`, `name`, `primary_keyword`, `clustering_method` (`semantic`, `serp`, `manual`), `search_intent`, `keyword_count`, `combined_search_volume` (nullable), `avg_difficulty` (nullable), `opportunity_score`, `suggested_pillar_page`, `suggested_supporting_content` (json), `is_saved`, `sent_to_content_studio_brief_id` (nullable FK → `content_briefs`), `timestamps`.
- **`ki_cluster_memberships`:** `id`, `cluster_id` (FK → `ki_clusters`), `keyword_id` (FK → `ki_keywords`), `is_primary` (boolean), `similarity_score` (decimal 4,2), `timestamps`, unique `(cluster_id, keyword_id)`.

### 2.6 `ki_cannibalization_findings`
- `id`, `organization_id`, `website_id`, `keyword`, `competing_urls` (json: `[{url, impressions, clicks, avg_position, share_pct}]`), `total_impressions`, `total_clicks`, `best_position`, `position_spread`, `confidence` (`high`, `medium`, `low`), `evidence_summary`, `suggested_resolution`, `status` (`open`, `acknowledged`, `resolved`, `dismissed`), `detected_at`, `timestamps`.

### 2.7 `ki_automation_workflows` & `ki_automation_runs`
- **`ki_automation_workflows`:** `id`, `organization_id`, `website_id`, `created_by_user_id`, `name`, `status` (`active`, `paused`), `seed_keywords` (json), `business_category`, `target_country`, `target_language`, `discovery_sources` (json), `schedule_frequency` (`daily`, `weekly`, `monthly`, `custom`), `custom_interval_hours` (nullable), `auto_cluster` (bool), `auto_monitor_rank` (bool), `auto_content_recommendations` (bool), `notify_on_opportunities` (bool), `min_opportunity_score` (default 65), `last_run_at`, `next_run_at`, `total_keywords_discovered`, `total_opportunities_found`, `last_error`, `timestamps`.
- **`ki_automation_runs`:** `id`, `organization_id`, `website_id`, `workflow_id` (FK → `ki_automation_workflows`), `trigger_type` (`scheduled`, `manual`), `status` (`running`, `completed`, `failed`), `keywords_discovered`, `new_keywords`, `clusters_updated`, `opportunities_found`, `alerts_created`, `content_recommendations_created`, `duration_ms`, `summary` (json), `error_message`, `started_at`, `completed_at`, `timestamps`.

### 2.8 `ki_alerts` & `ki_provider_usage_logs`
- **`ki_alerts`:** `id`, `organization_id`, `website_id`, `workflow_id` (nullable), `keyword_id` (nullable), `alert_type` (`new_keywords`, `high_opportunity`, `competitor_gain`, `trend_shift`, `content_opportunity`, `cannibalization`), `severity` (`info`, `high`, `critical`), `title`, `message`, `evidence` (json), `is_read` (bool default false), `read_at`, `timestamps`.
- **`ki_provider_usage_logs`:** `id`, `organization_id`, `website_id` (nullable), `user_id` (nullable), `provider`, `operation`, `query_summary`, `results_count`, `credits_used`, `estimated_cost_usd` (decimal 10,5), `cache_hit` (bool), `status` (`success`, `failed`, `rate_limited`, `unconfigured`), `duration_ms`, `error_message`, `timestamps`.
