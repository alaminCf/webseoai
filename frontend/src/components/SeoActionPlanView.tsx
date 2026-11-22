'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Sparkles,
  CheckCircle2,
  Clock,
  RefreshCw,
  Sliders,
  Check,
  X,
  Play,
  RotateCcw,
  Shield,
  ExternalLink,
  Eye,
  Search,
  Copy,
  Download,
  History,
  Ban,
  Zap,
  Wrench,
  AlertTriangle,
} from 'lucide-react';
import { Website, AiRecommendation, CmsChangeRequest, CmsSnapshot } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { ContextProfileModal } from './ContextProfileModal';

interface SeoActionPlanViewProps {
  website: Website | null;
  onNavigateToContent?: () => void;
  onNavigateToAudit?: () => void;
}

interface CmsCapabilities {
  platform: string;
  provider: string;
  capabilities: string[];
  is_read_only: boolean;
}

interface ActionPlanSummary {
  total: number;
  proposed: number;
  accepted: number;
  in_progress: number;
  applied: number;
  dismissed: number;
  quick_wins: number;
}

export function SeoActionPlanView({
  website,
  onNavigateToAudit,
}: SeoActionPlanViewProps) {
  const toast = useToast();

  const [activeSubTab, setActiveSubTab] = useState<'recommendations' | 'cms_executions' | 'snapshots'>('recommendations');
  const [recommendations, setRecommendations] = useState<AiRecommendation[]>([]);
  const [summary, setSummary] = useState<ActionPlanSummary | null>(null);
  const [changeRequests, setChangeRequests] = useState<CmsChangeRequest[]>([]);
  const [snapshots, setSnapshots] = useState<CmsSnapshot[]>([]);
  const [cmsCapabilities, setCmsCapabilities] = useState<CmsCapabilities | null>(null);

  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isGenerating, setIsGenerating] = useState<boolean>(false);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [isProfileModalOpen, setIsProfileModalOpen] = useState<boolean>(false);

  // Detail / Evidence Modal
  const [inspectingRec, setInspectingRec] = useState<AiRecommendation | null>(null);

  // Edit & Stage Modal for CMS Push
  const [stagingRec, setStagingRec] = useState<AiRecommendation | null>(null);
  const [stageTitle, setStageTitle] = useState<string>('');
  const [stageDescription, setStageDescription] = useState<string>('');
  const [stageTargetUrl, setStageTargetUrl] = useState<string>('');

  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);

  const loadData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      let recUrl = `/websites/${website.id}/recommendations?per_page=50`;
      if (statusFilter !== 'all') recUrl += `&status=${statusFilter}`;
      if (categoryFilter !== 'all') recUrl += `&category=${categoryFilter}`;

      const [recRes, crRes, snapRes, capRes] = await Promise.all([
        api.get<AiRecommendation[]>(recUrl),
        api.get<CmsChangeRequest[]>(`/websites/${website.id}/cms/change-requests`),
        api.get<CmsSnapshot[]>(`/websites/${website.id}/cms/snapshots`),
        api.get<CmsCapabilities>(`/websites/${website.id}/cms/capabilities`),
      ]);

      if (recRes.success && recRes.data) {
        setRecommendations(recRes.data);
        const metaSummary = (recRes.meta as { summary?: ActionPlanSummary } | undefined)?.summary;
        if (metaSummary) {
          setSummary(metaSummary);
        }
      } else {
        setRecommendations([]);
      }

      setChangeRequests(crRes.success && crRes.data ? crRes.data : []);
      setSnapshots(snapRes.success && snapRes.data ? snapRes.data : []);
      setCmsCapabilities(capRes.success && capRes.data ? capRes.data : null);
    } catch {
      toast.error('Failed to load SEO Action Plan.');
    } finally {
      setIsLoading(false);
    }
  }, [website, statusFilter, categoryFilter, toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Generate fresh evidence-backed recommendations from latest Site Audit
  const handleGenerate = async () => {
    if (!website) return;
    setIsGenerating(true);
    try {
      const res = await api.post<{ count: number; items: AiRecommendation[] }>(
        `/websites/${website.id}/recommendations/generate`
      );
      if (res.success && res.data) {
        toast.success(`Generated ${res.data.count} prioritized fixes from your real site audit!`);
        setStatusFilter('all');
        setCategoryFilter('all');
        await loadData();
      } else {
        toast.error(res.error?.message || 'Failed to generate recommendations.');
      }
    } catch {
      toast.error('Network error running AI Strategist.');
    } finally {
      setIsGenerating(false);
    }
  };

  // Update recommendation status (works across ALL platforms: WordPress, Shopify, Custom)
  const handleUpdateRecStatus = async (
    recId: number,
    newStatus: 'proposed' | 'accepted' | 'in_progress' | 'applied' | 'dismissed',
    successMsg: string
  ) => {
    setActionLoadingId(recId);
    try {
      const res = await api.patch<AiRecommendation>(`/recommendations/${recId}/status`, {
        status: newStatus,
      });
      if (res.success) {
        toast.success(successMsg);
        await loadData();
      } else {
        toast.error(res.error?.message || 'Failed to update recommendation status.');
      }
    } catch {
      toast.error('Network error updating status.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // Open Edit & Stage drawer prefilled with real suggested metadata
  const openStageModal = (rec: AiRecommendation) => {
    const ev = (rec.evidence || {}) as Record<string, unknown>;
    setStagingRec(rec);
    setStageTargetUrl(rec.affected_url || website?.target_url || '/');
    setStageTitle(
      typeof ev.suggested_meta_title === 'string' && ev.suggested_meta_title
        ? ev.suggested_meta_title
        : rec.title.slice(0, 60)
    );
    setStageDescription(
      typeof ev.suggested_meta_description === 'string' && ev.suggested_meta_description
        ? ev.suggested_meta_description
        : rec.recommended_action.slice(0, 155)
    );
  };

  // Submit staged Change Request to CMS Execution Pipeline
  const handleConfirmStage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website || !stagingRec) return;

    setActionLoadingId(stagingRec.id);
    try {
      const res = await api.post<CmsChangeRequest>(`/websites/${website.id}/cms/change-requests/stage`, {
        action_type: 'update_metadata',
        target_type: 'page',
        target_identifier: stageTargetUrl.trim() || '/',
        proposed_payload: {
          meta_title: stageTitle.trim(),
          meta_description: stageDescription.trim(),
        },
        risk_level: stagingRec.risk_level || 'low',
      });

      if (res.success && res.data) {
        toast.success(`Fix staged in CMS Execution Queue (#${res.data.id}). Approve & Execute when ready.`);
        await api.patch(`/recommendations/${stagingRec.id}/status`, { status: 'accepted' });
        setStagingRec(null);
        await loadData();
        setActiveSubTab('cms_executions');
      } else {
        toast.error(res.error?.message || 'Could not stage CMS change.');
      }
    } catch {
      toast.error('Network error staging CMS change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // Copy ready-to-apply fix guide for Custom / developer handoff
  const handleCopyFixGuide = (rec: AiRecommendation) => {
    const ev = (rec.evidence || {}) as Record<string, unknown>;
    const lines = [
      `SEO Fix: ${rec.title}`,
      `Target URL: ${rec.affected_url}`,
      `Priority Score: ${rec.priority_score}/100 (${rec.category} | ${rec.effort} effort | ${rec.risk_level} risk)`,
      `Action: ${rec.recommended_action}`,
    ];
    if (typeof ev.suggested_meta_title === 'string') {
      lines.push(`Suggested <title>: ${ev.suggested_meta_title}`);
    }
    if (typeof ev.suggested_meta_description === 'string') {
      lines.push(`Suggested <meta name="description">: ${ev.suggested_meta_description}`);
    }
    lines.push(`Verify: ${rec.verification_procedure}`);
    navigator.clipboard.writeText(lines.join('\n'));
    toast.info('Copied ready-to-apply SEO fix guide to clipboard!');
  };

  // Export entire Action Plan as CSV
  const handleExportCsv = () => {
    if (!website || recommendations.length === 0) return;
    const escapeCsv = (val: string) => `"${String(val || '').replace(/"/g, '""')}"`;
    const headers = ['Priority', 'Status', 'Category', 'Effort', 'Risk', 'Title', 'Affected URL', 'Recommended Action', 'Expected Benefit'];
    const rows = recommendations.map((r) => [
      String(r.priority_score),
      r.status,
      r.category,
      r.effort,
      r.risk_level,
      r.title,
      r.affected_url,
      r.recommended_action,
      r.expected_benefit,
    ]);
    const csv = [headers.map(escapeCsv).join(','), ...rows.map((row) => row.map(escapeCsv).join(','))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `seo-action-plan-${website.domain}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    toast.success(`Exported ${recommendations.length} SEO actions to CSV.`);
  };

  // CMS Approve
  const handleApproveCr = async (crId: number) => {
    if (!website) return;
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/approve`);
      if (res.success) {
        toast.success(`Change request #${crId} approved for execution.`);
        await loadData();
      } else {
        toast.error(res.error?.message || 'Approval failed.');
      }
    } catch {
      toast.error('Network error approving change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // CMS Reject
  const handleRejectCr = async (crId: number) => {
    if (!website) return;
    const reason = prompt('Reason for rejecting this change request:', 'Needs manual review');
    if (reason === null) return;
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/reject`, {
        reason: reason.trim() || 'Rejected by reviewer',
      });
      if (res.success) {
        toast.info(`Change request #${crId} rejected.`);
        await loadData();
      } else {
        toast.error(res.error?.message || 'Reject failed.');
      }
    } catch {
      toast.error('Network error rejecting change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // CMS Execute with 11-step verification
  const handleExecuteCr = async (crId: number) => {
    if (!website) return;
    if (!confirm(`Execute Change Request #${crId}? This acquires a lock, checks for conflicts, writes to CMS, and verifies live HTML tags.`)) {
      return;
    }
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/execute`);
      if (res.success) {
        toast.success('Change executed and live-verified successfully!');
        await loadData();
      } else {
        toast.error(res.error?.message || 'Execution failed or verification mismatched.');
        await loadData();
      }
    } catch {
      toast.error('Network error executing CMS change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // CMS Rollback
  const handleRollbackCr = async (crId: number) => {
    if (!website) return;
    if (!confirm(`Rollback Change Request #${crId}? This will restore the pre-change snapshot state.`)) {
      return;
    }
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/rollback`);
      if (res.success) {
        toast.success('Change rolled back to pre-change snapshot.');
        await loadData();
      } else {
        toast.error(res.error?.message || 'Rollback failed.');
      }
    } catch {
      toast.error('Network error rolling back change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <Sparkles className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-400 mt-1">Please select or connect a website to view the action plan.</p>
      </div>
    );
  }

  const canPushToCms = Boolean(cmsCapabilities && !cmsCapabilities.is_read_only);

  const totalNonDismissed = summary ? Math.max(0, summary.total - summary.dismissed) : recommendations.filter((r) => r.status !== 'dismissed').length;
  const appliedCount = summary ? summary.applied : recommendations.filter((r) => r.status === 'applied').length;
  const inProgressCount = summary ? summary.in_progress + summary.accepted : recommendations.filter((r) => r.status === 'in_progress' || r.status === 'accepted').length;
  const quickWinsCount = summary ? summary.quick_wins : recommendations.filter((r) => r.impact_score >= 7 && r.effort === 'low' && r.status !== 'dismissed').length;
  const completionPct = totalNonDismissed > 0 ? Math.round((appliedCount / totalNonDismissed) * 100) : 0;

  const filteredRecs = recommendations.filter((r) => {
    if (!searchQuery.trim()) return true;
    const q = searchQuery.toLowerCase();
    return (
      r.title.toLowerCase().includes(q) ||
      r.affected_url.toLowerCase().includes(q) ||
      r.recommended_action.toLowerCase().includes(q)
    );
  });

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header Banner */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
        <div>
          <div className="flex flex-wrap items-center gap-2 mb-1.5">
            <span className="p-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
              <Sparkles className="w-4 h-4" />
            </span>
            <h1 className="text-lg font-bold text-zinc-100">
              SEO Action Plan &amp; Execution Center
            </h1>
            <span
              className={`text-[10px] uppercase font-mono px-2 py-0.5 rounded-full border ${
                canPushToCms
                  ? 'bg-emerald-950/60 border-emerald-700/60 text-emerald-300'
                  : 'bg-zinc-900 border-zinc-700 text-zinc-300'
              }`}
            >
              {canPushToCms ? `Live CMS Push Ready (${cmsCapabilities?.provider})` : 'Guided Manual / Copy-Fix Mode'}
            </span>
          </div>
          <p className="text-xs text-zinc-400 max-w-2xl leading-relaxed">
            Evidence-backed SEO roadmap generated directly from <strong className="text-zinc-200">{website.domain}</strong>&apos;s real Site Audit issues &amp; Search Console opportunities, ranked by ICE Impact Score.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => setIsProfileModalOpen(true)}
            className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
          >
            <Sliders className="w-3.5 h-3.5 text-zinc-400" />
            <span>Brand Profile</span>
          </button>

          {recommendations.length > 0 && (
            <button
              onClick={handleExportCsv}
              className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
            >
              <Download className="w-3.5 h-3.5 text-zinc-400" />
              <span>Export CSV</span>
            </button>
          )}

          <button
            onClick={loadData}
            disabled={isLoading}
            className="p-2 text-zinc-400 hover:text-zinc-200 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
            title="Refresh action plan"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-emerald-400' : ''}`} />
          </button>

          <button
            onClick={handleGenerate}
            disabled={isGenerating}
            className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 rounded-xl shadow-lg shadow-emerald-950/40 transition disabled:opacity-50"
          >
            <Sparkles className={`w-3.5 h-3.5 ${isGenerating ? 'animate-spin' : ''}`} />
            <span>{isGenerating ? 'Analyzing Audit...' : 'Generate Action Plan'}</span>
          </button>
        </div>
      </div>

      {/* KPI Progress Strip */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400 font-medium">Active SEO Fixes</div>
          <div className="text-2xl font-extrabold text-zinc-100 font-mono mt-1">{totalNonDismissed}</div>
          <div className="text-[10px] text-zinc-500 mt-1">Prioritized by ICE score</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400 font-medium flex items-center gap-1">
            <Zap className="w-3 h-3 text-amber-400" /> Quick Wins
          </div>
          <div className="text-2xl font-extrabold text-amber-400 font-mono mt-1">{quickWinsCount}</div>
          <div className="text-[10px] text-zinc-500 mt-1">High impact • Low effort</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400 font-medium flex items-center gap-1">
            <Clock className="w-3 h-3 text-sky-400" /> In Progress / Queued
          </div>
          <div className="text-2xl font-extrabold text-sky-400 font-mono mt-1">{inProgressCount}</div>
          <div className="text-[10px] text-zinc-500 mt-1">Active or staged in CMS</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="flex items-center justify-between text-[11px] text-zinc-400 font-medium">
            <span className="flex items-center gap-1">
              <CheckCircle2 className="w-3 h-3 text-emerald-400" /> Applied
            </span>
            <span className="font-mono text-emerald-400 font-bold">{completionPct}%</span>
          </div>
          <div className="text-2xl font-extrabold text-emerald-400 font-mono mt-1">{appliedCount}</div>
          <div className="w-full h-1.5 bg-zinc-900 rounded-full overflow-hidden mt-2">
            <div
              className="h-full bg-emerald-500 transition-all duration-500"
              style={{ width: `${completionPct}%` }}
            />
          </div>
        </div>
      </div>

      {/* Sub Tabs: Recommendations vs Executions vs Snapshots */}
      <div className="flex flex-wrap items-center gap-2 border-b border-zinc-800 pb-2 text-xs">
        <button
          onClick={() => setActiveSubTab('recommendations')}
          className={`px-3 py-1.5 rounded-xl font-medium transition flex items-center gap-2 ${
            activeSubTab === 'recommendations'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Sparkles className="w-3.5 h-3.5" />
          <span>Prioritized Roadmap</span>
          <span className="px-1.5 py-0.5 rounded-full bg-zinc-800 text-[10px] text-zinc-300 font-mono">
            {recommendations.length}
          </span>
        </button>

        <button
          onClick={() => setActiveSubTab('cms_executions')}
          className={`px-3 py-1.5 rounded-xl font-medium transition flex items-center gap-2 ${
            activeSubTab === 'cms_executions'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Shield className="w-3.5 h-3.5" />
          <span>CMS Execution Queue</span>
          <span className="px-1.5 py-0.5 rounded-full bg-zinc-800 text-[10px] text-zinc-300 font-mono">
            {changeRequests.length}
          </span>
        </button>

        <button
          onClick={() => setActiveSubTab('snapshots')}
          className={`px-3 py-1.5 rounded-xl font-medium transition flex items-center gap-2 ${
            activeSubTab === 'snapshots'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <History className="w-3.5 h-3.5" />
          <span>Snapshots &amp; Rollbacks</span>
          <span className="px-1.5 py-0.5 rounded-full bg-zinc-800 text-[10px] text-zinc-300 font-mono">
            {snapshots.length}
          </span>
        </button>
      </div>

      {/* VIEW 1: PRIORITIZED RECOMMENDATIONS */}
      {activeSubTab === 'recommendations' && (
        <div className="space-y-4">
          {/* Filters Bar */}
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80">
            <div className="relative w-full sm:w-72">
              <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-2.5" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search fixes or URLs..."
                className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-9 pr-3 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-emerald-500/60"
              />
            </div>

            <div className="flex items-center gap-2 w-full sm:w-auto overflow-x-auto">
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none"
              >
                <option value="all">All Statuses</option>
                <option value="proposed">Proposed</option>
                <option value="in_progress">In Progress</option>
                <option value="accepted">Queued for CMS</option>
                <option value="applied">Applied / Done</option>
                <option value="dismissed">Dismissed</option>
              </select>

              <select
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none"
              >
                <option value="all">All Categories</option>
                <option value="technical">Technical</option>
                <option value="content">Content</option>
                <option value="links">Links</option>
                <option value="architecture">Architecture</option>
              </select>
            </div>
          </div>

          {/* Recommendations List */}
          {isLoading && recommendations.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-xs text-zinc-400">
              Loading SEO Action Plan...
            </div>
          ) : filteredRecs.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-3">
              <Sparkles className="w-8 h-8 text-emerald-500/60 mx-auto" />
              <div className="text-sm font-semibold text-zinc-200">No Action Items Generated Yet</div>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                Click &ldquo;Generate Action Plan&rdquo; to convert your latest Site Audit issues and Search Console insights into prioritized fixes for {website.domain}.
              </p>
              <div className="flex flex-wrap items-center justify-center gap-2 pt-1">
                <button
                  onClick={handleGenerate}
                  disabled={isGenerating}
                  className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-xl text-xs transition"
                >
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>{isGenerating ? 'Analyzing...' : 'Generate Action Plan Now'}</span>
                </button>
                {onNavigateToAudit && (
                  <button
                    onClick={onNavigateToAudit}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 font-medium rounded-xl text-xs transition"
                  >
                    <span>Go to Site Audit</span>
                  </button>
                )}
              </div>
            </div>
          ) : (
            <div className="space-y-3">
              {filteredRecs.map((rec) => {
                const isActionLoading = actionLoadingId === rec.id;
                const isApplied = rec.status === 'applied';
                const isDismissed = rec.status === 'dismissed';

                return (
                  <div
                    key={rec.id}
                    className={`p-5 border rounded-2xl transition space-y-3 ${
                      isApplied
                        ? 'bg-emerald-950/10 border-emerald-900/50'
                        : isDismissed
                        ? 'bg-zinc-950/40 border-zinc-800/50 opacity-70'
                        : 'bg-zinc-950/90 hover:bg-zinc-950 border-zinc-800/80 hover:border-zinc-700/80'
                    }`}
                  >
                    <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                      <div className="space-y-2 max-w-3xl">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span
                            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                              rec.category === 'technical'
                                ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                                : rec.category === 'content'
                                ? 'bg-purple-500/10 text-purple-400 border border-purple-500/30'
                                : 'bg-sky-500/10 text-sky-400 border border-sky-500/30'
                            }`}
                          >
                            {rec.category}
                          </span>

                          <span
                            className={`text-[10px] font-semibold px-2 py-0.5 rounded-full capitalize ${
                              rec.effort === 'low'
                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                                : rec.effort === 'medium'
                                ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                                : 'bg-rose-500/10 text-rose-400 border border-rose-500/30'
                            }`}
                          >
                            {rec.effort} Effort
                          </span>

                          <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                            ICE: <strong className="text-emerald-400">{rec.priority_score}</strong>/100
                          </span>

                          <span
                            className={`text-[10px] px-2 py-0.5 rounded capitalize font-semibold ${
                              isApplied
                                ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                : rec.status === 'in_progress'
                                ? 'bg-sky-500/15 text-sky-300 border border-sky-500/30'
                                : rec.status === 'accepted'
                                ? 'bg-purple-500/15 text-purple-300 border border-purple-500/30'
                                : 'bg-zinc-900 text-zinc-400'
                            }`}
                          >
                            {rec.status.replace('_', ' ')}
                          </span>
                        </div>

                        <h3 className="text-sm font-bold text-zinc-100">{rec.title}</h3>

                        <div className="text-xs text-zinc-400 flex items-center gap-1.5 font-mono flex-wrap">
                          <span>Target:</span>
                          <a
                            href={rec.affected_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-emerald-400 hover:text-emerald-300 bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800 break-all"
                          >
                            <span>{rec.affected_url}</span>
                            <ExternalLink className="w-3 h-3 shrink-0" />
                          </a>
                        </div>

                        <p className="text-xs text-zinc-300 leading-relaxed pt-0.5">
                          {rec.recommended_action}
                        </p>

                        {rec.expected_benefit && (
                          <div className="text-[11px] text-zinc-400 pt-0.5">
                            <strong className="text-emerald-400">Expected Impact:</strong>{' '}
                            <span>{rec.expected_benefit}</span>
                          </div>
                        )}
                      </div>

                      {/* Action Buttons */}
                      <div className="flex flex-wrap md:flex-col items-stretch gap-2 shrink-0 md:w-44">
                        {canPushToCms && !isApplied && !isDismissed && (
                          <button
                            onClick={() => openStageModal(rec)}
                            disabled={isActionLoading}
                            className="inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl text-xs transition shadow-md"
                          >
                            <Shield className="w-3.5 h-3.5" />
                            <span>Stage for CMS Push</span>
                          </button>
                        )}

                        {!isApplied && !isDismissed && (
                          <button
                            onClick={() =>
                              handleUpdateRecStatus(
                                rec.id,
                                rec.status === 'in_progress' ? 'applied' : 'in_progress',
                                rec.status === 'in_progress'
                                  ? 'Marked fix as Applied / Completed!'
                                  : 'Marked fix as In Progress.'
                              )
                            }
                            disabled={isActionLoading}
                            className={`inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition disabled:opacity-50 ${
                              rec.status === 'in_progress'
                                ? 'bg-emerald-600 hover:bg-emerald-500 text-white'
                                : 'bg-sky-600/20 hover:bg-sky-600/30 border border-sky-500/40 text-sky-300'
                            }`}
                          >
                            {rec.status === 'in_progress' ? (
                              <>
                                <CheckCircle2 className="w-3.5 h-3.5" />
                                <span>Mark Applied</span>
                              </>
                            ) : (
                              <>
                                <Wrench className="w-3.5 h-3.5" />
                                <span>Start Working</span>
                              </>
                            )}
                          </button>
                        )}

                        {rec.status === 'proposed' && !canPushToCms && (
                          <button
                            onClick={() =>
                              handleUpdateRecStatus(rec.id, 'applied', 'Marked fix as Applied / Completed!')
                            }
                            disabled={isActionLoading}
                            className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/40 text-emerald-300 rounded-xl text-xs font-semibold transition disabled:opacity-50"
                          >
                            <Check className="w-3.5 h-3.5" />
                            <span>Mark Done</span>
                          </button>
                        )}

                        {(isApplied || isDismissed) && (
                          <button
                            onClick={() =>
                              handleUpdateRecStatus(rec.id, 'proposed', 'Re-opened recommendation.')
                            }
                            disabled={isActionLoading}
                            className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 rounded-xl text-xs transition"
                          >
                            <RotateCcw className="w-3.5 h-3.5" />
                            <span>Re-open</span>
                          </button>
                        )}

                        <div className="flex items-center gap-1.5">
                          <button
                            onClick={() => setInspectingRec(rec)}
                            className="flex-1 inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 text-[11px] rounded-xl transition"
                          >
                            <Eye className="w-3 h-3 text-zinc-400" />
                            <span>Evidence</span>
                          </button>
                          <button
                            onClick={() => handleCopyFixGuide(rec)}
                            className="inline-flex items-center justify-center p-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-400 hover:text-emerald-400 rounded-xl transition"
                            title="Copy Fix Guide"
                          >
                            <Copy className="w-3.5 h-3.5" />
                          </button>
                        </div>

                        {!isDismissed && !isApplied && (
                          <button
                            onClick={() => handleUpdateRecStatus(rec.id, 'dismissed', 'Recommendation dismissed.')}
                            disabled={isActionLoading}
                            className="inline-flex items-center justify-center gap-1 text-[11px] text-zinc-500 hover:text-zinc-300 transition py-0.5"
                          >
                            <span>Dismiss</span>
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* VIEW 2: CMS EXECUTIONS & VERIFICATION */}
      {activeSubTab === 'cms_executions' && (
        <div className="space-y-4">
          <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-start gap-3 text-xs text-zinc-300">
            <Shield className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
            <div>
              <div className="font-bold text-zinc-200">Safe 11-Step CMS Execution Pipeline</div>
              <p className="text-zinc-400 mt-0.5">
                {canPushToCms
                  ? `Connected via ${cmsCapabilities?.provider}. Every execution captures a pre-change snapshot, checks for remote edit conflicts, pushes the approved diff, and verifies the live page.`
                  : `Platform "${website.platform}" is currently in read-only / manual mode. Connect WordPress, Shopify, or a Custom Webhook API in My Websites → Integrations to enable 1-click live CMS pushes.`}
              </p>
            </div>
          </div>

          {changeRequests.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-2">
              <Shield className="w-8 h-8 text-zinc-600 mx-auto" />
              <div className="text-sm font-semibold text-zinc-200">No CMS Change Requests Staged</div>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                {canPushToCms
                  ? 'Click "Stage for CMS Push" on any recommendation in the Prioritized Roadmap tab to review and execute live changes.'
                  : 'Use "Start Working", "Copy Fix Guide", and "Mark Done" in the Prioritized Roadmap tab, or connect a CMS webhook to stage live pushes.'}
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {changeRequests.map((cr) => {
                const isActionLoading = actionLoadingId === cr.id;
                return (
                  <div
                    key={cr.id}
                    className="p-5 bg-zinc-950 border border-zinc-800 rounded-2xl space-y-3"
                  >
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                      <div className="space-y-1">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="font-mono text-xs font-bold text-zinc-200">#{cr.id}</span>
                          <span className="capitalize text-xs font-semibold text-zinc-300">
                            {cr.action_type.replace(/_/g, ' ')}
                          </span>
                          <span
                            className={`text-[10px] font-bold px-2 py-0.5 rounded-full uppercase ${
                              cr.status === 'verified'
                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                                : cr.status === 'approved'
                                ? 'bg-sky-500/10 text-sky-400 border border-sky-500/30'
                                : cr.status === 'failed' || cr.status === 'rejected'
                                ? 'bg-rose-500/10 text-rose-400 border border-rose-500/30'
                                : 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                            }`}
                          >
                            {cr.status.replace(/_/g, ' ')}
                          </span>
                        </div>
                        <div className="text-xs text-zinc-400 flex items-center gap-1 font-mono">
                          <span>Target:</span>
                          <code className="text-zinc-200 break-all">{cr.target_identifier || cr.target_url}</code>
                        </div>
                      </div>

                      <div className="flex flex-wrap items-center gap-2">
                        {cr.status === 'pending_approval' && (
                          <>
                            <button
                              onClick={() => handleApproveCr(cr.id)}
                              disabled={isActionLoading}
                              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-sky-600 hover:bg-sky-500 text-white rounded-xl text-xs font-medium transition"
                            >
                              <Check className="w-3.5 h-3.5" />
                              <span>Approve</span>
                            </button>
                            <button
                              onClick={() => handleRejectCr(cr.id)}
                              disabled={isActionLoading}
                              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-rose-950/60 border border-zinc-800 hover:border-rose-800 text-zinc-300 hover:text-rose-300 rounded-xl text-xs font-medium transition"
                            >
                              <Ban className="w-3.5 h-3.5" />
                              <span>Reject</span>
                            </button>
                          </>
                        )}

                        {cr.status === 'approved' && (
                          <button
                            onClick={() => handleExecuteCr(cr.id)}
                            disabled={isActionLoading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold transition shadow"
                          >
                            <Play className="w-3.5 h-3.5" />
                            <span>Execute Live</span>
                          </button>
                        )}

                        {cr.status === 'verified' && (
                          <button
                            onClick={() => handleRollbackCr(cr.id)}
                            disabled={isActionLoading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 rounded-xl text-xs transition"
                          >
                            <RotateCcw className="w-3.5 h-3.5 text-amber-400" />
                            <span>Safe Rollback</span>
                          </button>
                        )}

                        {cr.status === 'failed' && (
                          <div className="text-xs text-rose-400 font-medium flex items-center gap-1">
                            <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
                            <span>{cr.execution_error || 'Execution / verification failed'}</span>
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Proposed Payload Preview */}
                    <div className="p-3 bg-zinc-900/60 rounded-xl border border-zinc-800/80 text-xs font-mono space-y-1">
                      {cr.proposed_payload?.meta_title && (
                        <div className="text-zinc-300">
                          <span className="text-emerald-400 font-semibold">+ Title:</span> &ldquo;{cr.proposed_payload.meta_title}&rdquo;
                        </div>
                      )}
                      {cr.proposed_payload?.meta_description && (
                        <div className="text-zinc-300">
                          <span className="text-emerald-400 font-semibold">+ Description:</span> &ldquo;{cr.proposed_payload.meta_description}&rdquo;
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* VIEW 3: SNAPSHOTS & ROLLBACK HISTORY */}
      {activeSubTab === 'snapshots' && (
        <div className="space-y-4">
          {snapshots.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-2">
              <History className="w-8 h-8 text-zinc-600 mx-auto" />
              <div className="text-sm font-semibold text-zinc-200">No CMS Snapshots Captured Yet</div>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                Pre-change and post-change snapshots are automatically saved whenever a CMS change request is staged or executed, enabling 1-click safe rollback.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {snapshots.map((snap) => (
                <div key={snap.id} className="p-4 bg-zinc-950 border border-zinc-800 rounded-2xl space-y-2">
                  <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div className="flex items-center gap-2">
                      <span className="font-mono font-bold text-zinc-200">Snapshot #{snap.id}</span>
                      <span className="px-2 py-0.5 text-[10px] uppercase font-bold rounded-full bg-zinc-900 border border-zinc-700 text-emerald-400">
                        {snap.snapshot_type.replace('_', ' ')}
                      </span>
                      <span className="text-zinc-400 font-mono">{snap.target_identifier}</span>
                    </div>
                    <span className="text-[11px] text-zinc-500">
                      {new Date(snap.created_at).toLocaleString()}
                    </span>
                  </div>
                  <div className="text-[11px] font-mono text-zinc-400">
                    Checksum: <span className="text-zinc-300">{snap.checksum}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Brand Context Modal */}
      <ContextProfileModal
        website={website}
        isOpen={isProfileModalOpen}
        onClose={() => setIsProfileModalOpen(false)}
        onProfileUpdated={() => loadData()}
      />

      {/* Edit & Stage CMS Push Modal */}
      {stagingRec && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-xl p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-zinc-800">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Shield className="w-4 h-4 text-emerald-400" />
                Stage SEO Metadata Update for Live CMS
              </h3>
              <button onClick={() => setStagingRec(null)} className="text-zinc-400 hover:text-zinc-200">
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleConfirmStage} className="space-y-4 text-xs">
              <div>
                <label className="block text-zinc-400 mb-1 font-medium">Target Page URL / Slug</label>
                <input
                  type="text"
                  required
                  value={stageTargetUrl}
                  onChange={(e) => setStageTargetUrl(e.target.value)}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-200 font-mono focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-zinc-400 font-medium">Proposed Meta Title</label>
                  <span className={`font-mono text-[11px] ${stageTitle.length > 60 ? 'text-amber-400' : 'text-emerald-400'}`}>
                    {stageTitle.length} / 60 chars
                  </span>
                </div>
                <input
                  type="text"
                  required
                  value={stageTitle}
                  onChange={(e) => setStageTitle(e.target.value)}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-zinc-400 font-medium">Proposed Meta Description</label>
                  <span className={`font-mono text-[11px] ${stageDescription.length > 160 ? 'text-amber-400' : 'text-emerald-400'}`}>
                    {stageDescription.length} / 160 chars
                  </span>
                </div>
                <textarea
                  rows={3}
                  required
                  value={stageDescription}
                  onChange={(e) => setStageDescription(e.target.value)}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-emerald-500 leading-relaxed"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setStagingRec(null)}
                  className="px-4 py-2 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 rounded-xl font-medium transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={actionLoadingId === stagingRec.id}
                  className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl font-semibold transition"
                >
                  {actionLoadingId === stagingRec.id ? 'Staging...' : 'Capture Snapshot & Stage'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Detail / Evidence Modal */}
      {inspectingRec && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-xl max-h-[88vh] flex flex-col shadow-2xl overflow-hidden">
            <div className="flex items-center justify-between p-5 border-b border-zinc-800">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-emerald-400 shrink-0" />
                <span>{inspectingRec.title}</span>
              </h3>
              <button onClick={() => setInspectingRec(null)} className="text-zinc-400 hover:text-zinc-200">
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-4 overflow-y-auto text-xs text-zinc-300">
              {/* ICE Breakdown */}
              <div className="grid grid-cols-4 gap-2 text-center">
                <div className="p-2.5 rounded-xl bg-zinc-900 border border-zinc-800">
                  <div className="text-[10px] text-zinc-500 uppercase">Impact</div>
                  <div className="text-base font-bold font-mono text-emerald-400">{inspectingRec.impact_score}/10</div>
                </div>
                <div className="p-2.5 rounded-xl bg-zinc-900 border border-zinc-800">
                  <div className="text-[10px] text-zinc-500 uppercase">Confidence</div>
                  <div className="text-base font-bold font-mono text-sky-400">{inspectingRec.confidence_score}/10</div>
                </div>
                <div className="p-2.5 rounded-xl bg-zinc-900 border border-zinc-800">
                  <div className="text-[10px] text-zinc-500 uppercase">Effort</div>
                  <div className="text-base font-bold font-mono text-amber-400">{inspectingRec.effort_score}/10</div>
                </div>
                <div className="p-2.5 rounded-xl bg-zinc-900 border border-zinc-800">
                  <div className="text-[10px] text-zinc-500 uppercase">Risk</div>
                  <div className="text-base font-bold font-mono text-rose-400">{inspectingRec.risk_score}/10</div>
                </div>
              </div>

              <div>
                <strong className="text-zinc-400 block mb-1">Audit Rationale:</strong>
                <p className="bg-zinc-900 p-3 rounded-xl border border-zinc-800 leading-relaxed">
                  {inspectingRec.reasoning_summary || 'Generated from verified technical audit findings.'}
                </p>
              </div>

              {inspectingRec.evidence && Object.keys(inspectingRec.evidence).length > 0 && (
                <div>
                  <strong className="text-zinc-400 block mb-1">Observed Crawl Evidence &amp; Suggestions:</strong>
                  <div className="bg-zinc-900 p-3 rounded-xl border border-zinc-800 space-y-1.5 font-mono text-[11px]">
                    {Object.entries(inspectingRec.evidence).map(([key, val]) => (
                      <div key={key} className="flex flex-col sm:flex-row sm:items-start gap-1 border-b border-zinc-800/60 pb-1 last:border-0 last:pb-0">
                        <span className="text-emerald-400 shrink-0">{key}:</span>
                        <span className="text-zinc-200 break-all">
                          {typeof val === 'object' ? JSON.stringify(val) : String(val ?? '—')}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <strong className="text-zinc-400 block mb-1">Verification Procedure:</strong>
                <p className="bg-zinc-900 p-3 rounded-xl border border-zinc-800 leading-relaxed font-mono text-[11px]">
                  {inspectingRec.verification_procedure || 'Re-run Site Audit and inspect live HTML tags.'}
                </p>
              </div>
            </div>

            <div className="flex justify-between items-center p-4 border-t border-zinc-800 bg-zinc-950">
              <button
                onClick={() => handleCopyFixGuide(inspectingRec)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 text-xs rounded-xl transition"
              >
                <Copy className="w-3.5 h-3.5 text-emerald-400" />
                <span>Copy Fix Guide</span>
              </button>
              <button
                onClick={() => setInspectingRec(null)}
                className="px-4 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium rounded-xl transition"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
