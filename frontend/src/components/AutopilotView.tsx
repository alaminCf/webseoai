'use client';

import React, { useState, useEffect } from 'react';
import {
  Zap,
  Play,
  Pause,
  CheckCircle,
  XCircle,
  Clock,
  AlertTriangle,
  RotateCcw,
  Plus,
  Shield,
  Layers,
  Sparkles,
  ChevronRight,
  RefreshCw,
  Search,
  CheckSquare,
  FileText,
  DollarSign,
  Activity,
  X,
  Lock,
  ChevronDown,
  Info
} from 'lucide-react';
import { Website, AutomationRule, WorkflowRun, WorkflowApproval } from '@/types';
import { useAuth } from '@/context/AuthContext';

interface AutopilotViewProps {
  website: Website | null;
  onNavigateToApprovals?: () => void;
}

export function AutopilotView({ website, onNavigateToApprovals }: AutopilotViewProps) {
  const { currentOrganization, user } = useAuth();
  const [rules, setRules] = useState<AutomationRule[]>([]);
  const [runs, setRuns] = useState<WorkflowRun[]>([]);
  const [approvals, setApprovals] = useState<WorkflowApproval[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [activeTab, setActiveTab] = useState<'rules' | 'runs' | 'approvals'>('rules');

  // Trigger loading state
  const [triggeringId, setTriggeringId] = useState<number | null>(null);
  const [selectedRun, setSelectedRun] = useState<WorkflowRun | null>(null);

  // New Rule Modal
  const [isNewRuleModalOpen, setIsNewRuleModalOpen] = useState(false);
  const [ruleForm, setRuleForm] = useState({
    name: '',
    description: '',
    trigger_type: 'schedule',
    action_type: 'full_audit_and_recommend',
    is_write_capable: false,
    require_human_approval: true,
    dry_run_mode: false,
    max_runs_per_day: 10,
    monthly_cost_budget: 50.0,
  });

  const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const fetchData = async () => {
    if (!website || !currentOrganization) return;
    setIsLoading(true);

    try {
      const token = localStorage.getItem('auth_token');
      const headers = {
        Authorization: `Bearer ${token}`,
        'X-Organization-Id': String(currentOrganization.id),
      };

      // 1. Fetch Rules
      const rulesRes = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/rules`,
        { credentials: 'omit', headers }
      );
      if (rulesRes.ok) {
        const data = await rulesRes.json();
        setRules(data.data || []);
      }

      // 2. Fetch Runs
      const runsRes = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/runs`,
        { credentials: 'omit', headers }
      );
      if (runsRes.ok) {
        const data = await runsRes.json();
        setRuns(data.data || []);
      }

      // 3. Fetch Approvals
      const appRes = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/approvals`,
        { credentials: 'omit', headers }
      );
      if (appRes.ok) {
        const data = await appRes.json();
        setApprovals(data.data || []);
      }
    } catch (err: any) {
      console.error('Failed to load autopilot data:', err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [website, currentOrganization]);

  // Create rule
  const handleCreateRule = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website || !currentOrganization) return;

    try {
      setIsLoading(true);
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/rules`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify(ruleForm),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Failed to create rule');

      setFeedbackMessage({ type: 'success', text: `Automation Rule '${data.data.name}' created successfully.` });
      setIsNewRuleModalOpen(false);
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    } finally {
      setIsLoading(false);
    }
  };

  // Toggle rule status (pause/resume)
  const handleToggleStatus = async (rule: AutomationRule) => {
    if (!website || !currentOrganization) return;
    const newStatus = rule.status === 'active' ? 'paused' : 'active';

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/rules/${rule.id}/status`,
        {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({ status: newStatus }),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Status update failed');

      setFeedbackMessage({ type: 'success', text: `Rule '${rule.name}' is now ${newStatus}.` });
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    }
  };

  // Trigger rule on-demand
  const handleTriggerRule = async (rule: AutomationRule) => {
    if (!website || !currentOrganization) return;
    setTriggeringId(rule.id);

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/rules/${rule.id}/trigger`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({ payload: { test_mode: true } }),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Trigger failed');

      setFeedbackMessage({
        type: 'success',
        text: `Rule '${rule.name}' triggered. Run #${data.data?.run_uuid?.slice(0, 8)} executed (${data.data?.status}).`,
      });
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: `Autopilot trigger failed: ${err.message}` });
    } finally {
      setTriggeringId(null);
    }
  };

  // Cancel run
  const handleCancelRun = async (run: WorkflowRun) => {
    if (!website || !currentOrganization) return;
    if (!confirm(`Cancel run #${run.id}?`)) return;

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/runs/${run.id}/cancel`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({ reason: 'Cancelled via dashboard' }),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Cancellation failed');

      setFeedbackMessage({ type: 'success', text: `Run #${run.id} cancelled.` });
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    }
  };

  // Approve queued action
  const handleApproveAction = async (approval: WorkflowApproval) => {
    if (!website || !currentOrganization) return;

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/approvals/${approval.id}/approve`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Organization-Id': String(currentOrganization.id),
          },
          body: JSON.stringify({ notes: 'Approved by human reviewer' }),
        }
      );

      const data = await res.json();
      if (!res.ok) throw new Error(data.error?.message || data.message || 'Approval failed');

      setFeedbackMessage({ type: 'success', text: `Action approved and workflow resumed.` });
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    }
  };

  // Reject queued action
  const handleRejectAction = async (approval: WorkflowApproval) => {
    if (!website || !currentOrganization) return;
    const reason = prompt('Please enter rejection reason:');
    if (!reason) return;

    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch(
        `http://localhost:8001/api/v1/websites/${website.id}/automation/approvals/${approval.id}/reject`,
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

      setFeedbackMessage({ type: 'success', text: `Action rejected and workflow cancelled.` });
      fetchData();
    } catch (err: any) {
      setFeedbackMessage({ type: 'error', text: err.message });
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'succeeded':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold text-emerald-400 bg-emerald-950/60 border border-emerald-800/40 rounded-lg">
            <CheckCircle className="w-3.5 h-3.5" /> Succeeded
          </span>
        );
      case 'waiting_for_approval':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold text-amber-400 bg-amber-950/60 border border-amber-800/40 rounded-lg">
            <Clock className="w-3.5 h-3.5" /> Waiting for Approval
          </span>
        );
      case 'running':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold text-sky-400 bg-sky-950/60 border border-sky-800/40 rounded-lg animate-pulse">
            <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Running
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold text-rose-400 bg-rose-950/60 border border-rose-800/40 rounded-lg">
            <AlertTriangle className="w-3.5 h-3.5" /> Failed
          </span>
        );
      case 'cancelled':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold text-zinc-400 bg-zinc-900 border border-zinc-800 rounded-lg">
            <XCircle className="w-3.5 h-3.5" /> Cancelled
          </span>
        );
      default:
        return (
          <span className="px-2.5 py-0.5 text-xs font-medium text-zinc-400 bg-zinc-900 border border-zinc-800 rounded-lg">
            {status}
          </span>
        );
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center border border-zinc-800 rounded-2xl bg-zinc-950/60">
        <Zap className="w-10 h-10 text-zinc-600 mx-auto mb-3" />
        <h3 className="text-base font-semibold text-zinc-200">No Website Selected</h3>
        <p className="text-xs text-zinc-500 mt-1">Select a verified website to configure Autopilot rules and automated workflows.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Autopilot Overview Banner */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <Zap className="w-5 h-5 text-amber-400" />
            <h2 className="text-lg font-bold text-white tracking-tight">
              SEO Autopilot & Workflow Engine
            </h2>
            <span className="px-2 py-0.5 text-[10px] font-bold text-amber-400 bg-amber-950/80 border border-amber-800/60 rounded-full uppercase">
              Phase 6 Durable Engine
            </span>
          </div>
          <p className="text-xs text-zinc-400 max-w-2xl leading-relaxed">
            Automated event-driven SEO loops for {website.name}. Run scheduled crawls, issue auto-remediation, and performance opportunity detection with strict budget limits, dry-run testing, and approval queues.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => setIsNewRuleModalOpen(true)}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-500 rounded-xl shadow-lg transition"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Create Automation Rule</span>
          </button>
          <button
            onClick={fetchData}
            disabled={isLoading}
            className="p-2 text-zinc-400 hover:text-zinc-200 bg-zinc-900 border border-zinc-800 rounded-xl transition"
            title="Refresh"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Active Rules</div>
            <div className="text-xl font-bold text-white mt-0.5">
              {rules.filter(r => r.status === 'active').length} / {rules.length}
            </div>
          </div>
          <div className="p-2.5 bg-zinc-800/60 rounded-xl text-amber-400">
            <Zap className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Durable Runs</div>
            <div className="text-xl font-bold text-emerald-400 mt-0.5">
              {runs.length}
            </div>
          </div>
          <div className="p-2.5 bg-zinc-800/60 rounded-xl text-emerald-400">
            <Activity className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Pending Approvals</div>
            <div className="text-xl font-bold text-sky-400 mt-0.5">
              {approvals.filter(a => a.status === 'pending').length}
            </div>
          </div>
          <div className="p-2.5 bg-zinc-800/60 rounded-xl text-sky-400">
            <Clock className="w-4 h-4" />
          </div>
        </div>

        <div className="p-4 bg-zinc-900/50 border border-zinc-800/80 rounded-xl flex items-center justify-between">
          <div>
            <div className="text-[11px] font-medium text-zinc-400">Monthly AI Cost</div>
            <div className="text-xl font-bold text-zinc-200 mt-0.5">
              ${rules.reduce((acc, r) => acc + Number(r.current_month_cost || 0), 0).toFixed(2)}
            </div>
          </div>
          <div className="p-2.5 bg-zinc-800/60 rounded-xl text-zinc-400">
            <DollarSign className="w-4 h-4" />
          </div>
        </div>
      </div>

      {/* Feedback Message */}
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
              <CheckCircle className="w-4 h-4 text-emerald-400" />
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

      {/* Navigation Tabs */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-3">
        <button
          onClick={() => setActiveTab('rules')}
          className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
            activeTab === 'rules' ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Automation Rules ({rules.length})
        </button>
        <button
          onClick={() => setActiveTab('runs')}
          className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
            activeTab === 'runs' ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Execution Runs ({runs.length})
        </button>
        <button
          onClick={() => setActiveTab('approvals')}
          className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition ${
            activeTab === 'approvals' ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Approval Queue ({approvals.filter(a => a.status === 'pending').length})
        </button>
      </div>

      {/* Main Tab Viewports */}
      {activeTab === 'rules' && (
        rules.length === 0 ? (
          <div className="p-12 text-center border border-zinc-800/80 rounded-2xl bg-zinc-950/40">
            <Zap className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-zinc-300">No Automation Rules Configured</h3>
            <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
              Create your first automation rule to schedule weekly crawls or auto-stage metadata fixes.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {rules.map((rule) => {
              const isTriggering = triggeringId === rule.id;

              return (
                <div
                  key={rule.id}
                  className="p-5 bg-zinc-950/80 border border-zinc-800/80 hover:border-zinc-700/80 rounded-2xl shadow-sm transition space-y-4"
                >
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-xl border ${
                        rule.status === 'active'
                          ? 'bg-amber-950/40 border-amber-800/40 text-amber-400'
                          : 'bg-zinc-900 border-zinc-800 text-zinc-500'
                      }`}>
                        <Zap className="w-4 h-4" />
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="text-xs font-bold text-white">{rule.name}</span>
                          <span className={`text-[10px] font-bold px-1.5 py-0.2 rounded uppercase ${
                            rule.status === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/60' : 'bg-zinc-900 text-zinc-500'
                          }`}>
                            {rule.status}
                          </span>
                          {rule.dry_run_mode && (
                            <span className="text-[10px] font-bold px-1.5 py-0.2 rounded uppercase bg-blue-950 text-blue-400 border border-blue-800/60">
                              Dry Run
                            </span>
                          )}
                          {rule.is_write_capable && (
                            <span className="text-[10px] font-bold px-1.5 py-0.2 rounded uppercase bg-rose-950 text-rose-400 border border-rose-800/60">
                              Write Capable
                            </span>
                          )}
                        </div>
                        <div className="text-[11px] text-zinc-400 mt-0.5">
                          Trigger: <span className="text-zinc-200 capitalize">{rule.trigger_type.replace('_', ' ')}</span> • Action:{' '}
                          <span className="text-zinc-200 capitalize">{rule.action_type.replace('_', ' ')}</span>
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleToggleStatus(rule)}
                        className="px-3 py-1.5 text-xs font-medium text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
                      >
                        {rule.status === 'active' ? 'Pause' : 'Resume'}
                      </button>
                      <button
                        onClick={() => handleTriggerRule(rule)}
                        disabled={isTriggering || rule.status !== 'active'}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-500 disabled:bg-zinc-800 disabled:text-zinc-600 rounded-xl shadow transition"
                      >
                        <Play className="w-3.5 h-3.5 fill-current" />
                        <span>Run Now</span>
                      </button>
                    </div>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-zinc-800/60 text-[11px] text-zinc-400 font-mono">
                    <div>
                      Daily Cap: <span className="text-zinc-200">{rule.max_runs_per_day} runs</span>
                    </div>
                    <div>
                      Monthly Budget: <span className="text-zinc-200">${rule.monthly_cost_budget}</span>
                    </div>
                    <div>
                      Current Spend: <span className="text-amber-400">${Number(rule.current_month_cost).toFixed(2)}</span>
                    </div>
                    <div>
                      Human Approval: <span className="text-zinc-200">{rule.require_human_approval ? 'Required' : 'Opted Out'}</span>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )
      )}

      {/* Execution Runs Tab */}
      {activeTab === 'runs' && (
        runs.length === 0 ? (
          <div className="p-12 text-center border border-zinc-800/80 rounded-2xl bg-zinc-950/40">
            <Activity className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-zinc-300">No Execution Runs Yet</h3>
            <p className="text-xs text-zinc-500 mt-1">Run an automation rule to inspect step-level execution telemetry.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {runs.map((run) => (
              <div
                key={run.id}
                className="p-4 bg-zinc-950/80 border border-zinc-800/80 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs"
              >
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-white font-mono">Run #{run.id}</span>
                    <span className="text-zinc-600">•</span>
                    <span className="text-zinc-300 font-medium">{run.rule?.name || 'Manual Workflow'}</span>
                    <span className="text-zinc-600">•</span>
                    <span className="text-zinc-400 font-mono text-[11px]">{run.run_uuid.slice(0, 8)}</span>
                  </div>
                  <div className="text-[11px] text-zinc-500 mt-1">
                    Triggered via <span className="text-zinc-300 capitalize">{run.trigger_source}</span> on{' '}
                    {new Date(run.created_at).toLocaleString()}
                    {run.finished_at && <span> • Finished in {new Date(run.finished_at).toLocaleTimeString()}</span>}
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  {getStatusBadge(run.status)}
                  <button
                    onClick={() => setSelectedRun(run)}
                    className="px-2.5 py-1 text-zinc-300 hover:text-white bg-zinc-900 border border-zinc-800 rounded-lg transition"
                  >
                    Inspect Steps
                  </button>
                  {run.status === 'running' && (
                    <button
                      onClick={() => handleCancelRun(run)}
                      className="px-2.5 py-1 text-rose-400 hover:text-white bg-rose-950/40 border border-rose-800/40 rounded-lg transition"
                    >
                      Cancel
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )
      )}

      {/* Approval Queue Tab */}
      {activeTab === 'approvals' && (
        approvals.length === 0 ? (
          <div className="p-12 text-center border border-zinc-800/80 rounded-2xl bg-zinc-950/40">
            <CheckSquare className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <h3 className="text-sm font-semibold text-zinc-300">Approval Queue Empty</h3>
            <p className="text-xs text-zinc-500 mt-1">
              Actions requiring human authorization will appear here before any live website writes occur.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {approvals.map((approval) => (
              <div
                key={approval.id}
                className="p-5 bg-zinc-950/80 border border-zinc-800/80 rounded-2xl space-y-3"
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-white">{approval.title}</span>
                      <span className={`text-[10px] font-bold px-1.5 py-0.2 rounded uppercase ${
                        approval.status === 'pending' ? 'bg-amber-950 text-amber-400 border border-amber-800/60' : 'bg-zinc-900 text-zinc-500'
                      }`}>
                        {approval.status}
                      </span>
                      <span className="text-[10px] font-bold px-1.5 py-0.2 rounded uppercase bg-zinc-900 text-zinc-400">
                        {approval.risk_level} Risk
                      </span>
                    </div>
                    <div className="text-xs text-zinc-400 mt-1">{approval.description}</div>
                  </div>

                  {approval.status === 'pending' && (
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleRejectAction(approval)}
                        className="px-3 py-1.5 text-xs text-zinc-300 hover:text-rose-400 bg-zinc-900 border border-zinc-800 rounded-xl transition"
                      >
                        Reject
                      </button>
                      <button
                        onClick={() => handleApproveAction(approval)}
                        className="px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
                      >
                        Approve & Resume
                      </button>
                    </div>
                  )}
                </div>

                {approval.proposed_diff && (
                  <div className="p-3 bg-zinc-900/60 rounded-xl font-mono text-[11px] text-zinc-300 overflow-x-auto">
                    <pre>{JSON.stringify(approval.proposed_diff, null, 2)}</pre>
                  </div>
                )}
              </div>
            ))}
          </div>
        )
      )}

      {/* Modal: Create Automation Rule */}
      {isNewRuleModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-lg bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div className="flex items-center gap-2">
                <Zap className="w-5 h-5 text-amber-400" />
                <h3 className="text-base font-bold text-white">Create Automation Rule</h3>
              </div>
              <button
                onClick={() => setIsNewRuleModalOpen(false)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateRule} className="space-y-4 text-xs">
              <div>
                <label className="block text-zinc-400 font-medium mb-1">Rule Name</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Weekly SEO Crawl & Issue Compare"
                  value={ruleForm.name}
                  onChange={(e) => setRuleForm({ ...ruleForm, name: e.target.value })}
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-amber-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Trigger Type</label>
                  <select
                    value={ruleForm.trigger_type}
                    onChange={(e) => setRuleForm({ ...ruleForm, trigger_type: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                  >
                    <option value="schedule">Schedule (Weekly)</option>
                    <option value="crawl_completed">Crawl Completion</option>
                    <option value="issue_detected">New Issue Detected</option>
                    <option value="performance_threshold">Search Threshold Crossed</option>
                    <option value="content_approved">Content Approved</option>
                  </select>
                </div>

                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Action Type</label>
                  <select
                    value={ruleForm.action_type}
                    onChange={(e) => setRuleForm({ ...ruleForm, action_type: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                  >
                    <option value="full_audit_and_recommend">Full Audit & Recommendations</option>
                    <option value="auto_stage_metadata_fix">Auto-Stage Missing Metadata Fix</option>
                    <option value="publish_approved_content">Publish Approved Content</option>
                    <option value="sync_gsc_and_report">Search Console Sync & Report</option>
                  </select>
                </div>
              </div>

              {/* Guardrail Policy Toggles */}
              <div className="p-3 bg-zinc-900/60 rounded-xl border border-zinc-800/60 space-y-2">
                <div className="text-[11px] font-bold text-zinc-300 uppercase tracking-wider mb-1">
                  Safety Guardrails
                </div>

                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={ruleForm.is_write_capable}
                    onChange={(e) => setRuleForm({ ...ruleForm, is_write_capable: e.target.checked })}
                    className="rounded bg-zinc-800 border-zinc-700 text-amber-500 focus:ring-0"
                  />
                  <span className="text-zinc-300">Write Capable (Can touch remote CMS / Website)</span>
                </label>

                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={ruleForm.require_human_approval}
                    onChange={(e) => setRuleForm({ ...ruleForm, require_human_approval: e.target.checked })}
                    className="rounded bg-zinc-800 border-zinc-700 text-amber-500 focus:ring-0"
                  />
                  <span className="text-zinc-300">Require Human Approval before Execution</span>
                </label>

                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={ruleForm.dry_run_mode}
                    onChange={(e) => setRuleForm({ ...ruleForm, dry_run_mode: e.target.checked })}
                    className="rounded bg-zinc-800 border-zinc-700 text-blue-500 focus:ring-0"
                  />
                  <span className="text-zinc-300">Dry-Run Mode (Simulate without committing live writes)</span>
                </label>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Max Runs / Day</label>
                  <input
                    type="number"
                    min="1"
                    max="100"
                    value={ruleForm.max_runs_per_day}
                    onChange={(e) => setRuleForm({ ...ruleForm, max_runs_per_day: Number(e.target.value) })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                  />
                </div>

                <div>
                  <label className="block text-zinc-400 font-medium mb-1">Monthly Budget ($)</label>
                  <input
                    type="number"
                    min="1"
                    max="500"
                    value={ruleForm.monthly_cost_budget}
                    onChange={(e) => setRuleForm({ ...ruleForm, monthly_cost_budget: Number(e.target.value) })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                  />
                </div>
              </div>

              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setIsNewRuleModalOpen(false)}
                  className="px-4 py-2 text-zinc-300 hover:text-white bg-zinc-900 border border-zinc-800 rounded-xl transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isLoading}
                  className="px-4 py-2 text-white bg-amber-600 hover:bg-amber-500 font-semibold rounded-xl shadow transition"
                >
                  Save Automation Rule
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Inspect Run Steps */}
      {selectedRun && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="w-full max-w-2xl bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4 max-h-[85vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div>
                <h3 className="text-base font-bold text-white">
                  Run #{selectedRun.id} Step-Level Telemetry
                </h3>
                <p className="text-xs text-zinc-500 font-mono">
                  UUID: {selectedRun.run_uuid} • Idempotency: {selectedRun.idempotency_key}
                </p>
              </div>
              <button
                onClick={() => setSelectedRun(null)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="font-semibold text-zinc-300 mb-1">Step Results Log:</div>
              {selectedRun.step_results && selectedRun.step_results.length > 0 ? (
                <div className="space-y-2">
                  {selectedRun.step_results.map((st, idx) => (
                    <div key={idx} className="p-3 bg-zinc-900/60 rounded-xl font-mono text-[11px] space-y-1">
                      <div className="flex items-center justify-between">
                        <span className="font-bold text-white uppercase">{st.step}</span>
                        <span className={`px-1.5 py-0.5 rounded text-[10px] ${
                          st.status === 'completed' ? 'bg-emerald-950 text-emerald-400' : 'bg-zinc-800 text-zinc-300'
                        }`}>
                          {st.status}
                        </span>
                      </div>
                      <div className="text-zinc-500 text-[10px]">{st.timestamp}</div>
                      {st.data && (
                        <pre className="mt-1 p-2 bg-black/60 rounded text-zinc-300 overflow-x-auto text-[10px]">
                          {JSON.stringify(st.data, null, 2)}
                        </pre>
                      )}
                      {st.error && (
                        <div className="text-rose-400 text-[11px] mt-1">{st.error}</div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="p-4 bg-zinc-900/40 rounded-xl text-zinc-500 italic">No step records yet.</div>
              )}
            </div>

            <div className="pt-2 border-t border-zinc-800 flex justify-end">
              <button
                onClick={() => setSelectedRun(null)}
                className="px-4 py-2 text-xs font-semibold text-zinc-300 bg-zinc-900 hover:bg-zinc-800 rounded-xl border border-zinc-800"
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
