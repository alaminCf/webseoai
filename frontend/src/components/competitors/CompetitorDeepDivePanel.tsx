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
  Target,
  Search,
  Gauge,
} from 'lucide-react';
import { Website, CompetitorDomainItem } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface CompetitorDeepDivePanelProps {
  website: Website;
  competitors: CompetitorDomainItem[];
  selectedCompetitorId: number;
  onSelectCompetitor: (id: number) => void;
  onClose: () => void;
  onNavigateToActionPlan?: () => void;
  onDataUpdated?: () => void;
}

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
  const [savingIdx, setSavingIdx] = useState<string | null>(null);
  const [savedIds, setSavedIds] = useState<Record<string, boolean>>({});

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
            toast.success('Live competitor data & comparison refreshed!');
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

  if (isLoading && !data) {
    return (
      <div className="p-10 bg-zinc-950 border border-zinc-800 rounded-2xl text-center space-y-3">
        <RefreshCw className="w-6 h-6 text-emerald-400 animate-spin mx-auto" />
        <div className="text-sm font-semibold text-zinc-100">
          Inspecting Competitor Live Data &amp; Comparing Head-to-Head...
        </div>
        <p className="text-xs text-zinc-400 max-w-md mx-auto">
          Auditing homepage HTML, Schema.org markup, load speed, content depth, Google-indexed pages, public mentions, and keyword gaps against {website.domain}.
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
          Back to Overview
        </button>
      </div>
    );
  }

  const target = data.target;
  const comp = data.competitor;
  const summary = data.summary || {};
  const scorecard: any[] = data.scorecard || [];
  const improvements: any[] = data.improvements || [];
  const profile = data.competitor_profile || {};
  const keywordBattles: any[] = data.keyword_battles || [];
  const citationOpps: any[] = data.citation_opportunities || [];

  const tScore = target?.seo_score;
  const cScore = comp?.seo_score;

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      {/* Top Bar: Back + Competitor Switcher + Live Re-Scan */}
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
          <span className="text-xs font-semibold text-zinc-400">Inspect Competitor:</span>

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

      {/* Head-to-Head Hero Scorecard */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-5">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-center">
          {/* Your Website */}
          <div className="p-4 rounded-2xl bg-emerald-950/20 border border-emerald-800/40 space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Your Website</span>
              <span className="text-[10px] font-mono text-zinc-400">Target</span>
            </div>
            <div className="text-base font-bold text-zinc-100 truncate">{target?.domain}</div>
            <div className="flex items-baseline gap-2">
              <span className="text-3xl font-extrabold font-mono text-emerald-400">
                {tScore != null ? `${tScore}` : '—'}
              </span>
              <span className="text-xs text-zinc-400">/ 100 On-Page SEO Score</span>
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
          <div className="p-4 rounded-2xl bg-sky-950/20 border border-sky-800/40 space-y-2">
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
              <span className="text-xs text-zinc-400">/ 100 On-Page SEO Score</span>
            </div>
          </div>
        </div>
      </div>

      {/* SECTION 1: PRIORITIZED IMPROVEMENT ROADMAP ("Where You Need to Improve") */}
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

      {/* SECTION 2: FULL SIDE-BY-SIDE METRIC SCORECARD */}
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

      {/* SECTION 3: COMPETITOR CONTENT BLUEPRINT & TOP PAGES */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Competitor Homepage SEO Profile & H2 Outline */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Layers className="w-4 h-4 text-purple-400" />
            {comp?.domain} — On-Page Blueprint
          </h3>

          <div className="space-y-3 text-xs">
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
                <div className="text-[10px] uppercase font-bold text-zinc-400 mb-1.5">Detected Schema.org Types</div>
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
            <div className="space-y-2 max-h-[420px] overflow-y-auto pr-1">
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

      {/* SECTION 4: GOOGLE KEYWORD BATTLES & PUBLIC CITATION OPPORTUNITIES */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Keyword Battles */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Search className="w-4 h-4 text-sky-400" />
            Google Keyword Battles ({keywordBattles.length})
          </h3>
          {keywordBattles.length === 0 ? (
            <p className="text-xs text-zinc-400">
              No head-to-head Google keyword battles stored yet for {comp?.domain}. Click &ldquo;Re-Scan Live Data&rdquo; or run SERP Analysis to compare exact rankings.
            </p>
          ) : (
            <div className="space-y-2">
              {keywordBattles.map((kb: any) => (
                <div key={kb.id} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between gap-3 text-xs">
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

        {/* Public Web Mentions & Outreach Citations */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Link2 className="w-4 h-4 text-amber-400" />
            Public Web Mentions &amp; Citation Opportunities ({citationOpps.length})
          </h3>
          {citationOpps.length === 0 ? (
            <p className="text-xs text-zinc-400">
              No unshared third-party Google brand mentions detected yet for {comp?.domain}. (Powered automatically when Serper.dev is connected.)
            </p>
          ) : (
            <div className="space-y-2 max-h-72 overflow-y-auto pr-1">
              {citationOpps.map((m: any, i: number) => (
                <div key={i} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1 text-xs">
                  <div className="flex items-center justify-between gap-2">
                    <a
                      href={m.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="font-semibold text-zinc-100 hover:text-amber-400 inline-flex items-center gap-1"
                    >
                      <Globe className="w-3 h-3 text-amber-400 shrink-0" />
                      <span>{m.domain}</span>
                      <ExternalLink className="w-3 h-3 text-zinc-500" />
                    </a>
                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-300 border border-amber-500/20">
                      Get Listed
                    </span>
                  </div>
                  {m.title && <div className="text-[11px] text-zinc-300 truncate">{m.title}</div>}
                  {m.snippet && <p className="text-[10px] text-zinc-500 line-clamp-2">{m.snippet}</p>}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
