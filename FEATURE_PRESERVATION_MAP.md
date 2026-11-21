# WEB SEO AI — FEATURE PRESERVATION & MAPPING MATRIX

**Date:** October 13, 2026  
**Guiding Principle:** Every existing feature, API endpoint, database schema, and test case from Phases 0–7 must be preserved, verified, and cleanly mapped to the redesigned user interface.

---

## 1. Feature to UI Entry Point Mapping

| Original Phase | Feature & Backend API | Previous UI Location | Redesigned UI Location | Status |
|---|---|---|---|---|
| **Phase 0 & 1** | Auth, Register, Login, Session (`/auth/*`) | `AuthView.tsx` | Preserved as core auth gateway; enhanced with guided onboarding trigger for new accounts. | **Preserved** |
| **Phase 0 & 1** | Org Management & Invites (`/organizations/*`) | `TeamView.tsx` | Mapped to **Clients & Team** $\rightarrow$ *Team Members* tab, with website-level permission editor. | **Preserved** |
| **Phase 1** | Website Management (`/websites/*`) | `WebsiteListView.tsx` | Mapped to **My Websites** view + top navbar site switcher. | **Preserved** |
| **Phase 1** | Domain Ownership Verification | `VerifyWebsiteModal.tsx` | Integrated into **My Websites** as an optional "Unlock Publishing" drawer. | **Preserved** |
| **Phase 2** | Web Crawler & Job Engine (`/websites/{id}/crawl`) | `CrawlProgressModal.tsx` | Mapped to **Site Audit** $\rightarrow$ *Run Scan* button with inline status banner and header indicator. | **Preserved** |
| **Phase 2** | Technical SEO Audits & Issues (`/audits/*`) | `AuditIssuesTable.tsx`, `AuditOverviewView.tsx` | Mapped to **Site Audit** tab with category filters and direct links to **SEO Action Plan**. | **Preserved** |
| **Phase 2** | Crawled Pages Inspector (`/crawl-jobs/{id}/pages`) | `CrawledPagesTable.tsx` | Mapped to **Site Audit** $\rightarrow$ *Discovered Pages* sub-tab. | **Preserved** |
| **Phase 2** | Audit Comparison (`/websites/{id}/audits/compare`) | `AuditCompareModal.tsx` | Mapped to **Site Audit** $\rightarrow$ *Compare Scans* button. | **Preserved** |
| **Phase 3** | Website Context Profile (`/context-profile`) | `ContextProfileModal.tsx` | Mapped to **Settings** $\rightarrow$ *Website SEO Profile* & onboarding wizard. | **Preserved** |
| **Phase 3** | AI Recommendations & ICE scoring (`/recommendations`) | `AiRecommendationsView.tsx` | Fully unified inside **SEO Action Plan** with preview diffs and execution buttons. | **Preserved** |
| **Phase 3** | Content Studio: Briefs & Articles (`/content/*`) | `ContentStudioView.tsx` | Mapped to **Content Studio** section with streamlined step tabs. | **Preserved** |
| **Phase 3** | Titles, Meta, FAQ & Gap Generator | `ContentStudioView.tsx` | Preserved in **Content Studio** $\rightarrow$ *Quick Tools* toolbar. | **Preserved** |
| **Phase 4** | Google Search Console OAuth & Sync (`/search-console/*`) | `SearchPerformanceView.tsx` | Mapped to **Performance** $\rightarrow$ *Search Console* tab with real API data and date filters. | **Preserved** |
| **Phase 4** | Search Opportunities (`/opportunities`) | `SearchOpportunitiesView.tsx` | Mapped to **Performance** $\rightarrow$ *Opportunities* tab. | **Preserved** |
| **Phase 4** | Keyword Research Provider (`/keywords/*`) | `KeywordResearchView.tsx` | Mapped to **Performance** $\rightarrow$ *Keyword Explorer* tab. | **Preserved** |
| **Phase 5** | WordPress & Shopify Adapters (`/integrations/*`) | `IntegrationsModal.tsx` | Mapped to **My Websites** $\rightarrow$ *Connect CMS* action & Settings. | **Preserved** |
| **Phase 5** | 11-Step Safe CMS Execution (`/cms/change-requests/*`) | `CmsApprovalsView.tsx` | Integrated into **SEO Action Plan** modal review and **Overview** $\rightarrow$ *Pending Approvals*. | **Preserved** |
| **Phase 5** | Pre/Post Snapshots & Rollback (`/cms/snapshots`) | `CmsApprovalsView.tsx` | Mapped to **SEO Action Plan** $\rightarrow$ *Change History & Snapshots* sub-view. | **Preserved** |
| **Phase 6** | Autopilot Rules & Engine (`/automation/*`) | `AutopilotView.tsx` | Mapped to **Autopilot** navigation item with simplified recipes + advanced rule builder. | **Preserved** |
| **Phase 6** | Workflow Run Telemetry & Step Logs | `AutopilotView.tsx` | Preserved inside **Autopilot** $\rightarrow$ *Run History & Step Drawer*. | **Preserved** |
| **Phase 7** | Client Workspaces & Sign-off Links (`/clients/*`) | `BillingAndAgencyView.tsx` | Mapped to **Clients & Team** $\rightarrow$ *Client Workspaces* tab. | **Preserved** |
| **Phase 7** | Stripe Billing & Quota Engine (`/billing/*`) | `BillingAndAgencyView.tsx` | Mapped to **Settings** $\rightarrow$ *Billing & Plans* tab with live quota bars and Stripe portal. | **Preserved** |
| **Phase 7** | White-Label Reports & Branding (`/white-label/*`) | `BillingAndAgencyView.tsx` | Mapped to **Settings** $\rightarrow$ *Agency White-Labeling* with live report preview modal. | **Preserved** |
| **Phase 7** | GDPR Org & Account Deletion (`/organizations/current/delete`)| `BillingAndAgencyView.tsx` | Mapped to **Settings** $\rightarrow$ *Security & Data Purge*. | **Preserved** |
| **Phase 1-7**| Audit Trail Timeline (`/audit-logs`) | `ActivityView.tsx` | Mapped to **Settings** $\rightarrow$ *Audit Trail* & dashboard recent activity card. | **Preserved** |

---

## 2. API Preservation Verification

All 19 backend controllers and their corresponding routes in `routes/api.php` remain 100% active and unmodified. The frontend API client (`frontend/src/lib/api.ts`) will continue calling the exact same REST endpoints.

```typescript
// Core REST Endpoints Preserved:
GET/POST   /api/v1/websites
GET/POST   /api/v1/websites/{id}/crawl
GET        /api/v1/websites/{id}/audits/latest
GET/POST   /api/v1/websites/{id}/recommendations
POST       /api/v1/websites/{id}/cms/change-requests/stage
POST       /api/v1/websites/{id}/cms/change-requests/{id}/execute
POST       /api/v1/websites/{id}/cms/change-requests/{id}/rollback
GET/POST   /api/v1/websites/{id}/automation/rules
GET/POST   /api/v1/billing/checkout-session
GET        /api/v1/billing/usage
GET/PUT    /api/v1/white-label/branding
```
