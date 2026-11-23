'use client';

import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  Search,
  MapPin,
  Globe,
  Monitor,
  Smartphone,
  RefreshCw,
  ExternalLink,
  CheckCircle2,
  AlertTriangle,
  Crosshair,
  TrendingUp,
  TrendingDown,
  Minus,
  ChevronDown,
  Clock,
  Sparkles,
  Star,
  HelpCircle,
  ArrowDownCircle,
  Layers,
  ShieldCheck,
  Target,
  X,
  KeyRound,
  Trash2,
} from 'lucide-react';
import { Website } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import {
  ALL_COUNTRIES,
  ALL_LANGUAGES,
  POPULAR_COUNTRY_CODES,
  countryFlag,
  getCountryName,
} from '@/lib/countries';

interface GscTopKeyword {
  query: string;
  clicks: number;
  impressions: number;
  ctr: number;
  avg_position: number;
  estimated_page: number;
}

interface RecentRankCheck {
  id: number;
  keyword: string;
  country: string;
  country_name: string;
  language: string;
  device: 'desktop' | 'mobile';
  location: string | null;
  target_position: number | null;
  google_page: number | null;
  rank_on_page: number | null;
  is_monitored: boolean;
  data_source: string;
  serp_features: string[];
  captured_at: string | null;
}

interface RankCheckerContextData {
  website: {
    id: number;
    name: string;
    domain: string;
    default_country: string;
    default_language: string;
  };
  provider: {
    name: string;
    label: string;
    configured: boolean;
    source?: 'server_env' | 'workspace_key' | 'unconfigured';
    key_hint?: string | null;
    has_workspace_key?: boolean;
  };
  gsc_top_keywords: GscTopKeyword[];
  recent_checks: RecentRankCheck[];
}

interface OrganicResultItem {
  position: number;
  google_page: number;
  rank_on_page: number;
  domain: string;
  url: string;
  title: string;
  snippet: string;
  date?: string | null;
  sitelinks?: Array<{ title?: string; link?: string }>;
  is_target: boolean;
  is_competitor: boolean;
  competitor_category?: string | null;
}

interface PageSummary {
  page: number;
  range_start: number;
  range_end: number;
  results_count: number;
  has_target: boolean;
  target_positions: number[];
}

interface LocalPackPlace {
  position: number;
  title: string;
  address?: string | null;
  rating?: number | null;
  rating_count?: number | null;
  category?: string | null;
  website?: string | null;
  phone?: string | null;
  is_target?: boolean;
}

interface GscComparison {
  has_gsc_data: boolean;
  gsc_avg_position: number | null;
  gsc_clicks: number;
  gsc_impressions: number;
  gsc_ctr: number;
  gsc_top_url: string | null;
  delta_vs_gsc: number | null;
  verdict: string;
}

interface RankCheckResult {
  available: boolean;
  snapshot_id: number;
  keyword: string;
  country: string;
  country_name: string;
  language: string;
  device: 'desktop' | 'mobile';
  location: string;
  depth_checked: number;
  results_returned: number;
  provider: string;
  provider_label: string;
  from_cache: boolean;
  captured_at: string | null;
  google_verify_url: string;
  target_domain: string;
  target_ranking: {
    found: boolean;
    position: number | null;
    google_page: number | null;
    rank_on_page: number | null;
    primary_match: OrganicResultItem | null;
    all_matches: OrganicResultItem[];
    in_local_pack: boolean;
    local_pack_position: number | null;
    previous_position: number | null;
    position_delta: number | null;
  };
  gsc_comparison: GscComparison;
  serp_features: string[];
  pages_summary: PageSummary[];
  organic_results: OrganicResultItem[];
  local_pack: LocalPackPlace[];
  people_also_ask: Array<{ question: string; snippet?: string; link?: string }>;
  related_searches: string[];
  answer_box?: { title?: string; answer?: string; snippet?: string; link?: string } | null;
}

const CITY_PRESETS: Record<string, string[]> = {
  SG: ['Singapore', 'Orchard, Singapore', 'Jurong East, Singapore', 'Tampines, Singapore', 'Woodlands, Singapore'],
  BD: ['Dhaka, Bangladesh', 'Gulshan, Dhaka, Bangladesh', 'Chittagong, Bangladesh', 'Sylhet, Bangladesh'],
  US: ['New York, NY, United States', 'Los Angeles, CA, United States', 'Chicago, IL, United States', 'Miami, FL, United States', 'Austin, TX, United States'],
  GB: ['London, United Kingdom', 'Manchester, United Kingdom', 'Birmingham, United Kingdom'],
  AU: ['Sydney, NSW, Australia', 'Melbourne, VIC, Australia', 'Brisbane, QLD, Australia'],
  AE: ['Dubai, United Arab Emirates', 'Abu Dhabi, United Arab Emirates', 'Sharjah, United Arab Emirates'],
  CA: ['Toronto, ON, Canada', 'Vancouver, BC, Canada', 'Montreal, QC, Canada'],
  MY: ['Kuala Lumpur, Malaysia', 'Johor Bahru, Malaysia', 'Penang, Malaysia'],
  IN: ['Mumbai, India', 'New Delhi, India', 'Bengaluru, India', 'Kolkata, India'],
  SA: ['Riyadh, Saudi Arabia', 'Jeddah, Saudi Arabia'],
  DE: ['Berlin, Germany', 'Munich, Germany', 'Frankfurt, Germany'],
  PK: ['Karachi, Pakistan', 'Lahore, Pakistan', 'Islamabad, Pakistan'],
};

interface RankCheckerViewProps {
  website: Website | null;
  initialKeyword?: string | null;
  onConsumeInitialKeyword?: () => void;
}

export function RankCheckerView({
  website,
  initialKeyword,
  onConsumeInitialKeyword,
}: RankCheckerViewProps) {
  const toast = useToast();

  // Context & history
  const [context, setContext] = useState<RankCheckerContextData | null>(null);
  const [isLoadingContext, setIsLoadingContext] = useState<boolean>(false);

  // Search form controls
  const [keyword, setKeyword] = useState<string>('');
  const [country, setCountry] = useState<string>('SG');
  const [location, setLocation] = useState<string>('');
  const [language, setLanguage] = useState<string>('en');
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [depth, setDepth] = useState<number>(50);
  const [forceRefresh, setForceRefresh] = useState<boolean>(true);

  // Searchable country dropdown
  const [countryDropdownOpen, setCountryDropdownOpen] = useState<boolean>(false);
  const [countrySearch, setCountrySearch] = useState<string>('');
  const countryDropdownRef = useRef<HTMLDivElement | null>(null);

  // GSC quick keyword search filter
  const [gscFilter, setGscFilter] = useState<string>('');

  // Active rank check result
  const [result, setResult] = useState<RankCheckResult | null>(null);
  const [isChecking, setIsChecking] = useState<boolean>(false);
  const [loadingSnapshotId, setLoadingSnapshotId] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Serper.dev Workspace API Key quick-connect state
  const [showKeySetup, setShowKeySetup] = useState<boolean>(false);
  const [serperKeyInput, setSerperKeyInput] = useState<string>('');
  const [isSavingKey, setIsSavingKey] = useState<boolean>(false);
  const [isRemovingKey, setIsRemovingKey] = useState<boolean>(false);

  // SERP pagination & filter
  const [selectedPage, setSelectedPage] = useState<number | 'all'>(1);
  const [serpSearch, setSerpSearch] = useState<string>('');
  const [expandedPaaIdx, setExpandedPaaIdx] = useState<number | null>(null);

  // Close country dropdown on outside click
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (countryDropdownRef.current && !countryDropdownRef.current.contains(e.target as Node)) {
        setCountryDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Load saved geo preferences per website from localStorage
  useEffect(() => {
    if (!website) return;
    setResult(null);
    setErrorMessage(null);
    try {
      const saved = localStorage.getItem(`wsa_rank_geo_${website.id}`);
      if (saved) {
        const parsed = JSON.parse(saved);
        if (parsed.country) setCountry(parsed.country);
        if (typeof parsed.location === 'string') setLocation(parsed.location);
        if (parsed.language) setLanguage(parsed.language);
        if (parsed.device === 'mobile' || parsed.device === 'desktop') setDevice(parsed.device);
        if ([20, 30, 50, 100].includes(parsed.depth)) setDepth(parsed.depth);
      } else {
        setCountry((website.target_country || 'US').toUpperCase());
        setLocation('');
        setLanguage((website.preferred_language || 'en').toLowerCase());
      }
    } catch {
      setCountry((website.target_country || 'US').toUpperCase());
      setLocation('');
    }
  }, [website]);

  // Persist geo preferences per website
  useEffect(() => {
    if (!website) return;
    try {
      localStorage.setItem(
        `wsa_rank_geo_${website.id}`,
        JSON.stringify({ country, location, language, device, depth })
      );
    } catch {
      // ignore storage quota errors
    }
  }, [website, country, location, language, device, depth]);

  const loadContext = useCallback(async () => {
    if (!website) return;
    setIsLoadingContext(true);
    try {
      const res = await api.get<RankCheckerContextData>(`/websites/${website.id}/rank-checker/context`);
      if (res.success && res.data) {
        setContext(res.data);
        if (!res.data.provider.configured) {
          setShowKeySetup(true);
        }
      }
    } finally {
      setIsLoadingContext(false);
    }
  }, [website]);

  useEffect(() => {
    loadContext();
  }, [loadContext]);

  const runLiveCheck = useCallback(
    async (overrides?: {
      keyword?: string;
      country?: string;
      location?: string;
      language?: string;
      device?: 'desktop' | 'mobile';
      depth?: number;
      forceRefresh?: boolean;
    }) => {
      if (!website) return;
      const kw = (overrides?.keyword ?? keyword).trim();
      if (!kw) {
        toast.error('Please enter a keyword to check on Google.');
        return;
      }

      const useCountry = overrides?.country ?? country;
      const useLocation = overrides?.location !== undefined ? overrides.location : location;
      const useLanguage = overrides?.language ?? language;
      const useDevice = overrides?.device ?? device;
      const useDepth = overrides?.depth ?? depth;
      const useForce = overrides?.forceRefresh !== undefined ? overrides.forceRefresh : forceRefresh;

      if (overrides?.keyword !== undefined) setKeyword(kw);
      if (overrides?.country !== undefined) setCountry(useCountry);
      if (overrides?.location !== undefined) setLocation(useLocation);
      if (overrides?.device !== undefined) setDevice(useDevice);
      if (overrides?.depth !== undefined) setDepth(useDepth);

      setIsChecking(true);
      setErrorMessage(null);
      setSerpSearch('');

      try {
        const res = await api.post<RankCheckResult>(`/websites/${website.id}/rank-checker/check`, {
          keyword: kw,
          country: useCountry,
          location: useLocation.trim() || null,
          language: useLanguage,
          device: useDevice,
          depth: useDepth,
          force_refresh: useForce,
        });

        if (res.success && res.data) {
          setResult(res.data);
          // Automatically focus the Google Page where the site ranks (or Page 1 if not found)
          const foundPage = res.data.target_ranking?.google_page;
          setSelectedPage(foundPage ?? 1);

          if (res.data.target_ranking?.found) {
            toast.success(
              `Found #${res.data.target_ranking.position} (Google Page ${res.data.target_ranking.google_page}) in ${res.data.location}!`
            );
          } else {
            toast.info(`Checked Top ${res.data.results_returned} in ${res.data.location} — site not found in top results.`);
          }

          loadContext();
        } else {
          const msg = res.error?.message || 'Live Google rank check failed.';
          setErrorMessage(msg);
          toast.error(msg);
        }
      } catch {
        setErrorMessage('Network error while checking live Google rankings.');
        toast.error('Network error while checking live Google rankings.');
      } finally {
        setIsChecking(false);
      }
    },
    [website, keyword, country, location, language, device, depth, forceRefresh, toast, loadContext]
  );

  // Auto-run when navigated from GSC Queries table with a specific keyword
  useEffect(() => {
    if (website && initialKeyword && initialKeyword.trim() !== '') {
      const kw = initialKeyword.trim();
      setKeyword(kw);
      onConsumeInitialKeyword?.();
      runLiveCheck({ keyword: kw });
    }
  }, [website, initialKeyword, onConsumeInitialKeyword, runLiveCheck]);

  const handleSaveSerperKey = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website) return;
    const trimmed = serperKeyInput.trim();
    if (trimmed.length < 10) {
      toast.error('Please paste your full Serper.dev API key.');
      return;
    }

    setIsSavingKey(true);
    setErrorMessage(null);
    try {
      const res = await api.post<{ saved: boolean; key_hint?: string }>(
        `/websites/${website.id}/rank-checker/provider-key`,
        { serper_api_key: trimmed }
      );
      if (res.success && res.data?.saved) {
        toast.success('Serper.dev Live Google Search Engine connected & encrypted for your workspace!');
        setSerperKeyInput('');
        setShowKeySetup(false);
        await loadContext();
        if (keyword.trim()) {
          runLiveCheck({ keyword: keyword.trim(), forceRefresh: true });
        }
      } else {
        const msg = res.error?.message || 'Could not verify Serper.dev API key.';
        setErrorMessage(msg);
        toast.error(msg);
      }
    } catch {
      toast.error('Network error verifying Serper.dev API key.');
    } finally {
      setIsSavingKey(false);
    }
  };

  const handleRemoveSerperKey = async () => {
    if (!website) return;
    setIsRemovingKey(true);
    try {
      const res = await api.delete(`/websites/${website.id}/rank-checker/provider-key`);
      if (res.success) {
        toast.info('Workspace Serper API key removed.');
        await loadContext();
      } else {
        toast.error(res.error?.message || 'Could not remove workspace API key.');
      }
    } finally {
      setIsRemovingKey(false);
    }
  };

  const loadStoredSnapshot = async (snapshotId: number) => {
    if (!website) return;
    setLoadingSnapshotId(snapshotId);
    setErrorMessage(null);
    try {
      const res = await api.get<RankCheckResult>(
        `/websites/${website.id}/rank-checker/history/${snapshotId}`
      );
      if (res.success && res.data) {
        setResult(res.data);
        setKeyword(res.data.keyword);
        setCountry(res.data.country);
        setLocation(res.data.location === res.data.country_name ? '' : res.data.location);
        setDevice(res.data.device);
        setSelectedPage(res.data.target_ranking?.google_page ?? 1);
      } else {
        toast.error(res.error?.message || 'Failed to load saved SERP snapshot.');
      }
    } finally {
      setLoadingSnapshotId(null);
    }
  };

  const jumpToPosition = (pos: number, googlePage: number) => {
    setSerpSearch('');
    setSelectedPage(googlePage);
    setTimeout(() => {
      const el = document.getElementById(`serp-row-${pos}`);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 60);
  };

  const filteredCountries = useMemo(() => {
    const q = countrySearch.trim().toLowerCase();
    if (!q) return ALL_COUNTRIES;
    return ALL_COUNTRIES.filter(
      (c) => c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q)
    );
  }, [countrySearch]);

  const popularCountries = useMemo(
    () =>
      POPULAR_COUNTRY_CODES.map((code) => ALL_COUNTRIES.find((c) => c.code === code)).filter(
        (c): c is NonNullable<typeof c> => Boolean(c)
      ),
    []
  );

  const cityPresets = useMemo(() => CITY_PRESETS[country] || [], [country]);

  const filteredGscKeywords = useMemo(() => {
    const list = context?.gsc_top_keywords || [];
    const q = gscFilter.trim().toLowerCase();
    if (!q) return list;
    return list.filter((k) => k.query.toLowerCase().includes(q));
  }, [context, gscFilter]);

  const displayedOrganicResults = useMemo(() => {
    if (!result) return [];
    let rows = result.organic_results || [];
    if (selectedPage !== 'all') {
      rows = rows.filter((r) => r.google_page === selectedPage);
    }
    const q = serpSearch.trim().toLowerCase();
    if (q) {
      rows = rows.filter(
        (r) =>
          r.domain.toLowerCase().includes(q) ||
          r.title.toLowerCase().includes(q) ||
          r.url.toLowerCase().includes(q) ||
          r.snippet.toLowerCase().includes(q)
      );
    }
    return rows;
  }, [result, selectedPage, serpSearch]);

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <Crosshair className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-400 mt-1">
          Select or connect a website to check exact live Google keyword rankings from any location.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header Banner */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 sm:p-6 shadow-sm">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <div className="flex flex-wrap items-center gap-2 mb-1.5">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold tracking-wider uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                <Crosshair className="w-3 h-3" />
                Live Geo-Located Google Rank Checker
              </span>
              <span className="text-xs font-mono text-zinc-400 bg-zinc-900 border border-zinc-800 px-2 py-0.5 rounded-md">
                {website.domain}
              </span>
              {context?.provider && (
                <button
                  type="button"
                  onClick={() => setShowKeySetup((v) => !v)}
                  className={`inline-flex items-center gap-1.5 text-[11px] font-medium px-2.5 py-0.5 rounded-md border transition ${
                    context.provider.configured
                      ? 'bg-sky-500/10 text-sky-400 border-sky-500/30 hover:bg-sky-500/20'
                      : 'bg-amber-500/15 text-amber-300 border-amber-500/40 hover:bg-amber-500/25'
                  }`}
                >
                  <KeyRound className="w-3 h-3" />
                  {context.provider.configured
                    ? `Live SERP: ${context.provider.label}${context.provider.key_hint ? ` (${context.provider.key_hint})` : ''}`
                    : 'Connect Live Google Search Key'}
                </button>
              )}
            </div>
            <h1 className="text-lg sm:text-xl font-bold text-zinc-100">
              Check Exact Google Position &amp; Page From Any Country or City
            </h1>
            <p className="text-xs text-zinc-400 mt-1 max-w-3xl">
              Simulate an unpersonalized Google search from Singapore, Bangladesh, USA, UK, or any of 195 countries on Desktop or Mobile. Inspect Google Pages 1–10, Maps Local Pack, and verify your live rank against your 28-day Search Console average.
            </p>
          </div>
        </div>

        {/* 1-Click Serper.dev Workspace API Key Setup Panel */}
        {showKeySetup && (
          <div className="mt-4 p-4 rounded-xl bg-amber-950/20 border border-amber-500/30 space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs font-bold text-amber-300">
                  <KeyRound className="w-4 h-4" />
                  Connect Live Google Search Engine (Serper.dev — 2,500 Free Searches)
                </div>
                <p className="text-[11px] text-zinc-300 leading-relaxed max-w-3xl">
                  Paste your <strong className="text-zinc-100">Serper.dev API Key</strong> below to unlock exact live Google Pages 1–10, country/city geo-targeting, and Local Map Pack checks immediately across all websites in your workspace (AES-256 encrypted, zero server redeploy needed).
                  <span className="block text-zinc-400 mt-0.5">
                    Already added <code className="text-amber-300">SERPER_API_KEY</code> on Vercel? In multi-service Vercel projects it must be attached to the <strong>backend</strong> service &amp; redeployed — or simply paste the exact same key here for instant activation.
                  </span>
                </p>
              </div>
              <div className="flex items-center gap-2">
                <a
                  href="https://serper.dev"
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-[11px] font-semibold text-zinc-200 transition"
                >
                  Get Free Key (serper.dev)
                  <ExternalLink className="w-3 h-3" />
                </a>
                {context?.provider?.configured && (
                  <button
                    type="button"
                    onClick={() => setShowKeySetup(false)}
                    className="p-1 text-zinc-400 hover:text-zinc-200"
                  >
                    <X className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>

            <form onSubmit={handleSaveSerperKey} className="flex flex-col sm:flex-row gap-2">
              <input
                type="password"
                value={serperKeyInput}
                onChange={(e) => setSerperKeyInput(e.target.value)}
                placeholder="Paste your Serper.dev API key (e.g. a1b2c3d4e5f6...)"
                className="flex-1 px-3.5 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500/60 font-mono"
              />
              <button
                type="submit"
                disabled={isSavingKey || serperKeyInput.trim().length < 10}
                className="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-semibold transition whitespace-nowrap"
              >
                {isSavingKey ? (
                  <>
                    <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                    Verifying Key...
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    Verify &amp; Activate Live Search
                  </>
                )}
              </button>
              {context?.provider?.has_workspace_key && (
                <button
                  type="button"
                  disabled={isRemovingKey}
                  onClick={handleRemoveSerperKey}
                  className="inline-flex items-center justify-center gap-1 px-3 py-2 rounded-xl bg-red-950/40 hover:bg-red-900/50 border border-red-800/60 text-red-300 text-xs font-medium transition"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  Remove
                </button>
              )}
            </form>
          </div>
        )}

        {/* Search Controls Form */}
        <form
          onSubmit={(e) => {
            e.preventDefault();
            runLiveCheck();
          }}
          className="mt-5 space-y-3"
        >
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
            {/* Keyword Input */}
            <div className="lg:col-span-5">
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">
                Keyword to Check on Google *
              </label>
              <div className="relative">
                <Search className="w-4 h-4 text-zinc-500 absolute left-3.5 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={keyword}
                  onChange={(e) => setKeyword(e.target.value)}
                  placeholder="e.g. repair hot water system"
                  className="w-full pl-10 pr-9 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500/60"
                />
                {keyword && (
                  <button
                    type="button"
                    onClick={() => setKeyword('')}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>

            {/* Searchable 195 Country Picker */}
            <div className="lg:col-span-3 relative" ref={countryDropdownRef}>
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">
                Search Country (195 Countries)
              </label>
              <button
                type="button"
                onClick={() => setCountryDropdownOpen((o) => !o)}
                className="w-full flex items-center justify-between px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 hover:border-zinc-700 rounded-xl text-sm text-zinc-100 transition"
              >
                <span className="flex items-center gap-2 truncate">
                  <span className="text-base leading-none">{countryFlag(country)}</span>
                  <span className="truncate font-medium">{getCountryName(country)}</span>
                  <span className="text-[10px] font-mono text-zinc-500">({country})</span>
                </span>
                <ChevronDown className="w-4 h-4 text-zinc-400 shrink-0" />
              </button>

              {countryDropdownOpen && (
                <div className="absolute left-0 right-0 mt-1.5 bg-zinc-900 border border-zinc-700 rounded-xl shadow-2xl z-40 overflow-hidden">
                  <div className="p-2 border-b border-zinc-800">
                    <div className="relative">
                      <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-2.5 top-1/2 -translate-y-1/2" />
                      <input
                        type="text"
                        autoFocus
                        value={countrySearch}
                        onChange={(e) => setCountrySearch(e.target.value)}
                        placeholder="Search country (e.g. Singapore, BD, US)..."
                        className="w-full pl-8 pr-2.5 py-1.5 bg-zinc-950 border border-zinc-800 rounded-lg text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                      />
                    </div>
                    {!countrySearch && (
                      <div className="flex flex-wrap gap-1 mt-2">
                        {popularCountries.map((c) => (
                          <button
                            key={c.code}
                            type="button"
                            onClick={() => {
                              setCountry(c.code);
                              setLocation('');
                              setCountryDropdownOpen(false);
                              setCountrySearch('');
                            }}
                            className={`px-2 py-0.5 rounded-md text-[11px] font-medium border transition ${
                              country === c.code
                                ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40'
                                : 'bg-zinc-950 text-zinc-300 border-zinc-800 hover:border-zinc-700'
                            }`}
                          >
                            {c.flag} {c.code}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  <div className="max-h-60 overflow-y-auto p-1">
                    {filteredCountries.length === 0 ? (
                      <div className="text-xs text-zinc-500 text-center py-4">No countries found</div>
                    ) : (
                      filteredCountries.map((c) => (
                        <button
                          key={c.code}
                          type="button"
                          onClick={() => {
                            setCountry(c.code);
                            setLocation('');
                            setCountryDropdownOpen(false);
                            setCountrySearch('');
                          }}
                          className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded-lg text-xs text-left transition ${
                            country === c.code
                              ? 'bg-emerald-500/15 text-emerald-300 font-semibold'
                              : 'text-zinc-200 hover:bg-zinc-800'
                          }`}
                        >
                          <span className="flex items-center gap-2 truncate">
                            <span>{c.flag}</span>
                            <span className="truncate">{c.name}</span>
                          </span>
                          <span className="text-[10px] font-mono text-zinc-500">{c.code}</span>
                        </button>
                      ))
                    )}
                  </div>
                </div>
              )}
            </div>

            {/* Optional Exact City / Region Location */}
            <div className="lg:col-span-4">
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1.5 uppercase tracking-wider">
                City / Exact Location <span className="text-zinc-500 normal-case">(Optional)</span>
              </label>
              <div className="relative">
                <MapPin className="w-4 h-4 text-zinc-500 absolute left-3.5 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={location}
                  onChange={(e) => setLocation(e.target.value)}
                  placeholder={`Default: ${getCountryName(country)} (e.g. Orchard, Singapore)`}
                  className="w-full pl-10 pr-8 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500/60"
                />
                {location && (
                  <button
                    type="button"
                    onClick={() => setLocation('')}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>
          </div>

          {/* City Quick Presets for Selected Country */}
          {cityPresets.length > 0 && (
            <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
              <span className="text-zinc-500 font-medium mr-1">
                Quick {getCountryName(country)} locations:
              </span>
              {cityPresets.map((city) => {
                const active = location === city;
                return (
                  <button
                    key={city}
                    type="button"
                    onClick={() => setLocation(active ? '' : city)}
                    className={`px-2.5 py-1 rounded-lg border transition ${
                      active
                        ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40 font-semibold'
                        : 'bg-zinc-900/70 text-zinc-400 border-zinc-800 hover:text-zinc-200 hover:border-zinc-700'
                    }`}
                  >
                    <MapPin className="w-3 h-3 inline mr-1 -mt-0.5" />
                    {city.split(',')[0]}
                  </button>
                );
              })}
            </div>
          )}

          {/* Secondary Bar: Device, Language, Depth, Fresh Toggle & Submit CTA */}
          <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
            <div className="flex flex-wrap items-center gap-2.5">
              {/* Device Toggle */}
              <div className="inline-flex bg-zinc-900 border border-zinc-800 rounded-xl p-1 text-xs">
                <button
                  type="button"
                  onClick={() => setDevice('desktop')}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium transition ${
                    device === 'desktop'
                      ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  <Monitor className="w-3.5 h-3.5" />
                  Desktop
                </button>
                <button
                  type="button"
                  onClick={() => setDevice('mobile')}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium transition ${
                    device === 'mobile'
                      ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  <Smartphone className="w-3.5 h-3.5" />
                  Mobile
                </button>
              </div>

              {/* Language */}
              <div className="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs">
                <Globe className="w-3.5 h-3.5 text-zinc-500" />
                <select
                  value={language}
                  onChange={(e) => setLanguage(e.target.value)}
                  className="bg-transparent text-zinc-200 font-medium focus:outline-none"
                >
                  {ALL_LANGUAGES.map((l) => (
                    <option key={l.code} value={l.code} className="bg-zinc-900 text-zinc-100">
                      {l.label}
                    </option>
                  ))}
                </select>
              </div>

              {/* Search Depth (Google Pages 1-10) */}
              <div className="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs">
                <Layers className="w-3.5 h-3.5 text-zinc-500" />
                <select
                  value={depth}
                  onChange={(e) => setDepth(parseInt(e.target.value, 10))}
                  className="bg-transparent text-zinc-200 font-medium focus:outline-none"
                >
                  <option value={30} className="bg-zinc-900 text-zinc-100">
                    Top 30 (Pages 1–3)
                  </option>
                  <option value={50} className="bg-zinc-900 text-zinc-100">
                    Top 50 (Pages 1–5)
                  </option>
                  <option value={100} className="bg-zinc-900 text-zinc-100">
                    Top 100 (Pages 1–10)
                  </option>
                </select>
              </div>

              {/* Fresh Live Checkbox */}
              <label className="inline-flex items-center gap-1.5 text-xs text-zinc-400 cursor-pointer select-none px-1">
                <input
                  type="checkbox"
                  checked={forceRefresh}
                  onChange={(e) => setForceRefresh(e.target.checked)}
                  className="rounded border-zinc-700 bg-zinc-900 text-emerald-500 focus:ring-emerald-500"
                />
                <span>Live Fresh (bypass cache)</span>
              </label>
            </div>

            <button
              type="submit"
              disabled={isChecking || !keyword.trim()}
              className="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold text-sm rounded-xl shadow-lg shadow-emerald-950/50 transition"
            >
              {isChecking ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  Searching Google ({country})...
                </>
              ) : (
                <>
                  <Crosshair className="w-4 h-4" />
                  Check Live Rank ({countryFlag(country)} {country})
                </>
              )}
            </button>
          </div>
        </form>

        {/* 1-Click GSC Keywords Bar */}
        {context && context.gsc_top_keywords.length > 0 && (
          <div className="mt-5 pt-4 border-t border-zinc-800/80">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2.5">
              <div className="flex items-center gap-2 text-xs">
                <Sparkles className="w-3.5 h-3.5 text-emerald-400" />
                <span className="font-semibold text-zinc-200">
                  1-Click Verify Your Google Search Console Keywords
                </span>
                <span className="text-[11px] text-zinc-500">
                  (Click any query to verify its real-time position in {getCountryName(country)})
                </span>
              </div>
              {context.gsc_top_keywords.length > 6 && (
                <div className="relative w-full sm:w-52">
                  <Search className="w-3 h-3 text-zinc-500 absolute left-2.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    value={gscFilter}
                    onChange={(e) => setGscFilter(e.target.value)}
                    placeholder="Filter GSC keywords..."
                    className="w-full pl-7 pr-2.5 py-1 bg-zinc-900 border border-zinc-800 rounded-lg text-[11px] text-zinc-200 focus:outline-none focus:border-emerald-500/50"
                  />
                </div>
              )}
            </div>
            <div className="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto pr-1">
              {filteredGscKeywords.map((item) => {
                const isSelected = keyword.trim().toLowerCase() === item.query.toLowerCase();
                return (
                  <button
                    key={item.query}
                    type="button"
                    disabled={isChecking}
                    onClick={() => runLiveCheck({ keyword: item.query })}
                    className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs border transition ${
                      isSelected
                        ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40 font-semibold'
                        : 'bg-zinc-900/80 text-zinc-300 border-zinc-800 hover:border-emerald-500/40 hover:text-zinc-100'
                    }`}
                  >
                    <span className="truncate max-w-[240px]">{item.query}</span>
                    <span className="px-1.5 py-0.5 rounded bg-zinc-950 border border-zinc-800 font-mono text-[10px] text-cyan-400">
                      GSC #{item.avg_position}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>
        )}
      </div>

      {/* Error Alert */}
      {errorMessage && (
        <div className="bg-red-950/30 border border-red-800/60 rounded-2xl p-4 flex items-start gap-3 text-xs text-red-200">
          <AlertTriangle className="w-4 h-4 text-red-400 shrink-0 mt-0.5" />
          <div>
            <div className="font-semibold text-red-300">Live Rank Check Could Not Complete</div>
            <p className="mt-0.5 text-red-200/80">{errorMessage}</p>
          </div>
        </div>
      )}

      {/* Loading Skeleton */}
      {isChecking && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-10 text-center space-y-3">
          <div className="w-10 h-10 rounded-full border-2 border-emerald-500 border-t-transparent animate-spin mx-auto" />
          <div className="text-sm font-semibold text-zinc-200">
            Searching Google in {countryFlag(country)} {location || getCountryName(country)} ({device})...
          </div>
          <p className="text-xs text-zinc-400">
            Scanning Top {depth} live organic results (Pages 1–{Math.ceil(depth / 10)}), Local Map Pack, and cross-checking with Search Console.
          </p>
        </div>
      )}

      {/* Results Area */}
      {!isChecking && result && (
        <div className="space-y-6">
          {/* Result Summary Hero Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
            {/* Live Position Card */}
            <div className="lg:col-span-7 bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 flex flex-col justify-between gap-4">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-4">
                  <div
                    className={`w-20 h-20 rounded-2xl flex flex-col items-center justify-center shrink-0 border ${
                      result.target_ranking.found
                        ? result.target_ranking.google_page === 1
                          ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-300'
                          : 'bg-cyan-500/15 border-cyan-500/40 text-cyan-300'
                        : 'bg-amber-500/10 border-amber-500/30 text-amber-300'
                    }`}
                  >
                    {result.target_ranking.found ? (
                      <>
                        <span className="text-[10px] uppercase font-bold tracking-wider opacity-80">
                          Rank
                        </span>
                        <span className="text-3xl font-black font-mono leading-none mt-0.5">
                          #{result.target_ranking.position}
                        </span>
                      </>
                    ) : (
                      <>
                        <span className="text-[10px] uppercase font-bold tracking-wider opacity-80">
                          Not in
                        </span>
                        <span className="text-lg font-black font-mono leading-none mt-0.5">
                          Top {result.results_returned}
                        </span>
                      </>
                    )}
                  </div>

                  <div className="space-y-1.5 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      {result.target_ranking.found ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-xs font-bold">
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          Google Page {result.target_ranking.google_page} • #{result.target_ranking.rank_on_page} on Page
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-300 text-xs font-bold">
                          <AlertTriangle className="w-3.5 h-3.5" />
                          Not ranked in Top {result.results_returned} (Pages 1–{Math.ceil(result.results_returned / 10)})
                        </span>
                      )}

                      {result.target_ranking.in_local_pack && (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-purple-500/15 border border-purple-500/30 text-purple-300 text-xs font-bold">
                          <MapPin className="w-3.5 h-3.5" />
                          Maps Local Pack #{result.target_ranking.local_pack_position}
                        </span>
                      )}

                      {result.target_ranking.position_delta !== null &&
                        result.target_ranking.position_delta !== 0 && (
                          <span
                            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[11px] font-bold border ${
                              result.target_ranking.position_delta > 0
                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                                : 'bg-red-500/10 text-red-400 border-red-500/30'
                            }`}
                          >
                            {result.target_ranking.position_delta > 0 ? (
                              <TrendingUp className="w-3 h-3" />
                            ) : (
                              <TrendingDown className="w-3 h-3" />
                            )}
                            {result.target_ranking.position_delta > 0 ? '+' : ''}
                            {result.target_ranking.position_delta} vs prev check
                          </span>
                        )}
                    </div>

                    <h2 className="text-sm sm:text-base font-bold text-zinc-100">
                      &ldquo;{result.keyword}&rdquo;
                    </h2>

                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-zinc-400">
                      <span className="flex items-center gap-1">
                        {countryFlag(result.country)} {result.location}
                      </span>
                      <span className="capitalize flex items-center gap-1">
                        {result.device === 'mobile' ? (
                          <Smartphone className="w-3 h-3" />
                        ) : (
                          <Monitor className="w-3 h-3" />
                        )}
                        {result.device}
                      </span>
                      <span>Lang: {result.language.toUpperCase()}</span>
                      <span>Checked {result.results_returned} results</span>
                    </div>

                    {result.target_ranking.primary_match && (
                      <div className="pt-1">
                        <a
                          href={result.target_ranking.primary_match.url}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-1.5 text-xs text-emerald-400 hover:underline font-mono break-all"
                        >
                          <ShieldCheck className="w-3.5 h-3.5 shrink-0" />
                          {result.target_ranking.primary_match.url}
                        </a>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Multiple URLs ranking or Expand to Top 100 */}
              {result.target_ranking.all_matches.length > 1 && (
                <div className="bg-zinc-900/60 border border-zinc-800 rounded-xl p-3 space-y-1.5">
                  <div className="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider">
                    All Ranking URLs for {result.target_domain} ({result.target_ranking.all_matches.length})
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {result.target_ranking.all_matches.map((m) => (
                      <button
                        key={m.position}
                        type="button"
                        onClick={() => jumpToPosition(m.position, m.google_page)}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 text-xs text-emerald-300 transition"
                      >
                        <span className="font-mono font-bold">#{m.position}</span>
                        <span className="text-[11px] opacity-80">(Page {m.google_page})</span>
                        <span className="truncate max-w-[200px] font-mono text-[11px]">
                          {m.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                        </span>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Bottom Actions */}
              <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-zinc-800/80 text-xs">
                <div className="flex flex-wrap items-center gap-2">
                  {result.target_ranking.found && result.target_ranking.position && result.target_ranking.google_page && (
                    <button
                      type="button"
                      onClick={() =>
                        jumpToPosition(
                          result.target_ranking.position!,
                          result.target_ranking.google_page!
                        )
                      }
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-500/30 text-emerald-300 font-semibold transition"
                    >
                      <ArrowDownCircle className="w-3.5 h-3.5" />
                      Jump to #{result.target_ranking.position} in SERP
                    </button>
                  )}

                  {!result.target_ranking.found && result.depth_checked < 100 && (
                    <button
                      type="button"
                      onClick={() => runLiveCheck({ depth: 100, forceRefresh: true })}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/30 text-amber-300 font-semibold transition"
                    >
                      <Layers className="w-3.5 h-3.5" />
                      Expand Check to Top 100 (Pages 1–10)
                    </button>
                  )}

                  <a
                    href={result.google_verify_url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 font-medium transition"
                  >
                    <ExternalLink className="w-3.5 h-3.5" />
                    Open on Google ({result.country})
                  </a>
                </div>

                <div className="text-[11px] text-zinc-500 flex items-center gap-1.5">
                  <Clock className="w-3 h-3" />
                  {result.captured_at
                    ? new Date(result.captured_at).toLocaleString()
                    : 'Just now'}
                  {result.from_cache ? ' • Saved Snapshot' : ' • Live'}
                </div>
              </div>
            </div>

            {/* Live Rank vs GSC 28-Day Average Comparison Card */}
            <div className="lg:col-span-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 flex flex-col justify-between gap-4">
              <div>
                <div className="flex items-center justify-between gap-2 mb-3">
                  <span className="text-xs font-bold uppercase tracking-wider text-zinc-400 flex items-center gap-1.5">
                    <Target className="w-3.5 h-3.5 text-sky-400" />
                    Live Google vs Search Console (28d)
                  </span>
                  {result.gsc_comparison.has_gsc_data && (
                    <span className="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/30">
                      GSC Verified
                    </span>
                  )}
                </div>

                {result.gsc_comparison.has_gsc_data ? (
                  <div className="space-y-3">
                    <div className="grid grid-cols-3 gap-2">
                      <div className="bg-zinc-900/80 border border-zinc-800 rounded-xl p-2.5 text-center">
                        <div className="text-[10px] text-zinc-500 uppercase font-semibold">
                          GSC 28d Avg
                        </div>
                        <div className="text-lg font-black font-mono text-sky-400 mt-0.5">
                          #{result.gsc_comparison.gsc_avg_position}
                        </div>
                      </div>
                      <div className="bg-zinc-900/80 border border-zinc-800 rounded-xl p-2.5 text-center">
                        <div className="text-[10px] text-zinc-500 uppercase font-semibold">
                          Live ({result.country})
                        </div>
                        <div
                          className={`text-lg font-black font-mono mt-0.5 ${
                            result.target_ranking.found ? 'text-emerald-400' : 'text-amber-400'
                          }`}
                        >
                          {result.target_ranking.found
                            ? `#${result.target_ranking.position}`
                            : `>${result.results_returned}`}
                        </div>
                      </div>
                      <div className="bg-zinc-900/80 border border-zinc-800 rounded-xl p-2.5 text-center">
                        <div className="text-[10px] text-zinc-500 uppercase font-semibold">
                          Variance
                        </div>
                        <div className="text-lg font-black font-mono mt-0.5 flex items-center justify-center gap-0.5">
                          {result.gsc_comparison.delta_vs_gsc === null ? (
                            <span className="text-zinc-500">—</span>
                          ) : Math.abs(result.gsc_comparison.delta_vs_gsc) <= 0.6 ? (
                            <span className="text-emerald-400 flex items-center gap-0.5 text-sm">
                              <Minus className="w-3.5 h-3.5" /> Match
                            </span>
                          ) : result.gsc_comparison.delta_vs_gsc > 0 ? (
                            <span className="text-emerald-400">
                              +{result.gsc_comparison.delta_vs_gsc}
                            </span>
                          ) : (
                            <span className="text-amber-400">
                              {result.gsc_comparison.delta_vs_gsc}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>

                    <p className="text-xs text-zinc-300 leading-relaxed bg-zinc-900/60 border border-zinc-800/80 rounded-xl p-3">
                      {result.gsc_comparison.verdict}
                    </p>

                    <div className="flex items-center justify-between text-[11px] text-zinc-400 px-1">
                      <span>
                        Clicks: <strong className="text-zinc-200 font-mono">{result.gsc_comparison.gsc_clicks.toLocaleString()}</strong>
                      </span>
                      <span>
                        Impressions: <strong className="text-zinc-200 font-mono">{result.gsc_comparison.gsc_impressions.toLocaleString()}</strong>
                      </span>
                      <span>
                        CTR: <strong className="text-zinc-200 font-mono">{result.gsc_comparison.gsc_ctr}%</strong>
                      </span>
                    </div>
                  </div>
                ) : (
                  <div className="bg-zinc-900/60 border border-zinc-800/80 rounded-xl p-4 text-xs text-zinc-400 space-y-1.5">
                    <p className="font-semibold text-zinc-300">No 28-day GSC data for this exact keyword</p>
                    <p>{result.gsc_comparison.verdict}</p>
                    <p className="text-[11px] text-zinc-500">
                      Tip: Sync Google Search Console in Performance to cross-check GSC keywords directly.
                    </p>
                  </div>
                )}
              </div>

              {/* SERP Features Detected */}
              {result.serp_features && result.serp_features.length > 0 && (
                <div className="pt-2 border-t border-zinc-800/80">
                  <div className="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1.5">
                    SERP Features on This Page
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {result.serp_features.map((feat) => (
                      <span
                        key={feat}
                        className="px-2 py-0.5 rounded-md bg-zinc-900 border border-zinc-800 text-[11px] text-zinc-300 capitalize"
                      >
                        {feat.replace(/_/g, ' ')}
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Main 2-Column Layout: Google SERP Preview (Left 8 cols) + Local Pack / PAA / History (Right 4 cols) */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {/* Left: Google-Style SERP Preview with Page-by-Page Tabs */}
            <div className="lg:col-span-8 bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
              {/* SERP Header & Page Tabs */}
              <div className="p-4 border-b border-zinc-800 space-y-3">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                  <div>
                    <h3 className="text-sm font-bold text-zinc-100">
                      Google SERP Results — {countryFlag(result.country)} {result.location}
                    </h3>
                    <p className="text-[11px] text-zinc-400">
                      Showing organic rankings page-by-page (10 results per Google page). Your domain is highlighted in green.
                    </p>
                  </div>
                  <div className="relative w-full sm:w-56">
                    <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      value={serpSearch}
                      onChange={(e) => {
                        setSerpSearch(e.target.value);
                        if (e.target.value.trim()) setSelectedPage('all');
                      }}
                      placeholder="Filter domain or title..."
                      className="w-full pl-8 pr-3 py-1.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-200 focus:outline-none focus:border-emerald-500/60"
                    />
                  </div>
                </div>

                {/* Google Page Tabs */}
                <div className="flex flex-wrap items-center gap-1.5">
                  <button
                    type="button"
                    onClick={() => setSelectedPage('all')}
                    className={`px-3 py-1.5 rounded-xl text-xs font-semibold border transition ${
                      selectedPage === 'all'
                        ? 'bg-zinc-100 text-zinc-950 border-zinc-100'
                        : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                    }`}
                  >
                    All ({result.organic_results.length})
                  </button>
                  {result.pages_summary.map((pg) => {
                    const active = selectedPage === pg.page;
                    return (
                      <button
                        key={pg.page}
                        type="button"
                        onClick={() => setSelectedPage(pg.page)}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold border transition ${
                          active
                            ? pg.has_target
                              ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/50'
                              : 'bg-sky-500/15 text-sky-300 border-sky-500/40'
                            : pg.has_target
                            ? 'bg-emerald-950/40 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/15'
                            : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                        }`}
                      >
                        <span>Page {pg.page}</span>
                        <span className="text-[10px] font-mono opacity-75">
                          ({pg.range_start}–{pg.range_end})
                        </span>
                        {pg.has_target && (
                          <span
                            className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"
                            title={`Your site ranks at #${pg.target_positions.join(', #')}`}
                          />
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Answer Box / Featured Snippet (shown on Page 1 or All) */}
              {result.answer_box && (selectedPage === 1 || selectedPage === 'all') && !serpSearch && (
                <div className="mx-4 mt-4 p-4 rounded-xl bg-sky-950/20 border border-sky-500/30 space-y-1.5">
                  <div className="text-[10px] font-bold uppercase tracking-wider text-sky-400">
                    Google Featured Snippet / Answer Box (Position 0)
                  </div>
                  {result.answer_box.title && (
                    <div className="text-sm font-semibold text-zinc-100">{result.answer_box.title}</div>
                  )}
                  <p className="text-xs text-zinc-300 leading-relaxed">
                    {result.answer_box.answer || result.answer_box.snippet}
                  </p>
                  {result.answer_box.link && (
                    <a
                      href={result.answer_box.link}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 text-[11px] text-sky-400 hover:underline font-mono"
                    >
                      {result.answer_box.link}
                      <ExternalLink className="w-3 h-3" />
                    </a>
                  )}
                </div>
              )}

              {/* Organic Results List */}
              <div className="divide-y divide-zinc-800/60">
                {displayedOrganicResults.length === 0 ? (
                  <div className="p-10 text-center text-xs text-zinc-400">
                    No organic results match this page/filter.
                  </div>
                ) : (
                  displayedOrganicResults.map((row) => (
                    <div
                      key={`${row.position}-${row.url}`}
                      id={`serp-row-${row.position}`}
                      className={`p-4 transition ${
                        row.is_target
                          ? 'bg-emerald-500/[0.08] border-l-4 border-l-emerald-400'
                          : row.is_competitor
                          ? 'bg-sky-500/[0.05] border-l-4 border-l-sky-400'
                          : 'hover:bg-zinc-900/40'
                      }`}
                    >
                      <div className="flex items-start gap-3.5">
                        {/* Position + Page Badge */}
                        <div className="flex flex-col items-center shrink-0 w-14 pt-0.5">
                          <span
                            className={`w-10 h-10 rounded-xl flex items-center justify-center font-mono text-sm font-black border ${
                              row.is_target
                                ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/50'
                                : row.position <= 3
                                ? 'bg-zinc-900 text-amber-300 border-amber-500/30'
                                : 'bg-zinc-900 text-zinc-300 border-zinc-800'
                            }`}
                          >
                            #{row.position}
                          </span>
                          <span className="text-[10px] text-zinc-500 font-mono mt-1">
                            P{row.google_page} • #{row.rank_on_page}
                          </span>
                        </div>

                        {/* Google Result Card Body */}
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs font-semibold text-zinc-300 truncate max-w-xs">
                              {row.domain}
                            </span>
                            {row.is_target && (
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                Your Site
                              </span>
                            )}
                            {row.is_competitor && (
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-sky-500/20 text-sky-300 border border-sky-500/40">
                                Tracked Competitor
                              </span>
                            )}
                            {row.date && (
                              <span className="text-[10px] text-zinc-500">{row.date}</span>
                            )}
                          </div>

                          <div className="text-[11px] text-zinc-500 font-mono truncate">
                            {row.url}
                          </div>

                          <a
                            href={row.url}
                            target="_blank"
                            rel="noreferrer"
                            className={`block text-sm sm:text-[15px] font-semibold leading-snug hover:underline ${
                              row.is_target ? 'text-emerald-300' : 'text-blue-400'
                            }`}
                          >
                            {row.title || row.url}
                          </a>

                          {row.snippet && (
                            <p className="text-xs text-zinc-400 leading-relaxed">{row.snippet}</p>
                          )}

                          {row.sitelinks && row.sitelinks.length > 0 && (
                            <div className="flex flex-wrap gap-2 pt-1">
                              {row.sitelinks.slice(0, 4).map((sl, i) => (
                                <a
                                  key={i}
                                  href={sl.link || row.url}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="text-[11px] text-blue-400/90 hover:underline bg-zinc-900/70 border border-zinc-800 px-2 py-0.5 rounded-md"
                                >
                                  {sl.title}
                                </a>
                              ))}
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            </div>

            {/* Right Sidebar: Local Pack, People Also Ask, Related Searches, Recent Checks */}
            <div className="lg:col-span-4 space-y-5">
              {/* Google Maps Local Pack */}
              {result.local_pack && result.local_pack.length > 0 && (
                <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-300 flex items-center gap-1.5">
                      <MapPin className="w-3.5 h-3.5 text-purple-400" />
                      Google Maps Local Pack
                    </h4>
                    <span className="text-[10px] font-mono text-zinc-500">
                      {result.local_pack.length} places
                    </span>
                  </div>
                  <div className="space-y-2">
                    {result.local_pack.map((pl) => (
                      <div
                        key={pl.position}
                        className={`p-3 rounded-xl border text-xs space-y-1 ${
                          pl.is_target
                            ? 'bg-emerald-500/10 border-emerald-500/40'
                            : 'bg-zinc-900/60 border-zinc-800'
                        }`}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-semibold text-zinc-100 truncate">
                            #{pl.position} {pl.title}
                          </span>
                          {pl.is_target && (
                            <span className="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300">
                              Your Business
                            </span>
                          )}
                        </div>
                        {pl.rating && (
                          <div className="flex items-center gap-1 text-[11px] text-amber-400">
                            <Star className="w-3 h-3 fill-amber-400" />
                            <span>{pl.rating}</span>
                            {pl.rating_count && (
                              <span className="text-zinc-500">({pl.rating_count} reviews)</span>
                            )}
                          </div>
                        )}
                        {pl.address && <div className="text-[11px] text-zinc-400">{pl.address}</div>}
                        {pl.website && (
                          <a
                            href={pl.website}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-[11px] text-sky-400 hover:underline font-mono truncate max-w-full"
                          >
                            {pl.website.replace(/^https?:\/\//, '')}
                          </a>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* People Also Ask */}
              {result.people_also_ask && result.people_also_ask.length > 0 && (
                <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 space-y-2.5">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-300 flex items-center gap-1.5">
                    <HelpCircle className="w-3.5 h-3.5 text-cyan-400" />
                    People Also Ask
                  </h4>
                  <div className="space-y-1.5">
                    {result.people_also_ask.map((paa, idx) => (
                      <div
                        key={idx}
                        className="bg-zinc-900/60 border border-zinc-800 rounded-xl overflow-hidden"
                      >
                        <button
                          type="button"
                          onClick={() => setExpandedPaaIdx(expandedPaaIdx === idx ? null : idx)}
                          className="w-full flex items-center justify-between gap-2 p-2.5 text-left text-xs font-medium text-zinc-200 hover:bg-zinc-900 transition"
                        >
                          <span>{paa.question}</span>
                          <ChevronDown
                            className={`w-3.5 h-3.5 text-zinc-500 shrink-0 transition-transform ${
                              expandedPaaIdx === idx ? 'rotate-180' : ''
                            }`}
                          />
                        </button>
                        {expandedPaaIdx === idx && paa.snippet && (
                          <div className="px-2.5 pb-2.5 text-[11px] text-zinc-400 border-t border-zinc-800/60 pt-2">
                            {paa.snippet}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Related Searches (clickable to run live check) */}
              {result.related_searches && result.related_searches.length > 0 && (
                <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 space-y-2.5">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-300 flex items-center gap-1.5">
                    <Search className="w-3.5 h-3.5 text-emerald-400" />
                    Related Google Searches
                  </h4>
                  <div className="flex flex-wrap gap-1.5">
                    {result.related_searches.map((rel) => (
                      <button
                        key={rel}
                        type="button"
                        onClick={() => runLiveCheck({ keyword: rel })}
                        className="px-2.5 py-1 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 hover:border-emerald-500/40 text-xs text-zinc-300 hover:text-emerald-300 transition text-left"
                      >
                        {rel}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Recent Rank Checks History */}
              <RecentChecksPanel
                checks={context?.recent_checks || []}
                activeSnapshotId={result.snapshot_id}
                loadingSnapshotId={loadingSnapshotId}
                isLoadingContext={isLoadingContext}
                onOpenSnapshot={loadStoredSnapshot}
                onRecheck={(chk) =>
                  runLiveCheck({
                    keyword: chk.keyword,
                    country: chk.country,
                    location: chk.location === chk.country_name ? '' : chk.location || '',
                    device: chk.device,
                    forceRefresh: true,
                  })
                }
              />
            </div>
          </div>
        </div>
      )}

      {/* Initial Empty State + Recent Checks when no search run yet */}
      {!isChecking && !result && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <div className="lg:col-span-8 bg-zinc-950 border border-zinc-800/80 rounded-2xl p-8 text-center space-y-4">
            <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 mx-auto">
              <Crosshair className="w-6 h-6" />
            </div>
            <div className="space-y-1.5 max-w-lg mx-auto">
              <h3 className="text-base font-bold text-zinc-100">
                Verify Exact Google Rankings From Any Location
              </h3>
              <p className="text-xs text-zinc-400 leading-relaxed">
                Enter any keyword above (or click one of your Google Search Console keywords), select a country such as <strong className="text-zinc-200">Singapore (SG)</strong> or <strong className="text-zinc-200">Bangladesh (BD)</strong>, pick Desktop or Mobile, and inspect your exact Google position, page number (Pages 1–10), and live SERP competitors.
              </p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-left text-xs">
              <div className="bg-zinc-900/60 border border-zinc-800 rounded-xl p-3">
                <div className="font-semibold text-emerald-400 mb-1">Exact Country &amp; City</div>
                <p className="text-zinc-400 text-[11px]">
                  Switch between all 195 countries + city-level geo-targeting without VPNs.
                </p>
              </div>
              <div className="bg-zinc-900/60 border border-zinc-800 rounded-xl p-3">
                <div className="font-semibold text-sky-400 mb-1">Page 1 to Page 10</div>
                <p className="text-zinc-400 text-[11px]">
                  See the exact Google page number and position on that page up to Top 100.
                </p>
              </div>
              <div className="bg-zinc-900/60 border border-zinc-800 rounded-xl p-3">
                <div className="font-semibold text-purple-400 mb-1">GSC Cross-Check</div>
                <p className="text-zinc-400 text-[11px]">
                  Instantly compare live local rank against your 28-day Search Console average.
                </p>
              </div>
            </div>
          </div>

          <div className="lg:col-span-4">
            <RecentChecksPanel
              checks={context?.recent_checks || []}
              activeSnapshotId={null}
              loadingSnapshotId={loadingSnapshotId}
              isLoadingContext={isLoadingContext}
              onOpenSnapshot={loadStoredSnapshot}
              onRecheck={(chk) =>
                runLiveCheck({
                  keyword: chk.keyword,
                  country: chk.country,
                  location: chk.location === chk.country_name ? '' : chk.location || '',
                  device: chk.device,
                  forceRefresh: true,
                })
              }
            />
          </div>
        </div>
      )}
    </div>
  );
}

function RecentChecksPanel({
  checks,
  activeSnapshotId,
  loadingSnapshotId,
  isLoadingContext,
  onOpenSnapshot,
  onRecheck,
}: {
  checks: RecentRankCheck[];
  activeSnapshotId: number | null;
  loadingSnapshotId: number | null;
  isLoadingContext: boolean;
  onOpenSnapshot: (id: number) => void;
  onRecheck: (chk: RecentRankCheck) => void;
}) {
  return (
    <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 space-y-3">
      <div className="flex items-center justify-between">
        <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-300 flex items-center gap-1.5">
          <Clock className="w-3.5 h-3.5 text-zinc-400" />
          Recent Rank Checks
        </h4>
        <span className="text-[10px] font-mono text-zinc-500">{checks.length} saved</span>
      </div>

      {isLoadingContext && checks.length === 0 ? (
        <div className="py-6 text-center text-xs text-zinc-500">Loading history...</div>
      ) : checks.length === 0 ? (
        <div className="py-6 text-center text-xs text-zinc-500">
          No rank checks yet. Run your first geo-located search above!
        </div>
      ) : (
        <div className="space-y-1.5 max-h-96 overflow-y-auto pr-0.5">
          {checks.map((chk) => {
            const isActive = activeSnapshotId === chk.id;
            const isLoading = loadingSnapshotId === chk.id;
            return (
              <div
                key={chk.id}
                className={`p-2.5 rounded-xl border transition flex items-center justify-between gap-2 ${
                  isActive
                    ? 'bg-emerald-500/10 border-emerald-500/40'
                    : 'bg-zinc-900/50 border-zinc-800 hover:border-zinc-700'
                }`}
              >
                <button
                  type="button"
                  onClick={() => onOpenSnapshot(chk.id)}
                  className="min-w-0 flex-1 text-left"
                >
                  <div className="text-xs font-semibold text-zinc-200 truncate">
                    {chk.keyword}
                  </div>
                  <div className="flex items-center gap-1.5 text-[10px] text-zinc-400 mt-0.5">
                    <span>
                      {countryFlag(chk.country)} {chk.location || chk.country_name}
                    </span>
                    <span>•</span>
                    <span className="capitalize">{chk.device}</span>
                  </div>
                </button>

                <div className="flex items-center gap-1.5 shrink-0">
                  {isLoading ? (
                    <RefreshCw className="w-3.5 h-3.5 animate-spin text-emerald-400" />
                  ) : chk.target_position ? (
                    <span className="px-2 py-0.5 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 font-mono text-[11px] font-bold">
                      #{chk.target_position} (P{chk.google_page})
                    </span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-lg bg-zinc-800 text-zinc-400 font-mono text-[10px]">
                      Not Found
                    </span>
                  )}

                  <button
                    type="button"
                    title="Re-check live on Google"
                    onClick={() => onRecheck(chk)}
                    className="p-1.5 rounded-lg text-zinc-400 hover:text-emerald-400 hover:bg-zinc-800 transition"
                  >
                    <RefreshCw className="w-3 h-3" />
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
