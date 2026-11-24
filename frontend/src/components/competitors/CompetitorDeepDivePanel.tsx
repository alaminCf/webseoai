'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  ArrowLeft,
  RefreshCw,
  ExternalLink,
  CheckCircle2,
  AlertTriangle,
  Sparkles,
  BookmarkPlus,
  Check,
  Globe,
  FileText,
  Layers,
  Link2,
  Trophy,
  Search,
  Gauge,
  FileDown,
  FileSpreadsheet,
  GitCompare,
  ShieldCheck,
} from 'lucide-react';
import { Website, CompetitorDomainItem } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import {
  downloadCompetitorComparisonCsv,
  downloadCompetitorComparisonPdf,
} from '@/lib/reportPdf';

interface CompetitorDeepDivePanelProps {
  website: Website;
  competitors: CompetitorDomainItem[];
  selectedCompetitorId: number;
  onSelectCompetitor: (id: number) => void;
  onClose: () => void;
  onNavigateToActionPlan?: () => void;
  onDataUpdated?: () => void;
}

type SectionTab = 'all' | 'backlinks' | 'scorecard' | 'keywords' | 'onpage' | 'roadmap';
type BacklinkTab = 'gap' | 'competitor' | 'target' | 'shared';

export function CompetitorDeepDivePanel({
  website,
  competitors,
  selectedCompetitorId,
  onSelectCompetitor,
  onClose,
  onNavigateToActionPlan,
  onDataUpdated,
}: CompetitorDeepDivePanelProps) {
  const toast = useToast();
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [isExportingPdf, setIsExportingPdf] = useState(false);
  const [savingIdx, setSavingIdx] = useState<string | null>(null);
  const [savedIds, setSavedIds] = useState<Record<string, boolean>>({});
  const [sectionTab, setSectionTab] = useState<SectionTab>('all');
  const [backlinkTab, setBacklinkTab] = useState<BacklinkTab>('gap');

  const loadDeepDive = useCallback(
    async (forceRefresh = false) => {
      if (forceRefresh) {
        setIsRefreshing(true);
      } else {
        setIsLoading(true);
      }
      try {
        const res = await api.get(
          `/websites/${website.id}/competitors/${selectedCompetitorId}/deep-dive${forceRefresh ? '?refresh=1' : ''}`
        );
        if (res.success && res.data) {
          setData(res.data);
          if (forceRefresh) {
            toast.success('Live competitor data, backlinks & comparison refreshed!');
            onDataUpdated?.();
          }
        } else {
          toast.error(res.error?.message || 'Could not load competitor deep dive.');
        }
      } catch {
        toast.error('Network error while fetching competitor intelligence.');
      } finally {
        setIsLoading(false);
        setIsRefreshing(false);
      }
    },
    [website.id, selectedCompetitorId, toast, onDataUpdated]
  );

  useEffect(() => {
    setSavedIds({});
    setSectionTab('all');
    setBacklinkTab('gap');
    loadDeepDive(false);
  }, [loadDeepDive]);

  const handleSaveImprovement = async (imp: any) => {
    setSavingIdx(imp.id);
    try {
      const res = await api.post(
        `/websites/${website.id}/competitors/${selectedCompetitorId}/deep-dive/save-action`,
        imp
      );
      if (res.success) {
        setSavedIds((prev) => ({ ...prev, [imp.id]: true }));
        toast.success('Added improvement directly to your SEO Action Plan!');
      } else {
        toast.error(res.error?.message || 'Could not save improvement.');
      }
    } finally {
      setSavingIdx(null);
    }
  };

  const handleExportPdf = async () => {
    if (!data) return;
    setIsExportingPdf(true);
    try {
      const brandRes = await api.get<{
        brand_name?: string;
        logo_url?: string | null;
        primary_color?: string;
        footer_text?: string;
      }>('/white-label/branding');
      const f = await downloadCompetitorComparisonPdf(data, brandRes.success ? brandRes.data : null);
      toast.success(`Downloaded Head-to-Head PDF: ${f}`);
    } catch {
      toast.error('Could not generate comparison PDF.');
    } finally {
      setIsExportingPdf(false);
    }
  };

  const handleExportCsv = () => {
    if (!data) return;
    const f = downloadCompetitorComparisonCsv(data);
    toast.success(`Downloaded Head-to-Head CSV: ${f}`);
  };

  if (isLoading && !data) {
    return (
      <div className="p-10 bg-zinc-950 border border-zinc-800 rounded-2xl text-center space-y-3">
        <RefreshCw className="w-6 h-6 text-emerald-400 animate-spin mx-auto" />
        <div className="text-sm font-semibold text-zinc-100">
          Comparing Your Site Head-to-Head Against Competitor...
        </div>
        <p className="text-xs text-zinc-400 max-w-lg mx-auto">
          Live-auditing HTML signals, Schema.org markup, load speed, content depth, Google-indexed pages, live HTML-verified backlinks &amp; referring domains, and keyword rankings against {website.domain}.
        </p>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="p-8 bg-zinc-950 border border-zinc-800 rounded-2xl text-center space-y-3">
        <p className="text-xs text-zinc-400">Could not load deep-dive comparison data.</p>
        <button
          type="button"
          onClick={onClose}
          className="px-4 py-2 text-xs font-semibold bg-zinc-900 border border-zinc-800 text-zinc-200 rounded-xl"
        >
          Back to Competitors
        </button>
      </div>
    );
  }

  const target = data.target;
  const comp = data.competitor;
  const summary = data.summary || {};
  const scorecard: any[] = data.scorecard || [];
  const improvements: any[] = data.improvements || [];
  const targetProfile = data.target_profile || {};
  const profile = data.competitor_profile || {};
  const keywordBattles: any[] = data.keyword_battles || [];
  const bl = data.backlink_comparison || {};
  const blSummary = bl.summary || {};
  const blGapLinks: any[] = bl.gap_links || [];
  const blSharedLinks: any[] = bl.shared_links || [];
  const blCompLinks: any[] = bl.competitor_links || [];
  const blTargetLinks: any[] = bl.target_links || [];

  const activeBacklinkRows =
    backlinkTab === 'gap'
      ? blGapLinks
      : backlinkTab === 'competitor'
      ? blCompLinks
      : backlinkTab === 'target'
      ? blTargetLinks
      : blSharedLinks;

  const tScore = target?.seo_score;
  const cScore = comp?.seo_score;

  const showSection = (tab: SectionTab) => sectionTab === 'all' || sectionTab === tab;

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      {/* Top Bar: Back + Competitor Switcher + PDF/CSV Export + Live Re-Scan */}
      <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-200 rounded-xl transition"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            <span>Back</span>
          </button>

          <span className="text-xs text-zinc-500 hidden sm:inline">|</span>
          <span className="text-xs font-semibold text-zinc-400">Comparing:</span>

          <div className="flex flex-wrap items-center gap-1.5">
            {competitors.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => onSelectCompetitor(c.id)}
                className={`px-2.5 py-1 rounded-lg text-xs font-medium border transition ${
                  c.id === selectedCompetitorId
                    ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                    : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {c.domain}
              </button>
            ))}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={handleExportPdf}
            disabled={isExportingPdf}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 rounded-xl transition disabled:opacity-50"
          >
            {isExportingPdf ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <FileDown className="w-3.5 h-3.5" />}
            <span>PDF</span>
          </button>

          <button
            type="button"
            onClick={handleExportCsv}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 rounded-xl transition"
          >
            <FileSpreadsheet className="w-3.5 h-3.5 text-sky-400" />
            <span>CSV</span>
          </button>

          <button
            type="button"
            onClick={() => loadDeepDive(true)}
            disabled={isRefreshing}
            className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl transition"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
            <span>{isRefreshing ? 'Re-Scanning Live...' : 'Re-Scan Live Data'}</span>
          </button>
        </div>
      </div>

      {/* Head-to-Head Hero Arena */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-5">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-center">
          {/* Your Website */}
          <div className="p-4 rounded-2xl bg-emerald-950/20 border border-emerald-800/40 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Your Website</span>
              <span className="text-[10px] font-mono text-zinc-400">Target</span>
            </div>
            <div className="text-base font-bold text-zinc-100 truncate">{target?.domain}</div>
            <div className="flex items-baseline gap-2">
              <span className="text-3xl font-extrabold font-mono text-emerald-400">
                {tScore != null ? `${tScore}` : '—'}
              </span>
              <span className="text-xs text-zinc-400">/ 100 SEO Score</span>
            </div>
            <div className="grid grid-cols-3 gap-2 pt-2 border-t border-emerald-800/30 text-[11px]">
              <div>
                <div className="text-zinc-500 text-[10px]">Ref. Domains</div>
                <div className="font-mono font-bold text-zinc-200">{blSummary.target_referring_domains ?? 0}</div>
              </div>
              <div>
                <div className="text-zinc-500 text-[10px]">Dofollow</div>
                <div className="font-mono font-bold text-emerald-300">{blSummary.target_dofollow ?? 0}</div>
              </div>
              <div>
                <div className="text-zinc-500 text-[10px]">Speed</div>
                <div className="font-mono font-bold text-zinc-200">
                  {targetProfile.load_time_ms ? `${targetProfile.load_time_ms}ms` : '—'}
                </div>
              </div>
            </div>
          </div>

          {/* Center Head-to-Head Summary */}
          <div className="text-center space-y-3 px-2">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-zinc-900 border border-zinc-800 text-xs font-bold text-zinc-200">
              <Trophy className="w-3.5 h-3.5 text-amber-400" />
              <span>Head-to-Head Verdict</span>
            </div>
            <div className="flex items-center justify-center gap-2 text-xs font-mono">
              <span className="px-2.5 py-1 rounded-lg bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 font-bold">
                {summary.wins ?? 0} Wins
              </span>
              <span className="px-2.5 py-1 rounded-lg bg-zinc-900 text-zinc-300 border border-zinc-800">
                {summary.ties ?? 0} Ties
              </span>
              <span className="px-2.5 py-1 rounded-lg bg-rose-500/15 text-rose-400 border border-rose-500/30 font-bold">
                {summary.gaps ?? 0} Gaps
              </span>
            </div>
            <p className="text-xs text-zinc-400 leading-relaxed">{summary.verdict}</p>
          </div>

          {/* Competitor Website */}
          <div className="p-4 rounded-2xl bg-sky-950/20 border border-sky-800/40 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider text-sky-400">
                {(comp?.category || 'direct').replace('_', ' ')} Competitor
              </span>
              <a
                href={`https://${comp?.domain}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-[10px] text-sky-400 hover:underline inline-flex items-center gap-1"
              >
                Visit <ExternalLink className="w-2.5 h-2.5" />
              </a>
            </div>
            <div className="text-base font-bold text-zinc-100 truncate">{comp?.domain}</div>
            <div className="flex items-baseline gap-2">
              <span className="text-3xl font-extrabold font-mono text-sky-400">
                {cScore != null ? `${cScore}` : '—'}
              </span>
              <span className="text-xs text-zinc-400">/ 100 SEO Score</span>
            </div>
            <div className="grid grid-cols-3 gap-2 pt-2 border-t border-sky-800/30 text-[11px]">
              <div>
                <div className="text-zinc-500 text-[10px]">Ref. Domains</div>
                <div className="font-mono font-bold text-zinc-200">{blSummary.competitor_referring_domains ?? 0}</div>
              </div>
              <div>
                <div className="text-zinc-500 text-[10px]">Dofollow</div>
                <div className="font-mono font-bold text-sky-300">{blSummary.competitor_dofollow ?? 0}</div>
              </div>
              <div>
                <div className="text-zinc-500 text-[10px]">Speed</div>
                <div className="font-mono font-bold text-zinc-200">
                  {profile.load_time_ms ? `${profile.load_time_ms}ms` : '—'}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Section Filter Pills */}
        <div className="flex flex-wrap items-center gap-1.5 pt-3 border-t border-zinc-800/80 text-xs">
          {(
            [
              { id: 'all', label: 'All Comparison Details' },
              { id: 'backlinks', label: `Backlinks & Referring Domains (${blSummary.gap_count ?? 0} Gap)` },
              { id: 'scorecard', label: `Metrics Scorecard (${scorecard.length})` },
              { id: 'keywords', label: `Keyword Battles (${keywordBattles.length})` },
              { id: 'onpage', label: 'On-Page & Pages Blueprint' },
              { id: 'roadmap', label: `Improvement Roadmap (${improvements.length})` },
            ] as const
          ).map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setSectionTab(tab.id)}
              className={`px-3 py-1.5 rounded-xl font-semibold border transition ${
                sectionTab === tab.id
                  ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40'
                  : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* SECTION A: FULL BACKLINKS & REFERRING DOMAINS COMPARISON */}
      {showSection('backlinks') && (
        <div className="bg-zinc-950 border border-amber-700/40 rounded-2xl overflow-hidden">
          <div className="p-5 border-b border-zinc-800 space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                  <Link2 className="w-4 h-4 text-amber-400" />
                  Backlinks &amp; Referring Domains Comparison ({target?.domain} vs {comp?.domain})
                </h3>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Source: {blSummary.source_label || 'Live HTML Backlink Verification + Google Citations'}. Every link below is mechanically verified from live external HTML or Google index — zero fabricated numbers.
                </p>
              </div>
              <span className="inline-flex items-center gap-1 text-[10px] font-mono px-2.5 py-1 rounded-full bg-amber-500/10 text-amber-300 border border-amber-500/30">
                <ShieldCheck className="w-3 h-3" />
                Verified Backlinks &amp; Citations
              </span>
            </div>

            {/* 4 Backlink KPI Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
              <div className="p-3.5 rounded-xl bg-emerald-950/20 border border-emerald-800/40">
                <div className="text-[10px] uppercase font-bold text-emerald-400">Your Referring Domains</div>
                <div className="text-2xl font-black font-mono text-zinc-100 mt-0.5">
                  {blSummary.target_referring_domains ?? 0}
                </div>
                <div className="text-[11px] text-zinc-400 mt-0.5">
                  {blSummary.target_verified_backlinks ?? 0} verified links ({blSummary.target_dofollow ?? 0} dofollow)
                </div>
              </div>

              <div className="p-3.5 rounded-xl bg-sky-950/20 border border-sky-800/40">
                <div className="text-[10px] uppercase font-bold text-sky-400">Competitor Referring Domains</div>
                <div className="text-2xl font-black font-mono text-zinc-100 mt-0.5">
                  {blSummary.competitor_referring_domains ?? 0}
                </div>
                <div className="text-[11px] text-zinc-400 mt-0.5">
                  {blSummary.competitor_verified_backlinks ?? 0} verified links ({blSummary.competitor_dofollow ?? 0} dofollow)
                </div>
              </div>

              <div className="p-3.5 rounded-xl bg-amber-950/20 border border-amber-700/40">
                <div className="text-[10px] uppercase font-bold text-amber-400">Backlink Gap Opportunities</div>
                <div className="text-2xl font-black font-mono text-amber-300 mt-0.5">
                  {blSummary.gap_count ?? 0}
                </div>
                <div className="text-[11px] text-zinc-400 mt-0.5">
                  Domains linking to {comp?.domain}, not you
                </div>
              </div>

              <div className="p-3.5 rounded-xl bg-zinc-900/70 border border-zinc-800">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Shared Referring Domains</div>
                <div className="text-2xl font-black font-mono text-zinc-200 mt-0.5">
                  {blSummary.shared_count ?? 0}
                </div>
                <div className="text-[11px] text-zinc-400 mt-0.5">
                  {blSummary.target_exclusive_count ?? 0} exclusive to your site
                </div>
              </div>
            </div>

            {/* Sub-Tabs for Backlink Tables */}
            <div className="flex flex-wrap items-center gap-1.5 text-xs">
              {(
                [
                  { id: 'gap', label: `Backlink Gap (${blGapLinks.length})` },
                  { id: 'competitor', label: `${comp?.domain} Backlinks (${blCompLinks.length})` },
                  { id: 'target', label: `${target?.domain} Backlinks (${blTargetLinks.length})` },
                  { id: 'shared', label: `Shared Domains (${blSharedLinks.length})` },
                ] as const
              ).map((t) => (
                <button
                  key={t.id}
                  type="button"
                  onClick={() => setBacklinkTab(t.id)}
                  className={`px-3 py-1.5 rounded-xl font-semibold border transition ${
                    backlinkTab === t.id
                      ? 'bg-amber-500/15 text-amber-300 border-amber-500/40'
                      : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                  }`}
                >
                  {t.label}
                </button>
              ))}
            </div>
          </div>

          <div className="overflow-x-auto">
            {activeBacklinkRows.length === 0 ? (
              <div className="p-8 text-center text-xs text-zinc-400">
                No external referring domains recorded in this tab yet. Click &ldquo;Re-Scan Live Data&rdquo; to inspect live backlinks &amp; citations.
              </div>
            ) : (
              <table className="w-full text-left text-xs">
                <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                  <tr>
                    <th className="px-4 py-3">Referring Domain &amp; Page</th>
                    <th className="px-4 py-3">Link Type</th>
                    <th className="px-4 py-3">Anchor Text / Context</th>
                    <th className="px-4 py-3">Verification Source</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/70">
                  {activeBacklinkRows.map((row: any, idx: number) => {
                    const lt = String(row.link_type || 'mention').toLowerCase();
                    return (
                      <tr key={`${row.referring_domain}-${idx}`} className="hover:bg-zinc-900/40">
                        <td className="px-4 py-3 max-w-md">
                          <a
                            href={row.referring_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-semibold text-zinc-100 hover:text-amber-400 inline-flex items-center gap-1.5"
                          >
                            <Globe className="w-3.5 h-3.5 text-amber-400 shrink-0" />
                            <span>{row.referring_domain}</span>
                            <ExternalLink className="w-3 h-3 text-zinc-500 shrink-0" />
                          </a>
                          <div className="text-[10px] font-mono text-zinc-500 truncate mt-0.5">
                            {row.referring_url}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border ${
                              lt === 'dofollow'
                                ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                                : lt === 'nofollow' || lt === 'ugc' || lt === 'sponsored'
                                ? 'bg-amber-500/15 text-amber-300 border-amber-500/30'
                                : 'bg-zinc-900 text-zinc-300 border-zinc-700'
                            }`}
                          >
                            {lt === 'mention' ? 'Brand Citation' : lt}
                          </span>
                        </td>
                        <td className="px-4 py-3 max-w-sm">
                          <div className="text-zinc-200 truncate">
                            {row.anchor_text || row.title || '—'}
                          </div>
                          {row.destination_url && (
                            <div className="text-[10px] font-mono text-emerald-400/80 truncate mt-0.5">
                              → {row.destination_url}
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-3 text-[10px] font-mono text-zinc-400">
                          {row.verified_backlink ? 'Live HTML Verified <a>' : 'Google Index Citation'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* SECTION B: FULL SIDE-BY-SIDE METRIC SCORECARD */}
      {showSection('scorecard') && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
          <div className="p-4 border-b border-zinc-800 flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <Gauge className="w-4 h-4 text-sky-400" />
              Head-to-Head Metrics Comparison ({target?.domain} vs {comp?.domain})
            </h3>
            <span className="text-[11px] text-zinc-400">
              Last scanned: {summary.last_analyzed_at ? new Date(summary.last_analyzed_at).toLocaleString() : 'Just now'}
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-3">SEO Signal</th>
                  <th className="px-4 py-3">Your Site ({target?.domain})</th>
                  <th className="px-4 py-3">Competitor ({comp?.domain})</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Data Source</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {scorecard.map((row, idx) => (
                  <tr key={idx} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-3">
                      <div className="font-semibold text-zinc-100">{row.metric}</div>
                      <div className="text-[10px] text-zinc-500">{row.category}</div>
                    </td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{row.target_display}</td>
                    <td className="px-4 py-3 font-mono text-zinc-200">{row.competitor_display}</td>
                    <td className="px-4 py-3">
                      {row.winner === 'target' ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                          <CheckCircle2 className="w-3 h-3" /> You Lead
                        </span>
                      ) : row.winner === 'competitor' ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30">
                          <AlertTriangle className="w-3 h-3" /> Competitor Ahead
                        </span>
                      ) : row.winner === 'tie' ? (
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-zinc-900 text-zinc-300 border border-zinc-700">
                          Tie
                        </span>
                      ) : (
                        <span className="text-[10px] text-zinc-500">N/A</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-[10px] font-mono text-zinc-400">{row.source_label}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* SECTION C: GOOGLE KEYWORD BATTLES */}
      {showSection('keywords') && (
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Search className="w-4 h-4 text-sky-400" />
            Google Keyword Ranking Battles ({keywordBattles.length})
          </h3>
          {keywordBattles.length === 0 ? (
            <p className="text-xs text-zinc-400">
              No head-to-head Google keyword battles stored yet for {comp?.domain}. Click &ldquo;Re-Scan Live Data&rdquo; to compare exact live rankings.
            </p>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5">
              {keywordBattles.map((kb: any) => (
                <div
                  key={kb.id}
                  className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between gap-3 text-xs"
                >
                  <div className="min-w-0">
                    <div className="font-semibold text-zinc-100 truncate">{kb.keyword}</div>
                    <div className="text-[10px] text-zinc-500 capitalize">
                      Gap: {kb.gap_type} • Source: {kb.data_source}
                    </div>
                  </div>
                  <div className="flex items-center gap-2 font-mono text-[11px] shrink-0">
                    <span className="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">
                      You: {kb.target_position ? `#${kb.target_position}` : 'Not Top 20'}
                    </span>
                    <span className="px-2 py-0.5 rounded bg-sky-500/10 text-sky-300 border border-sky-500/20">
                      Them: {kb.competitor_position ? `#${kb.competitor_position}` : '—'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* SECTION D: SIDE-BY-SIDE ON-PAGE BLUEPRINT & COMPETITOR TOP PAGES */}
      {showSection('onpage') && (
        <div className="space-y-5">
          {/* Side-by-Side On-Page Comparison */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Your Site Blueprint */}
            <div className="p-5 bg-zinc-950 border border-emerald-800/40 rounded-2xl space-y-3 text-xs">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <GitCompare className="w-4 h-4 text-emerald-400" />
                Your Site ({target?.domain}) — On-Page Blueprint
              </h3>
              <div className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Page Title</div>
                <div className="text-zinc-100 font-medium">{targetProfile.page_title || 'Not detected'}</div>
              </div>
              <div className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Meta Description</div>
                <div className="text-zinc-300">{targetProfile.meta_description || 'None detected'}</div>
              </div>
              <div className="flex flex-wrap gap-1.5">
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${targetProfile.has_sitemap ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Sitemap: {targetProfile.has_sitemap ? 'Yes' : 'No'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${targetProfile.has_blog_section ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Blog / Guides: {targetProfile.has_blog_section ? 'Active' : 'Not Detected'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${targetProfile.has_faq_signal ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  FAQ Section: {targetProfile.has_faq_signal ? 'Yes' : 'No'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${targetProfile.has_contact_cta ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Phone / WhatsApp CTA: {targetProfile.has_contact_cta ? 'Yes' : 'No'}
                </span>
              </div>
              {Array.isArray(targetProfile.structured_data_types) && targetProfile.structured_data_types.length > 0 && (
                <div>
                  <div className="text-[10px] uppercase font-bold text-zinc-400 mb-1.5">Your Schema.org Types</div>
                  <div className="flex flex-wrap gap-1.5">
                    {targetProfile.structured_data_types.map((st: string) => (
                      <span key={st} className="text-[10px] font-mono px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                        {st}
                      </span>
                    ))}
                  </div>
                </div>
              )}
              {Array.isArray(targetProfile.h2_outline) && targetProfile.h2_outline.length > 0 && (
                <div>
                  <div className="text-[10px] uppercase font-bold text-zinc-400 mb-1.5">
                    Your Homepage H2 Structure ({targetProfile.h2_outline.length})
                  </div>
                  <ul className="space-y-1 text-[11px] text-zinc-300 bg-zinc-900/50 border border-zinc-800 rounded-xl p-3">
                    {targetProfile.h2_outline.slice(0, 8).map((h2: string, i: number) => (
                      <li key={i} className="truncate">
                        <span className="font-mono text-zinc-500 mr-1.5">H2:</span>
                        {h2}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            {/* Competitor Blueprint */}
            <div className="p-5 bg-zinc-950 border border-sky-800/40 rounded-2xl space-y-3 text-xs">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Layers className="w-4 h-4 text-sky-400" />
                Competitor ({comp?.domain}) — On-Page Blueprint
              </h3>
              <div className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Page Title</div>
                <div className="text-zinc-100 font-medium">{profile.page_title || 'Not exposed (or protected by WAF)'}</div>
              </div>
              <div className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Meta Description</div>
                <div className="text-zinc-300">{profile.meta_description || 'None detected'}</div>
              </div>
              <div className="flex flex-wrap gap-1.5">
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${profile.has_sitemap ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Sitemap: {profile.has_sitemap ? 'Yes' : 'No'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${profile.has_blog_section ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Blog / Guides: {profile.has_blog_section ? 'Active' : 'Not Detected'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${profile.has_faq_signal ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  FAQ Section: {profile.has_faq_signal ? 'Yes' : 'No'}
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded-full border ${profile.has_contact_cta ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-zinc-900 text-zinc-500 border-zinc-800'}`}>
                  Phone / WhatsApp CTA: {profile.has_contact_cta ? 'Yes' : 'No'}
                </span>
              </div>
              {Array.isArray(profile.structured_data_types) && profile.structured_data_types.length > 0 && (
                <div>
                  <div className="text-[10px] uppercase font-bold text-zinc-400 mb-1.5">Competitor Schema.org Types</div>
                  <div className="flex flex-wrap gap-1.5">
                    {profile.structured_data_types.map((st: string) => (
                      <span key={st} className="text-[10px] font-mono px-2 py-0.5 rounded-md bg-purple-500/15 text-purple-300 border border-purple-500/30">
                        {st}
                      </span>
                    ))}
                  </div>
                </div>
              )}
              {Array.isArray(profile.h2_outline) && profile.h2_outline.length > 0 && (
                <div>
                  <div className="text-[10px] uppercase font-bold text-zinc-400 mb-1.5">
                    Competitor Homepage H2 Structure ({profile.h2_outline.length})
                  </div>
                  <ul className="space-y-1 text-[11px] text-zinc-300 bg-zinc-900/50 border border-zinc-800 rounded-xl p-3">
                    {profile.h2_outline.slice(0, 8).map((h2: string, i: number) => (
                      <li key={i} className="truncate">
                        <span className="font-mono text-zinc-500 mr-1.5">H2:</span>
                        {h2}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </div>

          {/* Competitor Top Indexed & Service Pages */}
          <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <FileText className="w-4 h-4 text-emerald-400" />
                {comp?.domain} — Top Indexed &amp; Service Pages ({(profile.top_pages || []).length})
              </h3>
              {summary.missing_pages_count > 0 && (
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-300 border border-purple-500/30">
                  {summary.missing_pages_count} Missing on Your Site
                </span>
              )}
            </div>

            {(profile.top_pages || []).length === 0 ? (
              <p className="text-xs text-zinc-400">
                No public sitemap or Google-indexed pages discovered yet. Click &ldquo;Re-Scan Live Data&rdquo; to inspect.
              </p>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2.5 max-h-[420px] overflow-y-auto pr-1">
                {(profile.top_pages || []).map((p: any, idx: number) => (
                  <div key={idx} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1 text-xs">
                    <div className="flex items-start justify-between gap-2">
                      <a
                        href={p.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-semibold text-zinc-100 hover:text-emerald-400 inline-flex items-center gap-1"
                      >
                        <span>{p.title}</span>
                        <ExternalLink className="w-3 h-3 shrink-0 text-zinc-500" />
                      </a>
                      {p.missing_on_target && (
                        <span className="shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-300 border border-purple-500/30">
                          Missing Topic
                        </span>
                      )}
                    </div>
                    <div className="text-[10px] font-mono text-zinc-500 truncate">{p.url}</div>
                    {p.snippet && <p className="text-[11px] text-zinc-400 line-clamp-2">{p.snippet}</p>}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* SECTION E: PRIORITIZED IMPROVEMENT ROADMAP */}
      {showSection('roadmap') && (
        <div className="bg-zinc-950 border border-emerald-800/50 rounded-2xl p-5 space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-emerald-400" />
                Your Improvement Roadmap to Outrank {comp?.domain} ({improvements.length} Actionable Opportunities)
              </h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                Every recommendation below is generated from verified head-to-head differences between {target?.domain} and {comp?.domain}.
              </p>
            </div>
            {onNavigateToActionPlan && (
              <button
                type="button"
                onClick={onNavigateToActionPlan}
                className="text-xs font-semibold text-emerald-400 hover:text-emerald-300"
              >
                Open SEO Action Plan →
              </button>
            )}
          </div>

          {improvements.length === 0 ? (
            <div className="p-6 text-center rounded-xl bg-zinc-900/50 border border-zinc-800 text-xs text-zinc-400">
              <CheckCircle2 className="w-6 h-6 text-emerald-400 mx-auto mb-1.5" />
              {target?.domain} currently matches or outperforms {comp?.domain} across all audited on-page &amp; technical signals!
            </div>
          ) : (
            <div className="space-y-3">
              {improvements.map((imp) => {
                const isSaved = Boolean(savedIds[imp.id]);
                return (
                  <div
                    key={imp.id}
                    className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-3"
                  >
                    <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span
                            className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border ${
                              imp.priority === 'critical'
                                ? 'bg-rose-500/15 text-rose-400 border-rose-500/30'
                                : imp.priority === 'high'
                                ? 'bg-amber-500/15 text-amber-400 border-amber-500/30'
                                : 'bg-sky-500/15 text-sky-400 border-sky-500/30'
                            }`}
                          >
                            {imp.priority}
                          </span>
                          <span className="text-[10px] uppercase px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-zinc-300">
                            {imp.category}
                          </span>
                          <span className="text-[10px] px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-zinc-400">
                            Effort: {imp.effort}
                          </span>
                        </div>
                        <h4 className="text-xs sm:text-sm font-bold text-zinc-100">{imp.title}</h4>
                      </div>

                      <button
                        type="button"
                        disabled={isSaved || savingIdx === imp.id}
                        onClick={() => handleSaveImprovement(imp)}
                        className={`shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold transition ${
                          isSaved
                            ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                            : 'bg-emerald-600 hover:bg-emerald-500 text-white disabled:opacity-50'
                        }`}
                      >
                        {isSaved ? (
                          <>
                            <Check className="w-3.5 h-3.5" />
                            <span>Saved to Action Plan</span>
                          </>
                        ) : (
                          <>
                            <BookmarkPlus className="w-3.5 h-3.5" />
                            <span>{savingIdx === imp.id ? 'Saving...' : 'Add to Action Plan'}</span>
                          </>
                        )}
                      </button>
                    </div>

                    {/* Direct Side-by-Side Evidence */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                      <div className="p-2.5 rounded-lg bg-rose-950/20 border border-rose-900/40">
                        <span className="text-[10px] font-bold uppercase text-rose-400 block">
                          Your Site ({target?.domain})
                        </span>
                        <span className="text-zinc-200">{imp.your_current}</span>
                      </div>
                      <div className="p-2.5 rounded-lg bg-sky-950/20 border border-sky-900/40">
                        <span className="text-[10px] font-bold uppercase text-sky-400 block">
                          Competitor Benchmark ({comp?.domain})
                        </span>
                        <span className="text-zinc-200">{imp.competitor_benchmark}</span>
                      </div>
                    </div>

                    <p className="text-xs text-zinc-400">{imp.why_it_matters}</p>

                    {Array.isArray(imp.action_steps) && imp.action_steps.length > 0 && (
                      <div className="space-y-1 pt-1 border-t border-zinc-800/70">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                          Exact Action Steps
                        </div>
                        <ul className="space-y-1 text-xs text-zinc-300 list-disc pl-4">
                          {imp.action_steps.map((step: string, idx: number) => (
                            <li key={idx}>{step}</li>
                          ))}
                        </ul>
                      </div>
                    )}

                    <div className="text-[11px] text-emerald-400 font-medium">
                      Expected Impact: {imp.expected_impact}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
