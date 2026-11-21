# WEB SEO AI — COMPREHENSIVE UX AUDIT

**Date:** October 13, 2026  
**Auditor:** Senior SaaS Product Designer & Full-Stack Architect  
**Scope:** Full-stack UX evaluation of Web SEO AI (Frontend Next.js 16 + Backend Laravel 11)

---

## 1. Executive Summary

Web SEO AI has a robust, highly capable backend engine with 59 passing tests spanning technical crawling, SSRF protection, AI strategy generation, Search Console ingestion, safe 11-step CMS write execution with rollbacks, background autopilot orchestration, and multi-tenant Stripe billing.

However, from an end-user and non-technical business owner perspective, the application currently presents significant usability, cognitive, and workflow friction:
- **Navigation cognitive overload:** 12 flat navigation tabs in the sidebar with no conceptual hierarchy.
- **Missing main executive dashboard:** The app opens directly to a raw website table (`websites`) rather than a task-oriented overview with "Your Next Best Action".
- **Fragmented action lifecycle:** AI recommendations, CMS change requests, and workflow approvals live across three separate tabs (`AI Strategist`, `Approvals & CMS`, `Autopilot Engine`).
- **No guided first-run onboarding:** New users are left on an empty website screen without a linear step-by-step path: `Welcome → Connect Website → Configure Goals → Initial Scan → Review Action Plan`.
- **Blurry permission boundaries:** Read-only audits vs. live CMS write integrations are not clearly distinguished in the UI, confusing users into thinking they need API passwords just to scan a website.

---

## 2. Route & Screen Inventory

### Frontend UI Tabs (Current State)

| Tab ID | Component | Current Purpose | Primary UX Deficiencies |
|---|---|---|---|
| `websites` | `WebsiteListView.tsx` | Table of websites, verification triggers, archive/restore | Acts as default landing view; lacks executive KPIs, status summaries, or guidance on what to do next. |
| `audits` | `AuditOverviewView.tsx` | Run crawl, view health score, issues table, crawled pages | Crowded modals (`CrawlProgressModal`, `AuditCompareModal`), jargon-heavy technical labels without contextual explanations. |
| `strategist` | `AiRecommendationsView.tsx` | List recommendations, ICE scores, status toggles | Disconnected from actual CMS change staging; user can accept an idea but doesn't have an intuitive path to execute it. |
| `content` | `ContentStudioView.tsx` | Briefs, article generation, meta titles, FAQs, keyword gaps | Dense form inputs; drafts and publishing status are difficult to track for non-technical users. |
| `analytics` | `SearchPerformanceView.tsx` | GSC clicks, impressions, CTR, position, date filter | If GSC is not connected, displays empty state without a clear 1-click sandbox or connection wizard. |
| `opportunities` | `SearchOpportunitiesView.tsx` | Striking-distance & decaying query opportunities | Separated from general performance analytics; could be a unified tab under Performance. |
| `keywords` | `KeywordResearchView.tsx` | External provider keyword search, volume, clusters | Separated into its own top-level navigation item despite being part of SEO research. |
| `approvals` | `CmsApprovalsView.tsx` | 11-step CMS change requests, snapshots, rollback | Very technical UI (diff payloads, checksums); non-technical users find it intimidating. |
| `autopilot` | `AutopilotView.tsx` | Automation rules, run history, step telemetry, approvals | Overlaps with `approvals`; rules builder has complex technical fields. |
| `billing` | `BillingAndAgencyView.tsx` | Quotas, Stripe checkout, white-label branding, workspaces | Merges commercial billing with agency client workspaces and GDPR deletion into one dense screen. |
| `activity` | `ActivityView.tsx` | Audit log timeline | Simple flat table with JSON metadata; lacks filtering by website or action severity. |
| `team` | `TeamView.tsx` | Organization member invitation & role assignment | Functional, but isolated from website-level granular ACLs. |

---

## 3. Heuristic Violations & Usability Gaps

### Heuristic 1: Visibility of System Status
- **Issue:** When a crawl is triggered, users rely on polling within a modal. If closed, there is no persistent progress indicator in the header or overview.
- **Issue:** Distinction between "Draft", "Awaiting Approval", "Applying", "Verified", and "Failed" is scattered across disparate screens.

### Heuristic 2: Match Between System and the Real World
- **Issue:** Terminology like `SSRF sanitized`, `Yoast Custom Metafields`, `Idempotency Key`, and `JSON diff payload` is displayed prominently rather than translated into human terms (e.g. *"Secure verification passed"*, *"SEO Title updated on WordPress"*, *"Safety snapshot preserved"*).

### Heuristic 3: User Control and Freedom
- **Issue:** Destructive actions (like deleting a website or rejecting a change) lacked undo or clear warnings in some legacy views.
- **Improvement Needed:** Clear confirmation dialogs and 1-click safe rollback options with human-readable before-and-after snapshots.

### Heuristic 4: Consistency and Standards
- **Issue:** Three different approval queues exist simultaneously:
  1. AI Recommendation status (`accepted` / `dismissed`) in `AiRecommendationsView`.
  2. CMS Change Request approval (`approved` / `rejected`) in `CmsApprovalsView`.
  3. Workflow Approval (`approved` / `rejected`) in `AutopilotView`.
- **Solution:** A unified **SEO Action Plan** where an issue triggers an AI recommendation, which previews a proposed fix and seamlessly transitions through a single approval/execution pipeline.

### Heuristic 5: Error Prevention & Clarity
- **Issue:** Users attempting to run a live CMS write on a `custom` website or a `Starter` plan encounter generic API error codes instead of contextual upgrade or setup guidance.

---

## 4. Technical Architecture & Constraints Audit

- **Framework Integrity:** Frontend is Next.js 16 with Tailwind CSS and Lucide icons; Backend is Laravel 11 with SQLite/MySQL, Sanctum auth, and robust domain services.
- **Preservation Principle:** No backend APIs or existing domain logic should be broken or rewritten. The redesign must purely orchestrate and streamline the UI layer, component architecture, and user workflows.
- **Security Invariance:** Tenant isolation (`X-Organization-Id`, `org.context` middleware) and role-based permissions must remain strictly enforced.
