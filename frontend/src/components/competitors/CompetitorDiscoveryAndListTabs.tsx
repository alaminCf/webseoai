'use client';

import React, { useState, useEffect } from 'react';
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
  Filter,
  Link2,
  Tag,
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
import { countryFlag, getCountryName } from '@/lib/countries';
import { useToast } from '@/context/ToastContext';
import { CountryMultiSelect } from './CountryMultiSelect';

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
  countries: string[];
  onCountriesChange?: (countries: string[]) => void;
  language: string;
  competitors: CompetitorDomainItem[];
  onRefresh: () => void;
}

export function MyCompetitorsTab({
  website,
  country,
  countries,
  onCountriesChange,
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
  const [strictCountryMatch, setStrictCountryMatch] = useState(true);
  const [isDiscovering, setIsDiscovering] = useState(false);
  const [discoveryRun, setDiscoveryRun] = useState<CompetitorDiscoveryRun | null>(null);
  const [selectedDomains, setSelectedDomains] = useState<Record<string, boolean>>({});
  const [candidateOverrides, setCandidateOverrides] = useState<
    Record<string, { category: CompetitorCategory; tier: CompetitorTier }>
  >({});
  const [hidePortals, setHidePortals] = useState(true);
  const [isConfirming, setIsConfirming] = useState(false);
  const activeCountries = countries.length > 0 ? countries : [country || 'US'];

  const hydrateDiscoveryRun = (run: CompetitorDiscoveryRun) => {
    setDiscoveryRun(run);
    const preselected: Record<string, boolean> = {};
    const overrides: Record<string, { category: CompetitorCategory; tier: CompetitorTier }> = {};
    (run.candidates || []).forEach((c) => {
      overrides[c.domain] = {
        category: c.category,
        tier: c.category === 'direct' ? 'primary' : 'secondary',
      };
      if (!c.already_tracked && !c.is_portal_filtered && c.category === 'direct' && c.confidence >= 65) {
        preselected[c.domain] = true;
      }
    });
    setSelectedDomains(preselected);
    setCandidateOverrides(overrides);
  };

  useEffect(() => {
    let active = true;
    api
      .get<CompetitorDiscoveryRun[]>(`/websites/${website.id}/competitors/discoveries`)
      .then((res) => {
        if (active && res.success && Array.isArray(res.data) && res.data.length > 0) {
          hydrateDiscoveryRun(res.data[0]);
        } else if (active) {
          setDiscoveryRun(null);
        }
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [website.id]);

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

  const handleDiscover = async (forceRefresh = false) => {
    setIsDiscovering(true);
    try {
      const res = await api.post<CompetitorDiscoveryRun>(`/websites/${website.id}/competitors/discover`, {
        country: activeCountries[0],
        countries: activeCountries,
        language,
        search_engine: 'google',
        industry_context: industryHint.trim() || undefined,
        strict_country_match: strictCountryMatch,
        force_refresh: forceRefresh,
      });
      if (res.success && res.data) {
        hydrateDiscoveryRun(res.data);
        const total = (res.data.candidates || []).length;
        const directCount = (res.data.candidates || []).filter((c) => c.category === 'direct').length;
        if (total > 0) {
          toast.success(`Discovery complete: found ${total} candidate(s) (${directCount} direct rivals) for review.`);
        } else {
          toast.info('Discovery finished with 0 candidates. Check methodology notice below.');
        }
      } else {
        toast.error(res.error?.message || 'Competitor discovery failed.');
      }
    } finally {
      setIsDiscovering(false);
    }
  };

  const handleSelectAllDirect = () => {
    if (!discoveryRun) return;
    const next = { ...selectedDomains };
    (discoveryRun.candidates || []).forEach((c) => {
      if (!c.already_tracked && !c.is_portal_filtered && (candidateOverrides[c.domain]?.category ?? c.category) === 'direct') {
        next[c.domain] = true;
      }
    });
    setSelectedDomains(next);
  };

  const handleConfirmSelected = async () => {
    if (!discoveryRun) return;
    const chosen = (discoveryRun.candidates || [])
      .filter((c) => selectedDomains[c.domain] && !c.already_tracked)
      .map((c) => ({
        domain: c.domain,
        category: candidateOverrides[c.domain]?.category ?? c.category,
        tier: candidateOverrides[c.domain]?.tier ?? (c.category === 'direct' ? 'primary' : 'secondary'),
      }));

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
              <label className="block text-zinc-400 mb-1 font-medium">Target Countries (Single or Multi-Country, All 195 Countries)</label>
              {onCountriesChange ? (
                <CountryMultiSelect
                  selected={activeCountries}
                  onChange={onCountriesChange}
                  maxSelection={5}
                  className="w-full"
                />
              ) : (
                <div className="text-zinc-300 font-mono">
                  {activeCountries.map((c) => `${countryFlag(c)} ${c}`).join(', ')}
                </div>
              )}
            </div>

            <div>
              <label className="block text-zinc-400 mb-1 font-medium">Industry / Niche Context (Optional)</label>
              <input
                type="text"
                value={industryHint}
                onChange={(e) => setIndustryHint(e.target.value)}
                placeholder="e.g. Home nursing & caregiver service"
                className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 focus:outline-none focus:border-sky-500"
              />
            </div>

            <label className="flex items-center gap-2 text-[11px] text-zinc-300 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={strictCountryMatch}
                onChange={(e) => setStrictCountryMatch(e.target.checked)}
                className="accent-emerald-500 rounded"
              />
              <span>
                <strong>Exact Country Match Only</strong> — automatically exclude foreign-country domains (e.g. US/UK/IN sites when searching {activeCountries.map((c) => getCountryName(c)).join(', ')})
              </span>
            </label>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => handleDiscover(false)}
                disabled={isDiscovering}
                className="flex-1 py-2.5 px-4 bg-sky-600 hover:bg-sky-500 disabled:opacity-50 text-white font-semibold rounded-xl transition flex items-center justify-center gap-2"
              >
                <Sparkles className={`w-4 h-4 ${isDiscovering ? 'animate-spin' : ''}`} />
                <span>
                  {isDiscovering
                    ? 'Scanning Live Search...'
                    : `Discover Competitors (${activeCountries.map((c) => `${countryFlag(c)} ${c}`).join(' + ')} / ${language})`}
                </span>
              </button>
              {discoveryRun && (
                <button
                  type="button"
                  onClick={() => handleDiscover(true)}
                  disabled={isDiscovering}
                  title="Bypass cache and run fresh discovery"
                  className="px-3 py-2.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 rounded-xl transition flex items-center gap-1.5"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${isDiscovering ? 'animate-spin' : ''}`} />
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Discovery Candidate Review Panel (Requires Manual Confirmation) */}
      {discoveryRun && (() => {
        const allCandidates = discoveryRun.candidates || [];
        const directCount = allCandidates.filter((c) => c.category === 'direct').length;
        const organicOrContentCount = allCandidates.filter((c) => c.category === 'organic_search' || c.category === 'content').length;
        const portalCount = allCandidates.filter((c) => c.is_portal_filtered).length;
        const visibleCandidates = hidePortals ? allCandidates.filter((c) => !c.is_portal_filtered) : allCandidates;
        const selectedCount = allCandidates.filter((c) => selectedDomains[c.domain] && !c.already_tracked).length;

        return (
          <div className="p-5 bg-zinc-950 border border-sky-800/50 rounded-2xl space-y-4">
            <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
              <div className="space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <h4 className="text-sm font-bold text-zinc-100">
                    Discovery Results — Review &amp; Confirm ({allCandidates.length} candidates)
                  </h4>
                  {directCount > 0 && (
                    <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                      {directCount} Direct Rivals
                    </span>
                  )}
                  {organicOrContentCount > 0 && (
                    <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-sky-500/15 text-sky-400 border border-sky-500/30">
                      {organicOrContentCount} Organic / Content
                    </span>
                  )}
                  {portalCount > 0 && (
                    <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">
                      {portalCount} Portals Filtered
                    </span>
                  )}
                </div>
                <p className="text-[11px] text-zinc-400 leading-relaxed">
                  <strong>Methodology ({discoveryRun.provider}):</strong> {discoveryRun.methodology}
                </p>
              </div>

              {allCandidates.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 shrink-0">
                  {directCount > 0 && (
                    <button
                      type="button"
                      onClick={handleSelectAllDirect}
                      className="px-3 py-2 text-[11px] font-semibold text-zinc-200 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
                    >
                      Select All Direct ({directCount})
                    </button>
                  )}
                  {portalCount > 0 && (
                    <button
                      type="button"
                      onClick={() => setHidePortals((prev) => !prev)}
                      className={`px-3 py-2 text-[11px] font-semibold rounded-xl border transition inline-flex items-center gap-1.5 ${
                        hidePortals
                          ? 'bg-amber-500/10 text-amber-300 border-amber-500/30'
                          : 'bg-zinc-900 text-zinc-300 border-zinc-800'
                      }`}
                    >
                      <Filter className="w-3 h-3" />
                      <span>{hidePortals ? `Show Portals (${portalCount})` : `Hide Portals (${portalCount})`}</span>
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={handleConfirmSelected}
                    disabled={isConfirming || selectedCount === 0}
                    className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition"
                  >
                    {isConfirming ? 'Confirming...' : `Confirm & Add Selected (${selectedCount})`}
                  </button>
                </div>
              )}
            </div>

            {allCandidates.length === 0 ? (
              <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs text-zinc-300 flex items-start gap-2.5">
                <AlertCircle className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                <div className="space-y-1">
                  <div className="font-semibold text-zinc-200">No external SERP candidates returned for this run</div>
                  <p className="text-zinc-400">
                    {discoveryRun.error_message || 'Enter a specific Industry / Niche phrase above (e.g. "home nursing caregiver service Bangladesh") and retry, or add a free SERPER_API_KEY (2,500 free Google searches) or DATAFORSEO credentials in Vercel Environment Variables.'}
                  </p>
                </div>
              </div>
            ) : visibleCandidates.length === 0 ? (
              <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 text-xs text-zinc-400 flex items-center justify-between gap-2">
                <span>All {portalCount} discovered domains were broad portals/directories and are currently hidden.</span>
                <button
                  type="button"
                  onClick={() => setHidePortals(false)}
                  className="text-sky-400 hover:text-sky-300 font-semibold"
                >
                  Show Filtered Portals
                </button>
              </div>
            ) : (
              <div className="space-y-2.5">
                {visibleCandidates.map((cand) => {
                  const activeCategory = candidateOverrides[cand.domain]?.category ?? cand.category;
                  const activeTier = candidateOverrides[cand.domain]?.tier ?? (cand.category === 'direct' ? 'primary' : 'secondary');
                  const isChecked = Boolean(selectedDomains[cand.domain]) || cand.already_tracked;

                  return (
                    <div
                      key={cand.domain}
                      className={`p-4 rounded-xl border transition ${
                        isChecked
                          ? 'bg-emerald-950/15 border-emerald-700/50'
                          : 'bg-zinc-900/60 border-zinc-800 hover:border-zinc-700'
                      }`}
                    >
                      <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
                        <div className="flex items-start gap-3 flex-1 min-w-0">
                          <input
                            type="checkbox"
                            disabled={cand.already_tracked}
                            checked={isChecked}
                            onChange={(e) =>
                              setSelectedDomains((prev) => ({ ...prev, [cand.domain]: e.target.checked }))
                            }
                            className="mt-1 h-4 w-4 accent-emerald-500 cursor-pointer shrink-0"
                          />
                          <div className="space-y-1.5 flex-1 min-w-0">
                            {/* Header line: Domain + Badges */}
                            <div className="flex flex-wrap items-center gap-2">
                              <a
                                href={`https://${cand.domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs font-bold text-zinc-100 hover:text-emerald-400 inline-flex items-center gap-1"
                              >
                                {cand.domain}
                                <ExternalLink className="w-3 h-3 text-zinc-500" />
                              </a>
                              <span
                                className={`text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full border ${
                                  activeCategory === 'direct'
                                    ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                    : activeCategory === 'organic_search'
                                    ? 'bg-sky-500/15 text-sky-400 border-sky-500/30'
                                    : activeCategory === 'content'
                                    ? 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                                    : 'bg-zinc-900 text-zinc-400 border-zinc-700'
                                }`}
                              >
                                {activeCategory.replace('_', ' ')}
                              </span>
                              <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-emerald-400">
                                Confidence {cand.confidence}%
                              </span>
                              {(cand.matched_countries && cand.matched_countries.length > 0) && (
                                <span
                                  className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                                    cand.country_verified
                                      ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                                      : 'bg-zinc-900 text-zinc-400 border-zinc-700'
                                  }`}
                                >
                                  {cand.matched_countries.map((cc) => `${countryFlag(cc)} ${cc}`).join(', ')}
                                  {cand.country_verified ? ' Verified' : ''}
                                </span>
                              )}
                              {cand.avg_position != null && (
                                <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-sky-300">
                                  Avg Rank #{cand.avg_position}
                                </span>
                              )}
                              {cand.serp_overlap_pct != null && (
                                <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-zinc-950 border border-zinc-800 text-zinc-300">
                                  {cand.serp_overlap_pct}% overlap
                                </span>
                              )}
                              {cand.is_portal_filtered && (
                                <span className="text-[10px] px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                  Portal Filtered
                                </span>
                              )}
                              {cand.already_tracked && (
                                <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                  Already Tracked
                                </span>
                              )}
                            </div>

                            {/* Observed Page Title & Snippet */}
                            {cand.sample_title && (
                              <div className="text-xs font-medium text-zinc-200 leading-snug">
                                {cand.sample_title}
                              </div>
                            )}
                            {cand.sample_snippet && (
                              <p className="text-[11px] text-zinc-400 leading-relaxed line-clamp-2">
                                {cand.sample_snippet}
                              </p>
                            )}

                            {/* Matched Queries & Niche Terms */}
                            {((cand.matched_queries && cand.matched_queries.length > 0) ||
                              (cand.matched_service_terms && cand.matched_service_terms.length > 0)) && (
                              <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                {(cand.matched_queries || []).map((q) => (
                                  <span
                                    key={q}
                                    className="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-md bg-sky-500/10 text-sky-300 border border-sky-500/20"
                                  >
                                    <Search className="w-2.5 h-2.5" />
                                    {q}
                                  </span>
                                ))}
                                {(cand.matched_service_terms || []).slice(0, 5).map((term) => (
                                  <span
                                    key={term}
                                    className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-md bg-zinc-950 text-zinc-400 border border-zinc-800"
                                  >
                                    <Tag className="w-2.5 h-2.5 text-emerald-500" />
                                    {term}
                                  </span>
                                ))}
                              </div>
                            )}

                            {/* Ranking Sample URLs */}
                            {cand.sample_urls && cand.sample_urls.length > 0 && (
                              <div className="flex flex-wrap items-center gap-2 pt-0.5">
                                {cand.sample_urls.slice(0, 2).map((u) => (
                                  <a
                                    key={u}
                                    href={u}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-[10px] font-mono text-zinc-400 hover:text-emerald-400 truncate max-w-md"
                                  >
                                    <Link2 className="w-2.5 h-2.5 shrink-0" />
                                    <span className="truncate">{u}</span>
                                  </a>
                                ))}
                              </div>
                            )}

                            {/* Relevance Rationale */}
                            <p className="text-[11px] text-zinc-500">{cand.relevance_reason}</p>
                          </div>
                        </div>

                        {/* Inline Category & Tier Controls before Confirm */}
                        <div className="flex flex-wrap sm:flex-nowrap items-center gap-2 shrink-0 pl-7 lg:pl-0">
                          {!cand.already_tracked && (
                            <>
                              <select
                                value={activeCategory}
                                onChange={(e) =>
                                  setCandidateOverrides((prev) => ({
                                    ...prev,
                                    [cand.domain]: {
                                      category: e.target.value as CompetitorCategory,
                                      tier: activeTier,
                                    },
                                  }))
                                }
                                className="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-1 text-[11px] text-zinc-200"
                              >
                                <option value="direct">Direct</option>
                                <option value="organic_search">Organic Search</option>
                                <option value="content">Content</option>
                                <option value="indirect">Indirect</option>
                              </select>
                              <select
                                value={activeTier}
                                onChange={(e) =>
                                  setCandidateOverrides((prev) => ({
                                    ...prev,
                                    [cand.domain]: {
                                      category: activeCategory,
                                      tier: e.target.value as CompetitorTier,
                                    },
                                  }))
                                }
                                className="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-1 text-[11px] text-zinc-200"
                              >
                                <option value="primary">Primary</option>
                                <option value="secondary">Secondary</option>
                              </select>
                            </>
                          )}
                          <span className="text-[10px] font-mono text-zinc-500 whitespace-nowrap">
                            {cand.shared_keywords !== null ? `${cand.shared_keywords} query match` : cand.source}
                          </span>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        );
      })()}

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
