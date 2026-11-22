'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Link2,
  Download,
  Search,
  Sparkles,
  History,
  Bell,
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  RefreshCw,
  BookmarkPlus,
  Eye,
  Shield,
  Calendar,
} from 'lucide-react';
import {
  Website,
  CompetitorSerpSnapshotItem,
  CompetitorStrategyReportItem,
} from '@/types';
import { api, getApiBaseUrl } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

/* ============================================================================
   SUBMODULE 6: BACKLINK GAP ANALYSIS
   ============================================================================ */
export function BacklinkGapTab({ website }: { website: Website }) {
  const toast = useToast();
  const [mode, setMode] = useState<'gap' | 'shared'>('gap');
  const [linkType, setLinkType] = useState<string>('all');
  const [search, setSearch] = useState<string>('');
  const [report, setReport] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);

  const loadBacklinks = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams({ mode, link_type: linkType });
      if (search.trim()) params.set('search', search.trim());
      const res = await api.get(`/websites/${website.id}/competitors/backlink-gap?${params.toString()}`);
      if (res.success && res.data) {
        setReport(res.data);
      }
    } finally {
      setIsLoading(false);
    }
  }, [website.id, mode, linkType, search]);

  useEffect(() => {
    loadBacklinks();
  }, [loadBacklinks]);

  const handleExportCsv = async () => {
    const token = typeof window !== 'undefined' ? localStorage.getItem('web_seo_ai_token') : '';
    const orgId = typeof window !== 'undefined' ? localStorage.getItem('web_seo_ai_org_id') : '';
    try {
      const res = await fetch(
        `${getApiBaseUrl()}/websites/${website.id}/competitors/backlink-gap?mode=${mode}&export=csv`,
        {
          headers: {
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...(orgId ? { 'X-Organization-Id': orgId } : {}),
          },
        }
      );
      const blob = new Blob([await res.text()], { type: 'text/csv;charset=utf-8;' });
      const dl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = dl;
      a.download = `backlink-gap-${website.domain}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(dl);
      toast.success('Exported Backlink Gap CSV.');
    } catch {
      toast.error('CSV export failed.');
    }
  };

  const summary = report?.summary ?? {};
  const items: any[] = report?.items ?? [];

  return (
    <div className="space-y-4">
      {report && !report.provider_connected && (
        <div className="p-4 rounded-2xl bg-amber-950/20 border border-amber-800/40 text-xs text-amber-300 flex items-start gap-2.5">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5 text-amber-400" />
          <div>
            <div className="font-bold text-amber-200">Backlink Data Provider Not Connected</div>
            <p className="mt-0.5">
              Web SEO AI never scrapes private backlink indexes or fabricates referring domain counts. Configure{' '}
              <code className="font-mono">DATAFORSEO_LOGIN</code> + <code className="font-mono">DATAFORSEO_PASSWORD</code>,{' '}
              <code className="font-mono">AHREFS_API_KEY</code>, or <code className="font-mono">SEMRUSH_API_KEY</code> in server env vars to unlock live Backlink Gap Analysis.
            </p>
          </div>
        </div>
      )}

      {/* Summary Strip */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400">Outreach Gap Domains</div>
          <div className="text-2xl font-extrabold text-emerald-400 font-mono mt-1">{summary.gap_domains_count ?? 0}</div>
          <div className="text-[10px] text-zinc-500">Link to rivals, not {website.domain}</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400">Shared Referring Domains</div>
          <div className="text-2xl font-extrabold text-sky-400 font-mono mt-1">{summary.shared_domains_count ?? 0}</div>
          <div className="text-[10px] text-zinc-500">Link to both you &amp; rivals</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400">Follow / Nofollow</div>
          <div className="text-2xl font-extrabold text-zinc-100 font-mono mt-1">
            {summary.dofollow_count ?? 0} / {summary.nofollow_count ?? 0}
          </div>
          <div className="text-[10px] text-zinc-500">Observed link attributes</div>
        </div>
        <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] text-zinc-400">New / Lost Links</div>
          <div className="text-2xl font-extrabold text-zinc-100 font-mono mt-1">
            <span className="text-emerald-400">+{summary.new_backlinks_count ?? 0}</span> /{' '}
            <span className="text-rose-400">-{summary.lost_backlinks_count ?? 0}</span>
          </div>
          <div className="text-[10px] text-zinc-500">Provider velocity signals</div>
        </div>
      </div>

      {/* Controls */}
      <div className="flex flex-wrap items-center justify-between gap-2 bg-zinc-950 p-3 rounded-2xl border border-zinc-800/80 text-xs">
        <div className="flex items-center gap-1.5">
          <button
            onClick={() => setMode('gap')}
            className={`px-3 py-1.5 rounded-xl font-semibold border ${
              mode === 'gap' ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400' : 'bg-zinc-900 border-zinc-800 text-zinc-400'
            }`}
          >
            Backlink Gap ({summary.gap_domains_count ?? 0})
          </button>
          <button
            onClick={() => setMode('shared')}
            className={`px-3 py-1.5 rounded-xl font-semibold border ${
              mode === 'shared' ? 'bg-sky-500/15 border-sky-500/40 text-sky-400' : 'bg-zinc-900 border-zinc-800 text-zinc-400'
            }`}
          >
            Shared Domains ({summary.shared_domains_count ?? 0})
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Filter referring domain..."
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-1.5 text-zinc-100"
          />
          <select value={linkType} onChange={(e) => setLinkType(e.target.value)} className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-zinc-200">
            <option value="all">All Link Types</option>
            <option value="dofollow">Dofollow</option>
            <option value="nofollow">Nofollow</option>
          </select>
          <button
            onClick={handleExportCsv}
            className="px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-200 rounded-xl inline-flex items-center gap-1.5"
          >
            <Download className="w-3.5 h-3.5 text-emerald-400" />
            CSV
          </button>
        </div>
      </div>

      {/* Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-xs text-zinc-400">Loading backlink gap data...</div>
        ) : items.length === 0 ? (
          <div className="p-10 text-center space-y-2 text-xs text-zinc-400">
            <Link2 className="w-7 h-7 text-zinc-600 mx-auto" />
            <div className="text-sm font-semibold text-zinc-200">No Backlink Gap Domains Recorded</div>
            <p>Connect a supported backlink provider and run Full Analysis to populate referring domain opportunities.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-3">Referring Domain</th>
                  <th className="px-3 py-3">Authority</th>
                  <th className="px-3 py-3">Competitors Linked</th>
                  <th className="px-3 py-3">Link Type</th>
                  <th className="px-3 py-3">Sample Anchor</th>
                  <th className="px-3 py-3">Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {items.map((row, idx) => (
                  <tr key={idx} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-3 font-semibold text-zinc-100">
                      <a
                        href={row.sample_referring_url || `https://${row.referring_domain}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="hover:text-emerald-400 inline-flex items-center gap-1"
                      >
                        {row.referring_domain}
                        <ExternalLink className="w-3 h-3 text-zinc-500" />
                      </a>
                    </td>
                    <td className="px-3 py-3 font-mono text-zinc-200">{row.referring_authority ?? 'N/A'}</td>
                    <td className="px-3 py-3 text-zinc-300">{(row.competitors_linked || []).join(', ')}</td>
                    <td className="px-3 py-3 uppercase text-[10px] font-bold text-emerald-400">{row.link_type}</td>
                    <td className="px-3 py-3 text-zinc-400 max-w-xs truncate">{row.sample_anchor || '—'}</td>
                    <td className="px-3 py-3 font-mono text-[10px] text-zinc-400">{row.data_source}</td>
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
   SUBMODULE 7: SERP ANALYSIS
   ============================================================================ */
export function SerpAnalysisTab({
  website,
  country,
  language,
}: {
  website: Website;
  country: string;
  language: string;
}) {
  const toast = useToast();
  const [keyword, setKeyword] = useState('');
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [location, setLocation] = useState('');
  const [monitor, setMonitor] = useState(true);
  const [isRunning, setIsRunning] = useState(false);

  const [snapshots, setSnapshots] = useState<CompetitorSerpSnapshotItem[]>([]);
  const [activeSnapshot, setActiveSnapshot] = useState<CompetitorSerpSnapshotItem | null>(null);
  const [providerMeta, setProviderMeta] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);

  const loadSnapshots = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<CompetitorSerpSnapshotItem[]>(`/websites/${website.id}/competitors/serp/snapshots`);
      if (res.success && res.data) {
        setSnapshots(res.data);
        setProviderMeta(res.meta);
        if (res.data.length > 0 && !activeSnapshot) {
          setActiveSnapshot(res.data[0]);
        }
      }
    } finally {
      setIsLoading(false);
    }
  }, [website.id, activeSnapshot]);

  useEffect(() => {
    loadSnapshots();
  }, [loadSnapshots]);

  const handleAnalyzeSerp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!keyword.trim()) return;
    setIsRunning(true);
    try {
      const res = await api.post<{ snapshot: CompetitorSerpSnapshotItem }>(
        `/websites/${website.id}/competitors/serp/analyze`,
        {
          keyword: keyword.trim(),
          country,
          language,
          device,
          location: location.trim() || undefined,
          monitor,
        }
      );
      if (res.success && res.data?.snapshot) {
        toast.success(`Captured SERP snapshot for "${keyword.trim()}".`);
        setActiveSnapshot(res.data.snapshot);
        setKeyword('');
        await loadSnapshots();
      } else {
        toast.error(res.error?.message || 'SERP analysis failed.');
      }
    } finally {
      setIsRunning(false);
    }
  };

  const handleToggleMonitor = async (snap: CompetitorSerpSnapshotItem) => {
    const res = await api.patch<CompetitorSerpSnapshotItem>(
      `/websites/${website.id}/competitors/serp/snapshots/${snap.id}/monitor`
    );
    if (res.success && res.data) {
      toast.info(res.data.is_monitored ? 'Keyword added to SERP monitoring.' : 'Monitoring paused.');
      setActiveSnapshot(res.data);
      loadSnapshots();
    }
  };

  return (
    <div className="space-y-5">
      {providerMeta && !providerMeta.provider_connected && (
        <div className="p-4 rounded-2xl bg-amber-950/20 border border-amber-800/40 text-xs text-amber-300 flex items-start gap-2.5">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5 text-amber-400" />
          <div>
            <div className="font-bold text-amber-200">SERP Data Provider Not Connected</div>
            <p className="mt-0.5">
              Configure <code className="font-mono">DATAFORSEO_LOGIN</code> + <code className="font-mono">DATAFORSEO_PASSWORD</code> or{' '}
              <code className="font-mono">SERPAPI_KEY</code> in server environment variables to capture real provider-backed SERP snapshots.
            </p>
          </div>
        </div>
      )}

      {/* SERP Analyzer Form */}
      <form onSubmit={handleAnalyzeSerp} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3 text-xs">
        <div className="font-bold text-sm text-zinc-100 flex items-center gap-2">
          <Search className="w-4 h-4 text-emerald-400" />
          Analyze SERP Snapshot ({country} / {language})
        </div>
        <div className="grid grid-cols-1 md:grid-cols-5 gap-2.5">
          <input
            type="text"
            required
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            placeholder="Enter target keyword to inspect SERP..."
            className="md:col-span-2 px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-emerald-500"
          />
          <select value={device} onChange={(e) => setDevice(e.target.value as 'desktop' | 'mobile')} className="px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-200">
            <option value="desktop">Desktop</option>
            <option value="mobile">Mobile</option>
          </select>
          <input
            type="text"
            value={location}
            onChange={(e) => setLocation(e.target.value)}
            placeholder="City/Location (optional)"
            className="px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100"
          />
          <button
            type="submit"
            disabled={isRunning}
            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl transition"
          >
            {isRunning ? 'Fetching SERP...' : 'Analyze SERP'}
          </button>
        </div>
        <label className="inline-flex items-center gap-2 text-[11px] text-zinc-400 cursor-pointer">
          <input type="checkbox" checked={monitor} onChange={(e) => setMonitor(e.target.checked)} className="accent-emerald-500" />
          Save keyword for ongoing SERP monitoring (snapshot disclaimer: third-party SERP snapshots may differ from personalized live Google results)
        </label>
      </form>

      {/* Active Snapshot View + History List */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 space-y-2">
          <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">Saved SERP Snapshots ({snapshots.length})</h4>
          {isLoading ? (
            <div className="text-xs text-zinc-500 py-4">Loading...</div>
          ) : snapshots.length === 0 ? (
            <div className="text-xs text-zinc-500 py-4">No SERP snapshots captured yet.</div>
          ) : (
            <div className="space-y-1.5 max-h-96 overflow-y-auto">
              {snapshots.map((s) => (
                <button
                  key={s.id}
                  onClick={() => setActiveSnapshot(s)}
                  className={`w-full text-left p-2.5 rounded-xl border text-xs transition ${
                    activeSnapshot?.id === s.id
                      ? 'bg-emerald-500/10 border-emerald-500/40 text-zinc-100'
                      : 'bg-zinc-900/50 border-zinc-800 text-zinc-300 hover:border-zinc-700'
                  }`}
                >
                  <div className="font-semibold truncate">{s.keyword}</div>
                  <div className="flex items-center justify-between text-[10px] text-zinc-400 mt-1">
                    <span>{s.country} • {s.device} • {s.data_source}</span>
                    <span className="font-mono text-emerald-400">You: {s.target_position ? `#${s.target_position}` : '—'}</span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="lg:col-span-2 bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 space-y-4">
          {!activeSnapshot ? (
            <div className="p-10 text-center text-xs text-zinc-400">Select or run a SERP snapshot to inspect organic rankings &amp; competitor presence.</div>
          ) : (
            <>
              <div className="flex flex-wrap items-start justify-between gap-2 border-b border-zinc-800 pb-3">
                <div>
                  <h4 className="text-sm font-bold text-zinc-100">&ldquo;{activeSnapshot.keyword}&rdquo;</h4>
                  <div className="text-[11px] text-zinc-400 mt-0.5">
                    Provider Snapshot ({activeSnapshot.data_source}) • {activeSnapshot.country}/{activeSnapshot.device} • Captured {new Date(activeSnapshot.captured_at).toLocaleString()}
                  </div>
                </div>
                <button
                  onClick={() => handleToggleMonitor(activeSnapshot)}
                  className={`px-3 py-1 rounded-xl text-xs font-semibold border ${
                    activeSnapshot.is_monitored
                      ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                      : 'bg-zinc-900 border-zinc-800 text-zinc-400'
                  }`}
                >
                  {activeSnapshot.is_monitored ? 'Monitoring ON' : 'Monitor Keyword'}
                </button>
              </div>

              {/* SERP Features & Content Pattern */}
              <div className="flex flex-wrap items-center gap-2 text-[11px]">
                <span className="text-zinc-400 font-semibold">SERP Features:</span>
                {(activeSnapshot.serp_features || []).length === 0 ? (
                  <span className="text-zinc-500">Standard Organic</span>
                ) : (
                  (activeSnapshot.serp_features || []).map((f) => (
                    <span key={f} className="px-2 py-0.5 rounded-full bg-sky-500/10 border border-sky-500/30 text-sky-400 font-mono">
                      {f}
                    </span>
                  ))
                )}
              </div>

              {activeSnapshot.content_patterns?.dominant_format && (
                <div className="text-xs text-zinc-400">
                  Dominant SERP Format: <strong className="text-zinc-200">{activeSnapshot.content_patterns.dominant_format}</strong> • Intent:{' '}
                  <strong className="capitalize text-emerald-400">{activeSnapshot.search_intent}</strong>
                </div>
              )}

              <div className="space-y-2 max-h-96 overflow-y-auto">
                {(activeSnapshot.organic_results || []).map((r) => (
                  <div
                    key={r.position}
                    className={`p-3 rounded-xl border text-xs ${
                      r.is_target
                        ? 'bg-emerald-950/30 border-emerald-700/60'
                        : r.is_competitor
                        ? 'bg-amber-950/20 border-amber-800/50'
                        : 'bg-zinc-900/50 border-zinc-800'
                    }`}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <div className="flex items-center gap-2 font-semibold text-zinc-100">
                        <span className="font-mono text-emerald-400">#{r.position}</span>
                        <span>{r.domain}</span>
                        {r.is_target && <span className="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300">YOU</span>}
                        {r.is_competitor && <span className="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300">COMPETITOR</span>}
                      </div>
                      <a href={r.url} target="_blank" rel="noopener noreferrer" className="text-zinc-400 hover:text-emerald-400">
                        <ExternalLink className="w-3.5 h-3.5" />
                      </a>
                    </div>
                    <div className="font-medium text-zinc-200 mt-1">{r.title}</div>
                    {r.snippet && <p className="text-[11px] text-zinc-400 mt-0.5">{r.snippet}</p>}
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

/* ============================================================================
   SUBMODULE 8: AI COMPETITOR STRATEGIST
   ============================================================================ */
export function AiStrategyTab({
  website,
  onNavigateToActionPlan,
}: {
  website: Website;
  onNavigateToActionPlan?: () => void;
}) {
  const toast = useToast();
  const [reports, setReports] = useState<CompetitorStrategyReportItem[]>([]);
  const [activeReport, setActiveReport] = useState<CompetitorStrategyReportItem | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [savingIdx, setSavingIdx] = useState<number | null>(null);

  const loadReports = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<CompetitorStrategyReportItem[]>(`/websites/${website.id}/competitors/strategy`);
      if (res.success && res.data) {
        setReports(res.data);
        if (res.data.length > 0) setActiveReport(res.data[0]);
      }
    } finally {
      setIsLoading(false);
    }
  }, [website.id]);

  useEffect(() => {
    loadReports();
  }, [loadReports]);

  const handleGenerate = async () => {
    setIsGenerating(true);
    try {
      const res = await api.post<CompetitorStrategyReportItem>(`/websites/${website.id}/competitors/strategy/generate`);
      if (res.success && res.data) {
        toast.success('Generated evidence-backed AI Competitor Strategy report!');
        setActiveReport(res.data);
        await loadReports();
      } else {
        toast.error(res.error?.message || 'Failed to generate AI strategy.');
      }
    } finally {
      setIsGenerating(false);
    }
  };

  const handleSaveToActionPlan = async (idx: number) => {
    if (!activeReport) return;
    setSavingIdx(idx);
    try {
      const res = await api.post<{ report: CompetitorStrategyReportItem }>(
        `/websites/${website.id}/competitors/strategy/${activeReport.id}/save-action`,
        { recommendation_index: idx }
      );
      if (res.success && res.data) {
        toast.success('Recommendation saved directly to SEO Action Plan!');
        setActiveReport(res.data.report);
      } else {
        toast.error(res.error?.message || 'Could not save recommendation.');
      }
    } finally {
      setSavingIdx(null);
    }
  };

  return (
    <div className="space-y-5">
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Sparkles className="w-4 h-4 text-emerald-400" />
            AI Competitor Strategist (Evidence-Grounded)
          </h3>
          <p className="text-xs text-zinc-400 mt-0.5">
            Synthesizes observed domain metrics, keyword/content/backlink gaps, and SERP snapshots into prioritized strategic actions. Explicitly separates observed facts from AI interpretation.
          </p>
        </div>
        <button
          onClick={handleGenerate}
          disabled={isGenerating}
          className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl inline-flex items-center gap-1.5"
        >
          <Sparkles className={`w-3.5 h-3.5 ${isGenerating ? 'animate-spin' : ''}`} />
          <span>{isGenerating ? 'Generating Report...' : 'Generate AI Strategy'}</span>
        </button>
      </div>

      {isLoading ? (
        <div className="p-8 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-xs text-zinc-400">
          Loading AI strategy reports...
        </div>
      ) : !activeReport ? (
        <div className="p-10 text-center bg-zinc-950 border border-zinc-800 rounded-2xl space-y-3 text-xs text-zinc-400">
          <Sparkles className="w-7 h-7 text-emerald-500/60 mx-auto" />
          <div className="text-sm font-semibold text-zinc-200">No Strategy Report Generated Yet</div>
          <p className="max-w-md mx-auto">Click &ldquo;Generate AI Strategy&rdquo; to analyze your tracked competitors and synthesize prioritized recommendations.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {/* Executive Summary */}
          <div className="p-5 bg-zinc-950 border border-emerald-900/50 rounded-2xl space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-zinc-400">
              <span className="font-bold uppercase tracking-wider text-emerald-400">Executive Summary (AI Insight)</span>
              <span className="font-mono">
                Model: {activeReport.provider_model} • Sources: {(activeReport.data_sources_used || []).join(', ')} • {new Date(activeReport.generated_at).toLocaleString()}
              </span>
            </div>
            <p className="text-xs text-zinc-200 leading-relaxed">{activeReport.executive_summary}</p>
          </div>

          {/* Prioritized Strategic Recommendations */}
          <div className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">
              Prioritized Strategic Recommendations ({activeReport.strategic_recommendations?.length || 0})
            </h4>
            {(activeReport.strategic_recommendations || []).map((rec, idx) => (
              <div key={idx} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
                <div className="flex flex-col md:flex-row md:items-start justify-between gap-3">
                  <div className="space-y-1.5">
                    <div className="flex flex-wrap items-center gap-1.5 text-[10px]">
                      <span
                        className={`uppercase font-bold px-2 py-0.5 rounded-full ${
                          rec.priority === 'critical'
                            ? 'bg-rose-500/15 text-rose-400'
                            : rec.priority === 'high'
                            ? 'bg-amber-500/15 text-amber-400'
                            : rec.priority === 'medium'
                            ? 'bg-sky-500/15 text-sky-400'
                            : 'bg-zinc-800 text-zinc-300'
                        }`}
                      >
                        {rec.priority} Priority
                      </span>
                      <span className="px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-300 capitalize">
                        Effort: {rec.effort}
                      </span>
                      <span className="px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-300 capitalize">
                        Confidence: {rec.confidence}
                      </span>
                    </div>
                    <h5 className="text-sm font-bold text-zinc-100">{rec.title}</h5>
                    <p className="text-xs text-zinc-300 leading-relaxed">{rec.explanation}</p>
                  </div>

                  <div className="shrink-0">
                    {rec.saved_recommendation_id ? (
                      <button
                        onClick={onNavigateToActionPlan}
                        className="px-3.5 py-2 text-xs font-semibold text-emerald-400 bg-emerald-950/40 border border-emerald-700/50 rounded-xl"
                      >
                        Saved to Action Plan ✓
                      </button>
                    ) : (
                      <button
                        onClick={() => handleSaveToActionPlan(idx)}
                        disabled={savingIdx === idx}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition"
                      >
                        <BookmarkPlus className="w-3.5 h-3.5" />
                        <span>{savingIdx === idx ? 'Saving...' : 'Save to Action Plan'}</span>
                      </button>
                    )}
                  </div>
                </div>

                {/* Observed vs Interpreted Proof Box */}
                {rec.observed_vs_interpreted && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5 pt-2 border-t border-zinc-800/70 text-[11px]">
                    <div className="p-2.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                      <span className="font-bold text-emerald-400 block mb-0.5">Observed Evidence:</span>
                      <span className="text-zinc-300">{rec.observed_vs_interpreted.observed}</span>
                    </div>
                    <div className="p-2.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                      <span className="font-bold text-sky-400 block mb-0.5">Strategic Interpretation:</span>
                      <span className="text-zinc-300">{rec.observed_vs_interpreted.interpreted}</span>
                    </div>
                  </div>
                )}

                <div className="text-[11px] text-zinc-400 flex flex-wrap items-center justify-between gap-2">
                  <span><strong className="text-zinc-200">Next Action:</strong> {rec.next_action}</span>
                  <span><strong className="text-emerald-400">Expected Benefit:</strong> {rec.expected_benefit}</span>
                </div>
              </div>
            ))}
          </div>

          {reports.length > 1 && (
            <div className="text-xs text-zinc-400 pt-2">
              Showing latest of {reports.length} historical strategy reports.
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ============================================================================
   SUBMODULE 9: COMPETITOR HISTORY & MONITORING
   ============================================================================ */
export function CompetitorHistoryTab({ website }: { website: Website }) {
  const toast = useToast();
  const [data, setData] = useState<any>(null);
  const [frequency, setFrequency] = useState('weekly');
  const [minDelta, setMinDelta] = useState(3);
  const [isSaving, setIsSaving] = useState(false);
  const [isRunning, setIsRunning] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  const loadMonitoring = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<any>(`/websites/${website.id}/competitors/monitoring`);
      if (res.success && res.data) {
        setData(res.data);
        setFrequency(res.data.config?.frequency || 'weekly');
        setMinDelta(res.data.config?.min_position_delta ?? 3);
      }
    } finally {
      setIsLoading(false);
    }
  }, [website.id]);

  useEffect(() => {
    loadMonitoring();
  }, [loadMonitoring]);

  const handleSaveSchedule = async () => {
    setIsSaving(true);
    try {
      const res = await api.put(`/websites/${website.id}/competitors/monitoring`, {
        frequency,
        min_position_delta: minDelta,
      });
      if (res.success) {
        toast.success(`Monitoring schedule saved (${frequency}).`);
        await loadMonitoring();
      } else {
        toast.error(res.error?.message || 'Failed to update schedule.');
      }
    } finally {
      setIsSaving(false);
    }
  };

  const handleRunCheck = async () => {
    setIsRunning(true);
    try {
      const res = await api.post<{ domains_checked: number; new_alerts_count: number }>(
        `/websites/${website.id}/competitors/monitoring/run`,
        { force_refresh: true }
      );
      if (res.success && res.data) {
        toast.success(`Checked ${res.data.domains_checked} domain(s) — ${res.data.new_alerts_count} meaningful change(s) detected.`);
        await loadMonitoring();
      } else {
        toast.error(res.error?.message || 'Monitoring check failed.');
      }
    } finally {
      setIsRunning(false);
    }
  };

  if (isLoading && !data) {
    return (
      <div className="p-8 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-xs text-zinc-400">
        Loading monitoring history...
      </div>
    );
  }

  const alerts: any[] = data?.config?.recent_alerts ?? [];
  const snapshots: any[] = data?.history?.recent_snapshots ?? [];
  const logs: any[] = data?.providers?.usage?.recent_logs ?? [];

  return (
    <div className="space-y-5">
      {/* Schedule Config Card */}
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="space-y-1">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Calendar className="w-4 h-4 text-emerald-400" />
            Competitor Monitoring Schedule
          </h3>
          <p className="text-xs text-zinc-400">
            Last run: {data?.config?.last_run_at ? new Date(data.config.last_run_at).toLocaleString() : 'Never'} • Next scheduled:{' '}
            {data?.config?.next_run_at ? new Date(data.config.next_run_at).toLocaleString() : 'Manual only'}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
          <select value={frequency} onChange={(e) => setFrequency(e.target.value)} className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-zinc-200">
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
            <option value="manual">Manual Refresh Only</option>
          </select>
          <button
            onClick={handleSaveSchedule}
            disabled={isSaving}
            className="px-3.5 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 font-semibold rounded-xl"
          >
            {isSaving ? 'Saving...' : 'Save Schedule'}
          </button>
          <button
            onClick={handleRunCheck}
            disabled={isRunning}
            className="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl inline-flex items-center gap-1.5"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isRunning ? 'animate-spin' : ''}`} />
            <span>{isRunning ? 'Checking...' : 'Run Check Now'}</span>
          </button>
        </div>
      </div>

      {/* Meaningful Change Alerts */}
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
        <h4 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
          <Bell className="w-4 h-4 text-amber-400" />
          Detected Competitive Change Alerts ({alerts.length})
        </h4>
        {alerts.length === 0 ? (
          <p className="text-xs text-zinc-400">
            No meaningful changes detected yet. Alerts trigger only on significant keyword, backlink, or homepage content shifts to prevent alert fatigue.
          </p>
        ) : (
          <div className="space-y-2">
            {alerts.map((a, idx) => (
              <div key={idx} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs flex items-center justify-between gap-3">
                <div>
                  <span className="uppercase text-[10px] font-bold text-amber-400 mr-2">{a.type}</span>
                  <span className="text-zinc-200">{a.message}</span>
                </div>
                <span className="text-[10px] text-zinc-500 shrink-0">{new Date(a.detected_at).toLocaleString()}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Historical Snapshot Timeline */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="p-4 border-b border-zinc-800 flex items-center justify-between">
          <h4 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <History className="w-4 h-4 text-sky-400" />
            Historical Domain Snapshots ({snapshots.length})
          </h4>
        </div>
        {snapshots.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-400">Run Competitor Analysis to begin capturing historical snapshots.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-2.5">Captured</th>
                  <th className="px-4 py-2.5">Domain</th>
                  <th className="px-3 py-2.5">Est. Traffic</th>
                  <th className="px-3 py-2.5">Keywords</th>
                  <th className="px-3 py-2.5">Ref. Domains</th>
                  <th className="px-3 py-2.5">Words (Crawl)</th>
                  <th className="px-3 py-2.5">Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {snapshots.map((s) => (
                  <tr key={s.id} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-2.5 text-[11px] text-zinc-400">{new Date(s.captured_at).toLocaleString()}</td>
                    <td className="px-4 py-2.5 font-semibold text-zinc-100">
                      {s.domain} {s.is_target && <span className="text-[10px] text-emerald-400">(You)</span>}
                    </td>
                    <td className="px-3 py-2.5 font-mono text-zinc-200">{s.organic_traffic_estimate ?? 'N/A'}</td>
                    <td className="px-3 py-2.5 font-mono text-zinc-200">{s.organic_keywords_count ?? 'N/A'}</td>
                    <td className="px-3 py-2.5 font-mono text-zinc-200">{s.referring_domains_count ?? 'N/A'}</td>
                    <td className="px-3 py-2.5 font-mono text-zinc-200">{s.crawled_signals?.word_count ?? 'N/A'}</td>
                    <td className="px-3 py-2.5 font-mono text-[10px] text-zinc-400">{s.data_source}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Provider API Usage Logs */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 space-y-3">
        <div className="flex items-center justify-between text-xs">
          <h4 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Shield className="w-4 h-4 text-emerald-400" />
            Provider API Usage &amp; Cache Logs
          </h4>
          <span className="font-mono text-zinc-400">
            Total Calls: {data?.providers?.usage?.total_calls ?? 0} • Est. Cost: ${data?.providers?.usage?.estimated_cost_usd ?? 0}
          </span>
        </div>
        {logs.length === 0 ? (
          <p className="text-xs text-zinc-400">No provider API requests recorded yet.</p>
        ) : (
          <div className="space-y-1.5 max-h-56 overflow-y-auto text-[11px] font-mono">
            {logs.map((l) => (
              <div key={l.id} className="p-2 rounded-lg bg-zinc-900/60 border border-zinc-800 flex flex-wrap items-center justify-between gap-2">
                <span className="text-zinc-200">
                  [{l.provider}] {l.capability} → {l.endpoint}
                </span>
                <span className={l.status === 'success' || l.status === 'cached' ? 'text-emerald-400' : 'text-amber-400'}>
                  {l.status} ({l.duration_ms}ms)
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
