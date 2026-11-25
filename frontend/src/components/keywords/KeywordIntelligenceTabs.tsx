'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Layers,
  GitMerge,
  Scissors,
  Download,
  FileText,
  Trash2,
  Bookmark,
  BookmarkCheck,
  Sparkles,
  Sliders,
  CheckSquare,
  HelpCircle,
  AlertTriangle,
  RefreshCw,
  Edit3,
  Plus,
} from 'lucide-react';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { Website } from '@/types';
import {
  KiCluster,
  KiKeyword,
  KiCannibalizationFinding,
  INTENT_STYLES,
  OPP_CATEGORY_STYLES,
  formatNullableNumber,
} from './types';

/* ============================================================================
 * SECTION 4: KEYWORD CLUSTERING (Semantic + SERP-based + Merge / Split / Studio)
 * ============================================================================ */
export function KeywordClusteringTab({
  website,
  onNavigateToContent,
  onRefreshOverview,
}: {
  website: Website;
  onNavigateToContent?: () => void;
  onRefreshOverview: () => void;
}) {
  const toast = useToast();
  const [clusters, setClusters] = useState<KiCluster[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [method, setMethod] = useState<'semantic' | 'serp'>('semantic');
  const [notice, setNotice] = useState<string | null>(null);
  const [selectedClusterIds, setSelectedClusterIds] = useState<number[]>([]);
  const [renamingId, setRenamingId] = useState<number | null>(null);
  const [renameText, setRenameText] = useState('');
  const [splitTarget, setSplitTarget] = useState<KiCluster | null>(null);
  const [splitKwIds, setSplitKwIds] = useState<number[]>([]);
  const [splitNewName, setSplitNewName] = useState('');

  const loadClusters = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get<KiCluster[]>(`/websites/${website.id}/keyword-intelligence/clusters`);
      if (res.success && res.data) {
        setClusters(res.data);
      }
    } finally {
      setLoading(false);
    }
  }, [website.id]);

  useEffect(() => {
    loadClusters();
  }, [loadClusters]);

  const handleGenerate = async () => {
    setGenerating(true);
    setNotice(null);
    try {
      const res = await api.post<{ clusters_created: number; notice?: string }>(
        `/websites/${website.id}/keyword-intelligence/clusters/generate`,
        { method, replace_unsaved: true }
      );
      if (res.success && res.data) {
        if (res.data.notice) setNotice(res.data.notice);
        toast.success(`Generated ${res.data.clusters_created} ${method} cluster(s).`);
        loadClusters();
        onRefreshOverview();
      } else {
        toast.error(res.error?.message || 'Clustering failed.');
      }
    } finally {
      setGenerating(false);
    }
  };

  const handleMerge = async () => {
    if (selectedClusterIds.length < 2) return;
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/clusters/merge`, {
      cluster_ids: selectedClusterIds,
    });
    if (res.success) {
      toast.success('Clusters merged cleanly.');
      setSelectedClusterIds([]);
      loadClusters();
      onRefreshOverview();
    } else {
      toast.error(res.error?.message || 'Could not merge clusters (check intent conflict).');
    }
  };

  const handleRename = async (clusterId: number) => {
    if (!renameText.trim()) return;
    const res = await api.patch(`/websites/${website.id}/keyword-intelligence/clusters/${clusterId}`, {
      name: renameText.trim(),
    });
    if (res.success) {
      toast.success('Cluster renamed.');
      setRenamingId(null);
      loadClusters();
    }
  };

  const handleToggleSave = async (c: KiCluster) => {
    const res = await api.patch(`/websites/${website.id}/keyword-intelligence/clusters/${c.id}`, {
      is_saved: !c.is_saved,
    });
    if (res.success) {
      toast.success(c.is_saved ? 'Cluster unsaved.' : 'Cluster saved.');
      loadClusters();
    }
  };

  const handleRemoveKeyword = async (clusterId: number, kwId: number) => {
    const res = await api.delete(`/websites/${website.id}/keyword-intelligence/clusters/${clusterId}/keywords/${kwId}`);
    if (res.success) {
      toast.info('Keyword removed from cluster.');
      loadClusters();
    }
  };

  const handleSplitConfirm = async () => {
    if (!splitTarget || splitKwIds.length === 0) return;
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/clusters/${splitTarget.id}/split`, {
      keyword_ids: splitKwIds,
      new_cluster_name: splitNewName || undefined,
    });
    if (res.success) {
      toast.success('Cluster split into two focused topic targets.');
      setSplitTarget(null);
      setSplitKwIds([]);
      setSplitNewName('');
      loadClusters();
      onRefreshOverview();
    } else {
      toast.error(res.error?.message || 'Split failed.');
    }
  };

  const handleSendToStudio = async (c: KiCluster) => {
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/clusters/${c.id}/send-content-studio`);
    if (res.success) {
      toast.success(`Sent cluster "${c.name}" to Content Studio as a draft brief.`);
      loadClusters();
      onNavigateToContent?.();
    } else {
      toast.error(res.error?.message || 'Could not send cluster to Content Studio.');
    }
  };

  const handleDeleteCluster = async (c: KiCluster) => {
    if (!confirm(`Delete cluster "${c.name}"?`)) return;
    const res = await api.delete(`/websites/${website.id}/keyword-intelligence/clusters/${c.id}`);
    if (res.success) {
      toast.info('Cluster deleted.');
      loadClusters();
      onRefreshOverview();
    }
  };

  const handleExport = async () => {
    const res = await api.downloadFile(
      `/websites/${website.id}/keyword-intelligence/clusters/export`,
      `keyword-clusters-${website.domain}.csv`
    );
    if (res.ok) toast.success('Clusters CSV exported.');
    else toast.error(res.error || 'Export failed.');
  };

  return (
    <div className="space-y-5">
      {/* Control Header */}
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => setMethod('semantic')}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold border ${
              method === 'semantic'
                ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'
                : 'bg-zinc-950 text-zinc-400 border-zinc-800'
            }`}
          >
            Semantic Clustering
          </button>
          <button
            onClick={() => setMethod('serp')}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold border ${
              method === 'serp'
                ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'
                : 'bg-zinc-950 text-zinc-400 border-zinc-800'
            }`}
          >
            SERP-Based Clustering
          </button>
          <button
            onClick={handleGenerate}
            disabled={generating}
            className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
          >
            <Layers className="w-3.5 h-3.5" />
            {generating ? 'Clustering...' : 'Generate Clusters'}
          </button>
        </div>

        <div className="flex items-center gap-2">
          {selectedClusterIds.length >= 2 && (
            <button
              onClick={handleMerge}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold"
            >
              <GitMerge className="w-3.5 h-3.5" />
              Merge Selected ({selectedClusterIds.length})
            </button>
          )}
          <button
            onClick={handleExport}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200 hover:bg-zinc-800"
          >
            <Download className="w-3.5 h-3.5 text-emerald-400" /> Export CSV
          </button>
        </div>
      </div>

      {notice && (
        <div className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-300">
          {notice}
        </div>
      )}

      {loading ? (
        <div className="p-10 text-center text-xs text-zinc-500">Loading keyword clusters...</div>
      ) : clusters.length === 0 ? (
        <div className="p-10 bg-zinc-900/50 border border-zinc-800 rounded-2xl text-center text-xs text-zinc-400 space-y-2">
          <p>No keyword clusters yet. Click &ldquo;Generate Clusters&rdquo; to automatically group your researched keywords without mixing conflicting search intents.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {clusters.map((c) => {
            const intentMeta = INTENT_STYLES[c.search_intent || 'informational'];
            const checked = selectedClusterIds.includes(c.id);
            return (
              <div key={c.id} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3.5">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-start gap-2.5 min-w-0">
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() =>
                        setSelectedClusterIds((prev) =>
                          prev.includes(c.id) ? prev.filter((id) => id !== c.id) : [...prev, c.id]
                        )
                      }
                      className="mt-1"
                    />
                    <div className="min-w-0">
                      {renamingId === c.id ? (
                        <div className="flex items-center gap-1.5">
                          <input
                            value={renameText}
                            onChange={(e) => setRenameText(e.target.value)}
                            className="px-2 py-1 rounded bg-zinc-950 border border-zinc-700 text-xs text-zinc-100"
                          />
                          <button onClick={() => handleRename(c.id)} className="px-2 py-1 rounded bg-emerald-600 text-white text-[11px]">Save</button>
                          <button onClick={() => setRenamingId(null)} className="px-2 py-1 rounded bg-zinc-800 text-zinc-300 text-[11px]">Cancel</button>
                        </div>
                      ) : (
                        <h4 className="text-sm font-bold text-zinc-100 truncate">{c.name}</h4>
                      )}
                      <div className="flex flex-wrap items-center gap-1.5 mt-1">
                        <span className={`px-2 py-0.5 rounded-full text-[10px] border ${intentMeta?.badge}`}>
                          {intentMeta?.label}
                        </span>
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-400 uppercase">
                          {c.clustering_method}
                        </span>
                        <span className="text-[11px] text-emerald-400 font-semibold">
                          Score {c.opportunity_score}/100
                        </span>
                      </div>
                    </div>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    <button onClick={() => { setRenamingId(c.id); setRenameText(c.name); }} title="Rename" className="p-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-zinc-100">
                      <Edit3 className="w-3.5 h-3.5" />
                    </button>
                    <button onClick={() => handleToggleSave(c)} title="Save cluster" className={`p-1.5 rounded-lg border ${c.is_saved ? 'bg-amber-500/15 text-amber-400 border-amber-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'}`}>
                      {c.is_saved ? <BookmarkCheck className="w-3.5 h-3.5" /> : <Bookmark className="w-3.5 h-3.5" />}
                    </button>
                    {(c.memberships?.length || 0) >= 2 && (
                      <button
                        onClick={() => { setSplitTarget(c); setSplitKwIds([]); setSplitNewName(''); }}
                        title="Split cluster"
                        className="p-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-sky-400"
                      >
                        <Scissors className="w-3.5 h-3.5" />
                      </button>
                    )}
                    <button onClick={() => handleDeleteCluster(c)} title="Delete cluster" className="p-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-rose-400">
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>

                {/* Cluster Metrics */}
                <div className="grid grid-cols-3 gap-2 text-center bg-zinc-950/60 border border-zinc-800/70 rounded-xl p-2.5 text-xs">
                  <div>
                    <div className="text-[10px] text-zinc-500">Keywords</div>
                    <div className="font-bold text-zinc-100">{c.keyword_count}</div>
                  </div>
                  <div>
                    <div className="text-[10px] text-zinc-500">Combined Vol.</div>
                    <div className="font-bold text-zinc-100">{formatNullableNumber(c.combined_search_volume)}</div>
                  </div>
                  <div>
                    <div className="text-[10px] text-zinc-500">Avg Difficulty</div>
                    <div className="font-bold text-zinc-100">{c.avg_difficulty !== null ? c.avg_difficulty : 'Unavailable'}</div>
                  </div>
                </div>

                {/* Pillar Page & Keywords */}
                {c.suggested_pillar_page && (
                  <div className="text-xs bg-emerald-500/5 border border-emerald-500/20 rounded-xl px-3 py-2 text-zinc-300">
                    <span className="text-[10px] font-bold text-emerald-400 uppercase block">Suggested Pillar Page</span>
                    {c.suggested_pillar_page}
                  </div>
                )}

                <div className="flex flex-wrap gap-1.5">
                  {(c.memberships || []).map((m) => (
                    <span
                      key={m.id}
                      className={`inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] border ${
                        m.is_primary
                          ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40 font-semibold'
                          : 'bg-zinc-950 text-zinc-300 border-zinc-800'
                      }`}
                    >
                      {m.is_primary && <span className="text-[9px] uppercase text-emerald-400">Primary</span>}
                      {m.keyword?.keyword}
                      {!m.is_primary && m.keyword && (
                        <button
                          onClick={() => handleRemoveKeyword(c.id, m.keyword!.id)}
                          className="text-zinc-500 hover:text-rose-400 ml-0.5"
                          title="Remove from cluster"
                        >
                          ×
                        </button>
                      )}
                    </span>
                  ))}
                </div>

                <div className="pt-2 border-t border-zinc-800 flex items-center justify-between">
                  <span className="text-[11px] text-zinc-500">
                    Primary: <strong className="text-zinc-300">{c.primary_keyword}</strong>
                  </span>
                  <button
                    onClick={() => handleSendToStudio(c)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-[11px] font-semibold"
                  >
                    <FileText className="w-3.5 h-3.5" />
                    {c.sent_to_content_studio_brief_id ? 'Sent to Studio ✓' : 'Send to Content Studio'}
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Split Modal */}
      {splitTarget && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-lg w-full p-5 space-y-4">
            <h4 className="text-sm font-bold text-zinc-100">Split Cluster: {splitTarget.name}</h4>
            <p className="text-xs text-zinc-400">Select the keywords to move into a new separate cluster:</p>
            <input
              type="text"
              value={splitNewName}
              onChange={(e) => setSplitNewName(e.target.value)}
              placeholder="New cluster name (optional)"
              className="w-full px-3 py-2 rounded-xl bg-zinc-900 border border-zinc-700 text-xs text-zinc-100"
            />
            <div className="space-y-1.5 max-h-56 overflow-y-auto">
              {(splitTarget.memberships || []).map((m) => (
                <label key={m.id} className="flex items-center gap-2 p-2 rounded-lg bg-zinc-900 text-xs text-zinc-200 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={splitKwIds.includes(m.keyword_id)}
                    onChange={() =>
                      setSplitKwIds((prev) =>
                        prev.includes(m.keyword_id) ? prev.filter((id) => id !== m.keyword_id) : [...prev, m.keyword_id]
                      )
                    }
                  />
                  <span>{m.keyword?.keyword}</span>
                </label>
              ))}
            </div>
            <div className="flex justify-end gap-2">
              <button onClick={() => setSplitTarget(null)} className="px-3 py-1.5 rounded-xl bg-zinc-800 text-xs text-zinc-300">Cancel</button>
              <button onClick={handleSplitConfirm} disabled={!splitKwIds.length} className="px-4 py-1.5 rounded-xl bg-emerald-600 text-white text-xs font-bold disabled:opacity-40">
                Split Cluster
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* ============================================================================
 * SECTION 5: SEARCH INTENT ANALYSIS
 * ============================================================================ */
export function SearchIntentTab({ website }: { website: Website }) {
  const toast = useToast();
  const [breakdown, setBreakdown] = useState<Record<string, {
    count: number;
    share_pct: number;
    total_volume: number | null;
    avg_opportunity: number | null;
    provider_verified: number;
    ai_inferred: number;
    rule_based: number;
  }>>({});
  const [selectedIntent, setSelectedIntent] = useState<string>('informational');
  const [keywords, setKeywords] = useState<KiKeyword[]>([]);
  const [classifying, setClassifying] = useState(false);

  const loadData = useCallback(async () => {
    const [sumRes, kwRes] = await Promise.all([
      api.get<{ breakdown: typeof breakdown }>(`/websites/${website.id}/keyword-intelligence/intent-summary`),
      api.get<{ items: KiKeyword[] }>(`/websites/${website.id}/keyword-intelligence/keywords?intent=${selectedIntent}&per_page=50`),
    ]);
    if (sumRes.success && sumRes.data) setBreakdown(sumRes.data.breakdown);
    if (kwRes.success && kwRes.data) setKeywords(kwRes.data.items);
  }, [website.id, selectedIntent]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleRunAiClassify = async () => {
    setClassifying(true);
    try {
      const res = await api.post<{ updated: number }>(`/websites/${website.id}/keyword-intelligence/intent/classify`, {});
      if (res.success && res.data) {
        toast.success(`AI-inferred intent updated for ${res.data.updated} keyword(s) with confidence scores.`);
        loadData();
      }
    } finally {
      setClassifying(false);
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3 bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4">
        <div>
          <h3 className="text-sm font-bold text-zinc-100">4-Way Search Intent Intelligence</h3>
          <p className="text-xs text-zinc-400">Provider-verified intent is always preserved; AI classifications are explicitly tagged as AI-inferred with confidence.</p>
        </div>
        <button
          onClick={handleRunAiClassify}
          disabled={classifying}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
        >
          <Sparkles className="w-3.5 h-3.5" />
          {classifying ? 'Classifying...' : 'Run AI Intent Classification'}
        </button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        {(['informational', 'navigational', 'commercial', 'transactional'] as const).map((key) => {
          const stat = breakdown[key];
          const meta = INTENT_STYLES[key];
          const active = selectedIntent === key;
          return (
            <button
              key={key}
              onClick={() => setSelectedIntent(key)}
              className={`text-left p-4 rounded-2xl border transition ${
                active
                  ? 'bg-zinc-900 border-emerald-500/50 ring-1 ring-emerald-500/30'
                  : 'bg-zinc-900/60 border-zinc-800 hover:border-zinc-700'
              }`}
            >
              <span className={`px-2 py-0.5 rounded-full text-[10px] border ${meta.badge}`}>{meta.label}</span>
              <div className="text-2xl font-bold text-zinc-100 mt-2">{stat?.count ?? 0}</div>
              <div className="text-[11px] text-zinc-400 mt-1">
                {stat?.share_pct ?? 0}% share • Avg Opp: {stat?.avg_opportunity ?? '—'}
              </div>
              <div className="text-[10px] text-zinc-500 mt-1">
                Provider: {stat?.provider_verified ?? 0} • AI: {stat?.ai_inferred ?? 0} • Rule: {stat?.rule_based ?? 0}
              </div>
            </button>
          );
        })}
      </div>

      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl overflow-hidden">
        <div className="px-4 py-3 border-b border-zinc-800 text-xs font-bold text-zinc-200 capitalize">
          {selectedIntent} Keywords ({keywords.length})
        </div>
        <div className="divide-y divide-zinc-800/60">
          {keywords.length === 0 ? (
            <div className="p-8 text-center text-xs text-zinc-500">No keywords classified under {selectedIntent} yet.</div>
          ) : (
            keywords.map((kw) => (
              <div key={kw.id} className="px-4 py-2.5 flex items-center justify-between text-xs hover:bg-zinc-800/30">
                <div>
                  <span className="font-semibold text-zinc-100">{kw.keyword}</span>
                  <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-400">
                    {kw.intent_source === 'ai_inferred'
                      ? `AI-inferred (${Math.round((kw.intent_confidence || 0.75) * 100)}%)`
                      : kw.intent_source || 'rule_based'}
                  </span>
                </div>
                <div className="font-mono text-zinc-400">
                  Vol: {formatNullableNumber(kw.search_volume)} • Opp: <strong className="text-emerald-400">{kw.opportunity_score}</strong>
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}

/* ============================================================================
 * SECTION 6: AI KEYWORD OPPORTUNITIES (Transparent Configurable Scoring Engine)
 * ============================================================================ */
export function KeywordOpportunitiesTab({
  website,
  onNavigateToActionPlan,
  onRefreshOverview,
}: {
  website: Website;
  onNavigateToActionPlan?: () => void;
  onRefreshOverview: () => void;
}) {
  const toast = useToast();
  const [items, setItems] = useState<KiKeyword[]>([]);
  const [category, setCategory] = useState('');
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [weights, setWeights] = useState<Record<string, number>>({
    demand: 25, difficulty: 20, relevance: 20, intent: 15, position_potential: 10, competitor_gap: 5, trend: 5,
  });
  const [showWeights, setShowWeights] = useState(false);
  const [savingWeights, setSavingWeights] = useState(false);

  const loadOpportunities = useCallback(async () => {
    const q = category ? `?category=${category}` : '';
    const res = await api.get<{
      items: KiKeyword[];
      category_counts: Record<string, number>;
      methodology: { weights: Record<string, number> };
    }>(`/websites/${website.id}/keyword-intelligence/opportunities${q}`);
    if (res.success && res.data) {
      setItems(res.data.items);
      setCounts(res.data.category_counts);
      if (res.data.methodology?.weights) setWeights(res.data.methodology.weights);
    }
  }, [website.id, category]);

  useEffect(() => {
    loadOpportunities();
  }, [loadOpportunities]);

  const handleSaveWeights = async () => {
    setSavingWeights(true);
    try {
      const res = await api.put<{ rescored_count: number }>(
        `/websites/${website.id}/keyword-intelligence/scoring-weights`,
        { weights }
      );
      if (res.success && res.data) {
        toast.success(`Updated scoring weights and rescored ${res.data.rescored_count} keywords.`);
        setShowWeights(false);
        loadOpportunities();
        onRefreshOverview();
      }
    } finally {
      setSavingWeights(false);
    }
  };

  const handleSaveAction = async (kw: KiKeyword) => {
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}/send-action-plan`);
    if (res.success) {
      toast.success(`Saved "${kw.keyword}" to SEO Action Plan.`);
      loadOpportunities();
      onNavigateToActionPlan?.();
    }
  };

  return (
    <div className="space-y-5">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 className="text-sm font-bold text-zinc-100">AI Keyword Opportunity Engine (0–100 Prioritization Index)</h3>
            <p className="text-[11px] text-zinc-400">
              Transparent weighted scoring across demand, difficulty, business relevance, intent, rank position, competitor gap &amp; trends. Not a guaranteed ranking probability.
            </p>
          </div>
          <button
            onClick={() => setShowWeights(!showWeights)}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200 hover:bg-zinc-800"
          >
            <Sliders className="w-3.5 h-3.5 text-emerald-400" /> Configure Scoring Weights
          </button>
        </div>

        {showWeights && (
          <div className="pt-3 border-t border-zinc-800 space-y-3">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {Object.entries(weights).map(([k, v]) => (
                <div key={k} className="bg-zinc-950 p-2.5 rounded-xl border border-zinc-800">
                  <div className="flex justify-between text-[11px] text-zinc-300 mb-1">
                    <span className="capitalize">{k.replace('_', ' ')}</span>
                    <span className="font-mono text-emerald-400">{v}</span>
                  </div>
                  <input
                    type="range"
                    min={0}
                    max={50}
                    value={v}
                    onChange={(e) => setWeights((prev) => ({ ...prev, [k]: Number(e.target.value) }))}
                    className="w-full accent-emerald-500"
                  />
                </div>
              ))}
            </div>
            <div className="flex justify-end">
              <button
                onClick={handleSaveWeights}
                disabled={savingWeights}
                className="px-4 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
              >
                {savingWeights ? 'Rescoring...' : 'Save Weights & Rescore All Keywords'}
              </button>
            </div>
          </div>
        )}

        {/* 6 Opportunity Categories */}
        <div className="flex flex-wrap gap-2 pt-1">
          <button
            onClick={() => setCategory('')}
            className={`px-3 py-1.5 rounded-xl text-xs font-semibold border ${category === '' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'}`}
          >
            All Categories
          </button>
          {Object.entries(OPP_CATEGORY_STYLES).map(([key, meta]) => (
            <button
              key={key}
              onClick={() => setCategory(key)}
              className={`px-3 py-1.5 rounded-xl text-xs font-semibold border ${category === key ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'}`}
            >
              {meta.label} ({counts[key] ?? 0})
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-2.5">
        {items.length === 0 ? (
          <div className="p-8 bg-zinc-900/50 border border-zinc-800 rounded-2xl text-center text-xs text-zinc-500">
            No keyword opportunities in this category yet.
          </div>
        ) : (
          items.map((kw) => {
            const catMeta = OPP_CATEGORY_STYLES[kw.opportunity_category || 'medium_opportunity'];
            return (
              <div key={kw.id} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div className="space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-bold text-zinc-100">{kw.keyword}</span>
                    <span className={`px-2 py-0.5 rounded-full text-[10px] border ${catMeta?.badge}`}>{catMeta?.label}</span>
                    <span className="px-1.5 py-0.5 rounded bg-zinc-800 text-[10px] font-bold text-zinc-200">{kw.recommended_priority}</span>
                    <span className="text-[10px] text-zinc-500 uppercase">Confidence: {kw.data_confidence}</span>
                  </div>
                  <p className="text-xs text-zinc-400">{kw.opportunity_reason}</p>
                  <div className="flex flex-wrap gap-3 text-[11px] text-zinc-400 font-mono">
                    <span>Relevance: {kw.relevance_score ?? '—'}%</span>
                    <span>Potential: {kw.ranking_potential}</span>
                    <span>Vol: {formatNullableNumber(kw.search_volume)}</span>
                    <span>KD: {kw.keyword_difficulty !== null ? kw.keyword_difficulty : 'Data Unavailable'}</span>
                  </div>
                </div>
                <div className="flex items-center gap-3 shrink-0">
                  <div className="text-center px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30">
                    <div className="text-[10px] text-emerald-400 font-semibold">SCORE</div>
                    <div className="text-lg font-bold text-emerald-400">{kw.opportunity_score}</div>
                  </div>
                  <button
                    onClick={() => handleSaveAction(kw)}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold"
                  >
                    <CheckSquare className="w-3.5 h-3.5" />
                    {kw.saved_recommendation_id ? 'In Action Plan ✓' : 'Save to Action Plan'}
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}

/* ============================================================================
 * SECTION 7: QUESTION KEYWORDS + FAQ IDEA GENERATOR
 * ============================================================================ */
export function QuestionKeywordsTab({ website }: { website: Website }) {
  const toast = useToast();
  const [activeCat, setActiveCat] = useState('');
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [items, setItems] = useState<KiKeyword[]>([]);
  const [selectedQuestions, setSelectedQuestions] = useState<string[]>([]);
  const [faqIdeas, setFaqIdeas] = useState<Array<{ question: string; suggested_answer_angle: string; recommended_placement: string; schema_type?: string }>>([]);
  const [generatingFaq, setGeneratingFaq] = useState(false);

  const loadQuestions = useCallback(async () => {
    const q = activeCat ? `?category=${activeCat}` : '';
    const res = await api.get<{ category_counts: Record<string, number>; items: KiKeyword[] }>(
      `/websites/${website.id}/keyword-intelligence/questions${q}`
    );
    if (res.success && res.data) {
      setCounts(res.data.category_counts);
      setItems(res.data.items);
    }
  }, [website.id, activeCat]);

  useEffect(() => {
    loadQuestions();
  }, [loadQuestions]);

  const handleGenerateFaqs = async () => {
    const qs = selectedQuestions.length > 0 ? selectedQuestions : items.slice(0, 6).map((i) => i.keyword);
    if (qs.length === 0) return;
    setGeneratingFaq(true);
    try {
      const res = await api.post<{ faq_ideas: typeof faqIdeas }>(
        `/websites/${website.id}/keyword-intelligence/questions/faq-ideas`,
        { questions: qs }
      );
      if (res.success && res.data) {
        setFaqIdeas(res.data.faq_ideas);
        toast.success(`Generated ${res.data.faq_ideas.length} FAQ content ideas.`);
      }
    } finally {
      setGeneratingFaq(false);
    }
  };

  return (
    <div className="space-y-5">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <HelpCircle className="w-4 h-4 text-sky-400" /> Question-Based Search Queries (10 Categories)
            </h3>
            <p className="text-xs text-zinc-400">Real user questions discovered via Autocomplete, Serper People Also Ask &amp; GSC.</p>
          </div>
          <button
            onClick={handleGenerateFaqs}
            disabled={generatingFaq || items.length === 0}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold disabled:opacity-40"
          >
            <Sparkles className="w-3.5 h-3.5" />
            {generatingFaq ? 'Generating FAQs...' : `Generate FAQ Content Ideas${selectedQuestions.length ? ` (${selectedQuestions.length})` : ''}`}
          </button>
        </div>

        <div className="flex flex-wrap gap-1.5">
          <button
            onClick={() => setActiveCat('')}
            className={`px-2.5 py-1 rounded-lg text-xs font-semibold border ${activeCat === '' ? 'bg-sky-500/15 text-sky-400 border-sky-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'}`}
          >
            All Questions
          </button>
          {Object.entries(counts).map(([cat, cnt]) => (
            <button
              key={cat}
              onClick={() => setActiveCat(cat)}
              className={`px-2.5 py-1 rounded-lg text-xs font-semibold border uppercase ${activeCat === cat ? 'bg-sky-500/15 text-sky-400 border-sky-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'}`}
            >
              {cat} ({cnt})
            </button>
          ))}
        </div>
      </div>

      {faqIdeas.length > 0 && (
        <div className="bg-emerald-950/30 border border-emerald-500/30 rounded-2xl p-5 space-y-3">
          <div className="text-xs font-bold text-emerald-400 uppercase">AI-Inferred FAQ Content Blueprints</div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {faqIdeas.map((f, idx) => (
              <div key={idx} className="p-3.5 rounded-xl bg-zinc-950/80 border border-zinc-800 text-xs space-y-1">
                <div className="font-bold text-zinc-100">{f.question}</div>
                <p className="text-zinc-400">{f.suggested_answer_angle}</p>
                <div className="text-[10px] text-emerald-400">
                  Placement: {f.recommended_placement} • Schema: {f.schema_type || 'FAQPage'}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl divide-y divide-zinc-800/60">
        {items.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-500">No question keywords discovered yet. Run Keyword Discovery with &ldquo;Question-based Keywords&rdquo; enabled.</div>
        ) : (
          items.map((kw) => (
            <label key={kw.id} className="px-4 py-3 flex items-center justify-between gap-3 text-xs hover:bg-zinc-800/30 cursor-pointer">
              <div className="flex items-center gap-2.5">
                <input
                  type="checkbox"
                  checked={selectedQuestions.includes(kw.keyword)}
                  onChange={() =>
                    setSelectedQuestions((prev) =>
                      prev.includes(kw.keyword) ? prev.filter((q) => q !== kw.keyword) : [...prev, kw.keyword]
                    )
                  }
                />
                <span className="px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-400 text-[10px] uppercase font-bold">{kw.question_category}</span>
                <span className="font-medium text-zinc-100">{kw.keyword}</span>
              </div>
              <div className="text-[11px] font-mono text-zinc-400">
                Vol: {formatNullableNumber(kw.search_volume)} • Opp: <strong className="text-emerald-400">{kw.opportunity_score}</strong>
              </div>
            </label>
          ))
        )}
      </div>
    </div>
  );
}

/* ============================================================================
 * SECTION 8: LONG-TAIL KEYWORDS
 * ============================================================================ */
export function LongTailKeywordsTab({ website, onNavigateToContent }: { website: Website; onNavigateToContent?: () => void }) {
  const toast = useToast();
  const [intent, setIntent] = useState('');
  const [items, setItems] = useState<KiKeyword[]>([]);
  const [dist, setDist] = useState<Record<string, number>>({});

  const loadLongTail = useCallback(async () => {
    const q = intent ? `?intent=${intent}` : '';
    const res = await api.get<{ items: KiKeyword[]; intent_distribution: Record<string, number> }>(
      `/websites/${website.id}/keyword-intelligence/long-tail${q}`
    );
    if (res.success && res.data) {
      setItems(res.data.items);
      setDist(res.data.intent_distribution || {});
    }
  }, [website.id, intent]);

  useEffect(() => {
    loadLongTail();
  }, [loadLongTail]);

  const handleDraft = async (kw: KiKeyword) => {
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}/send-content-studio`);
    if (res.success) {
      toast.success(`Draft Content Brief created for "${kw.keyword}".`);
      onNavigateToContent?.();
    }
  };

  return (
    <div className="space-y-4">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-bold text-zinc-100">Long-Tail Keyword Opportunities (4+ Words &amp; Specific Queries)</h3>
          <p className="text-xs text-zinc-400">High-specificity search phrases grouped by intent for faster organic traction.</p>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {['', 'informational', 'commercial', 'transactional', 'navigational'].map((i) => (
            <button
              key={i || 'all'}
              onClick={() => setIntent(i)}
              className={`px-2.5 py-1 rounded-lg text-xs font-semibold border capitalize ${
                intent === i ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40' : 'bg-zinc-950 text-zinc-400 border-zinc-800'
              }`}
            >
              {i || 'All'} {i ? `(${dist[i] ?? 0})` : ''}
            </button>
          ))}
        </div>
      </div>

      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl divide-y divide-zinc-800/60">
        {items.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-500">No long-tail keywords yet. Run Keyword Discovery to find specific multi-word opportunities.</div>
        ) : (
          items.map((kw) => {
            const intentMeta = INTENT_STYLES[kw.search_intent || 'informational'];
            return (
              <div key={kw.id} className="px-4 py-3 flex flex-wrap items-center justify-between gap-2 text-xs hover:bg-zinc-800/30">
                <div>
                  <div className="font-semibold text-zinc-100">{kw.keyword}</div>
                  <div className="flex items-center gap-2 mt-1 text-[10px] text-zinc-400">
                    <span className={`px-2 py-0.5 rounded-full border ${intentMeta?.badge}`}>{intentMeta?.label}</span>
                    <span>{kw.word_count} words</span>
                    <span>Vol: {formatNullableNumber(kw.search_volume)}</span>
                    <span>KD: {kw.keyword_difficulty !== null ? kw.keyword_difficulty : 'Data Unavailable'}</span>
                    <span>Rank: {kw.current_position !== null ? `#${kw.current_position}` : 'Unranked'}</span>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <span className="font-bold text-emerald-400">Opp {kw.opportunity_score}</span>
                  <button onClick={() => handleDraft(kw)} className="px-2.5 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-[11px] font-semibold">
                    Draft Brief
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}

/* ============================================================================
 * CANNIBALIZATION DETECTOR (GSC + Rank Checker Evidence-Based)
 * ============================================================================ */
export function CannibalizationTab({ website }: { website: Website }) {
  const toast = useToast();
  const [findings, setFindings] = useState<KiCannibalizationFinding[]>([]);
  const [scanning, setScanning] = useState(false);

  const loadFindings = useCallback(async () => {
    const res = await api.get<KiCannibalizationFinding[]>(`/websites/${website.id}/keyword-intelligence/cannibalization`);
    if (res.success && res.data) setFindings(res.data);
  }, [website.id]);

  useEffect(() => {
    loadFindings();
  }, [loadFindings]);

  const handleAnalyze = async () => {
    setScanning(true);
    try {
      const res = await api.post<{ findings_count: number; evaluated_queries: number }>(
        `/websites/${website.id}/keyword-intelligence/cannibalization/analyze`,
        { days: 90 }
      );
      if (res.success && res.data) {
        toast.success(`Analyzed ${res.data.evaluated_queries} GSC queries — found ${res.data.findings_count} cannibalization case(s).`);
        loadFindings();
      }
    } finally {
      setScanning(false);
    }
  };

  const handleStatus = async (id: number, status: string) => {
    const res = await api.patch(`/websites/${website.id}/keyword-intelligence/cannibalization/${id}`, { status });
    if (res.success) {
      toast.info(`Marked finding as ${status}.`);
      loadFindings();
    }
  };

  return (
    <div className="space-y-4">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-amber-400" /> Keyword Cannibalization Detection
          </h3>
          <p className="text-xs text-zinc-400">
            Detects competing URLs splitting GSC impressions/clicks with strict evidence thresholds (avoids false positives on benign multi-URL visibility).
          </p>
        </div>
        <button
          onClick={handleAnalyze}
          disabled={scanning}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${scanning ? 'animate-spin' : ''}`} />
          {scanning ? 'Scanning GSC Data...' : 'Run Cannibalization Scan'}
        </button>
      </div>

      {findings.length === 0 ? (
        <div className="p-8 bg-zinc-900/50 border border-zinc-800 rounded-2xl text-center text-xs text-zinc-500">
          No cannibalization conflicts detected yet. Sync Google Search Console data and run a scan.
        </div>
      ) : (
        <div className="space-y-3">
          {findings.map((f) => (
            <div key={f.id} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-bold text-zinc-100">{f.keyword}</span>
                  <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                    f.confidence === 'high' ? 'bg-rose-500/15 text-rose-400' : f.confidence === 'medium' ? 'bg-amber-500/15 text-amber-400' : 'bg-zinc-800 text-zinc-400'
                  }`}>
                    {f.confidence} confidence
                  </span>
                </div>
                <select
                  value={f.status}
                  onChange={(e) => handleStatus(f.id, e.target.value)}
                  className="px-2.5 py-1 rounded-lg bg-zinc-950 border border-zinc-700 text-xs text-zinc-200"
                >
                  <option value="open">Open</option>
                  <option value="acknowledged">Acknowledged</option>
                  <option value="resolved">Resolved</option>
                  <option value="dismissed">Dismissed</option>
                </select>
              </div>
              <p className="text-xs text-zinc-400">{f.evidence_summary}</p>
              <div className="space-y-1.5">
                {f.competing_urls.map((u, idx) => (
                  <div key={idx} className="px-3 py-2 rounded-xl bg-zinc-950/70 border border-zinc-800 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span className="text-sky-400 truncate max-w-md">{u.url}</span>
                    <span className="font-mono text-zinc-400">
                      {u.impressions} imp ({u.impression_share_pct ?? '—'}%) • {u.clicks} clicks • Pos #{u.avg_position}
                    </span>
                  </div>
                ))}
              </div>
              <div className="p-3 rounded-xl bg-emerald-500/5 border border-emerald-500/20 text-xs text-zinc-300">
                <strong className="text-emerald-400">Suggested Resolution:</strong> {f.suggested_resolution}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
