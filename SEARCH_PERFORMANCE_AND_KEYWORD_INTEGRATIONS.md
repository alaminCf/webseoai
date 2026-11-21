# Phase 4 — Search Performance & Keyword Data Integrations Documentation

**Web SEO AI Intelligence Layer — Phase 4**  
**Version:** 1.0 (Phase 4 Complete)  
**Status:** Implemented & Verified (36/36 Automated Tests Passing)

---

## 1. Executive Summary & Architecture Overview

Phase 4 integrates live search performance and keyword intelligence into Web SEO AI. It connects real Google Search Console (GSC) organic performance data and external keyword provider metrics with the technical audit engine built in Phase 2 and the AI Strategist built in Phase 3.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                              PHASE 4 ARCHITECTURE PIPELINE                             │
└────────────────────────────────────────────────────────────────────────────────────────┘
        │                                                                │
        ▼                                                                ▼
┌───────────────────────────────┐                                ┌───────────────────────────────┐
│ Google Search Console OAuth   │                                │ Keyword Provider Interface    │
│ - Scopes: webmasters.readonly │                                │ - Volume, Difficulty, CPC     │
│ - AES-256 Encrypted Tokens    │                                │ - Intent & Clusters           │
│ - Auto Token Refresh          │                                │ - Strict Provenance Tracking  │
└──────────────┬────────────────┘                                └──────────────┬────────────────┘
               │                                                                │
               ▼                                                                ▼
┌───────────────────────────────┐                                ┌───────────────────────────────┐
│ Search Analytics Ingestion    │                                │ Keyword Research Records      │
│ - Clicks, Impressions, CTR,   │                                │ - Never invent metrics        │
│   Position by Page & Query    │                                │ - Label missing/stale data    │
│ - Date-Range Comparison (28d) │                                └───────────────────────────────┘
└──────────────┬────────────────┘
               │
               ▼
┌───────────────────────────────┐
│ Opportunity Detection Engine  │
│ - Rule 1: Low CTR (Striking)  │
│ - Rule 2: Click Decay Alert   │
│ - Rule 3: Rising Fast Movers  │
│ - Rule 4: Audit Coverage Gap  │
└──────────────┬────────────────┘
               │
               ▼
┌───────────────────────────────┐
│ Unified Search Dashboard      │
│ - Trend Visualizations        │
│ - Queries & Pages Tables      │
│ - URL Inspection Tool         │
│ - Prioritized Opportunity Bar │
└───────────────────────────────┘
```

---

## 2. Google Search Console Integration

### 2.1 OAuth Configuration & Security
- **OAuth Protocol**: Google OAuth 2.0 Authorization Code flow with `access_type=offline` and `prompt=consent` to guarantee a persistent refresh token.
- **Minimal Privilege Scopes**:
  `https://www.googleapis.com/auth/webmasters.readonly`
  *(No write permissions, no DNS alteration access, no site verification tampering).*
- **Encrypted Storage**: Credentials (`access_token`, `refresh_token`, `expires_at`) are encrypted using Laravel's AES-256 envelope encryption (`Crypt::encryptString`) in `website_integrations.encrypted_credentials`. Credentials are hidden from JSON API serialization.
- **Automatic Token Refresh**: `GoogleSearchConsoleService::getValidAccessToken()` checks token expiry. If the access token expires within 5 minutes, it refreshes automatically via `https://oauth2.googleapis.com/token` and updates the database.
- **Revocation on Disconnect**: When disconnected by an authorized user (`seo_manager` or higher), the token is revoked via `https://oauth2.googleapis.com/revoke` and database credentials are removed.

### 2.2 Property Matching & Multi-Property Support
Supports both Google Search Console property types:
1. **Domain Properties**: `sc-domain:example.com`
2. **URL-Prefix Properties**: `https://example.com/`

The integration auto-detects matching verified properties from `webmasters/v3/sites` and allows authorized users to switch between available properties via `/api/v1/websites/{id}/search-console/property`.

### 2.3 Search Analytics Ingestion
- **Dimensions**: `date`, `query`, `page`, `country`, `device`.
- **Metrics**: `clicks`, `impressions`, `ctr` (decimal e.g. 0.0450 for 4.5%), `position` (average SERP rank).
- **Pagination & Quotas**: Batches rows up to 5,000 per request (capped at 25,000 per run) with exponential backoff on HTTP 429/503 rate limits.
- **Sync History**: Tracked in `search_console_sync_logs` storing rows ingested, start/end dates, total clicks, total impressions, execution duration, and error logs.
- **Development & Sandbox Fallback**: When live credentials are not set, an automated verified sandbox provides realistic deterministic search data for local testing and offline CI without third-party API dependencies.

### 2.4 Google URL Inspection Tool
- **Endpoint**: `POST /api/v1/websites/{id}/search-console/inspect`
- Queries `https://searchconsole.googleapis.com/v1/urlInspection/index:inspect`.
- Returns index verdict (`PASS`, `NEUTRAL`), coverage state, robots.txt status, last crawl time, and canonical URL.
- **Mandatory Disclaimer Displayed**:
  > *"URL Inspection provides Google's index status for this URL at the time of check; this does not guarantee future indexing or search rankings."*

---

## 3. Keyword Research Provider Architecture

### 3.1 Adapter Interface
Defined in `App\Domain\Keywords\Contracts\KeywordProviderInterface`:
- `getKeywordSuggestions(string $query, array $options = []): array`
- `getKeywordMetrics(array $keywords, array $options = []): array`
- `getRelatedClusters(string $keyword, array $options = []): array`

### 3.2 Strict Metric Provenance Rule
The prompt strictly mandates:
> *"Never substitute AI estimates for unavailable provider metrics. Clearly label missing or stale data."*

To enforce this:
1. When search volume or difficulty is not returned by the provider, it is stored as `null`.
2. Every keyword record in `keyword_research_records` persists a `provenance` JSON payload:
   - `provider`: e.g. `sandbox`, `dataforseo`, `semrush`
   - `fetched_at`: ISO timestamp
   - `country`: Target market code (e.g. `US`, `GB`)
   - `language`: Language code (e.g. `en`)
   - `is_stale`: Boolean
   - `missing_metric`: Boolean
   - `provenance_note`: Clear explanation of metric source
3. The UI explicitly labels unranked/low data keywords with *"Low/Unranked"* rather than substituting synthetic guesses.

---

## 4. Opportunity Detection Engine

`App\Domain\Performance\OpportunityDetector` implements 4 core heuristic rules:

### Rule 1: High Impressions with Comparatively Low CTR (Striking Distance)
- **Criteria**: Query ranks in top 20 (position 3.0 to 20.0), impressions $\ge 100$, but CTR $\le 2.5\%$.
- **Reasoning**: The page is ranking well enough to get seen, but searchers are clicking on other snippets.
- **Action**: Optimize title tags and meta descriptions to lead with the primary keyword and include strong value propositions.

### Rule 2: Click Decay (Traffic Loss vs Comparable Period)
- **Criteria**: Comparing current 28 days vs prior 28 days. Clicks declined by $\ge 20\%$ with at least 15 absolute clicks lost.
- **Reasoning**: Detects content obsolescence, search intent drift, or displacement by competing articles.
- **Action**: Audit content freshness, refresh statistics, check competitor changes, and strengthen internal linking.

### Rule 3: Rising Visibility (Fast Movers)
- **Criteria**: Position improved by $\ge 3.0$ ranks or impressions surged $\ge 50\%$ over comparable periods (with $\ge 50$ impressions).
- **Reasoning**: Google algorithms are actively testing this query for higher rankings.
- **Action**: Consolidate depth around this query and point 2–3 contextual internal links with descriptive anchor text.

### Rule 4: Technical Coverage Gap (Audit + Search Performance Intersection)
- **Criteria**: Landing pages with $\ge 100$ search impressions that have open critical or error audit issues from Phase 2 crawl runs (e.g. missing meta descriptions, broken internal links, thin content).
- **Reasoning**: High organic visibility is being penalized or leaked by preventable technical flaws.
- **Action**: Resolve technical audit flaws immediately to maximize conversion of impressions into clicks.

---

## 5. Google API Setup & Verification Steps

To configure live Google Search Console credentials in production:

1. **Google Cloud Console**:
   - Create a project in Google Cloud Console.
   - Enable the **Google Search Console API** (`searchconsole.googleapis.com`).
   - Configure the **OAuth Consent Screen**:
     - User Type: External (or Internal for Google Workspace).
     - Scopes: `https://www.googleapis.com/auth/webmasters.readonly`.
   - Create **OAuth 2.0 Client IDs**:
     - Application type: Web application.
     - Authorized redirect URIs:
       `https://your-domain.com/api/v1/integrations/google-search-console/callback`
2. **Environment Configuration (`.env`)**:
   ```env
   GOOGLE_SEARCH_CONSOLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
   GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET=your-client-secret
   GOOGLE_SEARCH_CONSOLE_REDIRECT_URI=https://your-domain.com/api/v1/integrations/google-search-console/callback
   ```
3. **Property Verification**:
   - Ensure the Google Account authorizing the connection is a Verified Owner or Full User in Google Search Console for the target domain (`sc-domain:example.com` or `https://example.com/`).

---

## 6. Automated Test Verification Results

All 36 tests pass with 247 assertions (`php artisan test`):
```
PASS  Tests\Feature\SearchPerformanceAndKeywordsTest
✓ google search console oauth flow and sandbox connection              0.02s
✓ search analytics synchronization and performance querying            0.02s
✓ url inspection returns disclaimer and index state                    0.01s
✓ opportunity detector identifies actionable seo priorities            0.02s
✓ keyword research provider stores provenance without invented metrics 0.01s
✓ tenant isolation prevents foreign organization search console access 0.01s

Total Suite: 36 passed (247 assertions) in 0.99s
Frontend: next build completed successfully (Zero TypeScript or JSX errors)
```
