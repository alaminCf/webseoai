# KEYWORD AUTOMATION WORKFLOW — Web SEO AI

## 1. Automation Lifecycle
Each workspace can create multiple recurring **Keyword Automation Workflows** scoped to a specific website, seed keywords/business category, and target country/language.

```
[Scheduler / Manual Trigger]
        │
        ▼
[RunKeywordAutomationJob (Queueable, Retries: 3, Backoff: 30s/120s/300s)]
        │
        ├── 1. Multi-Source Discovery (Autocomplete + Serper PAA/Related + GSC + Competitor Gap + DataForSEO if connected)
        ├── 2. Normalize & Deduplicate against `ki_keywords`
        ├── 3. Classify Search Intent (provider → deterministic rules → AI-inferred with confidence)
        ├── 4. Recalculate Transparent Opportunity Scores (0–100)
        ├── 5. Incremental Auto-Clustering (attach to matching intent-compatible cluster or create new cluster)
        ├── 6. Cannibalization Refresh (if GSC records present)
        ├── 7. Alert Engine (New Keywords, High Opportunity >= threshold, Competitor Gain, Trend Shift, Content Opportunity)
        ├── 8. Optional Rank Monitoring Handoff (queues tracked keywords into existing `RankCheckerService` — no duplicate engine)
        └── 9. Optional Content Opportunity Drafts (checks existing crawled pages, `content_briefs`, and `ai_recommendations` -> creates DRAFT recommendation only; NEVER auto-publishes!)
```

## 2. Scheduling & Execution Rules
- **Frequencies:** `daily` (24h), `weekly` (7d), `monthly` (30d), `custom` (`custom_interval_hours`: 6–720h).
- **Laravel Console Scheduler (`routes/console.php`):** Runs `keywords:run-scheduled-automations` hourly, querying `ki_automation_workflows` where `status = 'active'` and `(next_run_at IS NULL OR next_run_at <= now())`.
- **Idempotency:** Each execution checks for an active `running` run for the same workflow within the last 10 minutes before starting, preventing double execution.
- **Safety Gate:** Automation **never** publishes articles or modifies live CMS pages without explicit user approval. It only drafts recommendations into **SEO Action Plan** (`ai_recommendations` with `status = 'proposed'`) and **Content Studio** (`content_briefs` with `status = 'draft'`).

## 3. Alert Triggers
| Alert Type | Trigger Condition |
|---|---|
| `new_keywords` | Discovery run adds >= 1 net-new keyword for the website/market |
| `high_opportunity` | Newly discovered/updated keyword reaches `opportunity_score >= workflow.min_opportunity_score` |
| `competitor_gain` | Tracked competitor ranks Top 10 on a keyword where target is unranked or > #20 |
| `trend_shift` | Historical trend data shows >= +25% rising or <= -25% declining demand |
| `content_opportunity` | High-opportunity keyword/cluster has no existing matching page or Content Studio brief |
| `cannibalization` | New `high` or `medium` confidence multi-URL GSC split detected |
