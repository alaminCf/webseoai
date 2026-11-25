# KEYWORD IMPLEMENTATION PLAN — Web SEO AI

## Staged Delivery Roadmap

### Stage 1 — Architecture & Design Documentation (Completed)
- Inspect existing Laravel 11 + Next.js 16 codebase, providers, AI engine, GSC, Competitor Analysis, Rank Checker, Content Studio, and Action Plan.
- Deliver the 5 design specifications (`KEYWORD_INTELLIGENCE_ARCHITECTURE.md`, `KEYWORD_DATABASE_SCHEMA.md`, `KEYWORD_PROVIDER_INTEGRATION.md`, `KEYWORD_AUTOMATION_WORKFLOW.md`, `KEYWORD_IMPLEMENTATION_PLAN.md`).

### Stage 2 — Database Schema, Models & Provider Integration Architecture
- Create idempotent migration `2026_11_26_000001_create_keyword_intelligence_tables.php` covering all 10 normalized tables with indexes and foreign keys.
- Create Eloquent models (`KeywordProject`, `KiSeedKeyword`, `KiKeyword`, `KiKeywordHistory`, `KiCluster`, `KiClusterMembership`, `KiCannibalizationFinding`, `KiAutomationWorkflow`, `KiAutomationRun`, `KiAlert`, `KiProviderUsageLog`).
- Build provider adapters (`DataForSeoKeywordAdapter`, `SemrushKeywordAdapter`, `SerperKeywordAdapter`, `GoogleAutocompleteAdapter`) and `KeywordProviderManager` with caching, rate limiting, usage logging, and honest status reporting.

### Stage 3 — Keyword Discovery Engine & Keyword Explorer
- Implement `KeywordDiscoveryService` supporting all 6 seed input types (single, multi, list, URL, business name, category), all 10 discovery sources, strict deduplication, and zero metric fabrication.
- Expose Discovery, Overview KPIs, Keyword Explorer (filters, sorting, pagination, bulk save/delete, CSV export, live SERP preview), Saved Keywords, and Keyword History endpoints.

### Stage 4 — Keyword Intelligence, Long-tail, Questions, Search Intent, Clustering & Cannibalization
- Implement `KeywordIntentService` (provider + rule + AI-inferred with confidence).
- Implement `KeywordClusteringService` (Semantic + SERP-overlap clustering with intent-conflict guard, plus create/rename/merge/split/add/remove/export).
- Implement `KeywordCannibalizationService` (GSC + Rank Checker multi-URL split detection with evidence threshold & confidence levels).
- Implement Long-tail discovery, 10-category Question discovery + FAQ content idea generator, and historical Trend Analysis.

### Stage 5 — Transparent Opportunity Scoring Engine & AI Keyword Strategist
- Implement `KeywordOpportunityService` with configurable weights, transparent 0–100 scoring, 6 opportunity categories, and clear rationale.
- Implement `KeywordStrategistService` powered by `AiManager` + `SecurityPromptWrapper` for evidence-based seed generation, cluster recommendations, pillar pages, internal linking, and prioritization strategies.

### Stage 6 — Keyword Automation Engine, Alerts & Rank Monitoring
- Implement `KeywordAutomationService`, `RunKeywordAutomationJob` (retry + backoff + idempotency), and `keywords:run-scheduled-automations` scheduled console command.
- Implement Alerts inbox (read/dismiss/filter) and Rank Checker monitoring bridge.

### Stage 7 — Deep Module Integration & 11-Section Keyword Research Frontend
- Wire 1-click **Send to SEO Action Plan** (`AiRecommendation`), **Send Cluster/Keyword to Content Studio** (`ContentBrief`), **Track in Rank Checker** (`RankCheckerService`), **Sync GSC**, and **Pull Competitor Gaps**.
- Build primary Sidebar module **Keyword Research** (`frontend/src/components/keywords/`) containing all 11 tabs: Overview, Keyword Discovery, Keyword Explorer, Keyword Clustering, Search Intent Analysis, Keyword Opportunities, Question Keywords, Long-tail Keywords, Keyword History, Saved Keywords, and Automation (plus AI Strategist & Cannibalization tools).

### Stage 8 — Security Audit, Automated Tests, Build Verification & Live Deployment
- Write comprehensive PHPUnit feature tests covering all 15 required test areas (discovery, metrics mapping, deduplication, clustering, intent, opportunity scoring, cannibalization, automation scheduling, queue retries, provider failures, workspace authorization/tenant isolation, CSV export, Action Plan, Content Studio, and Rank Checker integration).
- Run full `php artisan test` (preserving all 90 existing tests + new tests) and `NEXT_PUBLIC_API_URL=/api/v1 npm run build`.
- Commit cleanly and deploy to `main`.
