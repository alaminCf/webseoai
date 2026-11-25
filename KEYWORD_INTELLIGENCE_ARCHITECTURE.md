# KEYWORD INTELLIGENCE ARCHITECTURE — Web SEO AI

## 1. Executive Summary
The **AI Keyword Intelligence & Automation System** adds an enterprise-grade, multi-tenant keyword research, semantic/SERP clustering, search-intent classification, cannibalization detection, AI strategy, and scheduled automation platform directly inside Web SEO AI without duplicating existing services or breaking any existing modules.

## 2. Existing Stack & Reuse Strategy
| Layer | Existing Technology | Reuse / Integration Point |
|---|---|---|
| **Frontend** | Next.js 16.4.6 (App Router, Turbopack), React 19, Tailwind CSS v4, Lucide Icons | New primary sidebar item **Keyword Research** (`keyword_research`) with modular 11-section workspace (`frontend/src/components/keywords/`) matching Web SEO AI's dark/light theme system. |
| **Backend** | Laravel 11 / PHP 8.4 REST API (`/api/v1`) | New domain `backend/app/Domain/KeywordIntelligence/` + controller `KeywordIntelligenceController` under `/api/v1/websites/{website}/keyword-intelligence/*`. Existing `/api/v1/keywords/*` endpoints remain 100% intact. |
| **Database** | Neon Serverless PostgreSQL (prod) / SQLite (PHPUnit) | Single additive migration creating 10 normalized, tenant-scoped (`organization_id`, `website_id`) tables with foreign keys & composite indexes. |
| **Auth & Multi-Tenancy** | Laravel Sanctum + `org.context` + `org.role` RBAC + `super.admin` | Every endpoint enforces `auth:sanctum` + `org.context` + role gates (`editor` for mutations, `viewer` for reads) and strict website ownership verification. |
| **AI Engine** | `App\Domain\AI\AiManager` (Gemini / OpenAI / Deterministic fallback) + `SecurityPromptWrapper` + `AiUsageLog` | Reused directly for AI Keyword Strategist, AI-inferred intent classification (labeled `ai_inferred` with confidence score), FAQ ideas, and evidence-backed strategy plans. |
| **SEO Data Providers** | `DataForSeoProvider`, `SemrushProvider`, `SerperProvider` (with Multi-Key Pool Auto-Failover & `SerpCreditService` Smart Cross-User Cache), Google Autocomplete (public suggest endpoint), `GoogleSearchConsoleService` | Provider-independent `KeywordProviderManager` orchestrates all live sources without fabricating metrics. Unavailable metrics explicitly display `"Data Unavailable"`. |
| **Cross-Module Bridges** | `SearchPerformanceRecord`, `CompetitorKeyword`, `CompetitorSerpSnapshot`, `RankCheckerService`, `AiRecommendation`, `ContentBrief`, `ContentStrategist`, `AuditLog` | Zero duplicate rank-tracking engine: monitoring delegates directly to `RankCheckerService` + `CompetitorSerpSnapshot`; approved opportunities push straight into `AiRecommendation` (SEO Action Plan) and `ContentBrief` (Content Studio). |
| **Queue & Scheduler** | Laravel Jobs + Console Scheduler (`routes/console.php`) | `RunKeywordAutomationJob` (idempotent, retryable, exponential backoff) + `keywords:run-scheduled-automations` artisan command scheduled hourly. |

## 3. Domain Architecture (`backend/app/Domain/KeywordIntelligence/`)
- **`Contracts/KeywordIntelligenceProviderInterface.php`** — Unified adapter contract for suggestions, volume/KD/CPC metrics, trends, and SERP data.
- **`Providers/`**
  - `DataForSeoKeywordAdapter.php` — Keyword suggestions, related keywords, search volume, KD, CPC, paid competition, intent, and 12-month historical trends via DataForSEO v3 (Admin Panel or env credentials).
  - `SemrushKeywordAdapter.php` — Phrase/related keyword metrics via Semrush API when configured.
  - `SerperKeywordAdapter.php` — Real Google SERP results, People Also Ask (questions), Related Searches, and live ranking positions via Serper Multi-Key Pool + Smart Cross-User Cache.
  - `GoogleAutocompleteAdapter.php` — Real live Google Suggest (`suggestqueries.google.com`) autocomplete, alphabet/long-tail modifiers, and question modifiers (`what/why/how/when/where/which/who/can/is/does`).
  - `KeywordProviderManager.php` — Orchestrates connected providers, enforces rate limiting/deduplication, caches responses, logs provider usage & costs, and reports honest connection status.
- **`Services/`**
  - `KeywordDiscoveryService.php` — Multi-source seed discovery (single/multi seed, list, website URL, business name/category) combining all 10 discovery methods without duplicating records; maps real metrics with strict provenance (`first_party_gsc`, `third_party_estimated`, `calculated`, `ai_inferred`).
  - `KeywordIntentService.php` — Deterministic + AI-assisted 4-way Search Intent classifier (`informational`, `navigational`, `commercial`, `transactional`) with explicit `intent_source` (`provider`, `rule_based`, `ai_inferred`) and `intent_confidence` (`0.00–1.00`).
  - `KeywordClusteringService.php` — Dual clustering engine (**Semantic clustering** via normalized token/stem Jaccard + intent guard AND **SERP-based clustering** via shared Top-10 ranking URL overlap). Never merges conflicting intents into a single content target. Supports create, rename, merge, split, add/remove keywords, export, and send-to-Content-Studio.
  - `KeywordCannibalizationService.php` — Analyzes `SearchPerformanceRecord` (GSC) + `CompetitorSerpSnapshot` for multi-URL impression splits, competing URLs, CTR/position dilution, confidence scoring (`low`/`medium`/`high` — never flags weak 1-impression overlaps as definite cannibalization), and resolution guidance.
  - `KeywordOpportunityService.php` — Transparent, configurable weighted Opportunity Scoring Engine (0–100) computing `opportunity_score`, `relevance_score`, `ranking_potential`, `competition_level`, `search_demand`, `priority`, `category`, `reason`, and `data_confidence`. Never claims guaranteed ranking probabilities.
  - `KeywordStrategistService.php` — Context-aware AI Keyword Strategist using website profile, existing pages, GSC data, competitor gaps, and researched keywords to generate evidence-backed seed ideas, clusters, pillar pages, internal links, and prioritization roadmaps via `AiManager` + `SecurityPromptWrapper`.
  - `KeywordAutomationService.php` — Manages recurring workflows (`daily`/`weekly`/`monthly`/`custom`), idempotent execution runs, incremental clustering, opportunity alerts, Rank Checker monitoring handoff, and Content Studio / SEO Action Plan draft recommendations (never auto-publishes).

## 4. Data Integrity & Provenance Rules
1. **Zero Fabricated Metrics:** Monthly search volume, KD, CPC, competition, ranking position, and historical trends are strictly `null` when unavailable from a real provider — rendered in UI as `"Data Unavailable"`.
2. **Clear Metric Attribution:** Every record carries `data_source`, `metric_classification` (`first_party_gsc`, `third_party_estimated`, `calculated`, `ai_inferred`), and `last_updated_at`.
3. **Strict Tenant Isolation:** Every query scopes by `organization_id` and `website_id`; cross-tenant access returns `403`.
