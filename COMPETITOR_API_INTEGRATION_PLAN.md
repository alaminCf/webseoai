# Web SEO AI — Competitor Data Provider & API Integration Plan

## 1. Current Credentials Audit

Inspection of `backend/.env`, `backend/config/keywords.php`, and `backend/config/ai.php` shows:
- **Google Search Console OAuth:** Live configured on Vercel (`GOOGLE_SEARCH_CONSOLE_CLIENT_ID` / `SECRET`) — supplies verified first-party organic queries, pages, clicks, impressions, and average positions.
- **Third-Party Competitor SEO Providers:** **Not yet configured** (`DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`, `SEMRUSH_API_KEY`, `AHREFS_API_KEY`, `SERPAPI_KEY` are currently unset).
- **AI Provider:** Defaults to `deterministic_studio` when `GEMINI_API_KEY` / `OPENAI_API_KEY` are unset.

---

## 2. Provider Evaluation Matrix

| Capability | DataForSEO v3 (Recommended Primary) | Semrush API | Ahrefs API v3 | SerpApi | First-Party Crawl + GSC (Always Active) |
|---|---|---|---|---|---|
| **Domain Metrics (Traffic, Keywords, Rank)** | Yes (`/v3/dataforseo_labs/google/domain_rank_overview/live`) | Yes (`domain_ranks`) | Yes (Enterprise) | No | Partial (Verified GSC clicks/queries + live HTML signals) |
| **Competitor Discovery** | Yes (`/v3/dataforseo_labs/google/competitors_domain/live`) | Yes (`domain_organic_organic`) | Limited | Via SERP extraction | Partial (On-page/GSC seed discovery) |
| **Keyword Gap (Volume, KD, CPC, Positions)** | Yes (`/v3/dataforseo_labs/google/domain_intersection/live` & `ranked_keywords`) | Yes (`domain_organic`) | Yes (Enterprise) | No | Partial (Target GSC queries only; no third-party volume/KD) |
| **Backlink Gap (Referring Domains, Anchors)** | Yes (`/v3/backlinks/summary/live`, `referring_domains/live`, `backlinks/live`) | Yes (`backlinks_overview`) | Yes (`/v3/site-explorer/refdomains`, `all-backlinks`) | No | No |
| **SERP Analysis (Live Organic, Features)** | Yes (`/v3/serp/google/organic/live/advanced`) | No | No | Yes (`/search.json`) | No |
| **Pricing Model** | Pay-as-you-go (no monthly minimum, ~$0.002–$0.05/call) | Business subscription + API units | Enterprise plan ($1,499+/mo) | Monthly credit tiers | Free (built-in) |

### Recommended Provider Selection Per Category
1. **All-in-One Default (Best Cost/Coverage):** **DataForSEO v3** (`DATAFORSEO_LOGIN` + `DATAFORSEO_PASSWORD`) covers all 5 categories cleanly under one pay-as-you-go account.
2. **Optional SERP Alternative:** **SerpApi** (`SERPAPI_KEY`) for Google Organic SERP snapshots & SERP-based discovery.
3. **Optional Domain/Keyword Alternative:** **Semrush API** (`SEMRUSH_API_KEY`) for Domain Overview & Organic Keyword datasets.
4. **Optional Backlink Specialist:** **Ahrefs API v3** (`AHREFS_API_KEY`) for Domain Rating (DR) & Referring Domain gap analysis.
5. **Always-Available Zero-Cost Baseline:** **FirstPartyCrawlProvider** (reusing our SSRF-safe `PageInspector` + `SearchPerformanceRecord` + `CrawledPage`) so users immediately get real verified target-vs-competitor technical/content/heading/schema comparisons and GSC-grounded gaps without ever fabricating third-party metrics.

---

## 3. Environment Variables Contract (`backend/config/competitors.php`)

All credentials live strictly in server-side environment variables — **NEVER** prefixed with `NEXT_PUBLIC_` and **NEVER** returned to the frontend:

```env
# Primary unified SEO data provider (Recommended)
DATAFORSEO_LOGIN=
DATAFORSEO_PASSWORD=
DATAFORSEO_BASE_URL=https://api.dataforseo.com/v3

# Optional specialized providers
SERPAPI_KEY=
SEMRUSH_API_KEY=
AHREFS_API_KEY=

# Caching & rate safety
COMPETITOR_CACHE_TTL_MINUTES=360
COMPETITOR_MAX_CONCURRENT_DOMAINS=5
```

---

## 4. Internal REST API Endpoints (`/api/v1/websites/{website}/competitors/...`)

All endpoints sit inside `auth:sanctum` + `org.context` with strict website-tenant authorization and role checks (`seo_manager` for mutations/jobs, any org member for reads):

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/websites/{website}/competitors/providers` | Returns provider connection status (boolean only, zero secrets), active driver per capability, recent usage stats, and setup guide. |
| `GET` | `/websites/{website}/competitors/overview` | Executive competitor workspace summary (tracked count, latest snapshots, top gaps, monitoring status, provider health). |
| `GET` | `/websites/{website}/competitors` | List tracked competitor domains with latest snapshot summary. |
| `POST` | `/websites/{website}/competitors` | Manually add a competitor domain (`domain`, `category`, `tier`, `country`, `language`). |
| `PATCH` | `/websites/{website}/competitors/{competitor}` | Update competitor `category`, `tier`, `display_name`, or `is_active`. |
| `DELETE` | `/websites/{website}/competitors/{competitor}` | Remove competitor domain and its associated snapshots. |
| `POST` | `/websites/{website}/competitors/discover` | Run intelligent competitor discovery (returns candidates with methodology, shared signals & confidence — requires explicit confirmation before adding). |
| `GET` | `/websites/{website}/competitors/discoveries` | List past discovery runs & pending candidates. |
| `POST` | `/websites/{website}/competitors/discoveries/{run}/confirm` | Confirm selected discovered candidates and persist them as tracked competitors. |
| `POST` | `/websites/{website}/competitors/analyze` | Launch full or scoped competitor analysis job (`full_analysis`, `domain_refresh`, `keyword_gap`, `content_gap`, `backlink_gap`). |
| `GET` | `/websites/{website}/competitors/jobs` | List analysis jobs & progress. |
| `GET` | `/websites/{website}/competitors/comparison` | Compare target website vs up to 5 selected competitors (`competitor_ids[]`, `country`, `language`). |
| `GET` | `/websites/{website}/competitors/keyword-gap` | Filtered, sorted, paginated Keyword Gap dataset (`gap_type`, `intent`, `min_volume`, `max_difficulty`, `competitor`, `search`, `export=csv`). |
| `POST` | `/websites/{website}/competitors/keyword-gap/{keyword}/save-action` | Save keyword gap opportunity directly into `ai_recommendations` (SEO Action Plan). |
| `GET` | `/websites/{website}/competitors/content-gap` | List grounded Content Gap opportunities (`priority`, `status`, `intent`). |
| `POST` | `/websites/{website}/competitors/content-gap/analyze` | Re-run Content Gap synthesis against crawled pages & competitor top pages. |
| `POST` | `/websites/{website}/competitors/content-gap/{gap}/send-to-studio` | Create a real `ContentBrief` in Content Studio from an approved Content Gap opportunity. |
| `GET` | `/websites/{website}/competitors/backlink-gap` | Referring domain gap, shared domains, follow/nofollow stats, pagination, and `export=csv`. |
| `POST` | `/websites/{website}/competitors/serp/analyze` | Run SERP analysis for a keyword (`keyword`, `country`, `language`, `device`, `location`, `monitor`). |
| `GET` | `/websites/{website}/competitors/serp/snapshots` | List SERP snapshots & historical rank changes (`monitored_only`, `keyword`). |
| `PATCH` | `/websites/{website}/competitors/serp/snapshots/{snapshot}/monitor` | Toggle ongoing monitoring for a SERP keyword. |
| `GET` | `/websites/{website}/competitors/strategy` | List AI Competitor Strategist reports. |
| `POST` | `/websites/{website}/competitors/strategy/generate` | Generate a fresh evidence-grounded AI Competitor Strategy report. |
| `POST` | `/websites/{website}/competitors/strategy/{report}/save-action` | Convert a specific strategic recommendation into a persistent `AiRecommendation` in SEO Action Plan. |
| `GET` | `/websites/{website}/competitors/monitoring` | Get monitoring config, historical snapshot trends, and detected change alerts. |
| `PUT` | `/websites/{website}/competitors/monitoring` | Update monitoring schedule (`daily`, `weekly`, `monthly`, `manual`) & alert thresholds. |
| `POST` | `/websites/{website}/competitors/monitoring/run` | Manually trigger a monitoring delta check & snapshot capture. |

---

## 5. Caching, Queues, Rate Limiting & Error Handling

- **Caching:** Provider API responses are cached per `(provider, capability, domain/keyword, country, language)` using `Cache::remember` for `COMPETITOR_CACHE_TTL_MINUTES` (default 6 hours) to prevent expensive duplicate calls.
- **Rate Limiting:** Discovery, SERP live check, and full analysis endpoints are throttled via Laravel rate limiters (`throttle:20,1`).
- **Queue Execution:** `RunCompetitorAnalysisJob` implements `ShouldQueue` with `$tries = 3`, `$backoff = [10, 30, 60]`, and graceful error recording onto `competitor_analysis_jobs`. Under `QUEUE_CONNECTION=sync` (Vercel container), it runs cleanly inline with predictable timeouts.
- **Graceful Degradation:** When external provider credentials are missing or a provider returns HTTP 401/402/429/5xx, the adapter logs the error in `competitor_provider_logs`, returns a structured `provider_status: 'unconfigured' | 'error'` envelope, and NEVER inserts synthetic metrics.
