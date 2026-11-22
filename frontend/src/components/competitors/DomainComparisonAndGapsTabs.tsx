'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  BarChart3,
  Search,
  Download,
  Sparkles,
  CheckCircle2,
  AlertTriangle,
  ExternalLink,
  RefreshCw,
  FileText,
  Info,
  ArrowUpDown,
  Send,
  BookmarkPlus,
} from 'lucide-react';
import {
  Website,
  CompetitorDomainItem,
  CompetitorKeywordItem,
  CompetitorContentGapItem,
} from '@/types';
import { api, getApiBaseUrl } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

/* ============================================================================
   SUBMODULE 3: DOMAIN COMPARISON (Up to 5 Competitors)
   ============================================================================ */
export function DomainComparisonTab({
  website,
  competitors,
  onLaunchAnalysis,
  isAnalyzing,
}: {
  website: Website;
  competitors: CompetitorDomainItem[];
  onLaunchAnalysis: () => void;
  isAnalyzing: boolean;
}) {
  const toast = useToast();
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [comparison, setComparison] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [showDefinitions, setShowDefinitions] = useState(false);

  useEffect(() => {
    setSelectedIds(competitors.slice(0, 5).map((c) => c.id));
  }, [competitors]);

  const loadComparison = useCallback(async () => {
    setIsLoading(true);
    try {
      const qs = selectedIds.length > 0 ? `?competitor_ids=${selectedIds.join(',')}` : '';
      const res = await api.get(`/websites/${website.id}/competitors/comparison${qs}`);
      if (res.success && res.data) {
        setComparison(res.data);
      }
    } catch {
      toast.error('Failed to load domain comparison.');
    } finally {
      setIsLoading(false);
    }
  }, [website.id, selectedIds, toast]);

  useEffect(() => {
    loadComparison();
  }, [loadComparison]);

  const toggleId = (id: number) => {
    setSelectedIds((prev) => {
      if (prev.includes(id)) return prev.filter((x) => x !== id);
      if (prev.length >= 5) {
        toast.info('You can compare up to 5 competitors at a time.');
        return prev;
      }
      return [...prev, id];
    });
  };

  const domains: any[] = comparison?.domains ?? [];
  const maxTraffic = Math.max(1, ...domains.map((d) => d.organic_traffic_estimate || 0));
  const maxKeywords = Math.max(1, ...domains.map((d) => d.organic_keywords_count || 0));
  const maxRefDomains = Math.max(1, ...domains.map((d) => d.referring_domains_count || 0));

  const fmt = (val: number | null | undefined, suffix = '') =>
    val !== null && val !== undefined ? `${val.toLocaleString()}${suffix}` : 'Data unavailable';

  return (
    <div className="space-y-5">
      {/* Competitor Selector Bar (up to 5) */}
      <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className="font-semibold text-zinc-300">Compare ({selectedIds.length}/5):</span>
          {competitors.length === 0 ? (
            <span className="text-zinc-500">Add competitors in &ldquo;My Competitors&rdquo; tab first.</span>
          ) : (
            competitors.map((c) => {
              const active = selectedIds.includes(c.id);
              return (
                <button
                  key={c.id}
                  type="button"
                  onClick={() => toggleId(c.id)}
                  className={`px-2.5 py-1 rounded-lg border text-xs font-medium transition ${
                    active
                      ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                      : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  {c.domain}
                </button>
              );
            })
          )}
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setShowDefinitions(!showDefinitions)}
            className="px-3 py-1.5 text-xs bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 rounded-xl inline-flex items-center gap-1.5"
          >
            <Info className="w-3.5 h-3.5 text-sky-400" />
            <span>Metric Definitions</span>
          </button>
          <button
            type="button"
            onClick={onLaunchAnalysis}
            disabled={isAnalyzing}
            className="px-3 py-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl inline-flex items-center gap-1.5"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} />
            <span>Refresh Snapshots</span>
          </button>
        </div>
      </div>

      {showDefinitions && comparison?.metric_definitions && (
        <div className="p-4 bg-zinc-900/60 border border-zinc-800 rounded-2xl grid grid-cols-1 md:grid-cols-2 gap-2.5 text-xs">
          {Object.entries(comparison.metric_definitions).map(([k, desc]) => (
            <div key={k}>
              <span className="font-mono text-emerald-400 font-semibold">{k}:</span>{' '}
              <span className="text-zinc-400">{String(desc)}</span>
            </div>
          ))}
        </div>
      )}

      {/* Visual Bar Charts */}
      {domains.length > 0 && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {[
            { label: 'Est. Organic Traffic (3rd-Party)', key: 'organic_traffic_estimate', max: maxTraffic, color: 'bg-emerald-500' },
            { label: 'Organic Keywords Count', key: 'organic_keywords_count', max: maxKeywords, color: 'bg-sky-500' },
            { label: 'Referring Domains', key: 'referring_domains_count', max: maxRefDomains, color: 'bg-purple-500' },
          ].map((chart) => (
            <div key={chart.key} className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
              <div className="text-xs font-bold text-zinc-200 flex items-center gap-1.5">
                <BarChart3 className="w-3.5 h-3.5 text-emerald-400" />
                {chart.label}
              </div>
              <div className="space-y-2.5">
                {domains.map((d) => {
                  const val = d[chart.key];
                  const pct = val != null ? Math.max(4, Math.round((val / chart.max) * 100)) : 0;
                  return (
                    <div key={d.domain} className="space-y-1 text-[11px]">
                      <div className="flex items-center justify-between">
                        <span className={`font-mono truncate ${d.is_target ? 'text-emerald-400 font-bold' : 'text-zinc-300'}`}>
                          {d.domain} {d.is_target ? '(You)' : ''}
                        </span>
                        <span className="font-mono text-zinc-400">
                          {val != null ? val.toLocaleString() : 'N/A'}
                        </span>
                      </div>
                      <div className="w-full h-2 bg-zinc-900 rounded-full overflow-hidden">
                        <div className={`h-full ${d.is_target ? 'bg-emerald-500' : chart.color}`} style={{ width: `${pct}%` }} />
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Side-by-Side Comparison Matrix Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="p-4 border-b border-zinc-800 flex items-center justify-between text-xs">
          <h3 className="text-sm font-bold text-zinc-100">Domain Comparison Matrix</h3>
          <span className="text-zinc-400">
            Last Updated: {comparison?.last_updated_at ? new Date(comparison.last_updated_at).toLocaleString() : 'Not scanned yet'}
          </span>
        </div>

        {isLoading ? (
          <div className="p-8 text-center text-xs text-zinc-400">Loading comparison matrix...</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-3">Domain</th>
                  <th className="px-4 py-3">Est. Traffic</th>
                  <th className="px-4 py-3">Organic Keywords</th>
                  <th className="px-4 py-3">Ref. Domains</th>
                  <th className="px-4 py-3">Backlinks</th>
                  <th className="px-4 py-3">Authority</th>
                  <th className="px-4 py-3">Indexed / Pages</th>
                  <th className="px-4 py-3">Homepage Words</th>
                  <th className="px-4 py-3">Data Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {domains.map((d) => (
                  <tr key={d.domain} className={d.is_target ? 'bg-emerald-950/15' : 'hover:bg-zinc-900/40'}>
                    <td className="px-4 py-3 font-semibold text-zinc-100">
                      <div>{d.domain}</div>
                      <span className="text-[10px] text-zinc-400">
                        {d.is_target ? 'Your Target Website' : (d.category || 'competitor').replace('_', ' ')}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.organic_traffic_estimate)}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.organic_keywords_count)}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.referring_domains_count)}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.backlinks_count)}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">
                      {d.authority_score != null ? (
                        <div>
                          <span className="font-bold">{d.authority_score}/100</span>
                          <span className="block text-[10px] text-zinc-400">{d.authority_metric_label}</span>
                        </div>
                      ) : (
                        'Data unavailable'
                      )}
                    </td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.indexed_pages_count)}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{fmt(d.crawled_signals?.word_count)}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-mono bg-zinc-900 border border-zinc-800 text-zinc-300">
                        {d.data_source}
                      </span>
                      <span className="block text-[9px] text-zinc-500 mt-0.5">{d.data_classification}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Strengths & Weaknesses Analysis */}
      {comparison?.insights && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="p-5 bg-zinc-950 border border-emerald-900/40 rounded-2xl space-y-2.5">
            <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-1.5">
              <CheckCircle2 className="w-4 h-4" /> Observed Strengths
            </h4>
            {(comparison.insights.strengths || []).length === 0 ? (
              <p className="text-xs text-zinc-400">Run analysis across tracked competitors to surface comparative strengths.</p>
            ) : (
              comparison.insights.strengths.map((s: any, i: number) => (
                <div key={i} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs">
                  <div className="font-semibold text-zinc-100">{s.metric}</div>
                  <p className="text-zinc-400 mt-0.5">{s.observation}</p>
                </div>
              ))
            )}
          </div>

          <div className="p-5 bg-zinc-950 border border-amber-900/40 rounded-2xl space-y-2.5">
            <h4 className="text-xs font-bold uppercase tracking-wider text-amber-400 flex items-center gap-1.5">
              <AlertTriangle className="w-4 h-4" /> Observed Weaknesses &amp; Gaps
            </h4>
            {(comparison.insights.weaknesses || []).length === 0 ? (
              <p className="text-xs text-zinc-400">No comparative weaknesses detected yet.</p>
            ) : (
              comparison.insights.weaknesses.map((w: any, i: number) => (
                <div key={i} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs">
                  <div className="font-semibold text-zinc-100">{w.metric}</div>
                  <p className="text-zinc-400 mt-0.5">{w.observation}</p>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}

/* ============================================================================
   SUBMODULE 4: KEYWORD GAP ANALYSIS
   ============================================================================ */
export function KeywordGapTab({
  website,
  competitors,
  country,
  language,
  onNavigateToActionPlan,
}: {
  website: Website;
  competitors: CompetitorDomainItem[];
  country: string;
  language: string;
  onNavigateToActionPlan?: () => void;
}) {
  const toast = useToast();
  const [gapType, setGapType] = useState<string>('all');
  const [intent, setIntent] = useState<string>('all');
  const [compFilter, setCompFilter] = useState<string>('all');
  const [minVolume, setMinVolume] = useState<string>('');
  const [maxDifficulty, setMaxDifficulty] = useState<string>('');
  const [search, setSearch] = useState<string>('');
  const [sortBy, setSortBy] = useState<string>('opportunity_score');

  const [keywords, setKeywords] = useState<CompetitorKeywordItem[]>([]);
  const [meta, setMeta] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [savingId, setSavingId] = useState<number | null>(null);

  const loadKeywords = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({
        gap_type: gapType,
        intent,
        competitor: compFilter,
        sort_by: sortBy,
        sort_dir: 'desc',
      });
      if (minVolume) params.set('min_volume', minVolume);
      if (maxDifficulty) params.set('max_difficulty', maxDifficulty);
      if (search.trim()) params.set('search', search.trim());

      const res = await api.get<CompetitorKeywordItem[]>(
        `/websites/${website.id}/competitors/keyword-gap?${params.toString()}`
      );
      if (res.success && res.data) {
        setKeywords(res.data);
        setMeta(res.meta);
      }
    } catch {
      toast.error('Failed to load Keyword Gap dataset.');
    } finally {
      setIsLoading(false);
    }
  }, [website.id, gapType, intent, compFilter, minVolume, maxDifficulty, search, sortBy, toast]);

  useEffect(() => {
    loadKeywords();
  }, [loadKeywords]);

  const handleSaveToActionPlan = async (kw: CompetitorKeywordItem) => {
    setSavingId(kw.id);
    try {
      const res = await api.post(`/websites/${website.id}/competitors/keyword-gap/${kw.id}/save-action`);
      if (res.success) {
        toast.success(`Saved "${kw.keyword}" to SEO Action Plan!`);
        await loadKeywords();
      } else {
        toast.error(res.error?.message || 'Could not save to SEO Action Plan.');
      }
    } finally {
      setSavingId(null);
    }
  };

  const handleExportCsv = async () => {
    const token = typeof window !== 'undefined' ? localStorage.getItem('web_seo_ai_token') : '';
    const orgId = typeof window !== 'undefined' ? localStorage.getItem('web_seo_ai_org_id') : '';
    const url = `${getApiBaseUrl()}/websites/${website.id}/competitors/keyword-gap?gap_type=${gapType}&export=csv`;
    try {
      const res = await fetch(url, {
        headers: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(orgId ? { 'X-Organization-Id': orgId } : {}),
        },
      });
      const text = await res.text();
      const blob = new Blob([text], { type: 'text/csv;charset=utf-8;' });
      const dl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = dl;
      a.download = `keyword-gap-${website.domain}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(dl);
      toast.success('Exported Keyword Gap CSV.');
    } catch {
      toast.error('CSV export failed.');
    }
  };

  const counts = meta?.counts ?? { all: 0, missing: 0, weak: 0, shared: 0, strong: 0, untapped: 0 };

  return (
    <div className="space-y-4">
      {/* Gap Category Pills */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-1.5 text-xs">
          {[
            { id: 'all', label: 'All Keywords' },
            { id: 'missing', label: 'Missing' },
            { id: 'weak', label: 'Weak' },
            { id: 'untapped', label: 'Untapped' },
            { id: 'shared', label: 'Shared' },
            { id: 'strong', label: 'Strong' },
          ].map((t) => (
            <button
              key={t.id}
              onClick={() => setGapType(t.id)}
              className={`px-3 py-1.5 rounded-xl font-medium transition flex items-center gap-1.5 border ${
                gapType === t.id
                  ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                  : 'bg-zinc-950 border-zinc-800 text-zinc-400 hover:text-zinc-200'
              }`}
            >
              <span>{t.label}</span>
              <span className="font-mono text-[10px] px-1.5 rounded bg-zinc-900 text-zinc-300">
                {counts[t.id] ?? 0}
              </span>
            </button>
          ))}
        </div>

        <button
          onClick={handleExportCsv}
          className="px-3 py-1.5 text-xs font-medium bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-200 rounded-xl inline-flex items-center gap-1.5"
        >
          <Download className="w-3.5 h-3.5 text-emerald-400" />
          Export CSV
        </button>
      </div>

      {/* Filter Bar */}
      <div className="p-3 bg-zinc-950 border border-zinc-800/80 rounded-2xl grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5 text-xs">
        <div className="relative lg:col-span-2">
          <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-2.5" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search keyword..."
            className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-8 pr-3 py-1.5 text-zinc-100 focus:outline-none focus:border-emerald-500"
          />
        </div>
        <select value={intent} onChange={(e) => setIntent(e.target.value)} className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-zinc-200">
          <option value="all">All Intents</option>
          <option value="transactional">Transactional</option>
          <option value="commercial">Commercial</option>
          <option value="informational">Informational</option>
          <option value="navigational">Navigational</option>
        </select>
        <select value={compFilter} onChange={(e) => setCompFilter(e.target.value)} className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-zinc-200">
          <option value="all">All Competitors</option>
          {competitors.map((c) => (
            <option key={c.id} value={c.domain}>{c.domain}</option>
          ))}
        </select>
        <input
          type="number"
          value={minVolume}
          onChange={(e) => setMinVolume(e.target.value)}
          placeholder="Min Vol"
          className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-zinc-100"
        />
        <div className="flex gap-1.5">
          <input
            type="number"
            value={maxDifficulty}
            onChange={(e) => setMaxDifficulty(e.target.value)}
            placeholder="Max KD"
            className="w-1/2 bg-zinc-900 border border-zinc-800 rounded-xl px-2 py-1.5 text-zinc-100"
          />
          <select value={sortBy} onChange={(e) => setSortBy(e.target.value)} className="w-1/2 bg-zinc-900 border border-zinc-800 rounded-xl px-2 py-1.5 text-zinc-200">
            <option value="opportunity_score">Score</option>
            <option value="search_volume">Volume</option>
            <option value="keyword_difficulty">KD</option>
            <option value="cpc">CPC</option>
          </select>
        </div>
      </div>

      {/* Honest Provider Attribution Banner */}
      {meta && !meta.provider_connected && (
        <div className="p-3 rounded-xl bg-amber-950/20 border border-amber-800/40 text-xs text-amber-300 flex items-center gap-2">
          <AlertTriangle className="w-4 h-4 shrink-0 text-amber-400" />
          <span>
            External keyword provider (DataForSEO / Semrush) is not connected. Showing verified first-party GSC queries only ({country}/{language}); third-party search volume &amp; competitor rankings show &ldquo;Data unavailable&rdquo;.
          </span>
        </div>
      )}

      {/* Keyword Gap Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-xs text-zinc-400">Loading Keyword Gap data...</div>
        ) : keywords.length === 0 ? (
          <div className="p-10 text-center space-y-2 text-xs text-zinc-400">
            <ArrowUpDown className="w-7 h-7 text-zinc-600 mx-auto" />
            <div className="text-sm font-semibold text-zinc-200">No Keyword Gap Rows Yet</div>
            <p className="max-w-md mx-auto">
              Run Full Competitor Analysis (with Google Search Console connected and/or DataForSEO/Semrush credentials) to populate real Keyword Gap records.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-3">Keyword</th>
                  <th className="px-3 py-3">Gap</th>
                  <th className="px-3 py-3">Intent</th>
                  <th className="px-3 py-3">Volume</th>
                  <th className="px-3 py-3">KD</th>
                  <th className="px-3 py-3">CPC</th>
                  <th className="px-3 py-3">You</th>
                  <th className="px-3 py-3">Competitors</th>
                  <th className="px-3 py-3">Source</th>
                  <th className="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {keywords.map((kw) => (
                  <tr key={kw.id} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-3">
                      <div className="font-semibold text-zinc-100">{kw.keyword}</div>
                      {kw.serp_features && kw.serp_features.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-1">
                          {kw.serp_features.slice(0, 3).map((f) => (
                            <span key={f} className="text-[9px] px-1.5 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                              {f}
                            </span>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-3 py-3">
                      <span
                        className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                          kw.gap_type === 'missing'
                            ? 'bg-rose-500/15 text-rose-400'
                            : kw.gap_type === 'weak'
                            ? 'bg-amber-500/15 text-amber-400'
                            : kw.gap_type === 'untapped'
                            ? 'bg-purple-500/15 text-purple-400'
                            : kw.gap_type === 'strong'
                            ? 'bg-emerald-500/15 text-emerald-400'
                            : 'bg-sky-500/15 text-sky-400'
                        }`}
                      >
                        {kw.gap_type}
                      </span>
                    </td>
                    <td className="px-3 py-3 capitalize text-zinc-300">{kw.search_intent || '—'}</td>
                    <td className="px-3 py-3 font-mono text-zinc-200">{kw.search_volume != null ? kw.search_volume.toLocaleString() : 'N/A'}</td>
                    <td className="px-3 py-3 font-mono text-zinc-200">{kw.keyword_difficulty != null ? `${kw.keyword_difficulty}` : 'N/A'}</td>
                    <td className="px-3 py-3 font-mono text-zinc-200">{kw.cpc != null ? `$${kw.cpc.toFixed(2)}` : 'N/A'}</td>
                    <td className="px-3 py-3 font-mono font-bold text-emerald-400">
                      {kw.target_position != null ? `#${kw.target_position}` : '—'}
                    </td>
                    <td className="px-3 py-3 font-mono text-[11px] text-zinc-300">
                      {Object.entries(kw.competitor_positions || {}).length === 0
                        ? '—'
                        : Object.entries(kw.competitor_positions).map(([dom, info]) => (
                            <div key={dom}>
                              {dom}: <strong className="text-sky-400">#{info?.position ?? '—'}</strong>
                            </div>
                          ))}
                    </td>
                    <td className="px-3 py-3 text-[10px] font-mono text-zinc-400">{kw.data_source}</td>
                    <td className="px-4 py-3 text-right">
                      {kw.saved_recommendation_id ? (
                        <button
                          onClick={onNavigateToActionPlan}
                          className="text-[11px] text-emerald-400 hover:underline font-medium"
                        >
                          In Action Plan ✓
                        </button>
                      ) : (
                        <button
                          onClick={() => handleSaveToActionPlan(kw)}
                          disabled={savingId === kw.id}
                          className="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/40 text-emerald-300 rounded-lg text-[11px] font-semibold transition"
                        >
                          <BookmarkPlus className="w-3 h-3" />
                          <span>{savingId === kw.id ? 'Saving...' : 'Save to Plan'}</span>
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

/* ============================================================================
   SUBMODULE 5: CONTENT GAP ANALYSIS
   ============================================================================ */
export function ContentGapTab({
  website,
  onNavigateToContent,
}: {
  website: Website;
  onNavigateToContent?: () => void;
}) {
  const toast = useToast();
  const [gaps, setGaps] = useState<CompetitorContentGapItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [sendingId, setSendingId] = useState<number | null>(null);

  const loadGaps = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<CompetitorContentGapItem[]>(`/websites/${website.id}/competitors/content-gap`);
      if (res.success && res.data) {
        setGaps(res.data);
      }
    } finally {
      setIsLoading(false);
    }
  }, [website.id]);

  useEffect(() => {
    loadGaps();
  }, [loadGaps]);

  const handleAnalyze = async () => {
    setIsAnalyzing(true);
    try {
      const res = await api.post<{ opportunities_found: number }>(`/websites/${website.id}/competitors/content-gap/analyze`);
      if (res.success) {
        toast.success(`Analyzed Content Gaps: ${res.data?.opportunities_found ?? 0} opportunities identified.`);
        await loadGaps();
      } else {
        toast.error(res.error?.message || 'Content gap analysis failed.');
      }
    } finally {
      setIsAnalyzing(false);
    }
  };

  const handleSendToStudio = async (gap: CompetitorContentGapItem) => {
    setSendingId(gap.id);
    try {
      const res = await api.post(`/websites/${website.id}/competitors/content-gap/${gap.id}/send-to-studio`);
      if (res.success) {
        toast.success(`Sent "${gap.suggested_title}" to Content Studio as a new Content Brief!`);
        await loadGaps();
      } else {
        toast.error(res.error?.message || 'Failed to send to Content Studio.');
      }
    } finally {
      setSendingId(null);
    }
  };

  return (
    <div className="space-y-4">
      <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <FileText className="w-4 h-4 text-purple-400" />
            Evidence-Grounded Content Gap Analysis
          </h3>
          <p className="text-xs text-zinc-400 mt-0.5">
            Topics mined from competitor ranking pages &amp; keyword gaps, verified directly against your crawled site pages before labeling anything missing.
          </p>
        </div>
        <button
          onClick={handleAnalyze}
          disabled={isAnalyzing}
          className="px-4 py-2 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 rounded-xl inline-flex items-center gap-1.5 transition"
        >
          <Sparkles className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} />
          <span>{isAnalyzing ? 'Scanning Topics...' : 'Refresh Content Gaps'}</span>
        </button>
      </div>

      {isLoading ? (
        <div className="p-8 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-xs text-zinc-400">
          Loading content gap opportunities...
        </div>
      ) : gaps.length === 0 ? (
        <div className="p-10 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-2 text-xs text-zinc-400">
          <FileText className="w-7 h-7 text-zinc-600 mx-auto" />
          <div className="text-sm font-semibold text-zinc-200">No Content Gaps Detected Yet</div>
          <p className="max-w-md mx-auto">
            Add competitors and run Full Analysis first, then click &ldquo;Refresh Content Gaps&rdquo; to compare competitor topics against your crawled pages.
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {gaps.map((gap) => (
            <div key={gap.id} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
              <div className="flex flex-col md:flex-row md:items-start justify-between gap-3">
                <div className="space-y-1.5">
                  <div className="flex flex-wrap items-center gap-2 text-[10px]">
                    <span className="uppercase font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-400 border border-purple-500/30">
                      {gap.suggested_content_type.replace('_', ' ')}
                    </span>
                    <span className="uppercase font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400">
                      {gap.priority} Priority
                    </span>
                    <span className="px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-300">
                      {gap.target_coverage_status === 'missing_verified'
                        ? 'Missing (Crawl Verified)'
                        : gap.target_coverage_status === 'weak_coverage'
                        ? 'Weak Coverage'
                        : 'Crawl Needed to Confirm'}
                    </span>
                    <span className="font-mono text-zinc-400">Intent: {gap.search_intent}</span>
                  </div>
                  <h4 className="text-sm font-bold text-zinc-100">{gap.suggested_title}</h4>
                  <div className="text-xs text-zinc-400">
                    Primary Keyword: <code className="text-emerald-400 font-mono">{gap.primary_keyword}</code>
                  </div>
                  <p className="text-xs text-zinc-300 leading-relaxed">{gap.rationale}</p>
                </div>

                <div className="shrink-0">
                  {gap.status === 'sent_to_studio' ? (
                    <button
                      onClick={onNavigateToContent}
                      className="px-3.5 py-2 text-xs font-semibold text-emerald-400 bg-emerald-950/40 border border-emerald-700/50 rounded-xl"
                    >
                      Open in Content Studio ✓
                    </button>
                  ) : (
                    <button
                      onClick={() => handleSendToStudio(gap)}
                      disabled={sendingId === gap.id}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition"
                    >
                      <Send className="w-3.5 h-3.5" />
                      <span>{sendingId === gap.id ? 'Sending...' : 'Send to Content Studio'}</span>
                    </button>
                  )}
                </div>
              </div>

              {/* Recommended Outline & Reference URLs */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-zinc-800/70 text-xs">
                <div>
                  <div className="text-[11px] font-semibold text-zinc-400 mb-1">Recommended Outline</div>
                  <ul className="space-y-1 text-zinc-300">
                    {(gap.recommended_outline || []).map((sec, idx) => (
                      <li key={idx} className="text-[11px]">
                        • <strong>H{sec.level}:</strong> {sec.heading}
                      </li>
                    ))}
                  </ul>
                </div>
                <div>
                  <div className="text-[11px] font-semibold text-zinc-400 mb-1">Competitor Reference URLs</div>
                  <div className="space-y-1">
                    {(gap.competitor_reference_urls || []).map((ref, idx) => (
                      <a
                        key={idx}
                        href={ref.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[11px] text-sky-400 hover:underline flex items-center gap-1 break-all"
                      >
                        <ExternalLink className="w-3 h-3 shrink-0" />
                        <span>{ref.url}</span>
                      </a>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
