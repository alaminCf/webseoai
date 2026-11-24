'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Globe,
  Play,
  GitCompare,
  Download,
  AlertCircle,
  AlertTriangle,
  Info,
  CheckCircle2,
  RefreshCw,
  Layers,
  FileText,
  ShieldCheck,
  ShieldAlert,
  ListFilter,
  History,
  Sparkles,
  Lock,
  FileCode2,
  Map,
  Gauge,
  Cpu,
  Ban,
} from 'lucide-react';
import { Website, AuditRun, AuditIssue, CrawledPage } from '@/types';
import { api, getApiBaseUrl } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { CrawlProgressModal } from './CrawlProgressModal';
import { AuditCompareModal } from './AuditCompareModal';
import { AuditIssuesTable } from './AuditIssuesTable';
import { CrawledPagesTable } from './CrawledPagesTable';
import { QuickExportButtons } from './QuickExportButtons';

interface AuditOverviewViewProps {
  website: Website | null;
  onRefreshWebsites?: () => void;
  onNavigateToStrategist?: () => void;
}

export function AuditOverviewView({ website, onRefreshWebsites, onNavigateToStrategist }: AuditOverviewViewProps) {
  const toast = useToast();
  const [activeTab, setActiveTab] = useState<'issues' | 'passed' | 'pages' | 'history'>('issues');

  // Modals
  const [isCrawlModalOpen, setIsCrawlModalOpen] = useState<boolean>(false);
  const [isCompareModalOpen, setIsCompareModalOpen] = useState<boolean>(false);

  // Data states
  const [latestAudit, setLatestAudit] = useState<AuditRun | null>(null);
  const [auditRuns, setAuditRuns] = useState<AuditRun[]>([]);
  const [issues, setIssues] = useState<AuditIssue[]>([]);
  const [crawledPages, setCrawledPages] = useState<CrawledPage[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isExporting, setIsExporting] = useState<boolean>(false);

  const loadRunDetails = useCallback(async (run: AuditRun) => {
    const [issuesRes, pagesRes] = await Promise.all([
      api.get<AuditIssue[]>(`/audits/${run.id}/issues?per_page=500`),
      run.crawl_job_id
        ? api.get<CrawledPage[]>(`/crawl-jobs/${run.crawl_job_id}/pages?per_page=500`)
        : Promise.resolve({ success: false, data: [] as CrawledPage[] }),
    ]);

    if (issuesRes.success && issuesRes.data) {
      setIssues(issuesRes.data);
    } else {
      setIssues([]);
    }

    if (pagesRes.success && pagesRes.data) {
      setCrawledPages(pagesRes.data);
    } else {
      setCrawledPages([]);
    }
  }, []);

  const loadAuditData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      const [auditRes, runsRes] = await Promise.all([
        api.get<AuditRun>(`/websites/${website.id}/audits/latest`),
        api.get<AuditRun[]>(`/websites/${website.id}/audits`),
      ]);

      if (auditRes.success && auditRes.data) {
        setLatestAudit(auditRes.data);
        await loadRunDetails(auditRes.data);
      } else {
        setLatestAudit(null);
        setIssues([]);
        setCrawledPages([]);
      }

      if (runsRes.success && runsRes.data) {
        setAuditRuns(runsRes.data);
      } else {
        setAuditRuns([]);
      }
    } catch {
      toast.error('Could not load technical audit data.');
    } finally {
      setIsLoading(false);
    }
  }, [website, toast, loadRunDetails]);

  useEffect(() => {
    loadAuditData();
  }, [loadAuditData]);

  const handleExport = async (format: 'csv' | 'json' = 'csv') => {
    if (!latestAudit || !website) return;
    setIsExporting(true);
    try {
      const token = api.getToken();
      const orgId = api.getOrganizationId();
      const baseUrl = getApiBaseUrl();
      const exportUrl = `${baseUrl}/audits/${latestAudit.id}/export?format=${format}`;

      const res = await fetch(exportUrl, {
        headers: {
          Authorization: token ? `Bearer ${token}` : '',
          'X-Organization-Id': orgId ? orgId.toString() : '',
        },
      });

      if (!res.ok) {
        toast.error('Failed to generate export file.');
        return;
      }

      if (format === 'csv') {
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `seo-audit-${website.domain}-run-${latestAudit.id}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        toast.success('Audit CSV export downloaded successfully!');
      } else {
        const json = await res.json();
        const blob = new Blob([JSON.stringify(json, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `seo-audit-${website.domain}-run-${latestAudit.id}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        toast.success('Audit JSON report downloaded successfully!');
      }
    } catch {
      toast.error('Network error exporting report.');
    } finally {
      setIsExporting(false);
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <Globe className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-500 mt-1">
          Select or add a website in your workspace to run technical SEO audits.
        </p>
      </div>
    );
  }

  const stats = latestAudit?.summary_stats || null;
  const siteChecks = stats?.site_checks || null;
  const passedChecks = stats?.passed_checks || [];
  const linksScore = latestAudit ? (latestAudit.links_score ?? latestAudit.link_score ?? 0) : 0;
  const indexingScore = latestAudit ? (latestAudit.indexing_score ?? 100) : 0;
  const pagesAnalyzed = latestAudit
    ? (latestAudit.total_pages_analyzed ?? stats?.total_pages_crawled ?? crawledPages.length)
    : 0;

  const getScoreColor = (score: number) =>
    score >= 80 ? 'text-emerald-400' : score >= 50 ? 'text-amber-400' : 'text-rose-400';

  const getScoreBarColor = (score: number) =>
    score >= 80 ? 'bg-emerald-500' : score >= 50 ? 'bg-amber-500' : 'bg-rose-500';

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Header Banner */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
        <div>
          <div className="flex items-center gap-2 mb-1.5 flex-wrap">
            <span className="p-1 rounded-md bg-emerald-950/60 border border-emerald-800/60 text-emerald-400">
              <Globe className="w-4 h-4" />
            </span>
            <h2 className="text-lg font-bold text-zinc-100 font-mono">{website.domain}</h2>
            <span className="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
              {website.platform}
            </span>
            {website.verified_at ? (
              <span className="text-[10px] font-semibold text-emerald-400 flex items-center gap-1 bg-emerald-950/30 px-2 py-0.5 rounded border border-emerald-800/40">
                <ShieldCheck className="w-3 h-3" /> Verified Target
              </span>
            ) : (
              <span className="text-[10px] font-semibold text-amber-400 flex items-center gap-1 bg-amber-950/30 px-2 py-0.5 rounded border border-amber-800/40">
                <ShieldAlert className="w-3 h-3" /> Unverified
              </span>
            )}
          </div>
          <p className="text-xs text-zinc-400 font-mono">
            {website.target_url} — Last Crawled:{' '}
            {website.last_crawled_at ? new Date(website.last_crawled_at).toLocaleString() : 'Never'}
          </p>
        </div>

        {/* Action Controls */}
        <div className="flex flex-wrap items-center gap-2.5">
          {onNavigateToStrategist && (
            <button
              onClick={onNavigateToStrategist}
              className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-emerald-300 bg-emerald-950/60 hover:bg-emerald-900/60 border border-emerald-800/60 rounded-xl shadow transition"
              title="Prioritized AI Recommendations & ICE Scoring"
            >
              <Sparkles className="w-3.5 h-3.5 text-emerald-400" />
              <span>AI Strategist</span>
            </button>
          )}

          <button
            onClick={() => setIsCrawlModalOpen(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
          >
            <Play className="w-3.5 h-3.5" />
            <span>Launch Audit Crawl</span>
          </button>

          <button
            onClick={() => setIsCompareModalOpen(true)}
            disabled={auditRuns.length < 2}
            className="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 disabled:opacity-40 border border-zinc-800 rounded-xl transition"
            title={auditRuns.length < 2 ? 'Requires at least 2 audit runs' : 'Compare Before/After'}
          >
            <GitCompare className="w-3.5 h-3.5 text-cyan-400" />
            <span>Compare Runs</span>
          </button>

          <QuickExportButtons
            website={website}
            section="site_audit"
            csvDataset="audit_issues"
            reportTitle="Technical Site Audit & Issues Report"
            filenamePrefix="site-audit"
          />
          <div className="flex items-center bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
            <button
              onClick={() => handleExport('csv')}
              disabled={!latestAudit || isExporting}
              className="px-3 py-2 text-xs font-semibold text-zinc-300 hover:text-white hover:bg-zinc-800 disabled:opacity-40 border-r border-zinc-800 transition flex items-center gap-1.5"
            >
              <Download className="w-3.5 h-3.5 text-emerald-400" />
              <span>Export CSV</span>
            </button>
            <button
              onClick={() => handleExport('json')}
              disabled={!latestAudit || isExporting}
              className="px-2.5 py-2 text-xs font-semibold text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800 disabled:opacity-40 transition font-mono"
            >
              JSON
            </button>
          </div>
        </div>
      </div>

      {/* Honest Diagnostics Banners (WAF / JS SPA Rendering) */}
      {stats && (stats.waf_blocked_pages || 0) > 0 && (
        <div className="flex items-start gap-3 p-4 bg-rose-950/25 border border-rose-800/50 rounded-2xl text-xs text-rose-200">
          <Ban className="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
          <div>
            <span className="font-bold text-rose-300">
              WAF / Anti-Bot Protection Detected ({stats.waf_blocked_pages} page{stats.waf_blocked_pages === 1 ? '' : 's'}):
            </span>{' '}
            The target server returned a firewall or bot challenge interstitial instead of real HTML. Content checks were automatically skipped on blocked URLs to prevent misleading audit scores. Allowlist <code className="px-1 py-0.5 rounded bg-black/40">WebSeoAi-AuditBot</code> in your firewall for complete inspection.
          </div>
        </div>
      )}

      {stats && (stats.js_rendered_pages || 0) > 0 && (
        <div className="flex items-start gap-3 p-3.5 bg-cyan-950/20 border border-cyan-800/40 rounded-2xl text-xs text-cyan-200">
          <Cpu className="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
          <div>
            <span className="font-semibold text-cyan-300">
              SPA Web Rendering Service Used ({stats.js_rendered_pages} page{stats.js_rendered_pages === 1 ? '' : 's'}):
            </span>{' '}
            Client-side JavaScript app detected. Our headless renderer hydrated the live DOM to inspect real rendered headings, word counts, images, and internal links.
          </div>
        </div>
      )}

      {/* 5-Card Executive Health Score Grid */}
      {latestAudit ? (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
            {/* Overall Health Score */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between shadow-sm relative overflow-hidden">
              <div className="absolute top-0 right-0 w-24 h-24 bg-emerald-500/5 blur-2xl rounded-full" />
              <div>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                  Overall Health Score
                </span>
                <div className="flex items-baseline gap-2">
                  <span className={`text-4xl font-extrabold font-mono tracking-tight ${getScoreColor(latestAudit.overall_score)}`}>
                    {latestAudit.overall_score}
                  </span>
                  <span className="text-zinc-500 text-sm font-mono">/100</span>
                </div>
              </div>
              <div className="mt-4 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400 flex items-center justify-between">
                <span>Pages Analyzed</span>
                <span className="font-mono font-bold text-zinc-200">{pagesAnalyzed}</span>
              </div>
            </div>

            {/* Technical & Performance (30%) */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between shadow-sm">
              <div>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                  Technical & Speed
                </span>
                <div className="flex items-baseline gap-2">
                  <span className={`text-3xl font-extrabold font-mono ${getScoreColor(latestAudit.technical_score)}`}>
                    {latestAudit.technical_score}
                  </span>
                  <span className="text-zinc-500 text-xs font-mono">/100</span>
                </div>
                <div className="w-full bg-zinc-900 h-1.5 rounded-full mt-3 overflow-hidden">
                  <div
                    className={`${getScoreBarColor(latestAudit.technical_score)} h-full rounded-full transition-all`}
                    style={{ width: `${latestAudit.technical_score}%` }}
                  />
                </div>
              </div>
              <div className="mt-4 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400 flex items-center justify-between">
                <span>HTTPS, HTTP, TTFB</span>
                <span className="font-mono text-zinc-300">30% wt</span>
              </div>
            </div>

            {/* On-Page & Content (30%) */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between shadow-sm">
              <div>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                  Content & On-Page
                </span>
                <div className="flex items-baseline gap-2">
                  <span className={`text-3xl font-extrabold font-mono ${getScoreColor(latestAudit.content_score)}`}>
                    {latestAudit.content_score}
                  </span>
                  <span className="text-zinc-500 text-xs font-mono">/100</span>
                </div>
                <div className="w-full bg-zinc-900 h-1.5 rounded-full mt-3 overflow-hidden">
                  <div
                    className={`${getScoreBarColor(latestAudit.content_score)} h-full rounded-full transition-all`}
                    style={{ width: `${latestAudit.content_score}%` }}
                  />
                </div>
              </div>
              <div className="mt-4 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400 flex items-center justify-between">
                <span>Titles, H1, Schema, Alt</span>
                <span className="font-mono text-zinc-300">30% wt</span>
              </div>
            </div>

            {/* Indexing & Crawlability (20%) */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between shadow-sm">
              <div>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                  Indexing & Robots
                </span>
                <div className="flex items-baseline gap-2">
                  <span className={`text-3xl font-extrabold font-mono ${getScoreColor(indexingScore)}`}>
                    {indexingScore}
                  </span>
                  <span className="text-zinc-500 text-xs font-mono">/100</span>
                </div>
                <div className="w-full bg-zinc-900 h-1.5 rounded-full mt-3 overflow-hidden">
                  <div
                    className={`${getScoreBarColor(indexingScore)} h-full rounded-full transition-all`}
                    style={{ width: `${indexingScore}%` }}
                  />
                </div>
              </div>
              <div className="mt-4 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400 flex items-center justify-between">
                <span>Robots, Sitemap, Canonical</span>
                <span className="font-mono text-zinc-300">20% wt</span>
              </div>
            </div>

            {/* Links & Architecture (20%) */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between shadow-sm">
              <div>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                  Link Architecture
                </span>
                <div className="flex items-baseline gap-2">
                  <span className={`text-3xl font-extrabold font-mono ${getScoreColor(linksScore)}`}>
                    {linksScore}
                  </span>
                  <span className="text-zinc-500 text-xs font-mono">/100</span>
                </div>
                <div className="w-full bg-zinc-900 h-1.5 rounded-full mt-3 overflow-hidden">
                  <div
                    className={`${getScoreBarColor(linksScore)} h-full rounded-full transition-all`}
                    style={{ width: `${linksScore}%` }}
                  />
                </div>
              </div>
              <div className="mt-4 pt-3 border-t border-zinc-800/60 text-[11px] text-zinc-400 flex items-center justify-between">
                <span>Graph, Orphans, Depth</span>
                <span className="font-mono text-zinc-300">20% wt</span>
              </div>
            </div>
          </div>

          {/* Site-Level Foundation Telemetry Bar */}
          {stats && (
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 text-xs">
              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <Lock className={`w-4 h-4 shrink-0 ${siteChecks?.https_enforcement?.uses_https !== false ? 'text-emerald-400' : 'text-rose-400'}`} />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">HTTPS</div>
                  <div className="font-semibold text-zinc-200 truncate">
                    {siteChecks?.https_enforcement?.uses_https === false ? 'Insecure HTTP' : 'Encrypted'}
                  </div>
                </div>
              </div>

              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <FileCode2 className={`w-4 h-4 shrink-0 ${siteChecks?.robots_txt?.exists ? (siteChecks.robots_txt.blocks_all ? 'text-rose-400' : 'text-emerald-400') : 'text-amber-400'}`} />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">robots.txt</div>
                  <div className="font-semibold text-zinc-200 truncate">
                    {siteChecks?.robots_txt?.blocks_all
                      ? 'Blocking All!'
                      : siteChecks?.robots_txt?.exists
                      ? 'Valid'
                      : 'Missing'}
                  </div>
                </div>
              </div>

              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <Map className={`w-4 h-4 shrink-0 ${siteChecks?.sitemap?.found ? 'text-emerald-400' : 'text-amber-400'}`} />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">XML Sitemap</div>
                  <div className="font-semibold text-zinc-200 truncate font-mono">
                    {siteChecks?.sitemap?.found ? `${siteChecks.sitemap.urls_count} URLs` : 'Not Found'}
                  </div>
                </div>
              </div>

              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-400" />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">Indexable</div>
                  <div className="font-semibold text-zinc-200 font-mono">
                    {stats.indexable_pages ?? 0} / {pagesAnalyzed}
                  </div>
                </div>
              </div>

              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <Gauge className={`w-4 h-4 shrink-0 ${(stats.avg_load_time_ms || 0) <= 1000 ? 'text-emerald-400' : (stats.avg_load_time_ms || 0) <= 2500 ? 'text-amber-400' : 'text-rose-400'}`} />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">Avg TTFB</div>
                  <div className="font-semibold text-zinc-200 font-mono">
                    {stats.avg_load_time_ms ?? 0}ms
                  </div>
                </div>
              </div>

              <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-xl flex items-center gap-2.5">
                <FileText className="w-4 h-4 shrink-0 text-cyan-400" />
                <div className="min-w-0">
                  <div className="text-[10px] text-zinc-500 uppercase">Avg Words</div>
                  <div className="font-semibold text-zinc-200 font-mono">
                    {stats.avg_word_count ?? 0}
                  </div>
                </div>
              </div>
            </div>
          )}
        </>
      ) : (
        <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <Layers className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
          <p className="text-sm font-semibold text-zinc-300">No Audits Run Yet</p>
          <p className="text-xs text-zinc-500 mt-1 max-w-md mx-auto mb-4">
            Click &quot;Launch Audit Crawl&quot; to run a complete technical, on-page, indexing, and link-graph SEO audit on {website.domain}.
          </p>
          <button
            onClick={() => setIsCrawlModalOpen(true)}
            className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow transition"
          >
            <Play className="w-3.5 h-3.5" />
            Launch Technical Audit Crawl
          </button>
        </div>
      )}

      {/* Issues Severity + Passed Checks Summary Strip */}
      {latestAudit && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <button
            onClick={() => setActiveTab('issues')}
            className="text-left p-3.5 bg-rose-950/20 hover:bg-rose-950/30 border border-rose-800/40 rounded-xl flex items-center justify-between transition"
          >
            <div className="flex items-center gap-2.5">
              <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" />
              <div>
                <span className="text-xs font-bold text-rose-400 block">Critical Errors</span>
                <span className="text-[10px] text-zinc-400">4xx/5xx, broken links, missing title/H1</span>
              </div>
            </div>
            <span className="text-lg font-bold font-mono text-rose-400">
              {latestAudit.critical_count}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('issues')}
            className="text-left p-3.5 bg-amber-950/20 hover:bg-amber-950/30 border border-amber-800/40 rounded-xl flex items-center justify-between transition"
          >
            <div className="flex items-center gap-2.5">
              <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0" />
              <div>
                <span className="text-xs font-bold text-amber-400 block">Warnings</span>
                <span className="text-[10px] text-zinc-400">Canonicals, meta, redirects, alt text</span>
              </div>
            </div>
            <span className="text-lg font-bold font-mono text-amber-400">
              {latestAudit.warning_count}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('issues')}
            className="text-left p-3.5 bg-blue-950/20 hover:bg-blue-950/30 border border-blue-800/40 rounded-xl flex items-center justify-between transition"
          >
            <div className="flex items-center gap-2.5">
              <Info className="w-4 h-4 text-blue-400 shrink-0" />
              <div>
                <span className="text-xs font-bold text-blue-400 block">Notices</span>
                <span className="text-[10px] text-zinc-400">Schema, OG tags, headers, depth</span>
              </div>
            </div>
            <span className="text-lg font-bold font-mono text-blue-400">
              {latestAudit.info_count}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('passed')}
            className="text-left p-3.5 bg-emerald-950/20 hover:bg-emerald-950/30 border border-emerald-800/40 rounded-xl flex items-center justify-between transition"
          >
            <div className="flex items-center gap-2.5">
              <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
              <div>
                <span className="text-xs font-bold text-emerald-400 block">Passed Checks</span>
                <span className="text-[10px] text-zinc-400">Verified healthy SEO signals</span>
              </div>
            </div>
            <span className="text-lg font-bold font-mono text-emerald-400">
              {passedChecks.length}
            </span>
          </button>
        </div>
      )}

      {/* Sub-view Nav Tabs */}
      <div className="flex items-center justify-between border-b border-zinc-800/80 pb-2 gap-2 flex-wrap">
        <div className="flex items-center gap-1.5 text-xs flex-wrap">
          <button
            onClick={() => setActiveTab('issues')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'issues'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <ListFilter className="w-3.5 h-3.5 text-rose-400" />
            <span>Issues ({issues.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('passed')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'passed'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
            <span>Passed Checks ({passedChecks.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('pages')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'pages'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <FileText className="w-3.5 h-3.5 text-cyan-400" />
            <span>Crawled Pages ({crawledPages.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('history')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'history'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <History className="w-3.5 h-3.5 text-indigo-400" />
            <span>Audit History ({auditRuns.length})</span>
          </button>
        </div>

        <button
          onClick={loadAuditData}
          disabled={isLoading}
          className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-lg transition"
          title="Refresh audit data"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* Tab Panels */}
      {activeTab === 'issues' && (
        <AuditIssuesTable issues={issues} isLoading={isLoading} />
      )}

      {activeTab === 'passed' && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 shadow-sm">
          {passedChecks.length === 0 ? (
            <div className="p-8 text-center text-zinc-500 text-xs">
              Run a fresh audit crawl to generate the full verified Passed Checks breakdown.
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {passedChecks.map((check) => (
                <div
                  key={check.code}
                  className="p-4 bg-emerald-950/15 border border-emerald-800/35 rounded-xl flex items-start gap-3"
                >
                  <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                  <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-xs font-bold text-zinc-100">{check.label}</span>
                      <span className="px-1.5 py-0.5 text-[9px] font-mono uppercase bg-zinc-900 text-zinc-400 border border-zinc-800 rounded">
                        {check.category}
                      </span>
                    </div>
                    <p className="text-xs text-zinc-400 mt-1 leading-relaxed">{check.detail}</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {activeTab === 'pages' && (
        <CrawledPagesTable pages={crawledPages} isLoading={isLoading} />
      )}

      {activeTab === 'history' && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
          {auditRuns.length === 0 ? (
            <div className="p-8 text-center text-zinc-500 text-xs">
              No historical audit runs recorded yet.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="border-b border-zinc-800/80 bg-zinc-900/50 text-zinc-400 font-medium">
                    <th className="py-3 px-4">Run ID</th>
                    <th className="py-3 px-4">Audit Timestamp</th>
                    <th className="py-3 px-4">Overall</th>
                    <th className="py-3 px-4">Pillars (T/C/I/L)</th>
                    <th className="py-3 px-4">Pages</th>
                    <th className="py-3 px-4">Critical / Warn</th>
                    <th className="py-3 px-4 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/60">
                  {auditRuns.map((run) => (
                    <tr
                      key={run.id}
                      className={`hover:bg-zinc-900/40 transition ${
                        latestAudit?.id === run.id ? 'bg-emerald-950/10' : ''
                      }`}
                    >
                      <td className="py-3.5 px-4 font-mono font-bold text-zinc-200">
                        #{run.id}
                      </td>
                      <td className="py-3.5 px-4 text-zinc-400 font-mono">
                        {new Date(run.created_at).toLocaleString()}
                      </td>
                      <td className="py-3.5 px-4">
                        <span
                          className={`font-mono font-bold px-2 py-0.5 rounded ${
                            run.overall_score >= 80
                              ? 'text-emerald-400 bg-emerald-950/40'
                              : run.overall_score >= 50
                              ? 'text-amber-400 bg-amber-950/40'
                              : 'text-rose-400 bg-rose-950/40'
                          }`}
                        >
                          {run.overall_score}/100
                        </span>
                      </td>
                      <td className="py-3.5 px-4 font-mono text-[11px] text-zinc-400">
                        {run.technical_score} / {run.content_score} / {run.indexing_score ?? 100} / {run.links_score ?? run.link_score ?? 0}
                      </td>
                      <td className="py-3.5 px-4 font-mono text-zinc-300">
                        {run.total_pages_analyzed ?? run.summary_stats?.total_pages_crawled ?? 0}
                      </td>
                      <td className="py-3.5 px-4 font-mono">
                        <span className="text-rose-400 font-bold">{run.critical_count}</span>
                        <span className="text-zinc-600 mx-1">/</span>
                        <span className="text-amber-400">{run.warning_count}</span>
                      </td>
                      <td className="py-3.5 px-4 text-right">
                        <button
                          onClick={async () => {
                            setLatestAudit(run);
                            await loadRunDetails(run);
                            setActiveTab('issues');
                            toast.info(`Switched to inspection of Audit Run #${run.id}`);
                          }}
                          className="px-2.5 py-1 text-[11px] font-semibold text-emerald-400 bg-emerald-950/40 border border-emerald-800/40 rounded-lg hover:bg-emerald-900/40 transition"
                        >
                          Inspect Run
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Modals */}
      <CrawlProgressModal
        website={website}
        isOpen={isCrawlModalOpen}
        onClose={() => setIsCrawlModalOpen(false)}
        onCrawlCompleted={() => {
          loadAuditData();
          onRefreshWebsites?.();
        }}
      />

      <AuditCompareModal
        website={website}
        isOpen={isCompareModalOpen}
        onClose={() => setIsCompareModalOpen(false)}
      />
    </div>
  );
}
