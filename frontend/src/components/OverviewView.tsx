'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Sparkles,
  ArrowRight,
  CheckCircle2,
  AlertTriangle,
  Layers,
  Globe,
  Activity,
  CheckSquare,
  BarChart3,
  RefreshCw,
  Sliders,
  ChevronRight,
  Clock,
  ShieldCheck,
  ExternalLink,
  Target,
  FileText,
  Zap,
} from 'lucide-react';
import { Website, AiRecommendation, AuditRun } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface OverviewViewProps {
  website: Website | null;
  onNavigate: (tab: string) => void;
  onStartOnboarding: () => void;
  onRunAudit: () => void;
}

interface DashboardStats {
  healthScore: number | null;
  lastAudit: AuditRun | null;
  totalPages: number;
  openIssues: number;
  criticalIssues: number;
  warningIssues: number;
  infoIssues: number;
  verifiedFixes: number;
  pendingApprovals: number;
  topRecommendation: AiRecommendation | null;
  gscConnected: boolean;
  searchImpressions: number | null;
  searchClicks: number | null;
  autopilotActive: boolean;
}

export function OverviewView({
  website,
  onNavigate,
  onStartOnboarding,
  onRunAudit,
}: OverviewViewProps) {
  const toast = useToast();
  const [stats, setStats] = useState<DashboardStats>({
    healthScore: null,
    lastAudit: null,
    totalPages: 0,
    openIssues: 0,
    criticalIssues: 0,
    warningIssues: 0,
    infoIssues: 0,
    verifiedFixes: 0,
    pendingApprovals: 0,
    topRecommendation: null,
    gscConnected: false,
    searchImpressions: null,
    searchClicks: null,
    autopilotActive: false,
  });
  const [isLoading, setIsLoading] = useState<boolean>(false);

  const fetchOverviewData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      // 1. Fetch audits
      const auditRes = await api.get<AuditRun[]>(`/websites/${website.id}/audits`);
      let lastAudit: AuditRun | null = null;
      let healthScore: number | null = null;
      let totalPages = 0;
      let critical = 0;
      let warning = 0;
      let info = 0;

      if (auditRes.success && auditRes.data && auditRes.data.length > 0) {
        lastAudit = auditRes.data[0];
        healthScore = lastAudit.overall_score ?? null;
        totalPages = lastAudit.total_pages_analyzed || 0;
        critical = lastAudit.critical_count || 0;
        warning = lastAudit.warning_count || 0;
        info = lastAudit.info_count || 0;
      }

      // 2. Fetch AI recommendations for "Next Best Action"
      const recRes = await api.get<AiRecommendation[]>(`/websites/${website.id}/recommendations`);
      let topRecommendation: AiRecommendation | null = null;
      let pendingApprovals = 0;
      let verifiedFixes = 0;

      if (recRes.success && recRes.data) {
        const activeRecs = recRes.data;
        const actionable = activeRecs.filter(
          (r) => r.status === 'proposed' || r.status === 'accepted'
        );
        if (actionable.length > 0) {
          topRecommendation = actionable.sort((a, b) => (b.priority_score || 0) - (a.priority_score || 0))[0];
        }
        pendingApprovals = activeRecs.filter((r) => r.status === 'accepted').length;
        verifiedFixes = activeRecs.filter((r) => r.status === 'applied').length;
      }

      // 3. Check GSC integration & performance
      let gscConnected = false;
      let searchImpressions: number | null = null;
      let searchClicks: number | null = null;
      try {
        const perfRes = await api.get<any>(`/websites/${website.id}/search-performance`);
        if (perfRes.success && perfRes.data) {
          gscConnected = true;
          searchImpressions = perfRes.data.total_impressions ?? null;
          searchClicks = perfRes.data.total_clicks ?? null;
        }
      } catch {
        // GSC not connected or error, keep false
      }

      // 4. Check Autopilot status
      let autopilotActive = false;
      try {
        const autoRes = await api.get<any>(`/websites/${website.id}/autopilot/status`);
        if (autoRes.success && autoRes.data) {
          autopilotActive = !!autoRes.data.is_enabled;
        }
      } catch {
        // Ignored
      }

      setStats({
        healthScore,
        lastAudit,
        totalPages,
        openIssues: critical + warning + info,
        criticalIssues: critical,
        warningIssues: warning,
        infoIssues: info,
        verifiedFixes,
        pendingApprovals,
        topRecommendation,
        gscConnected,
        searchImpressions,
        searchClicks,
        autopilotActive,
      });
    } catch {
      toast.error('Failed to load overview data.');
    } finally {
      setIsLoading(false);
    }
  }, [website, toast]);

  useEffect(() => {
    fetchOverviewData();
  }, [fetchOverviewData]);

  if (!website) {
    return (
      <div className="bg-zinc-950/70 border border-zinc-800 rounded-2xl p-12 text-center max-w-2xl mx-auto my-12">
        <div className="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 mx-auto mb-4">
          <Globe className="w-8 h-8" />
        </div>
        <h2 className="text-xl font-bold text-zinc-100 mb-2">Welcome to Web SEO AI</h2>
        <p className="text-sm text-zinc-400 mb-6">
          Connect your website to run an instant technical SEO audit, discover high-impact keyword opportunities, and generate safe AI fixes.
        </p>
        <button
          onClick={onStartOnboarding}
          className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold rounded-xl transition text-sm shadow-lg shadow-emerald-500/20"
        >
          <Sparkles className="w-4 h-4" />
          <span>Launch Onboarding Wizard</span>
          <ArrowRight className="w-4 h-4" />
        </button>
      </div>
    );
  }

  const isVerified = website.verification_status === 'verified';

  return (
    <div className="space-y-8 animate-in fade-in duration-300">
      {/* Top Banner / Welcome Context */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-zinc-900 via-zinc-900/90 to-zinc-950 border border-zinc-800/90 rounded-2xl p-6 shadow-xl">
        <div>
          <div className="flex items-center gap-2.5 mb-1.5">
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse" />
            <h1 className="text-xl font-bold text-zinc-100 flex items-center gap-2">
              {website.name}
              <span className="text-xs font-mono font-normal text-zinc-400 px-2 py-0.5 rounded-md bg-zinc-800 border border-zinc-700">
                {website.domain}
              </span>
            </h1>
          </div>
          <p className="text-xs text-zinc-400 flex items-center gap-2">
            <span>Platform: <strong className="text-zinc-200 capitalize">{website.platform || 'Custom'}</strong></span>
            <span>•</span>
            <span>Verification: <strong className={isVerified ? 'text-emerald-400' : 'text-amber-400'}>{isVerified ? 'Ownership Verified' : 'Read-Only (Unverified)'}</strong></span>
            {stats.lastAudit && (
              <>
                <span>•</span>
                <span>Last Scan: <strong className="text-zinc-200">{new Date(stats.lastAudit.created_at).toLocaleDateString()}</strong></span>
              </>
            )}
          </p>
        </div>

        <div className="flex items-center gap-2.5 shrink-0">
          <button
            onClick={fetchOverviewData}
            disabled={isLoading}
            className="p-2.5 text-zinc-400 hover:text-zinc-200 bg-zinc-800/60 hover:bg-zinc-800 border border-zinc-700/60 rounded-xl transition text-xs flex items-center gap-1.5"
            title="Refresh dashboard metrics"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-emerald-400' : ''}`} />
          </button>
          <button
            onClick={onRunAudit}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-medium rounded-xl text-xs shadow-md transition"
          >
            <Activity className="w-4 h-4" />
            <span>Run New SEO Scan</span>
          </button>
        </div>
      </div>

      {/* Hero Section: "Your Next Best Action" Card */}
      <div className="relative overflow-hidden bg-gradient-to-br from-emerald-950/40 via-zinc-900/90 to-zinc-950 border border-emerald-500/30 rounded-2xl p-6 shadow-2xl">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
          <div className="space-y-2 max-w-2xl">
            <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-[11px] font-semibold text-emerald-400 uppercase tracking-wider">
              <Sparkles className="w-3.5 h-3.5" />
              Your Next Best Action
            </div>
            {stats.topRecommendation ? (
              <>
                <h3 className="text-lg font-bold text-zinc-100">
                  {stats.topRecommendation.title}
                </h3>
                <p className="text-xs text-zinc-300 leading-relaxed">
                  {stats.topRecommendation.recommended_action || stats.topRecommendation.reasoning_summary}
                </p>
                <div className="flex flex-wrap items-center gap-3 pt-2 text-[11px] text-zinc-400">
                  <span className="px-2 py-0.5 rounded bg-zinc-800 border border-zinc-700 text-zinc-300">
                    Target: <code className="text-emerald-400">{stats.topRecommendation.affected_url || '/'}</code>
                  </span>
                  <span className="px-2 py-0.5 rounded bg-emerald-950/60 border border-emerald-800 text-emerald-300 font-mono">
                    Priority Score: {stats.topRecommendation.priority_score ?? 85}/100
                  </span>
                  <span className="capitalize px-2 py-0.5 rounded bg-zinc-800 text-zinc-400">
                    Category: {stats.topRecommendation.category}
                  </span>
                </div>
              </>
            ) : stats.openIssues > 0 ? (
              <>
                <h3 className="text-lg font-bold text-zinc-100">
                  Generate Prioritized AI Action Plan
                </h3>
                <p className="text-xs text-zinc-300 leading-relaxed">
                  We identified {stats.openIssues} technical issues on {website.domain}. Let our AI Strategist analyze your website context and generate safe, one-click SEO fixes.
                </p>
              </>
            ) : (
              <>
                <h3 className="text-lg font-bold text-zinc-100">
                  Start Your Initial Website Scan
                </h3>
                <p className="text-xs text-zinc-300 leading-relaxed">
                  Run your first read-only technical audit to discover crawl errors, missing metadata, broken redirects, and speed bottlenecks.
                </p>
              </>
            )}
          </div>

          <div className="shrink-0 flex items-center">
            {stats.topRecommendation ? (
              <button
                onClick={() => onNavigate('action_plan')}
                className="inline-flex items-center gap-2 px-5 py-3 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold rounded-xl transition text-sm shadow-xl shadow-emerald-500/20 group"
              >
                <span>Review & Approve Fix</span>
                <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>
            ) : stats.openIssues > 0 ? (
              <button
                onClick={() => onNavigate('action_plan')}
                className="inline-flex items-center gap-2 px-5 py-3 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold rounded-xl transition text-sm shadow-xl shadow-emerald-500/20 group"
              >
                <span>Generate Action Plan</span>
                <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>
            ) : (
              <button
                onClick={onRunAudit}
                className="inline-flex items-center gap-2 px-5 py-3 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold rounded-xl transition text-sm shadow-xl shadow-emerald-500/20 group"
              >
                <span>Run Initial Scan</span>
                <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Vital Metric Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Metric 1: Health Score */}
        <div
          onClick={() => onNavigate('audits')}
          className="cursor-pointer bg-zinc-900/60 hover:bg-zinc-900/90 border border-zinc-800 hover:border-zinc-700/80 rounded-2xl p-5 transition group"
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-medium text-zinc-400">SEO Health Score</span>
            <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
              <Activity className="w-4 h-4" />
            </div>
          </div>
          <div className="flex items-baseline gap-2 mb-1">
            <span className="text-2xl font-black text-zinc-100 font-mono">
              {stats.healthScore !== null ? `${stats.healthScore}%` : '—'}
            </span>
            {stats.healthScore !== null && (
              <span className={`text-[11px] font-semibold ${stats.healthScore >= 80 ? 'text-emerald-400' : stats.healthScore >= 60 ? 'text-amber-400' : 'text-red-400'}`}>
                {stats.healthScore >= 80 ? 'Good' : stats.healthScore >= 60 ? 'Needs Attention' : 'Critical'}
              </span>
            )}
          </div>
          <p className="text-[11px] text-zinc-400 flex items-center justify-between">
            <span>{stats.totalPages} pages crawled</span>
            <span className="group-hover:text-emerald-400 transition-colors flex items-center gap-0.5">
              Audit Details <ChevronRight className="w-3 h-3" />
            </span>
          </p>
        </div>

        {/* Metric 2: Open Issues */}
        <div
          onClick={() => onNavigate('audits')}
          className="cursor-pointer bg-zinc-900/60 hover:bg-zinc-900/90 border border-zinc-800 hover:border-zinc-700/80 rounded-2xl p-5 transition group"
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-medium text-zinc-400">Open SEO Issues</span>
            <div className="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
              <AlertTriangle className="w-4 h-4" />
            </div>
          </div>
          <div className="flex items-baseline gap-2 mb-1">
            <span className="text-2xl font-black text-zinc-100 font-mono">
              {stats.openIssues}
            </span>
            <div className="flex items-center gap-1.5 text-[10px] font-medium">
              <span className="text-red-400 font-mono">{stats.criticalIssues} crit</span>
              <span className="text-zinc-600">•</span>
              <span className="text-amber-400 font-mono">{stats.warningIssues} warn</span>
            </div>
          </div>
          <p className="text-[11px] text-zinc-400 flex items-center justify-between">
            <span>Technical bottlenecks</span>
            <span className="group-hover:text-amber-400 transition-colors flex items-center gap-0.5">
              Fix in Action Plan <ChevronRight className="w-3 h-3" />
            </span>
          </p>
        </div>

        {/* Metric 3: Pending Approvals */}
        <div
          onClick={() => onNavigate('action_plan')}
          className="cursor-pointer bg-zinc-900/60 hover:bg-zinc-900/90 border border-zinc-800 hover:border-zinc-700/80 rounded-2xl p-5 transition group"
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-medium text-zinc-400">Pending Approvals</span>
            <div className="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
              <CheckSquare className="w-4 h-4" />
            </div>
          </div>
          <div className="flex items-baseline gap-2 mb-1">
            <span className="text-2xl font-black text-zinc-100 font-mono">
              {stats.pendingApprovals}
            </span>
            <span className="text-[11px] text-zinc-400">queued changes</span>
          </div>
          <p className="text-[11px] text-zinc-400 flex items-center justify-between">
            <span>{stats.verifiedFixes} verified fixes live</span>
            <span className="group-hover:text-indigo-400 transition-colors flex items-center gap-0.5">
              Review Queue <ChevronRight className="w-3 h-3" />
            </span>
          </p>
        </div>

        {/* Metric 4: Search Performance / GSC */}
        <div
          onClick={() => onNavigate('performance')}
          className="cursor-pointer bg-zinc-900/60 hover:bg-zinc-900/90 border border-zinc-800 hover:border-zinc-700/80 rounded-2xl p-5 transition group"
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs font-medium text-zinc-400">Google Search Traffic</span>
            <div className="w-8 h-8 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400">
              <BarChart3 className="w-4 h-4" />
            </div>
          </div>
          <div className="flex items-baseline gap-2 mb-1">
            {stats.gscConnected && stats.searchClicks !== null ? (
              <>
                <span className="text-2xl font-black text-zinc-100 font-mono">
                  {stats.searchClicks.toLocaleString()}
                </span>
                <span className="text-[11px] text-emerald-400">clicks / 28d</span>
              </>
            ) : (
              <>
                <span className="text-sm font-semibold text-zinc-400">Not Connected</span>
              </>
            )}
          </div>
          <p className="text-[11px] text-zinc-400 flex items-center justify-between">
            <span>{stats.gscConnected ? `${stats.searchImpressions?.toLocaleString() ?? 0} impressions` : 'Connect Search Console'}</span>
            <span className="group-hover:text-sky-400 transition-colors flex items-center gap-0.5">
              Explore <ChevronRight className="w-3 h-3" />
            </span>
          </p>
        </div>
      </div>

      {/* 2-Column Action & Telemetry Hub */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Column 1 & 2: Quick Navigation & Action Workflow */}
        <div className="lg:col-span-2 space-y-6">
          <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-6">
            <h3 className="text-sm font-bold text-zinc-200 uppercase tracking-wider mb-4 flex items-center gap-2">
              <Layers className="w-4 h-4 text-emerald-400" />
              SEO Management Workflows
            </h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <button
                onClick={() => onNavigate('action_plan')}
                className="flex items-start gap-3.5 p-4 rounded-xl bg-zinc-950/60 hover:bg-zinc-950 border border-zinc-800 hover:border-emerald-500/40 text-left transition group"
              >
                <div className="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 shrink-0 group-hover:scale-105 transition-transform">
                  <Sparkles className="w-4 h-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-zinc-100 group-hover:text-emerald-300 transition-colors">
                    SEO Action Plan
                  </h4>
                  <p className="text-[11px] text-zinc-400 mt-0.5 leading-relaxed">
                    Prioritized AI fixes with side-by-side diff previews and one-click CMS publishing.
                  </p>
                </div>
              </button>

              <button
                onClick={() => onNavigate('audits')}
                className="flex items-start gap-3.5 p-4 rounded-xl bg-zinc-950/60 hover:bg-zinc-950 border border-zinc-800 hover:border-emerald-500/40 text-left transition group"
              >
                <div className="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 shrink-0 group-hover:scale-105 transition-transform">
                  <Activity className="w-4 h-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-zinc-100 group-hover:text-amber-300 transition-colors">
                    Technical Site Audit
                  </h4>
                  <p className="text-[11px] text-zinc-400 mt-0.5 leading-relaxed">
                    Inspect crawl health, robots.txt, canonicals, 404s, and page speed diagnostics.
                  </p>
                </div>
              </button>

              <button
                onClick={() => onNavigate('content')}
                className="flex items-start gap-3.5 p-4 rounded-xl bg-zinc-950/60 hover:bg-zinc-950 border border-zinc-800 hover:border-emerald-500/40 text-left transition group"
              >
                <div className="w-9 h-9 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 shrink-0 group-hover:scale-105 transition-transform">
                  <FileText className="w-4 h-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-zinc-100 group-hover:text-purple-300 transition-colors">
                    Content Studio
                  </h4>
                  <p className="text-[11px] text-zinc-400 mt-0.5 leading-relaxed">
                    Generate briefs, meta tags, and high-ranking articles tailored to your brand voice.
                  </p>
                </div>
              </button>

              <button
                onClick={() => onNavigate('performance')}
                className="flex items-start gap-3.5 p-4 rounded-xl bg-zinc-950/60 hover:bg-zinc-950 border border-zinc-800 hover:border-emerald-500/40 text-left transition group"
              >
                <div className="w-9 h-9 rounded-xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400 shrink-0 group-hover:scale-105 transition-transform">
                  <BarChart3 className="w-4 h-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-zinc-100 group-hover:text-sky-300 transition-colors">
                    Search Console & Keywords
                  </h4>
                  <p className="text-[11px] text-zinc-400 mt-0.5 leading-relaxed">
                    Monitor real organic rankings, keyword positions, and discover low-hanging fruit.
                  </p>
                </div>
              </button>
            </div>
          </div>
        </div>

        {/* Column 3: Platform Integration & Autopilot Status */}
        <div className="space-y-6">
          <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-6 space-y-4">
            <h3 className="text-sm font-bold text-zinc-200 uppercase tracking-wider flex items-center justify-between">
              <span>Platform Safeguards</span>
              <ShieldCheck className="w-4 h-4 text-emerald-400" />
            </h3>

            <div className="space-y-3">
              <div className="p-3 rounded-xl bg-zinc-950/60 border border-zinc-800 flex items-center justify-between">
                <div>
                  <div className="text-xs font-semibold text-zinc-200">CMS Connection</div>
                  <div className="text-[11px] text-zinc-400 capitalize">{website.platform || 'Custom (Read-Only)'}</div>
                </div>
                <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${isVerified ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-zinc-800 text-zinc-400'}`}>
                  {isVerified ? 'Authorized' : 'Read-Only'}
                </span>
              </div>

              <div className="p-3 rounded-xl bg-zinc-950/60 border border-zinc-800 flex items-center justify-between">
                <div>
                  <div className="text-xs font-semibold text-zinc-200">Autopilot Engine</div>
                  <div className="text-[11px] text-zinc-400">Scheduled audits & rules</div>
                </div>
                <button
                  onClick={() => onNavigate('autopilot')}
                  className={`text-[10px] font-semibold px-2 py-0.5 rounded-full transition ${stats.autopilotActive ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-zinc-800 text-zinc-400 hover:text-zinc-200'}`}
                >
                  {stats.autopilotActive ? 'Active' : 'Configure'}
                </button>
              </div>

              <div className="p-3 rounded-xl bg-zinc-950/60 border border-zinc-800 flex items-center justify-between">
                <div>
                  <div className="text-xs font-semibold text-zinc-200">Change Rollback</div>
                  <div className="text-[11px] text-zinc-400">Pre-execution snapshots</div>
                </div>
                <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                  Protected
                </span>
              </div>
            </div>

            <div className="pt-2">
              <button
                onClick={onStartOnboarding}
                className="w-full py-2.5 px-3 rounded-xl bg-zinc-800/80 hover:bg-zinc-800 border border-zinc-700/60 text-xs text-zinc-300 hover:text-white font-medium flex items-center justify-center gap-1.5 transition"
              >
                <Sliders className="w-3.5 h-3.5 text-emerald-400" />
                <span>Re-run Setup Wizard</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
