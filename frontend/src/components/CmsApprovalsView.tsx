'use client';

import React, { useState, useEffect } from 'react';
import {
  CheckSquare,
  Shield,
  AlertTriangle,
  RotateCcw,
  Check,
  X,
  Play,
  FileText,
  Clock,
  Layers,
  Sparkles,
  ArrowRight,
  ExternalLink,
  ChevronRight,
  RefreshCw,
  Search,
  Eye,
  Info,
  Lock,
  Plus
} from 'lucide-react';
import { Website, CmsChangeRequest, CmsSnapshot, CmsCapabilities } from '@/types';
import { useAuth } from '@/context/AuthContext';
import { getApiBaseUrl } from '@/lib/api';

interface CmsApprovalsViewProps {
  website: Website | null;
  onNavigateToContent?: () => void;
}

export function CmsApprovalsView({ website, onNavigateToContent }: CmsApprovalsViewProps) {
  const { currentOrganization, user } = useAuth();
  const [changeRequests, setChangeRequests] = useState<CmsChangeRequest[]>([]);
  const [snapshots, setSnapshots] = useState<CmsSnapshot[]>([]);
  const [capabilities, setCapabilities] = useState<CmsCapabilities | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [activeTab, setActiveTab] = useState<'requests' | 'snapshots'>('requests');

  // Inspection Drawer
  const [selectedRequest, setSelectedRequest] = useState<CmsChangeRequest | null>(null);
  const [selectedSnapshot, setSelectedSnapshot] = useState<CmsSnapshot | null>(null);

  // Staging Modal
  const [isStageModalOpen, setIsStageModalOpen] = useState(false);
  const [stageForm, setStageForm] = useState({
    action_type: 'update_metadata',
    target_type: 'page',
    target_identifier: '',
    meta_title: '',
    meta_description: '',
    canonical_url: '',
    risk_level: 'medium',
  });

  // Action status feedback
  const [actionLoadingId, setActionLoadingId] = useState<number | null>(null);
  const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const fetchCmsData = async () => {
    if (!website || !currentOrganization) return;
    setIsLoading(true);

    try {
      const token = localStorage.getItem('auth_token');
      const headers = {
        Authorization: `Bearer ${token}`,
        'X-Organization-Id': String(currentOrganization.id),
      };

      // 1. Fetch Capabilities
      const capRes = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/capabilities`,
        { credentials: 'omit', headers }
      );
      if (capRes.ok) {
        const capData = await capRes.json();
        setCapabilities(capData.data);
      }

      // 2. Fetch Change Requests
      const crRes = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests?status=${filterStatus}`,
        { credentials: 'omit', headers }
      );
      if (crRes.ok) {
        const crData = await crRes.json();
        setChangeRequests(crData.data || []);
      }

      // 3. Fetch Snapshots
      const snapRes = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/snapshots`,
        { credentials: 'omit', headers }
      );
      if (snapRes.ok) {
        const snapData = await snapRes.json();
        setSnapshots(snapData.data || []);
      }
    } catch (err: any) {
      console.error('Failed to load CMS integration data:', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchCmsData();
  }, [website, currentOrganization, filterStatus]);

  // Stage change submission
  const handleStageSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website || !currentOrganization) return;

    try {
      setIsLoading(true);
      const token = localStorage.getItem('auth_token');
      const proposedPayload: Record<string, string> = {};
      if (stageForm.meta_title) proposedPayload.meta_title = stageForm.meta_title;
      if (stageForm.meta_description) proposedPayload.meta_description = stageForm.meta_description;
      if (stageForm.canonical_url) proposedPayload.canonical_url = stageForm.canonical_url;

      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests/stage`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({
            action_type: stageForm.action_type,
            target_type: stageForm.target_type,
            target_identifier: stageForm.target_identifier,
            proposed_payload: proposedPayload,
            risk_level: stageForm.risk_level,
          }),
        }
      );

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error?.message || data.message || 'Staging failed');
      }

      setFeedbackMessage({
        type: 'success',
        text: `Change request staged successfully. Pre-change snapshot captured (${data.data?.pre_change_snapshot?.checksum?.slice(0, 8)}). Awaiting approval.`,
      });
      setIsStageModalOpen(false);
      fetchCmsData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    } finally {
      setIsLoading(false);
    }
  };

  // Approve change request
  const handleApprove = async (cr: CmsChangeRequest) => {
    if (!website || !currentOrganization) return;
    setActionLoadingId(cr.id);

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests/${cr.id}/approve`,
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Approval failed');

      setFeedbackMessage({ type: 'success', text: `Change request #${cr.id} approved. Ready for execution.` });
      fetchCmsData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    } finally {
      setActionLoadingId(null);
    }
  };

  // Reject change request
  const handleReject = async (cr: CmsChangeRequest) => {
    if (!website || !currentOrganization) return;
    const reason = prompt('Please enter rejection reason:');
    if (!reason) return;

    setActionLoadingId(cr.id);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests/${cr.id}/reject`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({ reason }),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Rejection failed');

      setFeedbackMessage({ type: 'success', text: `Change request #${cr.id} rejected.` });
      fetchCmsData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    } finally {
      setActionLoadingId(null);
    }
  };

  // Execute change request
  const handleExecute = async (cr: CmsChangeRequest) => {
    if (!website || !currentOrganization) return;
    if (!confirm(`Are you sure you want to execute Change Request #${cr.id}? This will safely acquire a lock, detect concurrent conflicts, write to remote CMS, and verify live state.`)) {
      return;
    }

    setActionLoadingId(cr.id);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests/${cr.id}/execute`,
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
        }
      );

      const data = await res.json();
      if (!res.ok) {
        const errorDetail = data.error?.message || data.message || 'Execution failed';
        throw new Error(errorDetail);
      }

      setFeedbackMessage({
        type: 'success',
        text: `Execution complete & verified! Post-change snapshot saved for page [${cr.target_identifier}].`,
      });
      fetchCmsData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: `Execution aborted: ${err.message}` });
    } finally {
      setActionLoadingId(null);
    }
  };

  // Rollback change request
  const handleRollback = async (cr: CmsChangeRequest) => {
    if (!website || !currentOrganization) return;
    if (!confirm(`Are you sure you want to ROLL BACK Change Request #${cr.id}? This will restore the pre-change snapshot state to the live remote website.`)) {
      return;
    }

    setActionLoadingId(cr.id);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/cms/change-requests/${cr.id}/rollback`,
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Rollback failed');

      setFeedbackMessage({
        type: 'success',
        text: `Rollback executed successfully! Target [${cr.target_identifier}] restored to original pre-change snapshot.`,
      });
      fetchCmsData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: `Rollback failed: ${err.message}` });
    } finally {
      setActionLoadingId(null);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'verified':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-emerald-400 bg-emerald-950/60 border border-emerald-800/40 rounded-lg">
            <Check className="w-3.5 h-3.5" /> Verified Live
          </span>
        );
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-sky-400 bg-sky-950/60 border border-sky-800/40 rounded-lg">
            <CheckSquare className="w-3.5 h-3.5" /> Approved
          </span>
        );
      case 'pending_approval':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 bg-amber-950/60 border border-amber-800/40 rounded-lg">
            <Clock className="w-3.5 h-3.5" /> Pending Approval
          </span>
        );
      case 'executing':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-blue-400 bg-blue-950/60 border border-blue-800/40 rounded-lg animate-pulse">
            <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Executing
          </span>
        );
      case 'rolled_back':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-purple-400 bg-purple-950/60 border border-purple-800/40 rounded-lg">
            <RotateCcw className="w-3.5 h-3.5" /> Rolled Back
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-rose-400 bg-rose-950/60 border border-rose-800/40 rounded-lg">
            <AlertTriangle className="w-3.5 h-3.5" /> Execution Failed
          </span>
        );
      case 'rejected':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-zinc-400 bg-zinc-900 border border-zinc-800 rounded-lg">
            <X className="w-3.5 h-3.5" /> Rejected
          </span>
        );
      default:
        return (
          <span className="px-2.5 py-1 text-xs font-medium text-zinc-400 bg-zinc-900 border border-zinc-800 rounded-lg">
            {status}
          </span>
        );
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center border border-zinc-800 rounded-2xl bg-zinc-950/60">
        <Shield className="w-10 h-10 text-zinc-600 mx-auto mb-3" />
        <h3 className="text-base font-semibold text-zinc-200">No Website Selected</h3>
        <p className="text-xs text-zinc-500 mt-1">Select a verified website to view and manage CMS integrations and change approvals.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Banner / Capabilities Bar */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <Shield className="w-5 h-5 text-emerald-400" />
            <h2 className="text-lg font-bold text-white tracking-tight">
              Safe SEO Execution & Approval Board
            </h2>
            <span className="px-2 py-0.5 text-[10px] font-bold text-emerald-400 bg-emerald-950/80 border border-emerald-800/60 rounded-full uppercase">
              11-Step Guardrail Pipeline
            </span>
          </div>
          <p className="text-xs text-zinc-400 max-w-2xl leading-relaxed">
            Strict human-in-the-loop governance for {website.name} (<span className="text-zinc-200 font-mono">{website.domain}</span>). Every write requires an immutable pre-change snapshot, diff policy review, approval, lock acquisition, checksum conflict detection, and live read-back verification.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => setIsStageModalOpen(true)}
            disabled={capabilities?.is_read_only}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:bg-zinc-800 disabled:text-zinc-600 rounded-xl shadow-lg transition"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Stage Change Request</span>
          </button>
          <button
            onClick={fetchCmsData}
            disabled={isLoading}
            className="p-2 text-zinc-400 hover:text-zinc-200 bg-zinc-900 border border-zinc-800 rounded-xl transition"
            title="Refresh"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      {/* Platform Capabilities & Safeguard Indicators */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Platform Driver</div>
            <div className="text-sm font-semibold text-white capitalize mt-0.5">
              {website.platform} ({capabilities?.provider || 'resolving'})
            </div>
          </div>
          <div className="p-2 bg-zinc-800/60 rounded-lg text-emerald-400">
            <Layers className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Write Policy</div>
            <div className="text-sm font-semibold mt-0.5">
              {capabilities?.is_read_only ? (
                <span className="text-amber-400 flex items-center gap-1">
                  <Lock className="w-3 h-3" /> Strictly Read-Only
                </span>
              ) : (
                <span className="text-emerald-400 flex items-center gap-1">
                  <Shield className="w-3 h-3" /> Approval-Gated Write
                </span>
              )}
            </div>
          </div>
          <div className="p-2 bg-zinc-800/60 rounded-lg text-sky-400">
            <Shield className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Conflict Detection</div>
            <div className="text-sm font-semibold text-emerald-400 mt-0.5 flex items-center gap-1">
              <Check className="w-3.5 h-3.5" /> SHA-256 State Checksum
            </div>
          </div>
          <div className="p-2 bg-zinc-800/60 rounded-lg text-amber-400">
            <Sparkles className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Rollback Safety</div>
            <div className="text-sm font-semibold text-purple-400 mt-0.5 flex items-center gap-1">
              <RotateCcw className="w-3.5 h-3.5" /> Snapshot Restoration
            </div>
          </div>
          <div className="p-2 bg-zinc-800/60 rounded-lg text-purple-400">
            <RotateCcw className="w-4 h-4" />
          </div>
        </div>
      </div>

      {/* Feedback Alert */}
      {feedbackMessage && (
        <div
          className={`p-3.5 rounded-xl border flex items-center justify-between text-xs font-medium ${
            feedbackMessage.type === 'success'
              ? 'bg-emerald-950/40 border-emerald-800/60 text-emerald-300'
              : 'bg-rose-950/40 border-rose-800/60 text-rose-300'
          }`}
        >
          <div className="flex items-center gap-2">
            {feedbackMessage.type === 'success' ? (
              <Check className="w-4 h-4 text-emerald-400" />
            ) : (
              <AlertTriangle className="w-4 h-4 text-rose-400" />
            )}
            <span>{feedbackMessage.text}</span>
          </div>
          <button
            onClick={() => setFeedbackMessage(null)}
            className="text-zinc-400 hover:text-white p-1"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* View Tabs & Filters */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-zinc-800 pb-3">
        <div className="flex items-center gap-2">
          <button
            onClick={() => setActiveTab('requests')}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
              activeTab === 'requests'
                ? 'bg-zinc-800 text-white'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            Change Requests ({changeRequests.length})
          </button>
          <button
            onClick={() => setActiveTab('snapshots')}
            className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
              activeTab === 'snapshots'
                ? 'bg-zinc-800 text-white'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            Snapshots & Backups ({snapshots.length})
          </button>
        </div>

        {activeTab === 'requests' && (
          <div className="flex items-center gap-2 text-xs">
            <span className="text-zinc-500">Filter:</span>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="bg-zinc-900 border border-zinc-800 text-zinc-300 rounded-lg px-2.5 py-1 text-xs focus:outline-none focus:border-emerald-500"
            >
              <option value="all">All Statuses</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="approved">Approved</option>
              <option value="verified">Verified Live</option>
              <option value="rolled_back">Rolled Back</option>
              <option value="failed">Failed</option>
            </select>
          </div>
        )}
      </div>

      {/* Main Content Area */}
      {activeTab === 'requests' ? (
        changeRequests.length === 0 ? (
          <div className="p-12 text-center border border-zinc-800/80 rounded-2xl bg-zinc-950/40">
            <CheckSquare className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-zinc-300">No Change Requests Found</h3>
            <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
              Stage a title or meta description change request to experience the 11-step safe execution workflow.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {changeRequests.map((cr) => {
              const isProcessing = actionLoadingId === cr.id;

              return (
                <div
                  key={cr.id}
                  className="p-5 bg-zinc-950/80 border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl shadow-sm transition space-y-4"
                >
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className="p-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-300 font-mono text-xs">
                        #{cr.id}
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="text-xs font-bold text-white capitalize">
                            {cr.action_type.replace('_', ' ')}
                          </span>
                          <span className="text-zinc-600">•</span>
                          <span className="text-xs font-mono text-emerald-400">
                            {cr.target_type}: {cr.target_identifier}
                          </span>
                          <span className="text-zinc-600">•</span>
                          <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded uppercase ${
                            cr.risk_level === 'high' ? 'bg-rose-950/80 text-rose-400 border border-rose-800/60' : 'bg-zinc-900 text-zinc-400'
                          }`}>
                            {cr.risk_level} Risk
                          </span>
                        </div>
                        <div className="text-[11px] text-zinc-500 mt-0.5">
                          Target URL:{' '}
                          <a
                            href={cr.target_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-zinc-400 hover:text-emerald-400 underline underline-offset-2"
                          >
                            {cr.target_url}
                          </a>
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      {getStatusBadge(cr.status)}
                    </div>
                  </div>

                  {/* Diff Overview Box */}
                  {cr.diff_summary && (
                    <div className="p-3 bg-zinc-900/60 border border-zinc-800/60 rounded-xl space-y-2">
                      <div className="text-[10px] font-bold tracking-wider uppercase text-zinc-400 flex items-center gap-1.5">
                        <FileText className="w-3 h-3 text-emerald-400" />
                        <span>Proposed Field Diff</span>
                      </div>
                      <div className="grid grid-cols-1 gap-2 text-xs">
                        {Object.entries(cr.diff_summary).map(([field, diff]) => (
                          <div key={field} className="p-2 bg-black/40 rounded-lg font-mono text-[11px] space-y-1">
                            <div className="text-zinc-500 font-semibold">{field}:</div>
                            <div className="text-rose-400 flex items-start gap-1">
                              <span className="select-none font-bold text-rose-500">-</span>
                              <span className="truncate">{String(diff.before ?? '(empty)')}</span>
                            </div>
                            <div className="text-emerald-400 flex items-start gap-1">
                              <span className="select-none font-bold text-emerald-500">+</span>
                              <span className="truncate">{String(diff.after ?? '(empty)')}</span>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Footer metadata & Action controls */}
                  <div className="pt-2 border-t border-zinc-800/60 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-zinc-500">
                    <div>
                      Staged by <span className="text-zinc-300 font-medium">{cr.requester?.name || 'Authorized Member'}</span> on{' '}
                      {new Date(cr.created_at).toLocaleDateString()} at {new Date(cr.created_at).toLocaleTimeString()}
                      {cr.executed_at && (
                        <span> • Executed on {new Date(cr.executed_at).toLocaleTimeString()}</span>
                      )}
                    </div>

                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setSelectedRequest(cr)}
                        className="px-2.5 py-1 text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-lg transition"
                      >
                        Inspect Details
                      </button>

                      {/* Approval Actions */}
                      {cr.status === 'pending_approval' && (
                        <>
                          <button
                            onClick={() => handleReject(cr)}
                            disabled={isProcessing}
                            className="px-3 py-1 text-zinc-300 hover:text-rose-400 bg-zinc-900 border border-zinc-800 hover:border-rose-900/60 rounded-lg transition"
                          >
                            Reject
                          </button>
                          <button
                            onClick={() => handleApprove(cr)}
                            disabled={isProcessing}
                            className="px-3 py-1 text-white bg-emerald-600 hover:bg-emerald-500 rounded-lg font-semibold shadow transition"
                          >
                            Approve
                          </button>
                        </>
                      )}

                      {/* Execution Action */}
                      {cr.status === 'approved' && (
                        <button
                          onClick={() => handleExecute(cr)}
                          disabled={isProcessing}
                          className="inline-flex items-center gap-1.5 px-3 py-1 text-white bg-sky-600 hover:bg-sky-500 rounded-lg font-semibold shadow transition"
                        >
                          <Play className="w-3.5 h-3.5 fill-current" />
                          <span>Execute & Verify</span>
                        </button>
                      )}

                      {/* Rollback Action */}
                      {cr.status === 'verified' && (
                        <button
                          onClick={() => handleRollback(cr)}
                          disabled={isProcessing}
                          className="inline-flex items-center gap-1.5 px-3 py-1 text-purple-300 hover:text-white bg-purple-950/60 hover:bg-purple-900/60 border border-purple-800/60 rounded-lg font-semibold transition"
                        >
                          <RotateCcw className="w-3.5 h-3.5" />
                          <span>Restore / Rollback</span>
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )
      ) : (
        /* Snapshots Tab */
        snapshots.length === 0 ? (
          <div className="p-12 text-center border border-zinc-800/80 rounded-2xl bg-zinc-950/40">
            <Layers className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-zinc-300">No Historical Snapshots</h3>
            <p className="text-xs text-zinc-500 mt-1">
              Snapshots are automatically created before and after every execution for automated rollbacks.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {snapshots.map((snap) => (
              <div
                key={snap.id}
                className="p-4 bg-zinc-950/80 border border-zinc-800/80 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
              >
                <div>
                  <div className="flex items-center gap-2 font-mono text-zinc-300">
                    <span className="font-bold text-white">Snapshot #{snap.id}</span>
                    <span className="text-zinc-600">•</span>
                    <span className="text-emerald-400 capitalize">{snap.snapshot_type.replace('_', ' ')}</span>
                    <span className="text-zinc-600">•</span>
                    <span className="text-zinc-400">{snap.target_identifier}</span>
                  </div>
                  <div className="text-[11px] text-zinc-500 mt-1">
                    SHA-256 Checksum: <span className="font-mono text-zinc-400">{snap.checksum}</span> • Captured{' '}
                    {new Date(snap.created_at).toLocaleString()}
                  </div>
                </div>

                <button
                  onClick={() => setSelectedSnapshot(snap)}
                  className="px-3 py-1.5 text-zinc-300 hover:text-white bg-zinc-900 border border-zinc-800 rounded-lg transition"
                >
                  View Payload
                </button>
              </div>
            ))}
          </div>
        )
      )}

      {/* Modal: Stage Change Request */}
      {isStageModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-lg bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div className="flex items-center gap-2">
                <CheckSquare className="w-5 h-5 text-emerald-400" />
                <h3 className="text-base font-bold text-white">Stage SEO Change Request</h3>
              </div>
              <button
                onClick={() => setIsStageModalOpen(false)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleStageSubmit} className="space-y-4 text-xs">
              <div>
                <label className="block text-zinc-400 font-medium mb-1">Target Resource Type</label>
                <select
                  value={stageForm.target_type}
                  onChange={(e) => setStageForm({ ...stageForm, target_type: e.target.value })}
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                >
                  <option value="page">Page</option>
                  <option value="post">Blog Post</option>
                  <option value="product">Shopify Product</option>
                  <option value="article">Article</option>
                </select>
              </div>

              <div>
                <label className="block text-zinc-400 font-medium mb-1">Target Identifier (Slug or ID)</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. 42 or contact-us"
                  value={stageForm.target_identifier}
                  onChange={(e) => setStageForm({ ...stageForm, target_identifier: e.target.value })}
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div className="border-t border-zinc-800 pt-3 space-y-3">
                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Optimized Meta Title</label>
                  <input
                    type="text"
                    placeholder="SEO Title | Target Keyword | Brand"
                    value={stageForm.meta_title}
                    onChange={(e) => setStageForm({ ...stageForm, meta_title: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Optimized Meta Description</label>
                  <textarea
                    rows={2}
                    placeholder="High-converting search snippet with call to action..."
                    value={stageForm.meta_description}
                    onChange={(e) => setStageForm({ ...stageForm, meta_description: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Canonical URL (Optional)</label>
                  <input
                    type="url"
                    placeholder={`https://${website.domain}/canonical-path`}
                    value={stageForm.canonical_url}
                    onChange={(e) => setStageForm({ ...stageForm, canonical_url: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                  />
                </div>
              </div>

              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setIsStageModalOpen(false)}
                  className="px-4 py-2 text-zinc-300 hover:text-white bg-zinc-900 border border-zinc-800 rounded-xl transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isLoading}
                  className="px-4 py-2 text-white bg-emerald-600 hover:bg-emerald-500 font-semibold rounded-xl shadow transition"
                >
                  Stage & Capture Pre-Snapshot
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Drawer: Detailed Inspection Modal */}
      {selectedRequest && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-2xl bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4 max-h-[85vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div>
                <h3 className="text-base font-bold text-white">
                  Change Request #{selectedRequest.id} Inspection
                </h3>
                <p className="text-xs text-zinc-500">
                  Idempotency Key: <span className="font-mono text-zinc-400">{selectedRequest.idempotency_key}</span>
                </p>
              </div>
              <button
                onClick={() => setSelectedRequest(null)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="space-y-4 text-xs">
              <div className="p-3 bg-zinc-900/60 rounded-xl space-y-1">
                <div className="font-semibold text-zinc-300">Live Verification Status</div>
                {selectedRequest.verification_details ? (
                  <div className="space-y-2 mt-2">
                    <div className="text-emerald-400 font-mono">
                      Verified: {selectedRequest.verification_details.verified ? 'YES' : 'NO'}
                    </div>
                    <div>
                      <span className="text-zinc-400 font-medium">Matches:</span>
                      <pre className="mt-1 p-2 bg-black/60 rounded font-mono text-[10px] text-zinc-300 overflow-x-auto">
                        {JSON.stringify(selectedRequest.verification_details.matches, null, 2)}
                      </pre>
                    </div>
                  </div>
                ) : (
                  <div className="text-zinc-500 italic">Not yet executed or verified.</div>
                )}
              </div>

              <div>
                <div className="font-semibold text-zinc-300 mb-1">Pre-Change Snapshot Payload</div>
                <pre className="p-3 bg-black/60 rounded-xl font-mono text-[10px] text-zinc-400 overflow-x-auto max-h-48">
                  {JSON.stringify(selectedRequest.pre_change_snapshot?.state_payload || {}, null, 2)}
                </pre>
              </div>

              <div>
                <div className="font-semibold text-zinc-300 mb-1">Proposed Execution Payload</div>
                <pre className="p-3 bg-black/60 rounded-xl font-mono text-[10px] text-emerald-400 overflow-x-auto">
                  {JSON.stringify(selectedRequest.proposed_payload, null, 2)}
                </pre>
              </div>
            </div>

            <div className="pt-2 border-t border-zinc-800 flex justify-end">
              <button
                onClick={() => setSelectedRequest(null)}
                className="px-4 py-2 text-xs font-semibold text-zinc-300 bg-zinc-900 hover:bg-zinc-800 rounded-xl border border-zinc-800"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal: View Snapshot Raw Payload */}
      {selectedSnapshot && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-xl bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4 max-h-[80vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div>
                <h3 className="text-sm font-bold text-white">
                  Snapshot #{selectedSnapshot.id} State Payload
                </h3>
                <p className="text-[11px] font-mono text-zinc-500">
                  {selectedSnapshot.checksum}
                </p>
              </div>
              <button
                onClick={() => setSelectedSnapshot(null)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <pre className="p-3 bg-black/60 rounded-xl font-mono text-[11px] text-zinc-300 overflow-x-auto">
              {JSON.stringify(selectedSnapshot.state_payload, null, 2)}
            </pre>

            <div className="pt-2 border-t border-zinc-800 flex justify-end">
              <button
                onClick={() => setSelectedSnapshot(null)}
                className="px-4 py-1.5 text-xs font-semibold text-zinc-300 bg-zinc-900 hover:bg-zinc-800 rounded-xl border border-zinc-800"
              >
                Dismiss
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
