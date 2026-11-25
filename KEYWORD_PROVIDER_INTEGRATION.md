# KEYWORD PROVIDER INTEGRATION — Web SEO AI

## 1. Provider Evaluation Matrix
| Provider | Capabilities Supported | Credential Source | Cost Model | Limitations |
|---|---|---|---|---|
| **DataForSEO v3** | Keyword suggestions, related keywords, search volume, KD, CPC, paid competition, search intent, 12-month historical trends, competitor ranked keywords, SERP | Admin Panel encrypted `platform_api_keys` (`provider = 'dataforseo'`) OR `DATAFORSEO_LOGIN` / `DATAFORSEO_PASSWORD` env vars | Pay-as-you-go (~\$0.01/request; \$1 free trial) | Requires positive balance; Backlinks API requires \$100/mo min commitment (Labs & Keyword Data are pure pay-as-you-go). |
| **Semrush API** | Domain keywords, phrase match, search volume, KD, CPC | `SEMRUSH_API_KEY` env var | Enterprise subscription + API units | No free tier; high monthly minimum. |
| **Serper.dev (Built-In Pool)** | Live Google SERP Top 10–100, People Also Ask (question keywords), Related Searches, SERP features, live ranking competitors | Multi-Key Failover Pool (`platform_api_keys`) + `SERPER_API_KEY` + Workspace BYOK | 2,500 free searches/key; auto-failover across pool keys + 12h Smart Cross-User Cache | Does NOT return monthly search volume, KD, or CPC (those stay `null` / `"Data Unavailable"`). |
| **Google Autocomplete** | Real-time Google search suggestions, long-tail expansions, question queries (`what/why/how/...`) | Public `suggestqueries.google.com` endpoint (no API key needed) | Free (rate-limited & cached) | Returns real search query suggestions only — no numeric volume/KD/CPC metrics. |
| **Google Search Console (First-Party)** | Target website's actual verified queries, clicks, impressions, CTR, impression-weighted average position, multi-URL ranking splits (cannibalization) | Per-website AES-256 encrypted OAuth 2.0 tokens (`website_integrations`) | Free (official Google API) | Strictly first-party for the verified website only — never used as a competitor search volume source. |
| **Competitor Analysis DB** | Tracked competitor ranked keywords & keyword gaps (`competitor_keywords`, `competitor_domains`) | Existing Web SEO AI database | Zero extra API cost | Dependent on competitor analysis runs. |

## 2. Security, Caching, Rate Limiting & Reliability Guarantees
1. **Zero Frontend Key Exposure:** All API credentials stay strictly server-side (AES-256 encrypted via Laravel `Crypt` or server env vars).
2. **Provider Status Visibility:** `GET /api/v1/websites/{website}/keyword-intelligence/providers` exposes live connection status, supported metrics, and honest disclaimers without ever pretending unconfigured providers are active.
3. **Deduplication & Caching:** Identical `(provider, operation, seed, country, language)` calls are cached (12h default) and deduplicated in-flight; SERP queries reuse `GlobalSerpCache` (0 extra credits on cache hit).
4. **Rate Limiting & Retries:** API routes enforce Laravel `throttle`; transient HTTP `429`/`5xx` provider errors retry with bounded backoff while permanent `401`/`402`/`403` errors fail fast and trigger Multi-Key Failover where applicable.
5. **Usage & Cost Attribution:** Every external call logs to `ki_provider_usage_logs` (`provider`, `operation`, `credits_used`, `estimated_cost_usd`, `cache_hit`, `status`, `duration_ms`).
6. **Honest Metric Attribution:**
   - First-party GSC data → `first_party_gsc`
   - DataForSEO / Semrush metrics → `third_party_estimated`
   - Formulaic scores (Opportunity Score, Relevance Score, Word Count) → `calculated`
   - LLM-classified intent / strategic advice → `ai_inferred` (with confidence level)
   - Missing metrics → `null` displayed as `"Data Unavailable"`
