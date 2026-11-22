'use client';

import React, { useState } from 'react';
import {
  Globe,
  Plus,
  Sparkles,
  Trash2,
  CheckCircle2,
  AlertCircle,
  Search,
  Star,
  ArrowRight,
  ExternalLink,
  RefreshCw,
  ShieldCheck,
  Info,
} from 'lucide-react';
import {
  Website,
  CompetitorDomainItem,
  CompetitorDiscoveryRun,
  CompetitorCategory,
  CompetitorTier,
  CompetitorProviderStatus,
} from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface OverviewTabProps {
  website: Website;
  overview: any;
  isLoading: boolean;
  isAnalyzing: boolean;
  onLaunchAnalysis: () => void;
  onSwitchSubTab: (tab: string) => void;
  onRefresh: () => void;
}

export function CompetitorOverviewTab({
  website,
  overview,
  isLoading,
  isAnalyzing,
  onLaunchAnalysis,
  onSwitchSubTab,
  onRefresh,
}: OverviewTabProps) {
  if (isLoading && !overview) {
    return (
      <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-xs text-zinc-400">
        Loading competitor intelligence overview...
      </div>
    );
  }

  const competitorsCount = overview?.competitors_count ?? 0;
  const kwSummary = overview?.keyword_gap_summary ?? { total: 0, missing: 0, weak: 0, shared: 0, strong: 0, untapped: 0 };
  const contentGapsOpen = overview?.content_gaps_open ?? 0;
  const targetSnap = overview?.target_snapshot;
  const providers: CompetitorProviderStatus | undefined = overview?.providers;

  const steps = [
    { num: 1, label: 'Website Selected', done: true, tab: 'overview' },
    { num: 2, label: 'Discover / Add Rivals', done: competitorsCount > 0, tab: 'my_competitors' },
    { num: 3, label: 'Run Analysis', done: Boolean(targetSnap), tab: 'comparison' },
    { num: 4, label: 'Explore Gaps', done: kwSummary.total > 0 || contentGapsOpen > 0, tab: 'keyword_gap' },
    { num: 5, label: 'AI Strategy → Action Plan', done: Boolean(overview?.latest_strategy_report), tab: 'ai_strategy' },
  ];

  return (
    <div className="space-y-6">
      {/* Guided Beginner Workflow Strip */}
      <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
          <span className="text-xs font-bold text-zinc-200 flex items-center gap-1.5">
            <Sparkles className="w-3.5 h-3.5 text-emerald-400" />
            Guided Competitor Intelligence Workflow
          </span>
          <span className="text-[11px] text-zinc-400">
            Target: <strong className="text-zinc-200 font-mono">{website.domain}</strong>
          </span>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">
          {steps.map((s) => (
            <button
              key={s.num}
              type="button"
              onClick={() => onSwitchSubTab(s.tab)}
              className={`p-2.5 rounded-xl border text-left transition ${
                s.done
                  ? 'bg-emerald-950/30 border-emerald-800/50 text-emerald-300'
                  : 'bg-zinc-900/60 border-zinc-800 text-zinc-400 hover:border-zinc-700'
              }`}
            >
              <div className="flex items-center justify-between text-[10px] font-mono mb-1">
                <span>STEP {s.num}</span>
                {s.done && <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />}
              </div>
              <div className="text-xs font-semibold truncate">{s.label}</div>
            </button>
          ))}
        </div>
      </div>

      {/* Executive KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-400">Tracked Competitors</div>
          <div className="text-3xl font-extrabold text-zinc-100 font-mono mt-1">{competitorsCount}</div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {overview?.primary_competitors_count ?? 0} Primary • Max 5 compared at once
          </div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-400">Keyword Gap Opportunities</div>
          <div className="text-3xl font-extrabold text-emerald-400 font-mono mt-1">
            {kwSummary.missing + kwSummary.weak + kwSummary.untapped}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {kwSummary.missing} Missing • {kwSummary.weak} Weak • {kwSummary.untapped} Untapped
          </div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-400">Content Gap Topics</div>
          <div className="text-3xl font-extrabold text-purple-400 font-mono mt-1">{contentGapsOpen}</div>
          <div className="text-[11px] text-zinc-400 mt-1">Evidence-checked against site crawl</div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-400">Target Organic Estimate</div>
          <div className="text-2xl font-extrabold text-zinc-100 font-mono mt-1">
            {targetSnap?.organic_traffic_estimate !== null && targetSnap?.organic_traffic_estimate !== undefined
              ? targetSnap.organic_traffic_estimate.toLocaleString()
              : 'Data unavailable'}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {targetSnap?.crawled_signals?.gsc_28d_clicks !== undefined
              ? `Verified GSC 28d clicks: ${targetSnap.crawled_signals.gsc_28d_clicks.toLocaleString()}`
              : targetSnap?.data_source
              ? `Source: ${targetSnap.data_source}`
              : 'Run analysis to capture baseline'}
          </div>
        </div>
      </div>

      {/* Data Provenance Notice */}
      <div className="p-4 rounded-2xl bg-zinc-900/60 border border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
        <div className="flex items-start gap-2.5">
          <Info className="w-4 h-4 text-sky-400 shrink-0 mt-0.5" />
          <div className="space-y-0.5">
            <div className="font-semibold text-zinc-200">Data Integrity &amp; Source Attribution</div>
            <p className="text-zinc-400">
              First-party crawl &amp; Google Search Console metrics are marked <strong>Verified First-Party</strong>. Third-party traffic, keyword volume, difficulty, and backlink metrics appear only when DataForSEO, Semrush, Ahrefs, or SerpApi credentials are configured — otherwise shown honestly as <em>&ldquo;Data unavailable&rdquo;</em>.
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {competitorsCount === 0 ? (
            <button
              onClick={() => onSwitchSubTab('my_competitors')}
              className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl transition flex items-center gap-1.5"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Add Competitors</span>
            </button>
          ) : (
            <button
              onClick={onLaunchAnalysis}
              disabled={isAnalyzing}
              className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition flex items-center gap-1.5"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} />
              <span>{isAnalyzing ? 'Running Analysis...' : 'Run Full Analysis'}</span>
            </button>
          )}
        </div>
      </div>

      {/* Tracked Competitors Snapshot Grid */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-bold text-zinc-100">Tracked Competitors</h3>
          <button
            onClick={() => onSwitchSubTab('my_competitors')}
            className="text-xs text-emerald-400 hover:text-emerald-300 font-medium inline-flex items-center gap-1"
          >
            Manage Competitors <ArrowRight className="w-3.5 h-3.5" />
          </button>
        </div>

        {competitorsCount === 0 ? (
          <div className="p-8 text-center border border-dashed border-zinc-800 rounded-xl space-y-3">
            <Globe className="w-7 h-7 text-zinc-500 mx-auto" />
            <div className="text-sm font-semibold text-zinc-200">No Competitors Tracked Yet</div>
            <p className="text-xs text-zinc-400 max-w-md mx-auto">
              Add rival domains manually or run Intelligent Competitor Discovery to start comparing domain metrics, keyword gaps, and content opportunities.
            </p>
            <button
              onClick={() => onSwitchSubTab('my_competitors')}
              className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl"
            >
              <Plus className="w-3.5 h-3.5" /> Add or Discover Competitors
            </button>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {(overview?.competitors ?? []).map((comp: CompetitorDomainItem) => {
              const snap = comp.latest_snapshot;
              return (
                <div key={comp.id} className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-2.5">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <div className="text-xs font-bold text-zinc-100 flex items-center gap-1.5">
                        {comp.domain}
                        {comp.tier === 'primary' && <Star className="w-3 h-3 text-amber-400 fill-amber-400" />}
                      </div>
                      <div className="text-[10px] text-zinc-400 capitalize mt-0.5">
                        {comp.category.replace('_', ' ')} • {comp.country}/{comp.language}
                      </div>
                    </div>
                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-zinc-400 font-mono">
                      {snap?.data_source || 'pending'}
                    </span>
                  </div>
                  <div className="grid grid-cols-2 gap-2 text-[11px] pt-1 border-t border-zinc-800/70">
                    <div>
                      <span className="text-zinc-500 block">Est. Traffic</span>
                      <span className="font-mono font-semibold text-zinc-200">
                        {snap?.organic_traffic_estimate != null ? snap.organic_traffic_estimate.toLocaleString() : 'Data unavailable'}
                      </span>
                    </div>
                    <div>
                      <span className="text-zinc-500 block">Ref. Domains</span>
                      <span className="font-mono font-semibold text-zinc-200">
                        {snap?.referring_domains_count != null ? snap.referring_domains_count.toLocaleString() : 'Data unavailable'}
                      </span>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Connected Provider Quick Status */}
      {providers && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-emerald-400" />
              SEO Data Provider Integrations
            </h3>
            <button onClick={onRefresh} className="text-xs text-zinc-400 hover:text-zinc-200">
              Refresh Status
            </button>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {providers.providers.map((p) => (
              <div key={p.id} className="p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-1.5 text-xs">
                <div className="flex items-center justify-between">
                  <span className="font-semibold text-zinc-200">{p.label}</span>
                  <span
                    className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                      p.configured
                        ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                        : 'bg-zinc-800 text-zinc-400'
                    }`}
                  >
                    {p.configured ? 'Connected' : 'Not Configured'}
                  </span>
                </div>
                <div className="text-[10px] text-zinc-400">
                  Capabilities: {p.capabilities.join(', ')}
                </div>
                {!p.configured && (
                  <div className="text-[10px] font-mono text-amber-400/90 break-all">
                    Env: {p.required_env_vars.join(', ')}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

interface MyCompetitorsTabProps {
  website: Website;
  country: string;
  language: string;
  competitors: CompetitorDomainItem[];
  onRefresh: () => void;
}

export function MyCompetitorsTab({
  website,
  country,
  language,
  competitors,
  onRefresh,
}: MyCompetitorsTabProps) {
  const toast = useToast();
  const [domainInput, setDomainInput] = useState('');
  const [category, setCategory] = useState<CompetitorCategory>('direct');
  const [tier, setTier] = useState<CompetitorTier>('primary');
  const [isAdding, setIsAdding] = useState(false);

  // Discovery state
  const [industryHint, setIndustryHint] = useState('');
  const [isDiscovering, setIsDiscovering] = useState(false);
  const [discoveryRun, setDiscoveryRun] = useState<CompetitorDiscoveryRun | null>(null);
  const [selectedDomains, setSelectedDomains] = useState<Record<string, boolean>>({});
  const [isConfirming, setIsConfirming] = useState(false);

  const handleManualAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!domainInput.trim()) return;
    setIsAdding(true);
    try {
      const res = await api.post<CompetitorDomainItem>(`/websites/${website.id}/competitors`, {
        domain: domainInput.trim(),
        category,
        tier,
        country,
        language,
      });
      if (res.success) {
        toast.success(`Added competitor ${res.data?.domain}!`);
        setDomainInput('');
        onRefresh();
      } else {
        toast.error(res.error?.message || 'Could not add competitor.');
      }
    } finally {
      setIsAdding(false);
    }
  };

  const handleDiscover = async () => {
    setIsDiscovering(true);
    try {
      const res = await api.post<CompetitorDiscoveryRun>(`/websites/${website.id}/competitors/discover`, {
        country,
        language,
        search_engine: 'google',
        industry_context: industryHint.trim() || undefined,
      });
      if (res.success && res.data) {
        setDiscoveryRun(res.data);
        const preselected: Record<string, boolean> = {};
        (res.data.candidates || []).forEach((c) => {
          if (!c.already_tracked && !c.is_portal_filtered && c.confidence >= 60) {
            preselected[c.domain] = true;
          }
        });
        setSelectedDomains(preselected);
        toast.success(`Discovery complete: found ${(res.data.candidates || []).length} candidate domain(s) for review.`);
      } else {
        toast.error(res.error?.message || 'Competitor discovery failed.');
      }
    } finally {
      setIsDiscovering(false);
    }
  };

  const handleConfirmSelected = async () => {
    if (!discoveryRun) return;
    const chosen = (discoveryRun.candidates || [])
      .filter((c) => selectedDomains[c.domain] && !c.already_tracked)
      .map((c) => ({ domain: c.domain, category: c.category, tier: 'primary' }));

    if (chosen.length === 0) {
      toast.error('Select at least one candidate domain to confirm.');
      return;
    }

    setIsConfirming(true);
    try {
      const res = await api.post<{ added_count: number; discovery_run: CompetitorDiscoveryRun }>(
        `/websites/${website.id}/competitors/discoveries/${discoveryRun.id}/confirm`,
        { selected: chosen }
      );
      if (res.success && res.data) {
        toast.success(`Confirmed and added ${res.data.added_count} competitor(s).`);
        setDiscoveryRun(res.data.discovery_run);
        setSelectedDomains({});
        onRefresh();
      } else {
        toast.error(res.error?.message || 'Could not confirm competitors.');
      }
    } finally {
      setIsConfirming(false);
    }
  };

  const handleUpdateComp = async (comp: CompetitorDomainItem, patch: Partial<CompetitorDomainItem>) => {
    const res = await api.patch(`/websites/${website.id}/competitors/${comp.id}`, patch);
    if (res.success) {
      toast.success(`Updated ${comp.domain}.`);
      onRefresh();
    } else {
      toast.error(res.error?.message || 'Update failed.');
    }
  };

  const handleDeleteComp = async (comp: CompetitorDomainItem) => {
    if (!confirm(`Remove ${comp.domain} and its snapshots from tracked competitors?`)) return;
    const res = await api.delete(`/websites/${website.id}/competitors/${comp.id}`);
    if (res.success) {
      toast.info(`Removed ${comp.domain}.`);
      onRefresh();
    } else {
      toast.error(res.error?.message || 'Could not remove competitor.');
    }
  };

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Manual Add Card */}
        <form onSubmit={handleManualAdd} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
          <div>
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <Plus className="w-4 h-4 text-emerald-400" />
              Add Competitor Manually
            </h3>
            <p className="text-xs text-zinc-400 mt-0.5">
              Enter any competing website domain and classify its relationship to {website.domain}.
            </p>
          </div>

          <div className="space-y-3 text-xs">
            <div>
              <label className="block text-zinc-400 mb-1 font-medium">Competitor Domain *</label>
              <input
                type="text"
                required
                value={domainInput}
                onChange={(e) => setDomainInput(e.target.value)}
                placeholder="e.g. rival-company.com"
                className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-emerald-500"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-zinc-400 mb-1 font-medium">Category</label>
                <select
                  value={category}
                  onChange={(e) => setCategory(e.target.value as CompetitorCategory)}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-200 focus:outline-none"
                >
                  <option value="direct">Direct Business Competitor</option>
                  <option value="organic_search">Organic Search Competitor</option>
                  <option value="content">Content Competitor</option>
                  <option value="indirect">Indirect Competitor</option>
                </select>
              </div>
              <div>
                <label className="block text-zinc-400 mb-1 font-medium">Priority Tier</label>
                <select
                  value={tier}
                  onChange={(e) => setTier(e.target.value as CompetitorTier)}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-200 focus:outline-none"
                >
                  <option value="primary">Primary Competitor</option>
                  <option value="secondary">Secondary Competitor</option>
                </select>
              </div>
            </div>

            <button
              type="submit"
              disabled={isAdding}
              className="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl transition"
            >
              {isAdding ? 'Adding...' : 'Add Competitor Domain'}
            </button>
          </div>
        </form>

        {/* Intelligent Auto-Discovery Card */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
          <div>
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <Search className="w-4 h-4 text-sky-400" />
              Intelligent Competitor Discovery
            </h3>
            <p className="text-xs text-zinc-400 mt-0.5">
              Discovers rivals using {country}/{language} SERP overlap, shared keywords &amp; industry context while filtering out generic directories/portals. You confirm before any domain is added.
            </p>
          </div>

          <div className="space-y-3 text-xs">
            <div>
              <label className="block text-zinc-400 mb-1 font-medium">Industry / Niche Context (Optional)</label>
              <input
                type="text"
                value={industryHint}
                onChange={(e) => setIndustryHint(e.target.value)}
                placeholder="e.g. Home healthcare services in Bangladesh"
                className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-sky-500"
              />
            </div>

            <button
              type="button"
              onClick={handleDiscover}
              disabled={isDiscovering}
              className="w-full py-2.5 px-4 bg-sky-600 hover:bg-sky-500 disabled:opacity-50 text-white font-semibold rounded-xl transition flex items-center justify-center gap-2"
            >
              <Sparkles className={`w-4 h-4 ${isDiscovering ? 'animate-spin' : ''}`} />
              <span>{isDiscovering ? 'Discovering Competitors...' : `Discover Competitors (${country} / ${language})`}</span>
            </button>
          </div>
        </div>
      </div>

      {/* Discovery Candidate Review Panel (Requires Manual Confirmation) */}
      {discoveryRun && (
        <div className="p-5 bg-zinc-950 border border-sky-800/50 rounded-2xl space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h4 className="text-sm font-bold text-zinc-100">
                Discovery Results — Review &amp; Confirm ({discoveryRun.candidates?.length || 0} candidates)
              </h4>
              <p className="text-[11px] text-zinc-400 mt-0.5">
                <strong>Methodology ({discoveryRun.provider}):</strong> {discoveryRun.methodology}
              </p>
            </div>
            {(discoveryRun.candidates || []).length > 0 && (
              <button
                type="button"
                onClick={handleConfirmSelected}
                disabled={isConfirming}
                className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition"
              >
                {isConfirming ? 'Confirming...' : 'Confirm & Add Selected'}
              </button>
            )}
          </div>

          {(discoveryRun.candidates || []).length === 0 ? (
            <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs text-zinc-400 flex items-start gap-2">
              <AlertCircle className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
              <span>
                No external SERP overlap candidates returned ({discoveryRun.error_message || 'connect DataForSEO, Semrush, or SerpApi to enable automated discovery, or add competitors manually above'}).
              </span>
            </div>
          ) : (
            <div className="space-y-2">
              {discoveryRun.candidates.map((cand) => (
                <label
                  key={cand.domain}
                  className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-xl bg-zinc-900/60 border border-zinc-800 hover:border-zinc-700 cursor-pointer"
                >
                  <div className="flex items-start gap-3">
                    <input
                      type="checkbox"
                      disabled={cand.already_tracked}
                      checked={Boolean(selectedDomains[cand.domain]) || cand.already_tracked}
                      onChange={(e) =>
                        setSelectedDomains((prev) => ({ ...prev, [cand.domain]: e.target.checked }))
                      }
                      className="mt-1 accent-emerald-500"
                    />
                    <div className="space-y-0.5">
                      <div className="text-xs font-bold text-zinc-100 flex flex-wrap items-center gap-2">
                        <span>{cand.domain}</span>
                        <span className="text-[10px] uppercase px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-700 text-zinc-300">
                          {cand.category.replace('_', ' ')}
                        </span>
                        <span className="text-[10px] font-mono text-emerald-400">
                          Confidence: {cand.confidence}%
                        </span>
                        {cand.is_portal_filtered && (
                          <span className="text-[10px] px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">
                            Portal Filtered
                          </span>
                        )}
                        {cand.already_tracked && (
                          <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400">
                            Already Tracked
                          </span>
                        )}
                      </div>
                      <p className="text-[11px] text-zinc-400">{cand.relevance_reason}</p>
                    </div>
                  </div>
                  <div className="text-[11px] font-mono text-zinc-400 shrink-0">
                    {cand.shared_keywords !== null ? `${cand.shared_keywords} shared kw` : 'Profile match'}
                  </div>
                </label>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Tracked Competitors Management Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="p-4 border-b border-zinc-800 flex items-center justify-between">
          <h3 className="text-sm font-bold text-zinc-100">Tracked Competitors ({competitors.length})</h3>
        </div>
        {competitors.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-400">
            No competitors added yet. Use the manual form or intelligent discovery above.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[11px] uppercase">
                <tr>
                  <th className="px-4 py-3">Domain</th>
                  <th className="px-4 py-3">Category</th>
                  <th className="px-4 py-3">Tier</th>
                  <th className="px-4 py-3">Discovery</th>
                  <th className="px-4 py-3">Last Analyzed</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {competitors.map((comp) => (
                  <tr key={comp.id} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-3">
                      <a
                        href={`https://${comp.domain}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-semibold text-zinc-100 hover:text-emerald-400 inline-flex items-center gap-1"
                      >
                        {comp.domain}
                        <ExternalLink className="w-3 h-3 text-zinc-500" />
                      </a>
                    </td>
                    <td className="px-4 py-3">
                      <select
                        value={comp.category}
                        onChange={(e) => handleUpdateComp(comp, { category: e.target.value as CompetitorCategory })}
                        className="bg-zinc-900 border border-zinc-800 rounded-lg px-2 py-1 text-xs text-zinc-200"
                      >
                        <option value="direct">Direct</option>
                        <option value="organic_search">Organic Search</option>
                        <option value="content">Content</option>
                        <option value="indirect">Indirect</option>
                      </select>
                    </td>
                    <td className="px-4 py-3">
                      <button
                        type="button"
                        onClick={() =>
                          handleUpdateComp(comp, {
                            tier: comp.tier === 'primary' ? 'secondary' : 'primary',
                          })
                        }
                        className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold capitalize border transition ${
                          comp.tier === 'primary'
                            ? 'bg-amber-500/15 text-amber-400 border-amber-500/30'
                            : 'bg-zinc-900 text-zinc-400 border-zinc-800'
                        }`}
                      >
                        {comp.tier}
                      </button>
                    </td>
                    <td className="px-4 py-3 text-zinc-400 font-mono text-[11px]">
                      {comp.discovery_source}
                      {comp.discovery_confidence != null ? ` (${comp.discovery_confidence}%)` : ''}
                    </td>
                    <td className="px-4 py-3 text-zinc-400 text-[11px]">
                      {comp.last_analyzed_at ? new Date(comp.last_analyzed_at).toLocaleString() : 'Not analyzed yet'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        onClick={() => handleDeleteComp(comp)}
                        className="p-1.5 text-zinc-400 hover:text-rose-400 hover:bg-zinc-900 rounded-lg transition"
                        title="Remove Competitor"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
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
