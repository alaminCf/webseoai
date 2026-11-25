'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Zap,
  Play,
  Pause,
  Trash2,
  Plus,
  Bell,
  CheckCheck,
  Sparkles,
  Search,
  Link2,
  Layers,
  Clock,
  RefreshCw,
} from 'lucide-react';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { Website } from '@/types';
import {
  KiAlert,
  KiAutomationWorkflow,
  COUNTRIES,
  LANGUAGES,
} from './types';

/* ============================================================================
 * SECTION 11: KEYWORD AUTOMATION CONTROL CENTER + ALERTS INBOX
 * ============================================================================ */
export function KeywordAutomationTab({
  website,
  onRefreshOverview,
}: {
  website: Website;
  onRefreshOverview: () => void;
}) {
  const toast = useToast();
  const [workflows, setWorkflows] = useState<KiAutomationWorkflow[]>([]);
  const [alerts, setAlerts] = useState<KiAlert[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [runningId, setRunningId] = useState<number | null>(null);

  // Create form state
  const [name, setName] = useState('Weekly Keyword Discovery');
  const [seedsText, setSeedsText] = useState('');
  const [category, setCategory] = useState('');
  const [country, setCountry] = useState(website.target_country || 'US');
  const [language, setLanguage] = useState(website.preferred_language || 'en');
  const [frequency, setFrequency] = useState<'daily' | 'weekly' | 'monthly' | 'custom'>('weekly');
  const [customHours, setCustomHours] = useState(48);
  const [autoCluster, setAutoCluster] = useState(true);
  const [autoMonitorRank, setAutoMonitorRank] = useState(false);
  const [autoContentRecs, setAutoContentRecs] = useState(true);
  const [notifyOpp, setNotifyOpp] = useState(true);
  const [minScore, setMinScore] = useState(65);
  const [creating, setCreating] = useState(false);

  const loadAll = useCallback(async () => {
    setLoading(true);
    try {
      const [wfRes, alRes] = await Promise.all([
        api.get<KiAutomationWorkflow[]>(`/websites/${website.id}/keyword-intelligence/automations`),
        api.get<KiAlert[]>(`/websites/${website.id}/keyword-intelligence/alerts`),
      ]);
      if (wfRes.success && wfRes.data) setWorkflows(wfRes.data);
      if (alRes.success && alRes.data) setAlerts(alRes.data);
    } finally {
      setLoading(false);
    }
  }, [website.id]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    const seeds = seedsText.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
    if (!seeds.length) {
      toast.error('Please enter at least one seed keyword.');
      return;
    }
    setCreating(true);
    try {
      const res = await api.post(`/websites/${website.id}/keyword-intelligence/automations`, {
        name: name.trim(),
        seed_keywords: seeds,
        business_category: category.trim() || undefined,
        target_country: country,
        target_language: language,
        schedule_frequency: frequency,
        custom_interval_hours: frequency === 'custom' ? customHours : undefined,
        auto_cluster: autoCluster,
        auto_monitor_rank: autoMonitorRank,
        auto_content_recommendations: autoContentRecs,
        notify_on_opportunities: notifyOpp,
        min_opportunity_score: minScore,
      });
      if (res.success) {
        toast.success('Keyword automation workflow created.');
        setShowCreate(false);
        setSeedsText('');
        loadAll();
        onRefreshOverview();
      } else {
        toast.error(res.error?.message || 'Failed to create workflow.');
      }
    } finally {
      setCreating(false);
    }
  };

  const handleToggleStatus = async (wf: KiAutomationWorkflow) => {
    const next = wf.status === 'active' ? 'paused' : 'active';
    const res = await api.patch(`/websites/${website.id}/keyword-intelligence/automations/${wf.id}`, {
      status: next,
    });
    if (res.success) {
      toast.info(`Workflow ${next === 'active' ? 'resumed' : 'paused'}.`);
      loadAll();
      onRefreshOverview();
    }
  };

  const handleRunNow = async (wf: KiAutomationWorkflow) => {
    setRunningId(wf.id);
    try {
      const res = await api.post<{ run?: { new_keywords: number; opportunities_found: number; alerts_created: number } }>(
        `/websites/${website.id}/keyword-intelligence/automations/${wf.id}/run`,
        {}
      );
      if (res.success) {
        const r = res.data?.run;
        toast.success(
          `Workflow completed: ${r?.new_keywords ?? 0} new keywords, ${r?.opportunities_found ?? 0} opportunities, ${r?.alerts_created ?? 0} alerts.`
        );
        loadAll();
        onRefreshOverview();
      } else {
        toast.error(res.error?.message || 'Run failed.');
      }
    } finally {
      setRunningId(null);
    }
  };

  const handleDelete = async (wf: KiAutomationWorkflow) => {
    if (!confirm(`Delete automation workflow "${wf.name}"?`)) return;
    const res = await api.delete(`/websites/${website.id}/keyword-intelligence/automations/${wf.id}`);
    if (res.success) {
      toast.info('Workflow deleted.');
      loadAll();
      onRefreshOverview();
    }
  };

  const handleMarkAllRead = async () => {
    await api.post(`/websites/${website.id}/keyword-intelligence/alerts/read-all`);
    loadAll();
    onRefreshOverview();
  };

  return (
    <div className="space-y-6">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Zap className="w-4 h-4 text-emerald-400" /> Keyword Automation Control Center
          </h3>
          <p className="text-xs text-zinc-400">
            Schedule recurring keyword discovery, incremental clustering, opportunity alerts, Rank Checker monitoring, and draft Content Studio / Action Plan recommendations (never auto-publishes).
          </p>
        </div>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
        >
          <Plus className="w-3.5 h-3.5" />
          {showCreate ? 'Close Builder' : 'New Automation Workflow'}
        </button>
      </div>

      {/* Create Form */}
      {showCreate && (
        <form onSubmit={handleCreate} className="bg-zinc-900/80 border border-emerald-500/30 rounded-2xl p-5 space-y-4">
          <h4 className="text-sm font-bold text-emerald-400">Create Recurring Keyword Automation</h4>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">Workflow Name</label>
              <input value={name} onChange={(e) => setName(e.target.value)} required className="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100" />
            </div>
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">Business Category (Optional)</label>
              <input value={category} onChange={(e) => setCategory(e.target.value)} placeholder="e.g. Home Care Services" className="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100" />
            </div>
          </div>

          <div>
            <label className="block text-[11px] text-zinc-400 mb-1">Seed Keywords (comma or newline separated)</label>
            <textarea rows={2} value={seedsText} onChange={(e) => setSeedsText(e.target.value)} placeholder="e.g. home nursing care, elderly care dhaka, physiotherapy at home" required className="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100" />
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">Country</label>
              <select value={country} onChange={(e) => setCountry(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
                {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">Language</label>
              <select value={language} onChange={(e) => setLanguage(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
                {LANGUAGES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">Schedule Frequency</label>
              <select value={frequency} onChange={(e) => setFrequency(e.target.value as typeof frequency)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom Interval (Hours)</option>
              </select>
            </div>
            <div>
              <label className="block text-[11px] text-zinc-400 mb-1">
                {frequency === 'custom' ? 'Interval Hours' : 'Min Opportunity Score'}
              </label>
              {frequency === 'custom' ? (
                <input type="number" min={1} max={720} value={customHours} onChange={(e) => setCustomHours(Number(e.target.value))} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
              ) : (
                <input type="number" min={30} max={95} value={minScore} onChange={(e) => setMinScore(Number(e.target.value))} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 text-xs">
            <label className="flex items-center gap-2 p-2.5 rounded-xl bg-zinc-950 border border-zinc-800 cursor-pointer">
              <input type="checkbox" checked={autoCluster} onChange={(e) => setAutoCluster(e.target.checked)} />
              <span className="text-zinc-200">Auto-Cluster New Keywords</span>
            </label>
            <label className="flex items-center gap-2 p-2.5 rounded-xl bg-zinc-950 border border-zinc-800 cursor-pointer">
              <input type="checkbox" checked={autoContentRecs} onChange={(e) => setAutoContentRecs(e.target.checked)} />
              <span className="text-zinc-200">Draft Action Recommendations</span>
            </label>
            <label className="flex items-center gap-2 p-2.5 rounded-xl bg-zinc-950 border border-zinc-800 cursor-pointer">
              <input type="checkbox" checked={notifyOpp} onChange={(e) => setNotifyOpp(e.target.checked)} />
              <span className="text-zinc-200">Opportunity &amp; Trend Alerts</span>
            </label>
            <label className="flex items-center gap-2 p-2.5 rounded-xl bg-zinc-950 border border-zinc-800 cursor-pointer">
              <input type="checkbox" checked={autoMonitorRank} onChange={(e) => setAutoMonitorRank(e.target.checked)} />
              <span className="text-zinc-200">Auto-Monitor Top Ranks</span>
            </label>
          </div>

          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setShowCreate(false)} className="px-4 py-2 rounded-xl bg-zinc-800 text-xs text-zinc-300">Cancel</button>
            <button type="submit" disabled={creating} className="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold">
              {creating ? 'Saving...' : 'Create Workflow'}
            </button>
          </div>
        </form>
      )}

      {/* Workflows + Alerts Split */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 space-y-3">
          <h4 className="text-xs font-bold text-zinc-400 uppercase tracking-wider">Scheduled Workflows ({workflows.length})</h4>
          {loading ? (
            <div className="p-8 text-center text-xs text-zinc-500">Loading workflows...</div>
          ) : workflows.length === 0 ? (
            <div className="p-8 bg-zinc-900/50 border border-zinc-800 rounded-2xl text-center text-xs text-zinc-500">
              No automation workflows created yet. Click &ldquo;New Automation Workflow&rdquo; to automate recurring keyword research.
            </div>
          ) : (
            workflows.map((wf) => (
              <div key={wf.id} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-bold text-zinc-100">{wf.name}</span>
                      <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                        wf.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-800 text-zinc-400'
                      }`}>
                        {wf.status}
                      </span>
                      <span className="px-2 py-0.5 rounded bg-zinc-800 text-[10px] text-zinc-300 uppercase">
                        {wf.schedule_frequency}
                      </span>
                    </div>
                    <div className="text-[11px] text-zinc-400 mt-1">
                      Seeds: {(wf.seed_keywords || []).join(', ')} • {wf.target_country}/{wf.target_language}
                    </div>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <button
                      onClick={() => handleRunNow(wf)}
                      disabled={runningId === wf.id}
                      className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-semibold"
                    >
                      {runningId === wf.id ? <RefreshCw className="w-3 h-3 animate-spin" /> : <Play className="w-3 h-3" />}
                      Run Now
                    </button>
                    <button
                      onClick={() => handleToggleStatus(wf)}
                      className="p-1.5 rounded-xl bg-zinc-950 border border-zinc-800 text-zinc-300 hover:text-amber-400"
                      title={wf.status === 'active' ? 'Pause' : 'Resume'}
                    >
                      {wf.status === 'active' ? <Pause className="w-3.5 h-3.5" /> : <Play className="w-3.5 h-3.5" />}
                    </button>
                    <button
                      onClick={() => handleDelete(wf)}
                      className="p-1.5 rounded-xl bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-rose-400"
                      title="Delete workflow"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>

                <div className="flex flex-wrap gap-3 text-[11px] text-zinc-400">
                  <span>Discovered: <strong className="text-zinc-200">{wf.total_keywords_discovered}</strong></span>
                  <span>Opportunities: <strong className="text-emerald-400">{wf.total_opportunities_found}</strong></span>
                  {wf.last_run_at && <span>Last run: {new Date(wf.last_run_at).toLocaleString()}</span>}
                  {wf.next_run_at && <span>Next run: {new Date(wf.next_run_at).toLocaleString()}</span>}
                </div>

                {/* Recent Run Logs */}
                {wf.runs && wf.runs.length > 0 && (
                  <div className="pt-2 border-t border-zinc-800 space-y-1.5">
                    <div className="text-[10px] font-semibold text-zinc-500 uppercase flex items-center gap-1">
                      <Clock className="w-3 h-3" /> Execution Logs
                    </div>
                    {wf.runs.slice(0, 3).map((r) => (
                      <div key={r.id} className="text-[11px] px-2.5 py-1.5 rounded-lg bg-zinc-950/70 border border-zinc-800/70 flex flex-wrap items-center justify-between gap-2">
                        <span className={r.status === 'completed' ? 'text-emerald-400 font-semibold' : r.status === 'failed' ? 'text-rose-400' : 'text-amber-400'}>
                          {r.status.toUpperCase()} ({r.trigger_type})
                        </span>
                        <span className="text-zinc-400 font-mono">
                          +{r.new_keywords} new • {r.clusters_updated} clusters • {r.opportunities_found} opp • {r.duration_ms}ms
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))
          )}
        </div>

        {/* Alerts Inbox */}
        <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 space-y-3 h-fit">
          <div className="flex items-center justify-between">
            <h4 className="text-xs font-bold text-zinc-200 uppercase flex items-center gap-1.5">
              <Bell className="w-3.5 h-3.5 text-emerald-400" /> Alerts Inbox ({alerts.filter((a) => !a.is_read).length} unread)
            </h4>
            {alerts.some((a) => !a.is_read) && (
              <button onClick={handleMarkAllRead} className="text-[11px] text-emerald-400 hover:underline inline-flex items-center gap-1">
                <CheckCheck className="w-3 h-3" /> Mark all read
              </button>
            )}
          </div>
          {alerts.length === 0 ? (
            <p className="text-xs text-zinc-500 py-6 text-center">No keyword alerts yet.</p>
          ) : (
            <div className="space-y-2 max-h-[520px] overflow-y-auto">
              {alerts.map((al) => (
                <div
                  key={al.id}
                  className={`p-3 rounded-xl border text-xs ${
                    al.is_read ? 'bg-zinc-950/40 border-zinc-800/60 opacity-75' : 'bg-zinc-950 border-emerald-500/30'
                  }`}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold text-zinc-100">{al.title}</span>
                    <span className={`text-[9px] px-1.5 py-0.5 rounded uppercase font-bold ${
                      al.severity === 'high' ? 'bg-amber-500/20 text-amber-300' : 'bg-zinc-800 text-zinc-400'
                    }`}>
                      {al.severity}
                    </span>
                  </div>
                  <p className="text-[11px] text-zinc-400 mt-1">{al.message}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/* ============================================================================
 * PHASE 6: AI KEYWORD STRATEGIST (Evidence-Backed Recommendations)
 * ============================================================================ */
export function AiKeywordStrategistTab({
  website,
  onResearchSeed,
}: {
  website: Website;
  onResearchSeed: (seed: string) => void;
}) {
  const toast = useToast();
  const [goal, setGoal] = useState('Grow qualified organic search traffic and close high-intent content gaps');
  const [focusTopic, setFocusTopic] = useState('');
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<{
    disclaimer: string;
    evidence_summary: Record<string, number>;
    strategy: {
      executive_summary: string;
      recommended_seed_keywords?: Array<{ seed: string; intent: string; evidence: string }>;
      missing_keyword_coverage?: Array<{ topic: string; primary_keyword: string; secondary_keywords?: string[]; search_intent?: string; evidence: string }>;
      pillar_page_recommendations?: Array<{ pillar_title: string; primary_keyword: string; supporting_topics?: string[]; search_intent?: string; evidence: string }>;
      internal_linking_opportunities?: Array<{ from_page: string; to_target: string; suggested_anchor: string; rationale: string }>;
      prioritization_roadmap?: Array<{ phase: string; priority: string; action: string; target_keywords?: string[]; evidence: string }>;
    };
  } | null>(null);

  const handleAnalyze = async (e: React.FormEvent) => {
    e.preventDefault();
    setRunning(true);
    try {
      const res = await api.post<typeof result>(`/websites/${website.id}/keyword-intelligence/strategist/analyze`, {
        goal,
        focus_topic: focusTopic || undefined,
      });
      if (res.success && res.data) {
        setResult(res.data);
        toast.success('AI Keyword Strategy generated from your verified workspace data.');
      } else {
        toast.error(res.error?.message || 'AI Strategist failed.');
      }
    } finally {
      setRunning(false);
    }
  };

  return (
    <div className="space-y-5">
      <form onSubmit={handleAnalyze} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-4">
        <div>
          <div className="inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-400 uppercase">
            <Sparkles className="w-3.5 h-3.5" /> AI Keyword Strategist (Evidence-Backed)
          </div>
          <h3 className="text-base font-bold text-zinc-100 mt-0.5">
            Generate Seed Keywords, Pillar Pages, Internal Links &amp; Prioritized Roadmap
          </h3>
          <p className="text-xs text-zinc-400 mt-0.5">
            Grounded strictly in your website context profile, crawled pages, GSC queries, competitor gaps, and researched keywords.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className="block text-[11px] text-zinc-400 mb-1">Primary SEO Goal</label>
            <input value={goal} onChange={(e) => setGoal(e.target.value)} className="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100" />
          </div>
          <div>
            <label className="block text-[11px] text-zinc-400 mb-1">Optional Focus Topic / Service Line</label>
            <input value={focusTopic} onChange={(e) => setFocusTopic(e.target.value)} placeholder="e.g. Technical SEO, Home Nursing, Pricing..." className="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100" />
          </div>
        </div>

        <div className="flex justify-end">
          <button
            type="submit"
            disabled={running}
            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
          >
            <Sparkles className="w-4 h-4" />
            {running ? 'Analyzing Workspace Data...' : 'Generate AI Keyword Strategy'}
          </button>
        </div>
      </form>

      {result && (
        <div className="space-y-4">
          <div className="p-3.5 rounded-xl bg-sky-500/10 border border-sky-500/30 text-xs text-sky-200">
            <strong>AI-Inferred Notice:</strong> {result.disclaimer}
          </div>

          <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-2">
            <h4 className="text-xs font-bold text-emerald-400 uppercase">Executive Summary</h4>
            <p className="text-xs text-zinc-200 leading-relaxed">{result.strategy.executive_summary}</p>
          </div>

          {/* Recommended Seeds */}
          {(result.strategy.recommended_seed_keywords?.length || 0) > 0 && (
            <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
              <h4 className="text-xs font-bold text-zinc-200 uppercase">Recommended Seed Keywords</h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                {result.strategy.recommended_seed_keywords!.map((s, idx) => (
                  <div key={idx} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 flex items-start justify-between gap-2 text-xs">
                    <div>
                      <div className="font-bold text-zinc-100">{s.seed}</div>
                      <div className="text-[11px] text-zinc-400 mt-0.5">{s.evidence}</div>
                    </div>
                    <button
                      onClick={() => onResearchSeed(s.seed)}
                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 text-[11px] font-semibold shrink-0 hover:bg-emerald-600/30"
                    >
                      <Search className="w-3 h-3" /> Research
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Pillar Pages & Internal Linking */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
              <h4 className="text-xs font-bold text-zinc-200 uppercase flex items-center gap-1.5">
                <Layers className="w-3.5 h-3.5 text-emerald-400" /> Pillar Page Blueprints
              </h4>
              {(result.strategy.pillar_page_recommendations || []).map((p, idx) => (
                <div key={idx} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs space-y-1">
                  <div className="font-bold text-zinc-100">{p.pillar_title}</div>
                  <div className="text-emerald-400 text-[11px]">Primary: {p.primary_keyword}</div>
                  <div className="text-zinc-400 text-[11px]">{p.evidence}</div>
                </div>
              ))}
            </div>

            <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
              <h4 className="text-xs font-bold text-zinc-200 uppercase flex items-center gap-1.5">
                <Link2 className="w-3.5 h-3.5 text-sky-400" /> Internal Linking Opportunities
              </h4>
              {(result.strategy.internal_linking_opportunities || []).length === 0 ? (
                <p className="text-xs text-zinc-500">Run a Site Crawl first to unlock page-to-page internal linking recommendations.</p>
              ) : (
                result.strategy.internal_linking_opportunities!.map((l, idx) => (
                  <div key={idx} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs space-y-1">
                    <div className="font-semibold text-zinc-100">Anchor: &ldquo;{l.suggested_anchor}&rdquo;</div>
                    <div className="text-[11px] text-zinc-400 truncate">From: {l.from_page}</div>
                    <div className="text-[11px] text-emerald-400 truncate">To: {l.to_target}</div>
                    <div className="text-[11px] text-zinc-500">{l.rationale}</div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Prioritized Roadmap */}
          {(result.strategy.prioritization_roadmap?.length || 0) > 0 && (
            <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
              <h4 className="text-xs font-bold text-zinc-200 uppercase">Prioritization Roadmap</h4>
              <div className="space-y-2.5">
                {result.strategy.prioritization_roadmap!.map((r, idx) => (
                  <div key={idx} className="p-3.5 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs space-y-1">
                    <div className="flex items-center justify-between">
                      <span className="font-bold text-zinc-100">{r.phase}</span>
                      <span className="px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-400 font-bold text-[10px]">{r.priority}</span>
                    </div>
                    <p className="text-zinc-300">{r.action}</p>
                    <p className="text-[11px] text-zinc-500">Evidence: {r.evidence}</p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
