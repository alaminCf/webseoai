'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Search,
  Sparkles,
  Bookmark,
  BookmarkCheck,
  Download,
  SlidersHorizontal,
  Crosshair,
  FileText,
  CheckSquare,
  Trash2,
  Eye,
  TrendingUp,
  TrendingDown,
  Minus,
  Layers,
  Clock,
  Zap,
  Globe,
  RefreshCw,
  ArrowUpRight,
  X,
  AlertTriangle,
} from 'lucide-react';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { Website } from '@/types';
import {
  KiKeyword,
  KiOverviewData,
  KiSeedRun,
  INTENT_STYLES,
  OPP_CATEGORY_STYLES,
  COUNTRIES,
  LANGUAGES,
  DISCOVERY_SOURCES,
  formatNullableNumber,
} from './types';

interface OverviewTabProps {
  website: Website;
  overview: KiOverviewData | null;
  loading: boolean;
  onStartResearch: () => void;
  onSwitchSection: (section: string) => void;
  onRefresh: () => void;
}

export function KeywordOverviewTab({
  overview,
  loading,
  onStartResearch,
  onSwitchSection,
  onRefresh,
}: OverviewTabProps) {
  const m = overview?.metrics;
  const intent = overview?.intent_breakdown;
  const totalIntent = (intent?.informational || 0) + (intent?.navigational || 0) + (intent?.commercial || 0) + (intent?.transactional || 0);

  return (
    <div className="space-y-6">
      {/* Hero Action Banner */}
      <div className="rounded-2xl bg-gradient-to-br from-emerald-950/60 via-zinc-900 to-zinc-950 border border-emerald-500/25 p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-400 uppercase tracking-wider mb-1">
            <Sparkles className="w-3.5 h-3.5" />
            AI Keyword Intelligence & Automation
          </div>
          <h2 className="text-xl font-bold text-zinc-100">
            Discover, Cluster & Automate High-Opportunity Keywords
          </h2>
          <p className="text-xs text-zinc-400 mt-1 max-w-2xl">
            Every metric below is backed by persisted workspace data — combining First-Party GSC, Live Autocomplete,
            Serper SERP/PAA, Competitor Gaps, and connected third-party volume providers without fabricated numbers.
          </p>
        </div>
        <div className="flex items-center gap-2.5 shrink-0">
          <button
            onClick={onRefresh}
            disabled={loading}
            className="inline-flex items-center gap-1.5 px-3.5 py-2.5 text-xs font-medium text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 rounded-xl transition"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <button
            onClick={onStartResearch}
            className="inline-flex items-center gap-2 px-5 py-2.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow-lg shadow-emerald-950/40 transition"
          >
            <Search className="w-4 h-4" />
            Start Keyword Research
          </button>
        </div>
      </div>

      {/* Real Persisted KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4">
          <div className="text-[11px] text-zinc-400 font-medium">Total Researched Keywords</div>
          <div className="text-2xl font-bold text-zinc-100 mt-1">{m?.total_researched_keywords ?? 0}</div>
          <div className="text-[11px] text-emerald-400 mt-1">
            +{m?.new_keyword_opportunities_7d ?? 0} opportunities (7d)
          </div>
        </div>
        <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4">
          <div className="text-[11px] text-zinc-400 font-medium">High-Opportunity Keywords</div>
          <div className="text-2xl font-bold text-emerald-400 mt-1">{m?.high_opportunity_keywords ?? 0}</div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {m?.total_saved_keywords ?? 0} saved • {m?.total_clusters ?? 0} clusters
          </div>
        </div>
        <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4">
          <div className="text-[11px] text-zinc-400 font-medium">Avg Keyword Difficulty</div>
          <div className="text-2xl font-bold text-zinc-100 mt-1">
            {m?.avg_keyword_difficulty !== null && m?.avg_keyword_difficulty !== undefined
              ? `${m.avg_keyword_difficulty}/100`
              : <span className="text-sm font-medium text-zinc-500">Data Unavailable</span>}
          </div>
          <div className="text-[11px] text-zinc-500 mt-1">Third-party provider metric</div>
        </div>
        <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4">
          <div className="text-[11px] text-zinc-400 font-medium">Total Est. Search Volume</div>
          <div className="text-2xl font-bold text-zinc-100 mt-1">
            {m?.total_estimated_search_volume !== null && m?.total_estimated_search_volume !== undefined
              ? m.total_estimated_search_volume.toLocaleString()
              : <span className="text-sm font-medium text-zinc-500">Data Unavailable</span>}
          </div>
          <div className="text-[11px] text-zinc-500 mt-1">
            {m?.active_automations_count ?? 0} active automation(s)
          </div>
        </div>
      </div>

      {/* Quick Intelligence Tiles */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {/* Intent Breakdown */}
        <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-5 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold text-zinc-200 uppercase tracking-wider">Search Intent Mix</h3>
            <button onClick={() => onSwitchSection('intent')} className="text-[11px] text-emerald-400 hover:underline">
              Analyze →
            </button>
          </div>
          <div className="space-y-2.5">
            {(['informational', 'commercial', 'transactional', 'navigational'] as const).map((key) => {
              const count = intent?.[key] || 0;
              const pct = totalIntent > 0 ? Math.round((count / totalIntent) * 100) : 0;
              return (
                <div key={key}>
                  <div className="flex justify-between text-xs mb-1">
                    <span className="capitalize text-zinc-300">{key}</span>
                    <span className="text-zinc-400 font-mono">{count} ({pct}%)</span>
                  </div>
                  <div className="h-1.5 rounded-full bg-zinc-800 overflow-hidden">
                    <div
                      className="h-full bg-emerald-500 rounded-full transition-all"
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Recent Research Activity */}
        <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-5 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold text-zinc-200 uppercase tracking-wider">Recent Research Activity</h3>
            <button onClick={() => onSwitchSection('history')} className="text-[11px] text-emerald-400 hover:underline">
              Full History →
            </button>
          </div>
          {!overview?.recent_research_activity?.length ? (
            <div className="text-xs text-zinc-500 py-6 text-center">
              No keyword discovery runs yet. Click &ldquo;Start Keyword Research&rdquo; above.
            </div>
          ) : (
            <div className="space-y-2">
              {overview.recent_research_activity.slice(0, 5).map((run) => (
                <div key={run.id} className="flex items-center justify-between text-xs p-2 rounded-lg bg-zinc-950/60 border border-zinc-800/70">
                  <div className="truncate pr-2">
                    <div className="font-medium text-zinc-200 truncate">{run.seed_text}</div>
                    <div className="text-[10px] text-zinc-500">
                      {run.country}/{run.language} • {run.seed_type}
                    </div>
                  </div>
                  <span className="text-[11px] font-mono text-emerald-400 shrink-0">
                    +{run.discovered_count} kw
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Active Automation Workflows */}
        <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl p-5 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold text-zinc-200 uppercase tracking-wider">Active Automations</h3>
            <button onClick={() => onSwitchSection('automation')} className="text-[11px] text-emerald-400 hover:underline">
              Manage →
            </button>
          </div>
          {!overview?.active_automation_workflows?.length ? (
            <div className="text-xs text-zinc-500 py-6 text-center space-y-2">
              <p>No recurring keyword automations scheduled yet.</p>
              <button
                onClick={() => onSwitchSection('automation')}
                className="inline-flex items-center gap-1 text-emerald-400 font-medium hover:underline"
              >
                <Zap className="w-3.5 h-3.5" /> Create Automation Workflow
              </button>
            </div>
          ) : (
            <div className="space-y-2">
              {overview.active_automation_workflows.slice(0, 4).map((wf) => (
                <div key={wf.id} className="p-2.5 rounded-lg bg-zinc-950/60 border border-zinc-800/70 text-xs">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-zinc-200 truncate">{wf.name}</span>
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400 uppercase font-semibold">
                      {wf.schedule_frequency}
                    </span>
                  </div>
                  <div className="text-[10px] text-zinc-500 mt-1">
                    {wf.total_keywords_discovered} discovered • {wf.total_opportunities_found} opportunities
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Top Opportunities Preview Table */}
      <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl overflow-hidden">
        <div className="px-5 py-3.5 border-b border-zinc-800 flex items-center justify-between">
          <div>
            <h3 className="text-sm font-bold text-zinc-100">Top Keyword Opportunities</h3>
            <p className="text-[11px] text-zinc-400">Highest transparent opportunity scores from your persisted research</p>
          </div>
          <button
            onClick={() => onSwitchSection('opportunities')}
            className="text-xs font-semibold text-emerald-400 hover:underline"
          >
            View All Opportunities →
          </button>
        </div>
        {!overview?.top_opportunities?.length ? (
          <div className="p-8 text-center text-xs text-zinc-500">
            Run Keyword Discovery to populate scored opportunities, question keywords, long-tail ideas, and topic clusters.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead className="bg-zinc-950/60 text-zinc-400 border-b border-zinc-800">
                <tr>
                  <th className="text-left px-4 py-2.5 font-semibold">Keyword</th>
                  <th className="text-left px-3 py-2.5 font-semibold">Intent</th>
                  <th className="text-right px-3 py-2.5 font-semibold">Volume</th>
                  <th className="text-right px-3 py-2.5 font-semibold">KD</th>
                  <th className="text-right px-3 py-2.5 font-semibold">Rank</th>
                  <th className="text-right px-3 py-2.5 font-semibold">Opportunity</th>
                  <th className="text-left px-4 py-2.5 font-semibold">Category</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {overview.top_opportunities.map((kw) => {
                  const intentMeta = INTENT_STYLES[kw.search_intent || 'informational'];
                  const catMeta = OPP_CATEGORY_STYLES[kw.opportunity_category || 'medium_opportunity'];
                  return (
                    <tr key={kw.id} className="hover:bg-zinc-800/30">
                      <td className="px-4 py-2.5 font-medium text-zinc-100">{kw.keyword}</td>
                      <td className="px-3 py-2.5">
                        <span className={`px-2 py-0.5 rounded-full text-[10px] border ${intentMeta?.badge}`}>
                          {intentMeta?.label}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-right font-mono text-zinc-300">
                        {formatNullableNumber(kw.search_volume)}
                      </td>
                      <td className="px-3 py-2.5 text-right font-mono text-zinc-300">
                        {kw.keyword_difficulty !== null ? kw.keyword_difficulty : 'Data Unavailable'}
                      </td>
                      <td className="px-3 py-2.5 text-right font-mono text-zinc-300">
                        {kw.current_position !== null ? `#${kw.current_position}` : '—'}
                      </td>
                      <td className="px-3 py-2.5 text-right font-bold text-emerald-400">
                        {kw.opportunity_score ?? '—'}/100
                      </td>
                      <td className="px-4 py-2.5">
                        <span className={`px-2 py-0.5 rounded-full text-[10px] border ${catMeta?.badge}`}>
                          {catMeta?.label}
                        </span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

interface DiscoveryTabProps {
  website: Website;
  initialSeed?: string | null;
  onConsumeInitialSeed?: () => void;
  onDiscovered: () => void;
  onOpenExplorer: () => void;
}

export function KeywordDiscoveryTab({
  website,
  initialSeed,
  onConsumeInitialSeed,
  onDiscovered,
  onOpenExplorer,
}: DiscoveryTabProps) {
  const toast = useToast();
  const [seedType, setSeedType] = useState<'keyword' | 'multiple' | 'list' | 'url' | 'business' | 'category'>('keyword');
  const [seedKeyword, setSeedKeyword] = useState('');
  const [multiSeeds, setMultiSeeds] = useState('');
  const [keywordList, setKeywordList] = useState('');
  const [websiteUrl, setWebsiteUrl] = useState(website.domain ? `https://${website.domain}` : '');
  const [businessName, setBusinessName] = useState(website.name || '');
  const [businessCategory, setBusinessCategory] = useState('');

  const [country, setCountry] = useState(website.target_country || 'US');
  const [language, setLanguage] = useState(website.preferred_language || 'en');
  const [searchEngine, setSearchEngine] = useState('google');
  const [location, setLocation] = useState('');
  const [device, setDevice] = useState('desktop');
  const [searchNetwork, setSearchNetwork] = useState('google_search');
  const [selectedSources, setSelectedSources] = useState<string[]>(DISCOVERY_SOURCES.map((s) => s.id));

  const [running, setRunning] = useState(false);
  const [lastResult, setLastResult] = useState<{
    total_discovered: number;
    new_keywords: number;
    updated_keywords: number;
    providers_used: string[];
    provider_errors: Record<string, string>;
    keywords: KiKeyword[];
  } | null>(null);

  useEffect(() => {
    if (initialSeed) {
      setSeedType('keyword');
      setSeedKeyword(initialSeed);
      onConsumeInitialSeed?.();
    }
  }, [initialSeed, onConsumeInitialSeed]);

  const toggleSource = (id: string) => {
    setSelectedSources((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  };

  const handleDiscover = async (e: React.FormEvent) => {
    e.preventDefault();
    setRunning(true);
    try {
      const payload: Record<string, unknown> = {
        seed_type: seedType,
        country,
        language,
        search_engine: searchEngine,
        location: location || undefined,
        device,
        search_network: searchNetwork,
        sources: selectedSources,
      };

      if (seedType === 'keyword') payload.seed_keyword = seedKeyword;
      if (seedType === 'multiple') {
        payload.seed_keywords = multiSeeds.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
      }
      if (seedType === 'list') payload.keyword_list = keywordList;
      if (seedType === 'url') payload.website_url = websiteUrl;
      if (seedType === 'business') payload.business_name = businessName;
      if (seedType === 'category') payload.business_category = businessCategory;

      const res = await api.post<{
        total_discovered: number;
        new_keywords: number;
        updated_keywords: number;
        providers_used: string[];
        provider_errors: Record<string, string>;
        keywords: KiKeyword[];
      }>(`/websites/${website.id}/keyword-intelligence/discover`, payload);

      if (res.success && res.data) {
        setLastResult(res.data);
        toast.success(`Discovered ${res.data.total_discovered} keywords (${res.data.new_keywords} new)!`);
        onDiscovered();
      } else {
        toast.error(res.error?.message || 'Keyword discovery failed.');
      }
    } finally {
      setRunning(false);
    }
  };

  return (
    <div className="space-y-6">
      <form onSubmit={handleDiscover} className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-6 space-y-5">
        <div>
          <h3 className="text-base font-bold text-zinc-100">Advanced Multi-Source Keyword Discovery</h3>
          <p className="text-xs text-zinc-400 mt-0.5">
            Combine Google Autocomplete, Serper PAA &amp; Related Searches, First-Party GSC, Competitor Gaps, and DataForSEO / Semrush without duplicates.
          </p>
        </div>

        {/* 6 Seed Input Modes */}
        <div className="flex flex-wrap gap-2">
          {[
            { id: 'keyword', label: 'Single Seed' },
            { id: 'multiple', label: 'Multiple Seeds' },
            { id: 'list', label: 'Keyword List' },
            { id: 'url', label: 'Website URL' },
            { id: 'business', label: 'Business Name' },
            { id: 'category', label: 'Business Category' },
          ].map((mode) => (
            <button
              type="button"
              key={mode.id}
              onClick={() => setSeedType(mode.id as typeof seedType)}
              className={`px-3 py-1.5 rounded-xl text-xs font-semibold border transition ${
                seedType === mode.id
                  ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'
                  : 'bg-zinc-950 text-zinc-400 border-zinc-800 hover:text-zinc-200'
              }`}
            >
              {mode.label}
            </button>
          ))}
        </div>

        {/* Dynamic Input Field */}
        <div>
          {seedType === 'keyword' && (
            <input
              type="text"
              value={seedKeyword}
              onChange={(e) => setSeedKeyword(e.target.value)}
              placeholder="e.g. technical seo audit, home care services in dhaka..."
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
            />
          )}
          {seedType === 'multiple' && (
            <textarea
              rows={3}
              value={multiSeeds}
              onChange={(e) => setMultiSeeds(e.target.value)}
              placeholder="Enter up to 5 seed keywords separated by commas or new lines..."
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
            />
          )}
          {seedType === 'list' && (
            <textarea
              rows={4}
              value={keywordList}
              onChange={(e) => setKeywordList(e.target.value)}
              placeholder="Paste a list of keywords (one per line or comma-separated) to classify, enrich with GSC/competitors, and score..."
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
            />
          )}
          {seedType === 'url' && (
            <input
              type="text"
              value={websiteUrl}
              onChange={(e) => setWebsiteUrl(e.target.value)}
              placeholder="https://example.com"
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 focus:outline-none focus:border-emerald-500"
            />
          )}
          {seedType === 'business' && (
            <input
              type="text"
              value={businessName}
              onChange={(e) => setBusinessName(e.target.value)}
              placeholder="Your brand or business name"
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 focus:outline-none focus:border-emerald-500"
            />
          )}
          {seedType === 'category' && (
            <input
              type="text"
              value={businessCategory}
              onChange={(e) => setBusinessCategory(e.target.value)}
              placeholder="e.g. B2B SaaS SEO Software, Home Nursing Care, Dental Clinic..."
              className="w-full px-4 py-3 rounded-xl bg-zinc-950 border border-zinc-700 text-sm text-zinc-100 focus:outline-none focus:border-emerald-500"
            />
          )}
        </div>

        {/* Market & Targeting Grid */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Country</label>
            <select value={country} onChange={(e) => setCountry(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Language</label>
            <select value={language} onChange={(e) => setLanguage(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              {LANGUAGES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Search Engine</label>
            <select value={searchEngine} onChange={(e) => setSearchEngine(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="google">Google</option>
              <option value="bing">Bing</option>
              <option value="youtube">YouTube</option>
            </select>
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Location (Optional)</label>
            <input type="text" value={location} onChange={(e) => setLocation(e.target.value)} placeholder="City / Region" className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Device</label>
            <select value={device} onChange={(e) => setDevice(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="desktop">Desktop</option>
              <option value="mobile">Mobile</option>
            </select>
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Network</label>
            <select value={searchNetwork} onChange={(e) => setSearchNetwork(e.target.value)} className="w-full px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="google_search">Google Search</option>
              <option value="search_partners">Search Partners</option>
            </select>
          </div>
        </div>

        {/* 10 Discovery Sources Selector */}
        <div>
          <div className="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider mb-2">
            Discovery Sources ({selectedSources.length}/10 Selected)
          </div>
          <div className="flex flex-wrap gap-2">
            {DISCOVERY_SOURCES.map((src) => {
              const active = selectedSources.includes(src.id);
              return (
                <button
                  type="button"
                  key={src.id}
                  onClick={() => toggleSource(src.id)}
                  className={`px-2.5 py-1 rounded-lg text-[11px] font-medium border transition ${
                    active
                      ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/40'
                      : 'bg-zinc-950 text-zinc-500 border-zinc-800 hover:text-zinc-300'
                  }`}
                >
                  {active ? '✓ ' : ''}{src.label}
                </button>
              );
            })}
          </div>
        </div>

        <div className="flex items-center justify-between pt-2">
          <div className="text-[11px] text-zinc-500">
            Duplicates are automatically merged by normalized keyword + country + language.
          </div>
          <button
            type="submit"
            disabled={running}
            className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-bold shadow transition"
          >
            {running ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Search className="w-4 h-4" />}
            {running ? 'Discovering Keywords...' : 'Run Keyword Discovery'}
          </button>
        </div>
      </form>

      {/* Discovery Results Summary */}
      {lastResult && (
        <div className="bg-zinc-900/70 border border-emerald-500/30 rounded-2xl p-5 space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h4 className="text-sm font-bold text-emerald-400">
                Discovery Completed — {lastResult.total_discovered} Keywords Processed ({lastResult.new_keywords} New, {lastResult.updated_keywords} Updated)
              </h4>
              <p className="text-[11px] text-zinc-400 mt-0.5">
                Sources used: {lastResult.providers_used.join(', ') || 'seed_input'}
              </p>
            </div>
            <button
              onClick={onOpenExplorer}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold"
            >
              Open Full Keyword Explorer <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>
          {Object.keys(lastResult.provider_errors || {}).length > 0 && (
            <div className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-[11px] text-amber-300">
              Partial provider notes: {Object.entries(lastResult.provider_errors).map(([k, v]) => `${k}: ${v}`).join(' | ')}
            </div>
          )}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5">
            {lastResult.keywords.slice(0, 12).map((kw) => (
              <div key={kw.id} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs flex items-center justify-between gap-2">
                <div className="truncate">
                  <div className="font-semibold text-zinc-100 truncate">{kw.keyword}</div>
                  <div className="text-[10px] text-zinc-500 mt-0.5">
                    {kw.search_intent} • {kw.discovery_source} • Vol: {formatNullableNumber(kw.search_volume)}
                  </div>
                </div>
                <span className="text-xs font-bold text-emerald-400 shrink-0">{kw.opportunity_score}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

interface ExplorerTabProps {
  website: Website;
  savedOnly?: boolean;
  onRefreshOverview: () => void;
  onNavigateToActionPlan?: () => void;
  onNavigateToContent?: () => void;
  onCheckLiveRank?: (keyword: string) => void;
}

export function KeywordExplorerTab({
  website,
  savedOnly = false,
  onRefreshOverview,
  onNavigateToActionPlan,
  onNavigateToContent,
  onCheckLiveRank,
}: ExplorerTabProps) {
  const toast = useToast();
  const [keywords, setKeywords] = useState<KiKeyword[]>([]);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);

  // Filters
  const [search, setSearch] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const [intent, setIntent] = useState('');
  const [country, setCountry] = useState('');
  const [source, setSource] = useState('');
  const [volMin, setVolMin] = useState('');
  const [volMax, setVolMax] = useState('');
  const [kdMin, setKdMin] = useState('');
  const [kdMax, setKdMax] = useState('');
  const [cpcMin, setCpcMin] = useState('');
  const [cpcMax, setCpcMax] = useState('');
  const [posMax, setPosMax] = useState('');
  const [serpFeature, setSerpFeature] = useState('');
  const [sortBy, setSortBy] = useState('opportunity_score');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  // Selection & SERP Inspector modal
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [inspectingKw, setInspectingKw] = useState<KiKeyword | null>(null);
  const [serpLoading, setSerpLoading] = useState(false);
  const [serpData, setSerpData] = useState<{
    available: boolean;
    organic_results?: Array<{ position: number; domain: string; url: string; title: string; snippet?: string }>;
    serp_features?: string[];
    people_also_ask?: Array<{ question: string }>;
    related_searches?: string[];
    error?: string;
  } | null>(null);

  const buildQueryString = useCallback(
    (extra: Record<string, string> = {}) => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', '25');
      params.set('sort_by', sortBy);
      params.set('sort_dir', sortDir);
      if (savedOnly) params.set('saved', '1');
      if (search.trim()) params.set('search', search.trim());
      if (intent) params.set('intent', intent);
      if (country) params.set('country', country);
      if (source) params.set('source', source);
      if (volMin) params.set('volume_min', volMin);
      if (volMax) params.set('volume_max', volMax);
      if (kdMin) params.set('kd_min', kdMin);
      if (kdMax) params.set('kd_max', kdMax);
      if (cpcMin) params.set('cpc_min', cpcMin);
      if (cpcMax) params.set('cpc_max', cpcMax);
      if (posMax) params.set('position_max', posMax);
      if (serpFeature) params.set('serp_feature', serpFeature);
      Object.entries(extra).forEach(([k, v]) => params.set(k, v));
      return params.toString();
    },
    [page, sortBy, sortDir, savedOnly, search, intent, country, source, volMin, volMax, kdMin, kdMax, cpcMin, cpcMax, posMax, serpFeature]
  );

  const loadKeywords = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get<{
        items: KiKeyword[];
        pagination: { current_page: number; last_page: number; total: number };
      }>(`/websites/${website.id}/keyword-intelligence/keywords?${buildQueryString()}`);

      if (res.success && res.data) {
        setKeywords(res.data.items);
        setTotalPages(res.data.pagination.last_page);
        setTotalCount(res.data.pagination.total);
      }
    } finally {
      setLoading(false);
    }
  }, [website.id, buildQueryString]);

  useEffect(() => {
    loadKeywords();
  }, [loadKeywords]);

  const toggleSave = async (kw: KiKeyword) => {
    const res = await api.patch<KiKeyword>(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}`, {
      is_saved: !kw.is_saved,
    });
    if (res.success) {
      toast.success(kw.is_saved ? 'Removed from Saved Keywords.' : 'Saved keyword.');
      loadKeywords();
      onRefreshOverview();
    }
  };

  const handleBulk = async (action: 'save' | 'unsave' | 'delete' | 'send_action_plan') => {
    if (!selectedIds.length) return;
    if (action === 'delete' && !confirm(`Delete ${selectedIds.length} selected keywords?`)) return;

    const res = await api.post(`/websites/${website.id}/keyword-intelligence/keywords/bulk`, {
      action,
      keyword_ids: selectedIds,
    });
    if (res.success) {
      toast.success(`Bulk action "${action}" completed for ${selectedIds.length} keyword(s).`);
      setSelectedIds([]);
      loadKeywords();
      onRefreshOverview();
    } else {
      toast.error(res.error?.message || 'Bulk action failed.');
    }
  };

  const handleExportCsv = async () => {
    const res = await api.downloadFile(
      `/websites/${website.id}/keyword-intelligence/keywords?${buildQueryString({ export: 'csv' })}`,
      `keywords-${website.domain}.csv`
    );
    if (res.ok) toast.success('CSV exported.');
    else toast.error(res.error || 'Export failed.');
  };

  const handleInspectSerp = async (kw: KiKeyword) => {
    setInspectingKw(kw);
    setSerpLoading(true);
    setSerpData(null);
    try {
      const res = await api.post<typeof serpData>(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}/serp`);
      if (res.success && res.data) {
        setSerpData(res.data);
        loadKeywords();
      }
    } finally {
      setSerpLoading(false);
    }
  };

  const handleSendActionPlan = async (kw: KiKeyword) => {
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}/send-action-plan`);
    if (res.success) {
      toast.success(`Saved "${kw.keyword}" to SEO Action Plan.`);
      loadKeywords();
      onNavigateToActionPlan?.();
    } else {
      toast.error(res.error?.message || 'Could not save to Action Plan.');
    }
  };

  const handleSendContentStudio = async (kw: KiKeyword) => {
    const res = await api.post(`/websites/${website.id}/keyword-intelligence/keywords/${kw.id}/send-content-studio`);
    if (res.success) {
      toast.success(`Draft Content Brief created for "${kw.keyword}" in Content Studio.`);
      loadKeywords();
      onNavigateToContent?.();
    } else {
      toast.error(res.error?.message || 'Could not send to Content Studio.');
    }
  };

  const toggleAllVisible = () => {
    if (selectedIds.length === keywords.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(keywords.map((k) => k.id));
    }
  };

  return (
    <div className="space-y-4">
      {/* Search, Filter Toggle & Export Toolbar */}
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-4 space-y-3">
        <div className="flex flex-wrap items-center gap-2.5">
          <div className="relative flex-1 min-w-[220px]">
            <Search className="w-4 h-4 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              placeholder={savedOnly ? 'Search saved keywords...' : 'Search researched keywords...'}
              className="w-full pl-9 pr-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
            />
          </div>

          <select
            value={intent}
            onChange={(e) => { setIntent(e.target.value); setPage(1); }}
            className="px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200"
          >
            <option value="">All Intents</option>
            <option value="informational">Informational</option>
            <option value="commercial">Commercial</option>
            <option value="transactional">Transactional</option>
            <option value="navigational">Navigational</option>
          </select>

          <select
            value={sortBy}
            onChange={(e) => setSortBy(e.target.value)}
            className="px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-200"
          >
            <option value="opportunity_score">Sort: Opportunity</option>
            <option value="search_volume">Sort: Search Volume</option>
            <option value="keyword_difficulty">Sort: Difficulty</option>
            <option value="cpc">Sort: CPC</option>
            <option value="current_position">Sort: Rank Position</option>
            <option value="gsc_impressions">Sort: GSC Impressions</option>
            <option value="created_at">Sort: Newest</option>
          </select>

          <button
            onClick={() => setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'))}
            className="px-2.5 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 hover:bg-zinc-800"
            title="Toggle sort direction"
          >
            {sortDir === 'desc' ? '↓ Desc' : '↑ Asc'}
          </button>

          <button
            onClick={() => setShowFilters(!showFilters)}
            className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium border transition ${
              showFilters
                ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'
                : 'bg-zinc-950 text-zinc-300 border-zinc-800 hover:bg-zinc-800'
            }`}
          >
            <SlidersHorizontal className="w-3.5 h-3.5" />
            Filters
          </button>

          <button
            onClick={handleExportCsv}
            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-zinc-950 hover:bg-zinc-800 border border-zinc-800 text-xs font-medium text-zinc-200 transition"
          >
            <Download className="w-3.5 h-3.5 text-emerald-400" />
            Export CSV
          </button>
        </div>

        {/* Progressive Advanced Filters */}
        {showFilters && (
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2.5 pt-3 border-t border-zinc-800">
            <input type="number" placeholder="Vol Min" value={volMin} onChange={(e) => { setVolMin(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <input type="number" placeholder="Vol Max" value={volMax} onChange={(e) => { setVolMax(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <input type="number" placeholder="KD Min" value={kdMin} onChange={(e) => { setKdMin(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <input type="number" placeholder="KD Max" value={kdMax} onChange={(e) => { setKdMax(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <input type="number" step="0.1" placeholder="CPC Min ($)" value={cpcMin} onChange={(e) => { setCpcMin(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <input type="number" step="0.1" placeholder="CPC Max ($)" value={cpcMax} onChange={(e) => { setCpcMax(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <select value={country} onChange={(e) => { setCountry(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="">All Countries</option>
              {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}
            </select>
            <input type="number" placeholder="Max Rank (e.g. 20)" value={posMax} onChange={(e) => { setPosMax(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200" />
            <select value={source} onChange={(e) => { setSource(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="">All Sources</option>
              <option value="gsc">GSC (First-Party)</option>
              <option value="google_autocomplete">Google Autocomplete</option>
              <option value="serper">Serper SERP/PAA</option>
              <option value="dataforseo">DataForSEO v3</option>
              <option value="keyword_gap">Competitor Gap</option>
            </select>
            <select value={serpFeature} onChange={(e) => { setSerpFeature(e.target.value); setPage(1); }} className="px-2.5 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-xs text-zinc-200">
              <option value="">Any SERP Feature</option>
              <option value="featured_snippet">Featured Snippet</option>
              <option value="people_also_ask">People Also Ask</option>
              <option value="local_pack">Local Pack</option>
              <option value="video_carousel">Video Carousel</option>
              <option value="shopping">Shopping</option>
            </select>
            <button
              onClick={() => {
                setVolMin(''); setVolMax(''); setKdMin(''); setKdMax(''); setCpcMin(''); setCpcMax('');
                setCountry(''); setPosMax(''); setSource(''); setSerpFeature(''); setIntent(''); setSearch('');
                setPage(1);
              }}
              className="px-2.5 py-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-xs text-zinc-200"
            >
              Reset Filters
            </button>
          </div>
        )}

        {/* Bulk Action Bar */}
        {selectedIds.length > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-2 pt-3 border-t border-zinc-800 text-xs">
            <span className="font-semibold text-emerald-400">{selectedIds.length} keyword(s) selected</span>
            <div className="flex items-center gap-2">
              <button onClick={() => handleBulk('save')} className="px-2.5 py-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200">Save</button>
              <button onClick={() => handleBulk('unsave')} className="px-2.5 py-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200">Unsave</button>
              <button onClick={() => handleBulk('send_action_plan')} className="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">Add to Action Plan</button>
              <button onClick={() => handleBulk('delete')} className="px-2.5 py-1 rounded-lg bg-rose-500/20 hover:bg-rose-500/30 text-rose-300">Delete</button>
            </div>
          </div>
        )}
      </div>

      {/* Main Keyword Table */}
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl overflow-hidden">
        <div className="px-4 py-2.5 border-b border-zinc-800 flex items-center justify-between text-xs text-zinc-400">
          <span>Showing {keywords.length} of {totalCount} {savedOnly ? 'saved' : 'researched'} keywords</span>
          <span className="text-[11px] text-zinc-500">Missing third-party metrics display &ldquo;Data Unavailable&rdquo;</span>
        </div>

        {loading ? (
          <div className="p-10 text-center text-xs text-zinc-500">Loading keywords...</div>
        ) : keywords.length === 0 ? (
          <div className="p-10 text-center text-xs text-zinc-500">
            No keywords matched your current filters. Run Keyword Discovery or clear filters.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead className="bg-zinc-950/70 text-zinc-400 border-b border-zinc-800">
                <tr>
                  <th className="px-3 py-2.5 w-8">
                    <input
                      type="checkbox"
                      checked={selectedIds.length === keywords.length && keywords.length > 0}
                      onChange={toggleAllVisible}
                    />
                  </th>
                  <th className="text-left px-3 py-2.5 font-semibold">Keyword</th>
                  <th className="text-right px-2.5 py-2.5 font-semibold">Volume</th>
                  <th className="text-right px-2.5 py-2.5 font-semibold">KD</th>
                  <th className="text-right px-2.5 py-2.5 font-semibold">CPC</th>
                  <th className="text-left px-2.5 py-2.5 font-semibold">Intent</th>
                  <th className="text-center px-2.5 py-2.5 font-semibold">Trend</th>
                  <th className="text-right px-2.5 py-2.5 font-semibold">Rank / GSC</th>
                  <th className="text-right px-2.5 py-2.5 font-semibold">Opp.</th>
                  <th className="text-left px-2.5 py-2.5 font-semibold">Source</th>
                  <th className="text-right px-3 py-2.5 font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60">
                {keywords.map((kw) => {
                  const intentMeta = INTENT_STYLES[kw.search_intent || 'informational'];
                  return (
                    <tr key={kw.id} className="hover:bg-zinc-800/30">
                      <td className="px-3 py-2.5">
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(kw.id)}
                          onChange={() =>
                            setSelectedIds((prev) =>
                              prev.includes(kw.id) ? prev.filter((id) => id !== kw.id) : [...prev, kw.id]
                            )
                          }
                        />
                      </td>
                      <td className="px-3 py-2.5 max-w-[260px]">
                        <div className="font-semibold text-zinc-100 truncate" title={kw.keyword}>{kw.keyword}</div>
                        <div className="flex items-center gap-1.5 mt-0.5 text-[10px] text-zinc-500">
                          <span>{kw.country}/{kw.language}</span>
                          {kw.is_long_tail && <span className="px-1 rounded bg-zinc-800 text-zinc-300">Long-tail</span>}
                          {kw.question_category && <span className="px-1 rounded bg-sky-500/15 text-sky-400 uppercase">{kw.question_category}</span>}
                          {(kw.serp_features?.length || 0) > 0 && (
                            <span className="px-1 rounded bg-violet-500/15 text-violet-300">
                              {kw.serp_features!.length} SERP feat
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-2.5 py-2.5 text-right font-mono text-zinc-300 whitespace-nowrap">
                        {kw.search_volume !== null ? kw.search_volume.toLocaleString() : <span className="text-zinc-600 text-[10px]">Data Unavailable</span>}
                      </td>
                      <td className="px-2.5 py-2.5 text-right font-mono text-zinc-300 whitespace-nowrap">
                        {kw.keyword_difficulty !== null ? kw.keyword_difficulty : <span className="text-zinc-600 text-[10px]">Data Unavailable</span>}
                      </td>
                      <td className="px-2.5 py-2.5 text-right font-mono text-zinc-300 whitespace-nowrap">
                        {kw.cpc !== null ? `$${kw.cpc.toFixed(2)}` : <span className="text-zinc-600 text-[10px]">Data Unavailable</span>}
                      </td>
                      <td className="px-2.5 py-2.5">
                        <span className={`px-2 py-0.5 rounded-full text-[10px] border ${intentMeta?.badge}`}>
                          {intentMeta?.label}
                        </span>
                        {kw.intent_source === 'ai_inferred' && (
                          <div className="text-[9px] text-zinc-500 mt-0.5">
                            AI ({Math.round((kw.intent_confidence || 0.75) * 100)}%)
                          </div>
                        )}
                      </td>
                      <td className="px-2.5 py-2.5 text-center">
                        {kw.trend_direction === 'rising' ? (
                          <TrendingUp className="w-4 h-4 text-emerald-400 inline" />
                        ) : kw.trend_direction === 'declining' ? (
                          <TrendingDown className="w-4 h-4 text-rose-400 inline" />
                        ) : kw.trend_direction === 'stable' ? (
                          <Minus className="w-4 h-4 text-zinc-400 inline" />
                        ) : (
                          <span className="text-[10px] text-zinc-600">—</span>
                        )}
                      </td>
                      <td className="px-2.5 py-2.5 text-right font-mono">
                        {kw.current_position !== null ? (
                          <div className="text-zinc-200">#{kw.current_position}</div>
                        ) : (
                          <div className="text-zinc-600 text-[10px]">Unranked</div>
                        )}
                        {kw.gsc_impressions !== null && (
                          <div className="text-[10px] text-emerald-400">GSC {kw.gsc_impressions.toLocaleString()} imp</div>
                        )}
                      </td>
                      <td className="px-2.5 py-2.5 text-right">
                        <div className="font-bold text-emerald-400">{kw.opportunity_score ?? '—'}</div>
                        <div className="text-[9px] text-zinc-500 uppercase">{kw.recommended_priority}</div>
                      </td>
                      <td className="px-2.5 py-2.5">
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-300">
                          {kw.data_source}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-right whitespace-nowrap">
                        <div className="inline-flex items-center gap-1">
                          <button
                            onClick={() => toggleSave(kw)}
                            title={kw.is_saved ? 'Remove bookmark' : 'Save keyword'}
                            className={`p-1.5 rounded-lg border transition ${
                              kw.is_saved
                                ? 'bg-amber-500/15 text-amber-400 border-amber-500/40'
                                : 'bg-zinc-950 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                            }`}
                          >
                            {kw.is_saved ? <BookmarkCheck className="w-3.5 h-3.5" /> : <Bookmark className="w-3.5 h-3.5" />}
                          </button>
                          <button
                            onClick={() => handleInspectSerp(kw)}
                            title="Inspect Live SERP & Competitors"
                            className="p-1.5 rounded-lg bg-zinc-950 text-zinc-400 hover:text-sky-400 border border-zinc-800"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => handleSendActionPlan(kw)}
                            title="Save to SEO Action Plan"
                            className="p-1.5 rounded-lg bg-zinc-950 text-zinc-400 hover:text-emerald-400 border border-zinc-800"
                          >
                            <CheckSquare className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => handleSendContentStudio(kw)}
                            title="Send to Content Studio (Draft Brief)"
                            className="p-1.5 rounded-lg bg-zinc-950 text-zinc-400 hover:text-violet-400 border border-zinc-800"
                          >
                            <FileText className="w-3.5 h-3.5" />
                          </button>
                          {onCheckLiveRank && (
                            <button
                              onClick={() => onCheckLiveRank(kw.keyword)}
                              title="Open in Live Rank Checker"
                              className="p-1.5 rounded-lg bg-zinc-950 text-zinc-400 hover:text-amber-400 border border-zinc-800"
                            >
                              <Crosshair className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="px-4 py-3 border-t border-zinc-800 flex items-center justify-between text-xs">
            <button
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="px-3 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-zinc-300 disabled:opacity-40"
            >
              Previous
            </button>
            <span className="text-zinc-400">Page {page} of {totalPages}</span>
            <button
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              className="px-3 py-1.5 rounded-lg bg-zinc-950 border border-zinc-800 text-zinc-300 disabled:opacity-40"
            >
              Next
            </button>
          </div>
        )}
      </div>

      {/* SERP & Trend Inspector Modal */}
      {inspectingKw && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-3xl w-full max-h-[85vh] overflow-y-auto p-6 space-y-5">
            <div className="flex items-start justify-between gap-4">
              <div>
                <div className="text-[11px] font-semibold text-emerald-400 uppercase">SERP &amp; Trend Inspector</div>
                <h3 className="text-lg font-bold text-zinc-100 mt-0.5">{inspectingKw.keyword}</h3>
                <div className="text-xs text-zinc-400 mt-1">
                  {inspectingKw.country}/{inspectingKw.language} • Opportunity: {inspectingKw.opportunity_score}/100 ({inspectingKw.data_confidence} confidence)
                </div>
              </div>
              <button onClick={() => setInspectingKw(null)} className="p-1.5 text-zinc-400 hover:text-zinc-100 rounded-lg bg-zinc-900">
                <X className="w-4 h-4" />
              </button>
            </div>

            {inspectingKw.opportunity_reason && (
              <div className="p-3 rounded-xl bg-zinc-900/80 border border-zinc-800 text-xs text-zinc-300">
                <strong className="text-emerald-400">Scoring Rationale:</strong> {inspectingKw.opportunity_reason}
              </div>
            )}

            {/* Historical Trend Chart (if provider supplied) */}
            {inspectingKw.trend_data && inspectingKw.trend_data.length > 0 && (
              <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-semibold text-zinc-200">Historical Monthly Search Volume</span>
                  <span className="text-[10px] uppercase text-emerald-400">Historical Provider Data ({inspectingKw.trend_direction})</span>
                </div>
                <div className="flex items-end gap-1.5 h-24 pt-2">
                  {(() => {
                    const maxV = Math.max(1, ...inspectingKw.trend_data.map((p) => p.volume));
                    return inspectingKw.trend_data.map((pt) => (
                      <div key={pt.period} className="flex-1 flex flex-col items-center gap-1">
                        <div
                          className="w-full bg-emerald-500/70 rounded-t"
                          style={{ height: `${Math.max(8, Math.round((pt.volume / maxV) * 72))}px` }}
                          title={`${pt.period}: ${pt.volume.toLocaleString()}`}
                        />
                        <span className="text-[9px] text-zinc-500">{pt.period.slice(5)}</span>
                      </div>
                    ));
                  })()}
                </div>
              </div>
            )}

            {/* Live SERP Results */}
            {serpLoading ? (
              <div className="py-10 text-center text-xs text-zinc-400">Fetching live Google SERP competitors...</div>
            ) : serpData?.available ? (
              <div className="space-y-4">
                {(serpData.serp_features?.length || 0) > 0 && (
                  <div className="flex flex-wrap gap-1.5">
                    {serpData.serp_features!.map((f) => (
                      <span key={f} className="px-2 py-0.5 rounded-full text-[10px] bg-violet-500/15 text-violet-300 border border-violet-500/30">
                        {f}
                      </span>
                    ))}
                  </div>
                )}
                <div className="space-y-2">
                  <h4 className="text-xs font-bold text-zinc-300 uppercase">Top Ranking Competitors</h4>
                  {(serpData.organic_results || []).map((r) => (
                    <div key={`${r.position}-${r.url}`} className="p-3 rounded-xl bg-zinc-900/70 border border-zinc-800 text-xs">
                      <div className="flex items-center gap-2">
                        <span className="w-6 h-6 rounded-md bg-zinc-800 text-emerald-400 font-bold flex items-center justify-center shrink-0">
                          #{r.position}
                        </span>
                        <a href={r.url} target="_blank" rel="noreferrer" className="font-semibold text-zinc-100 hover:text-emerald-400 truncate">
                          {r.title || r.url}
                        </a>
                      </div>
                      <div className="text-[11px] text-zinc-500 mt-1 truncate">{r.url}</div>
                      {r.snippet && <p className="text-[11px] text-zinc-400 mt-1 line-clamp-2">{r.snippet}</p>}
                    </div>
                  ))}
                </div>
              </div>
            ) : (
              <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-300 flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 shrink-0" />
                <span>{serpData?.error || 'Live SERP provider not connected. Connect Serper.dev to inspect top SERP competitors.'}</span>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

export function KeywordHistoryTab({ website }: { website: Website }) {
  const [seedRuns, setSeedRuns] = useState<KiSeedRun[]>([]);
  const [snapshots, setSnapshots] = useState<Array<{
    id: number;
    search_volume: number | null;
    keyword_difficulty: number | null;
    position: number | null;
    gsc_impressions: number | null;
    data_source: string;
    captured_at: string;
    keyword?: { keyword: string; country: string; language: string };
  }>>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const res = await api.get<{ seed_runs: KiSeedRun[]; metric_snapshots: typeof snapshots }>(
          `/websites/${website.id}/keyword-intelligence/history`
        );
        if (res.success && res.data) {
          setSeedRuns(res.data.seed_runs);
          setSnapshots(res.data.metric_snapshots);
        }
      } finally {
        setLoading(false);
      }
    })();
  }, [website.id]);

  if (loading) return <div className="p-8 text-center text-xs text-zinc-500">Loading keyword research history...</div>;

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
        <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
          <Clock className="w-4 h-4 text-emerald-400" /> Research Runs ({seedRuns.length})
        </h3>
        {seedRuns.length === 0 ? (
          <p className="text-xs text-zinc-500 py-6 text-center">No research runs recorded yet.</p>
        ) : (
          <div className="space-y-2 max-h-[460px] overflow-y-auto">
            {seedRuns.map((s) => (
              <div key={s.id} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs flex items-center justify-between">
                <div>
                  <div className="font-semibold text-zinc-100">{s.seed_text}</div>
                  <div className="text-[11px] text-zinc-500 mt-0.5">
                    {s.seed_type} • {s.country}/{s.language} • {new Date(s.created_at).toLocaleString()}
                  </div>
                </div>
                <span className="font-mono text-emerald-400 font-semibold">{s.discovered_count} kw</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-5 space-y-3">
        <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
          <Layers className="w-4 h-4 text-sky-400" /> Metric History Snapshots ({snapshots.length})
        </h3>
        {snapshots.length === 0 ? (
          <p className="text-xs text-zinc-500 py-6 text-center">No metric snapshots recorded yet.</p>
        ) : (
          <div className="space-y-2 max-h-[460px] overflow-y-auto">
            {snapshots.map((snap) => (
              <div key={snap.id} className="p-3 rounded-xl bg-zinc-950/70 border border-zinc-800 text-xs flex items-center justify-between gap-2">
                <div className="truncate">
                  <div className="font-semibold text-zinc-200 truncate">{snap.keyword?.keyword || 'Keyword'}</div>
                  <div className="text-[10px] text-zinc-500">
                    {snap.data_source} • {new Date(snap.captured_at).toLocaleString()}
                  </div>
                </div>
                <div className="text-right font-mono text-[11px] shrink-0">
                  <div>Vol: {formatNullableNumber(snap.search_volume)}</div>
                  <div className="text-zinc-500">Pos: {snap.position !== null ? `#${snap.position}` : '—'}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
