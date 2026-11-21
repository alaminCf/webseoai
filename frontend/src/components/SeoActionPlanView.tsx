'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Sparkles,
  CheckCircle2,
  AlertTriangle,
  Clock,
  ArrowRight,
  RefreshCw,
  Sliders,
  Filter,
  Check,
  X,
  Play,
  RotateCcw,
  Shield,
  Layers,
  FileText,
  ExternalLink,
  ChevronRight,
  Eye,
  Info,
  Lock,
  Search,
} from 'lucide-react';
import { Website, AiRecommendation, CmsChangeRequest } from '@/types';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';
import { ContextProfileModal } from './ContextProfileModal';

interface SeoActionPlanViewProps {
  website: Website | null;
  onNavigateToContent?: () => void;
  onNavigateToAudit?: () => void;
}

export function SeoActionPlanView({
  website,
  onNavigateToContent,
  onNavigateToAudit,
}: SeoActionPlanViewProps) {
  const { currentOrganization } = useAuth();
  const toast = useToast();

  const [activeSubTab, setActiveSubTab] = useState<'recommendations' | 'cms_executions' | 'snapshots'>('recommendations');
  const [recommendations, setRecommendations] = useState<AiRecommendation[]>([]);
  const [changeRequests, setChangeRequests] = useState<CmsChangeRequest[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isGenerating, setIsGenerating] = useState<boolean>(false);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [isProfileModalOpen, setIsProfileModalOpen] = useState<boolean>(false);

  // Detail Modal / Visual Diff Drawer
  const [inspectingRec, setInspectingRec] = useState<AiRecommendation | null>(null);
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);

  const loadData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      // 1. Fetch AI recommendations
      let recUrl = `/websites/${website.id}/recommendations?per_page=50`;
      if (statusFilter !== 'all') recUrl += `&status=${statusFilter}`;
      if (categoryFilter !== 'all') recUrl += `&category=${categoryFilter}`;
      const recRes = await api.get<AiRecommendation[]>(recUrl);
      if (recRes.success && recRes.data) {
        setRecommendations(recRes.data);
      } else {
        setRecommendations([]);
      }

      // 2. Fetch CMS change requests
      const crRes = await api.get<CmsChangeRequest[]>(`/websites/${website.id}/cms/change-requests`);
      if (crRes.success && crRes.data) {
        setChangeRequests(crRes.data);
      } else {
        setChangeRequests([]);
      }
    } catch {
      toast.error('Failed to load SEO Action Plan.');
    } finally {
      setIsLoading(false);
    }
  }, [website, statusFilter, categoryFilter, toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Generate AI Recommendations
  const handleGenerate = async () => {
    if (!website) return;
    setIsGenerating(true);
    try {
      const res = await api.post<{ count: number; items: AiRecommendation[] }>(
        `/websites/${website.id}/recommendations/generate`
      );
      if (res.success && res.data) {
        toast.success(`Generated ${res.data.count} prioritized AI SEO recommendations!`);
        loadData();
      } else {
        toast.error(res.error?.message || 'Failed to generate recommendations.');
      }
    } catch {
      toast.error('Network error running AI Strategist.');
    } finally {
      setIsGenerating(false);
    }
  };

  // Stage recommendation directly into CMS Change Request queue
  const handleStageAndApproveRec = async (rec: AiRecommendation) => {
    if (!website) return;
    setActionLoadingId(rec.id);
    try {
      // Create a staged change request
      const res = await api.post<CmsChangeRequest>(`/websites/${website.id}/cms/change-requests/stage`, {
        action_type: 'update_metadata',
        target_type: 'page',
        target_identifier: rec.affected_url || '/',
        proposed_payload: {
          meta_title: rec.title,
          meta_description: rec.recommended_action,
        },
        risk_level: rec.risk_level || 'low',
      });

      if (res.success && res.data) {
        toast.success(`Action staged into verification queue (#${res.data.id}). Ready for execution.`);
        // Mark recommendation as accepted
        await api.patch(`/recommendations/${rec.id}/status`, { status: 'accepted' });
        loadData();
        setActiveSubTab('cms_executions');
      } else {
        toast.error(res.error?.message || 'Could not stage change.');
      }
    } catch {
      toast.error('Network error staging change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // Dismiss recommendation
  const handleDismissRec = async (recId: number) => {
    try {
      const res = await api.patch(`/recommendations/${recId}/status`, { status: 'dismissed' });
      if (res.success) {
        toast.info('Recommendation dismissed.');
        setRecommendations((prev) => prev.filter((r) => r.id !== recId));
      }
    } catch {
      toast.error('Failed to dismiss recommendation.');
    }
  };

  // CMS Approve
  const handleApproveCr = async (crId: number) => {
    if (!website) return;
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/approve`);
      if (res.success) {
        toast.success(`Change request #${crId} approved for execution.`);
        loadData();
      } else {
        toast.error(res.error?.message || 'Approval failed.');
      }
    } catch {
      toast.error('Network error approving change.');
    } finally {
      setActionLoadingId(null);
    }
  };

  // CMS Execute with 11-step verification
  const handleExecuteCr = async (crId: number) => {
    if (!website) return;
    if (!confirm(`Execute Change Request #${crId}? This safely acquires a mutex lock, checks for remote conflicts, writes to CMS, and verifies live HTTP tags.`)) {
      return;
    }
    setActionLoadingId(crId);
    try {
      const res = await api.post(`/websites/${website.id}/cms/change-requests/${crId}/execute`);
      if (res.success) {
        toast.success(`Change executed and live-verified successfully!`);
        loadData();
      } else {
        toast.error(res.error?.message || 'Execution failed or verification mismatched.');
        loadData();
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
        toast.success(`Change rolled back to pre-change snapshot.`);
        loadData();
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
          <div className="flex items-center gap-2 mb-1.5">
            <span className="p-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
              <Sparkles className="w-4 h-4" />
            </span>
            <h1 className="text-lg font-bold text-zinc-100">
              SEO Action Plan & Live Execution
            </h1>
            <span className="text-[10px] uppercase font-mono px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-400">
              Safe 11-Step Pipeline
            </span>
          </div>
          <p className="text-xs text-zinc-400 max-w-2xl leading-relaxed">
            Prioritized recommendations generated by our AI SEO Strategist. Review visual diffs, approve fixes with single-click safety locks, and verify live results on <strong className="text-zinc-200">{website.domain}</strong>.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            onClick={() => setIsProfileModalOpen(true)}
            className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
          >
            <Sliders className="w-3.5 h-3.5 text-zinc-400" />
            <span>Brand Profile</span>
          </button>

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
            <span>{isGenerating ? 'Analyzing Site...' : 'Generate New Fixes'}</span>
          </button>
        </div>
      </div>

      {/* Sub Tabs: Recommendations vs Executions vs Snapshots */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-2 text-xs">
        <button
          onClick={() => setActiveSubTab('recommendations')}
          className={`px-3 py-1.5 rounded-xl font-medium transition flex items-center gap-2 ${
            activeSubTab === 'recommendations'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Sparkles className="w-3.5 h-3.5" />
          <span>Prioritized Recommendations</span>
          <span className="px-1.5 py-0.2 rounded-full bg-zinc-800 text-[10px] text-zinc-300 font-mono">
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
          <span>Live CMS Executions & Verification</span>
          <span className="px-1.5 py-0.2 rounded-full bg-zinc-800 text-[10px] text-zinc-300 font-mono">
            {changeRequests.length}
          </span>
        </button>
      </div>

      {/* VIEW 1: AI RECOMMENDATIONS */}
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
                <option value="proposed">Proposed / Draft</option>
                <option value="accepted">Accepted / Queued</option>
                <option value="applied">Applied / Live</option>
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
          {filteredRecs.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-3">
              <Sparkles className="w-8 h-8 text-zinc-600 mx-auto" />
              <div className="text-sm font-semibold text-zinc-200">No Recommendations Found</div>
              <p className="text-xs text-zinc-400 max-w-md mx-auto">
                Click &ldquo;Generate New Fixes&rdquo; to analyze your latest audit findings, or run a technical scan first.
              </p>
              <button
                onClick={handleGenerate}
                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-medium rounded-xl text-xs transition"
              >
                <Sparkles className="w-3.5 h-3.5" />
                <span>Run AI Strategist Now</span>
              </button>
            </div>
          ) : (
            <div className="space-y-3">
              {filteredRecs.map((rec) => {
                const isActionLoading = actionLoadingId === rec.id;
                return (
                  <div
                    key={rec.id}
                    className="p-5 bg-zinc-950/80 hover:bg-zinc-950 border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl transition space-y-4"
                  >
                    <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                      <div className="space-y-1.5 max-w-3xl">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                            rec.category === 'technical'
                              ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                              : rec.category === 'content'
                              ? 'bg-purple-500/10 text-purple-400 border border-purple-500/30'
                              : 'bg-sky-500/10 text-sky-400 border border-sky-500/30'
                          }`}>
                            {rec.category}
                          </span>

                          <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                            rec.risk_level === 'high'
                              ? 'bg-red-500/10 text-red-400 border border-red-500/30'
                              : rec.risk_level === 'medium'
                              ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                              : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                          }`}>
                            {rec.risk_level} Risk
                          </span>

                          <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                            ICE Score: <strong className="text-emerald-400">{rec.priority_score || 85}</strong>
                          </span>

                          <span className="text-[10px] px-2 py-0.5 rounded bg-zinc-900 text-zinc-500 capitalize">
                            Status: <strong className="text-zinc-300">{rec.status}</strong>
                          </span>
                        </div>

                        <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                          {rec.title}
                        </h3>

                        <div className="text-xs text-zinc-400 flex items-center gap-1.5 font-mono">
                          <span>Target:</span>
                          <code className="text-emerald-400 bg-zinc-900 px-1.5 py-0.5 rounded border border-zinc-800">
                            {rec.affected_url}
                          </code>
                        </div>

                        <p className="text-xs text-zinc-300 leading-relaxed pt-1">
                          {rec.recommended_action}
                        </p>

                        {rec.expected_benefit && (
                          <div className="text-[11px] text-zinc-400 flex items-center gap-1.5 pt-1">
                            <strong className="text-emerald-400">Estimated Benefit:</strong>
                            <span>{rec.expected_benefit}</span>
                          </div>
                        )}
                      </div>

                      {/* Action Buttons */}
                      <div className="flex md:flex-col items-center gap-2 shrink-0">
                        <button
                          onClick={() => handleStageAndApproveRec(rec)}
                          disabled={isActionLoading}
                          className="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl text-xs transition shadow-md"
                        >
                          {isActionLoading ? (
                            <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Shield className="w-3.5 h-3.5" />
                          )}
                          <span>Stage & Execute Fix</span>
                        </button>

                        <button
                          onClick={() => setInspectingRec(rec)}
                          className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 text-xs rounded-xl transition"
                        >
                          <Eye className="w-3.5 h-3.5 text-zinc-400" />
                          <span>View Evidence</span>
                        </button>

                        <button
                          onClick={() => handleDismissRec(rec.id)}
                          className="w-full inline-flex items-center justify-center gap-1 text-[11px] text-zinc-500 hover:text-zinc-300 transition py-1"
                        >
                          <span>Dismiss</span>
                        </button>
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
              <div className="font-bold text-zinc-200">Safe 11-Step CMS Execution Model</div>
              <p className="text-zinc-400 mt-0.5">
                Every execution acquires a website lock, performs real-time drift & conflict detection against pre-change snapshots, writes via authorized adapters, and performs mandatory live HTTP verification before declaring success.
              </p>
            </div>
          </div>

          {changeRequests.length === 0 ? (
            <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-2">
              <Shield className="w-8 h-8 text-zinc-600 mx-auto" />
              <div className="text-sm font-semibold text-zinc-200">No CMS Change Requests Staged</div>
              <p className="text-xs text-zinc-400">
                Stage a recommendation from the &ldquo;Prioritized Recommendations&rdquo; tab to view its execution pipeline here.
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
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-xs font-bold text-zinc-200">
                            #{cr.id}
                          </span>
                          <span className="capitalize text-xs font-semibold text-zinc-300">
                            {cr.action_type.replace(/_/g, ' ')}
                          </span>
                          <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full uppercase ${
                            cr.status === 'verified'
                              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                              : cr.status === 'approved'
                              ? 'bg-sky-500/10 text-sky-400 border border-sky-500/30'
                              : cr.status === 'failed'
                              ? 'bg-red-500/10 text-red-400 border border-red-500/30'
                              : 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
                          }`}>
                            {cr.status.replace(/_/g, ' ')}
                          </span>
                        </div>
                        <div className="text-xs text-zinc-400 flex items-center gap-1 font-mono">
                          <span>Target:</span>
                          <code className="text-zinc-200">{cr.target_identifier || cr.target_url}</code>
                        </div>
                      </div>

                      {/* Controls */}
                      <div className="flex items-center gap-2">
                        {cr.status === 'pending_approval' && (
                          <button
                            onClick={() => handleApproveCr(cr.id)}
                            disabled={isActionLoading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-sky-600 hover:bg-sky-500 text-white rounded-xl text-xs font-medium transition"
                          >
                            <Check className="w-3.5 h-3.5" />
                            <span>Approve</span>
                          </button>
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
                          <div className="text-xs text-red-400 font-medium">
                            {cr.execution_error || 'Execution / verification failed'}
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Diff Preview / Payload */}
                    <div className="p-3 bg-zinc-900/60 rounded-xl border border-zinc-800/80 text-xs font-mono space-y-1">
                      {cr.proposed_payload.meta_title && (
                        <div className="text-zinc-300">
                          <span className="text-emerald-400 font-semibold">+ Title:</span> &ldquo;{cr.proposed_payload.meta_title}&rdquo;
                        </div>
                      )}
                      {cr.proposed_payload.meta_description && (
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

      {/* Brand Context Modal */}
      <ContextProfileModal
        website={website}
        isOpen={isProfileModalOpen}
        onClose={() => setIsProfileModalOpen(false)}
        onProfileUpdated={() => loadData()}
      />

      {/* Detail / Evidence Modal */}
      {inspectingRec && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-xl p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-zinc-800">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-emerald-400" />
                {inspectingRec.title}
              </h3>
              <button
                onClick={() => setInspectingRec(null)}
                className="text-zinc-400 hover:text-zinc-200 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="space-y-3 text-xs text-zinc-300">
              <div>
                <strong className="text-zinc-400 block mb-0.5">Rationale:</strong>
                <p className="bg-zinc-900 p-3 rounded-xl border border-zinc-800 leading-relaxed">
                  {inspectingRec.reasoning_summary || 'Generated based on audit rules and context profile.'}
                </p>
              </div>

              <div>
                <strong className="text-zinc-400 block mb-0.5">Verification Procedure:</strong>
                <p className="bg-zinc-900 p-3 rounded-xl border border-zinc-800 leading-relaxed font-mono text-[11px]">
                  {inspectingRec.verification_procedure || 'Automated HTTP inspection of target URL meta tags.'}
                </p>
              </div>
            </div>

            <div className="flex justify-end pt-3 border-t border-zinc-800">
              <button
                onClick={() => setInspectingRec(null)}
                className="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium rounded-xl transition"
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
