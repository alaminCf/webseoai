# Web SEO AI — Competitor Data Provider Configuration Guide

Web SEO AI uses a provider-independent architecture so you can connect whichever external SEO data subscription fits your agency budget — without exposing any API keys to the browser.

## Recommended All-in-One Setup: DataForSEO v3 (Pay-As-You-Go)

DataForSEO covers all 5 competitor capabilities (**Discovery**, **Domain Metrics**, **Keyword Gap**, **Backlink Gap**, and **SERP Analysis**) under one pay-as-you-go account with no mandatory high monthly minimum.

1. Create an account at https://app.dataforseo.com/
2. Retrieve your **API Login** and **API Password** from the DataForSEO API Dashboard.
3. Add these environment variables in `backend/.env` (local) and **Vercel Project → Settings → Environment Variables** (Production & Preview):

```env
DATAFORSEO_LOGIN=your_dataforseo_login
DATAFORSEO_PASSWORD=your_dataforseo_password
DATAFORSEO_BASE_URL=https://api.dataforseo.com/v3
```

4. Redeploy Vercel. Visit **Competitor Analysis → Overview** to confirm **DataForSEO v3** displays **Connected**.

---

## Optional Alternative / Specialist Providers

You may connect any combination of the following providers; `CompetitorProviderManager` automatically selects the best configured provider per capability:

### Serper.dev (Fastest Free Google SERP & Competitor Discovery — 2,500 Free Queries)
```env
SERPER_API_KEY=your_serper_api_key
```
Sign up free at https://serper.dev/ (no credit card required for the first 2,500 Google searches). Powers both **Intelligent Competitor Discovery** and **SERP Analysis** with real Google organic rankings, People Also Ask, and SERP features.

### SerpApi (Google Organic SERP & SERP Discovery)
```env
SERPAPI_KEY=your_serpapi_private_key
```

### Semrush Analytics API (Domain Overview, Organic Keywords, Backlinks Overview)
```env
SEMRUSH_API_KEY=your_semrush_api_key
```

### Ahrefs API v3 (Domain Rating & Backlink Gap — Enterprise Plan Required)
```env
AHREFS_API_KEY=your_ahrefs_v3_token
```

---

## Cost Control & Caching Settings

To prevent redundant paid API calls across team members, responses are cached server-side and logged in `competitor_provider_logs`:

```env
COMPETITOR_CACHE_TTL_MINUTES=360
COMPETITOR_MAX_COMPARISON=5
COMPETITOR_HTTP_TIMEOUT=15
```

## Security Rules
- **NEVER** prefix any SEO provider key with `NEXT_PUBLIC_`.
- Keys are read exclusively on the Laravel backend (`backend/config/competitors.php`).
- All external usage, cache hits, latency, and estimated costs are visible inside **Competitor Analysis → Competitor History → Provider API Usage & Cache Logs**.
