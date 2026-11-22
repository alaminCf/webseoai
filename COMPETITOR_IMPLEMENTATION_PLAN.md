# Web SEO AI — Competitor Analysis Staged Implementation Plan

Following strict staged engineering discipline, the Competitor Analysis Module will be built, tested, and verified across **5 sequential stages**:

---

## Stage 1 — Database Schema, Models, Config & Provider Architecture (Phases 12 & 13)
- Create migration `backend/database/migrations/2026_11_22_000001_create_competitor_analysis_tables.php` defining all 11 persistent tables with foreign keys, tenant isolation, and indexes.
- Create Eloquent models in `backend/app/Models/`:
  - `CompetitorDomain`, `CompetitorDiscoveryRun`, `CompetitorDomainSnapshot`, `CompetitorKeyword`, `CompetitorContentGap`, `CompetitorBacklink`, `CompetitorSerpSnapshot`, `CompetitorStrategyReport`, `CompetitorMonitoringConfig`, `CompetitorAnalysisJob`, `CompetitorProviderLog`.
- Create `backend/config/competitors.php` (environment-driven credentials & caching).
- Implement provider contracts & adapters in `backend/app/Domain/Competitors/`:
  - `DataForSeoProvider`, `SemrushProvider`, `AhrefsProvider`, `SerpApiProvider`, `FirstPartyCrawlProvider`, and `CompetitorProviderManager`.
- **Stage 1 Gate:** Run `php artisan migrate` + unit/feature verification on models, provider status detection, and zero-leak credential safety.

## Stage 2 — Core Competitor Services, Jobs & REST API (Phases 3–11, 15)
- Implement all 8 domain services in `backend/app/Domain/Competitors/Services/`:
  1. `CompetitorDiscoveryService` (anti-portal filtering, confidence scoring, confirmation workflow)
  2. `DomainComparisonService` (up to 5 competitors, strength/weakness matrix, provenance labeling)
  3. `KeywordGapService` (`missing`, `weak`, `shared`, `strong`, `untapped`, CSV export, Save to SEO Action Plan)
  4. `ContentGapService` (verified against target `CrawledPage` & GSC, Send to Content Studio)
  5. `BacklinkGapService` (referring domain gap, shared domains, follow/nofollow breakdown, CSV export)
  6. `SerpAnalysisService` (SERP snapshots, competitor presence, monitored keyword history)
  7. `AiCompetitorStrategistService` (evidence-grounded AI strategy with Critical/High/Medium/Low priorities, observed vs interpreted separation)
  8. `CompetitorMonitoringService` (daily/weekly/monthly/manual schedules, snapshot history, delta alert generation)
- Implement `RunCompetitorAnalysisJob` (queueable with retry/backoff & status updates).
- Implement `CompetitorAnalysisController` + register all `/api/v1/websites/{website}/competitors/*` routes with `auth:sanctum`, `org.context`, RBAC, and throttling.
- **Stage 2 Gate:** Write & pass `CompetitorAnalysisTest` covering all 13 required test scenarios (discovery, manual CRUD, domain comparison, keyword gap math, content gap, backlink gap, SERP snapshots, AI evidence grounding, provider failures, job retries, tenant isolation, RBAC, CSV export) + verify existing Site Audit & GSC tests still pass.

## Stage 3 — Frontend Navigation, Types & 9 Interactive Submodules (Phases 2, 3–11, 14)
- Add TypeScript types for all Competitor Analysis entities in `frontend/src/types/index.ts`.
- Add **`Competitor Analysis`** primary navigation entry to `frontend/src/components/Sidebar.tsx` and wire it in `frontend/src/app/page.tsx` (passing `onNavigateToActionPlan` and `onNavigateToContent` callbacks for seamless cross-module handoff).
- Build modular UI components under `frontend/src/components/competitors/`:
  - `CompetitorAnalysisView.tsx` (main shell with 9 submodule tabs, country/language selector, progressive disclosure, provider status drawer)
  - `CompetitorOverviewTab.tsx` (guided 9-step beginner workflow + executive KPIs + provider attribution)
  - `MyCompetitorsTab.tsx` (manual add + intelligent discovery modal with confirmation + primary/secondary & category management)
  - `DomainComparisonTab.tsx` (up to 5 competitors side-by-side table, interactive comparison bar charts, strengths/weaknesses, metric definitions)
  - `KeywordGapTab.tsx` (Missing/Weak/Shared/Strong/Untapped filters, search, sort, CSV export, Save to SEO Action Plan)
  - `ContentGapTab.tsx` (topic cards, recommended outlines, target coverage badge, Send to Content Studio)
  - `BacklinkGapTab.tsx` (referring domain gap, shared domains, link type breakdown, CSV export, transparent provider guide)
  - `SerpAnalysisTab.tsx` (live SERP runner, organic table, SERP feature chips, competitor highlight, monitored history)
  - `AiStrategyTab.tsx` (structured AI Strategist report with observed vs interpreted labels + 1-click Save to SEO Action Plan)
  - `CompetitorHistoryTab.tsx` (monitoring schedule editor, trend charts over time, detected alerts, provider usage logs)
- **Stage 3 Gate:** Run `NEXT_PUBLIC_API_URL=/api/v1 npm run build` (strict TypeScript + Turbopack production build) and verify zero errors across Dark & Light themes.

## Stage 4 — End-to-End Validation, Regression Check & Final Reports (Phase 16)
- Run the **entire** backend PHPUnit test suite (`php artisan test`) confirming zero regressions in Site Audit, GSC, SEO Action Plan, Content Studio, CMS Execution, Autopilot, and Multi-Tenant SaaS Billing.
- Run frontend production build (`npm run build`).
- Generate final reports:
  - `COMPETITOR_TEST_REPORT.md`
  - `COMPETITOR_FEATURE_COMPLETION_REPORT.md`
  - `PROVIDER_CONFIGURATION_GUIDE.md`
- Commit & push to `origin main` for live Vercel deployment.
