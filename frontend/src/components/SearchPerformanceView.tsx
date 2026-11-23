'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  BarChart3,
  Globe,
  RefreshCw,
  Search,
  ExternalLink,
  Calendar,
  Filter,
  CheckCircle2,
  AlertTriangle,
  ArrowUpRight,
  ArrowDownRight,
  Monitor,
  Smartphone,
  Sliders,
  Layers,
  Sparkles,
  Link2,
  Unlink,
  ShieldCheck,
  Info,
  Clock,
  ChevronRight,
  Zap,
  Crosshair,
} from 'lucide-react';
import { Website, SearchConsoleStatus, SearchPerformanceData } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface SearchPerformanceViewProps {
  website: Website | null;
  onNavigateToOpportunities?: () => void;
  onNavigateToKeywords?: () => void;
  onCheckLiveRank?: (keyword: string) => void;
}

export function SearchPerformanceView({
  website,
  onNavigateToOpportunities,
  onNavigateToKeywords,
  onCheckLiveRank,
}: SearchPerformanceViewProps) {
  const toast = useToast();

  const [activeTab, setActiveTab] = useState<'queries' | 'pages' | 'devices' | 'inspection'>('queries');
  const [dateRange, setDateRange] = useState<'7d' | '28d' | '3m'>('28d');
  const [deviceFilter, setDeviceFilter] = useState<string>('ALL');
  const [countryFilter, setCountryFilter] = useState<string>('ALL');
  const [searchFilter, setSearchFilter] = useState<string>('');

  const [status, setStatus] = useState<SearchConsoleStatus | null>(null);
  const [performance, setPerformance] = useState<SearchPerformanceData | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isSyncing, setIsSyncing] = useState<boolean>(false);
  const [isConnecting, setIsConnecting] = useState<boolean>(false);

  // URL Inspection state
  const [inspectUrlInput, setInspectUrlInput] = useState<string>('');
  const [inspectionResult, setInspectionResult] = useState<any | null>(null);
  const [isInspecting, setIsInspecting] = useState<boolean>(false);

  // Load Status and Performance
  const loadData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      // 1. Fetch GSC status
      const statusRes = await api.get<SearchConsoleStatus>(`/websites/${website.id}/search-console/status`);
      if (statusRes.success && statusRes.data) {
        setStatus(statusRes.data);

        // If connected, fetch performance
        if (statusRes.data.connected) {
          let perfEndpoint = `/websites/${website.id}/search-console/performance?range=${dateRange}`;
          if (deviceFilter !== 'ALL') perfEndpoint += `&device=${deviceFilter}`;
          if (countryFilter !== 'ALL') perfEndpoint += `&country=${countryFilter}`;
          if (searchFilter) perfEndpoint += `&query=${encodeURIComponent(searchFilter)}`;

          const perfRes = await api.get<SearchPerformanceData>(perfEndpoint);
          if (perfRes.success && perfRes.data) {
            setPerformance(perfRes.data);
          }
        }
      }
    } catch {
      toast.error('Failed to load Search Console performance data.');
    } finally {
      setIsLoading(false);
    }
  }, [website, dateRange, deviceFilter, countryFilter, searchFilter, toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Connect OAuth or Sandbox
  const handleConnect = async () => {
    if (!website) return;
    setIsConnecting(true);
    try {
      const res = await api.get<{ authorization_url: string }>(`/websites/${website.id}/search-console/connect`);
      if (res.success && res.data) {
        const url = res.data.authorization_url;
        if (url.includes('/sandbox-callback?')) {
          // Trigger sandbox callback directly (strip origin and /api/v1 prefix so api.get doesn't double-prefix)
          const relativeEndpoint = url.replace(/^https?:\/\/[^/]+/, '').replace(/^\/api\/v1/, '');
          const callbackRes = await api.get(relativeEndpoint);
          if (callbackRes.success) {
            toast.success('Connected to Google Search Console (Verified Property).');
            loadData();
          } else {
            toast.error(callbackRes.error?.message || 'Connection failed.');
          }
        } else {
          // Open live OAuth
          window.location.href = url;
        }
      }
    } catch {
      toast.error('Could not initiate Google Search Console authorization.');
    } finally {
      setIsConnecting(false);
    }
  };

  // Disconnect
  const handleDisconnect = async () => {
    if (!website) return;
    if (!confirm('Disconnect Google Search Console? Token credentials will be revoked.')) return;

    try {
      const res = await api.post(`/websites/${website.id}/search-console/disconnect`);
      if (res.success) {
        toast.info('Google Search Console disconnected.');
        setPerformance(null);
        loadData();
      }
    } catch {
      toast.error('Failed to disconnect Search Console.');
    }
  };

  // Select active Search Console property
  const handleSelectProperty = async (propertyId: string) => {
    if (!website || !propertyId) return;
    try {
      const res = await api.post(`/websites/${website.id}/search-console/property`, {
        property_id: propertyId,
      });
      if (res.success) {
        toast.success(`Switched active property to ${propertyId}`);
        loadData();
      } else {
        toast.error(res.error?.message || 'Could not update Search Console property.');
      }
    } catch {
      toast.error('Failed to update Search Console property.');
    }
  };

  // Trigger Sync
  const handleSync = async () => {
    if (!website) return;
    setIsSyncing(true);
    try {
      const res = await api.post<any>(`/websites/${website.id}/search-console/sync`);
      if (res.success) {
        toast.success(`Search Console sync completed. Ingested ${res.data?.rows_synced || 0} performance records.`);
        loadData();
      } else {
        toast.error(res.error?.message || 'Sync failed.');
      }
    } catch {
      toast.error('Sync failed due to a network error.');
    } finally {
      setIsSyncing(false);
    }
  };

  // Inspect URL
  const handleInspectUrl = async (targetUrl?: string) => {
    if (!website) return;
    const urlToInspect = targetUrl || inspectUrlInput;
    if (!urlToInspect) {
      toast.error('Please enter a full URL to inspect.');
      return;
    }

    setIsInspecting(true);
    try {
      const res = await api.post<any>(`/websites/${website.id}/search-console/inspect`, {
        url: urlToInspect,
      });

      if (res.success && res.data) {
        setInspectionResult(res.data);
        setActiveTab('inspection');
        setInspectUrlInput(urlToInspect);
        toast.success('Retrieved Google index state.');
      } else {
        toast.error(res.error?.message || 'URL inspection failed.');
      }
    } catch {
      toast.error('URL inspection encountered an error.');
    } finally {
      setIsInspecting(false);
    }
  };

  if (!website) {
    return (
      <div className="p-8 text-center text-zinc-400">
        Please select a website to view search performance data.
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Header & Connection Health Bar */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs font-semibold uppercase tracking-wider text-emerald-400">
              Phase 4: Search Performance
            </span>
            {status?.connected && (
              <span className="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center gap-1">
                <CheckCircle2 className="w-3 h-3" />
                {status.is_sandbox ? 'GSC Verified (Sandbox)' : 'GSC Live Connected'}
              </span>
            )}
          </div>
          <h1 className="text-xl font-extrabold text-zinc-100 flex items-center gap-2">
            Google Search Console Intelligence
          </h1>
          <p className="text-xs text-zinc-400">
            Real search analytics, page visibility, query CTR breakdowns, and opportunity detection.
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-wrap items-center gap-2.5">
          {onNavigateToOpportunities && (
            <button
              onClick={onNavigateToOpportunities}
              className="px-3 py-2 text-xs font-semibold text-amber-300 bg-amber-950/40 hover:bg-amber-900/50 border border-amber-800/50 rounded-xl transition flex items-center gap-1.5 shadow"
            >
              <Zap className="w-3.5 h-3.5 text-amber-400" />
              <span>Opportunities</span>
            </button>
          )}

          {onNavigateToKeywords && (
            <button
              onClick={onNavigateToKeywords}
              className="px-3 py-2 text-xs font-semibold text-cyan-300 bg-cyan-950/40 hover:bg-cyan-900/50 border border-cyan-800/50 rounded-xl transition flex items-center gap-1.5 shadow"
            >
              <Search className="w-3.5 h-3.5 text-cyan-400" />
              <span>Keyword Research</span>
            </button>
          )}

          {status?.connected ? (
            <>
              <button
                onClick={handleSync}
                disabled={isSyncing}
                className="px-3.5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition flex items-center gap-2 shadow"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin' : ''}`} />
                <span>{isSyncing ? 'Syncing...' : 'Sync Data'}</span>
              </button>

              <button
                onClick={handleDisconnect}
                className="px-3 py-2 text-xs font-semibold text-zinc-400 hover:text-rose-400 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition flex items-center gap-1.5"
                title="Disconnect Account"
              >
                <Unlink className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">Disconnect</span>
              </button>
            </>
          ) : (
            <button
              onClick={handleConnect}
              disabled={isConnecting}
              className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition flex items-center gap-2 shadow"
            >
              <Link2 className="w-3.5 h-3.5" />
              <span>{isConnecting ? 'Connecting...' : 'Connect Search Console'}</span>
            </button>
          )}
        </div>
      </div>

      {/* Disconnected State Callout */}
      {!status?.connected && !isLoading && (
        <div className="p-8 border border-zinc-800 rounded-2xl bg-zinc-950/60 text-center max-w-2xl mx-auto space-y-4">
          <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 mx-auto flex items-center justify-center">
            <Globe className="w-6 h-6" />
          </div>
          <div>
            <h3 className="text-base font-bold text-zinc-100">Connect Google Search Console</h3>
            <p className="text-xs text-zinc-400 mt-1 max-w-lg mx-auto">
              Sync real search analytics, identify striking distance keywords, track CTR decay, and unlock Google URL inspection.
            </p>
          </div>
          <div className="pt-2">
            <button
              onClick={handleConnect}
              disabled={isConnecting}
              className="px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl shadow transition"
            >
              {isConnecting
                ? 'Redirecting to Google...'
                : status?.oauth_mode === 'live'
                  ? 'Continue with Google Search Console'
                  : 'Authorize via OAuth (webmasters.readonly)'}
            </button>
          </div>
          {status?.oauth_mode === 'live' ? (
            <p className="text-[11px] text-emerald-400/90 flex items-center justify-center gap-1.5">
              <CheckCircle2 className="w-3.5 h-3.5" />
              Live Google OAuth 2.0 is active. You will be redirected to Google to grant read-only access.
            </p>
          ) : (
            <p className="text-[11px] text-amber-400/90 flex items-center justify-center gap-1.5">
              <Info className="w-3.5 h-3.5" />
              Sandbox mode active (add GOOGLE_SEARCH_CONSOLE_CLIENT_ID &amp; SECRET in Vercel env vars for live Google OAuth).
            </p>
          )}
          <p className="text-[11px] text-zinc-400 flex items-center justify-center gap-1.5">
            <ShieldCheck className="w-3.5 h-3.5 text-zinc-400" />
            Tokens are stored with AES-256 envelope encryption. Only read-only search analytics scopes are requested.
          </p>
        </div>
      )}

      {/* Connected Performance Dashboard */}
      {status?.connected && (
        <>
          {/* Health & Property Bar */}
          <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl flex flex-wrap items-center justify-between text-xs text-zinc-400 gap-3">
            <div className="flex items-center gap-2">
              <span className="font-semibold text-zinc-300">Property:</span>
              {status.properties && status.properties.length > 1 ? (
                <select
                  value={status.selected_property || ''}
                  onChange={(e) => handleSelectProperty(e.target.value)}
                  className="font-mono text-emerald-400 bg-zinc-950 px-2 py-1 rounded border border-zinc-800 focus:outline-none focus:border-emerald-500/60 text-xs"
                >
                  {status.properties.map((p) => (
                    <option key={p.siteUrl} value={p.siteUrl} className="bg-zinc-950 text-zinc-100">
                      {p.siteUrl} ({p.permissionLevel})
                    </option>
                  ))}
                </select>
              ) : (
                <span className="font-mono text-emerald-400 bg-zinc-950 px-2 py-0.5 rounded border border-zinc-800">
                  {status.selected_property || `sc-domain:${website.domain}`}
                </span>
              )}
            </div>
            <div className="flex items-center gap-4 text-[11px]">
              <div>
                <span className="text-zinc-400">Last Synced:</span>{' '}
                <span className="text-zinc-200">
                  {status.last_synced_at
                    ? new Date(status.last_synced_at).toLocaleString()
                    : 'Not synced yet'}
                </span>
              </div>
              <div>
                <span className="text-zinc-400">Ingested Rows:</span>{' '}
                <span className="text-zinc-200 font-mono">
                  {status.latest_sync_log?.rows_synced || 0}
                </span>
              </div>
            </div>
          </div>

          {/* Stats Cards with Comparison Indicators */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Total Clicks */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-1">
                Total Clicks
              </span>
              <div className="flex items-baseline justify-between">
                <span className="text-3xl font-extrabold text-zinc-100 font-mono">
                  {performance?.summary?.clicks?.toLocaleString() ?? '—'}
                </span>
                {performance?.summary?.comparison && (
                  <span
                    className={`text-xs font-bold flex items-center ${
                      performance.summary.comparison.diff_clicks >= 0
                        ? 'text-emerald-400'
                        : 'text-rose-400'
                    }`}
                  >
                    {performance.summary.comparison.diff_clicks >= 0 ? (
                      <ArrowUpRight className="w-3.5 h-3.5" />
                    ) : (
                      <ArrowDownRight className="w-3.5 h-3.5" />
                    )}
                    {performance.summary.comparison.diff_clicks >= 0 ? '+' : ''}
                    {performance.summary.comparison.diff_clicks}
                  </span>
                )}
              </div>
              <span className="text-[10px] text-zinc-400 mt-1 block">
                vs prior {dateRange} period
              </span>
            </div>

            {/* Total Impressions */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-1">
                Total Impressions
              </span>
              <div className="flex items-baseline justify-between">
                <span className="text-3xl font-extrabold text-zinc-100 font-mono">
                  {performance?.summary?.impressions?.toLocaleString() ?? '—'}
                </span>
                {performance?.summary?.comparison && (
                  <span
                    className={`text-xs font-bold flex items-center ${
                      performance.summary.comparison.diff_impressions >= 0
                        ? 'text-emerald-400'
                        : 'text-rose-400'
                    }`}
                  >
                    {performance.summary.comparison.diff_impressions >= 0 ? (
                      <ArrowUpRight className="w-3.5 h-3.5" />
                    ) : (
                      <ArrowDownRight className="w-3.5 h-3.5" />
                    )}
                    {performance.summary.comparison.diff_impressions >= 0 ? '+' : ''}
                    {performance.summary.comparison.diff_impressions?.toLocaleString()}
                  </span>
                )}
              </div>
              <span className="text-[10px] text-zinc-400 mt-1 block">
                vs prior {dateRange} period
              </span>
            </div>

            {/* Average CTR */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-1">
                Average CTR
              </span>
              <div className="flex items-baseline justify-between">
                <span className="text-3xl font-extrabold text-zinc-100 font-mono">
                  {performance?.summary?.ctr !== undefined ? `${performance.summary.ctr}%` : '—'}
                </span>
                {performance?.summary?.comparison && (
                  <span
                    className={`text-xs font-bold flex items-center ${
                      performance.summary.comparison.diff_ctr >= 0
                        ? 'text-emerald-400'
                        : 'text-rose-400'
                    }`}
                  >
                    {performance.summary.comparison.diff_ctr >= 0 ? '+' : ''}
                    {performance.summary.comparison.diff_ctr}%
                  </span>
                )}
              </div>
              <span className="text-[10px] text-zinc-400 mt-1 block">
                Click-through rate
              </span>
            </div>

            {/* Average Position */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 block mb-1">
                Average Position
              </span>
              <div className="flex items-baseline justify-between">
                <span className="text-3xl font-extrabold text-zinc-100 font-mono">
                  {performance?.summary?.position !== undefined ? performance.summary.position : '—'}
                </span>
                {performance?.summary?.comparison && (
                  <span
                    className={`text-xs font-bold flex items-center ${
                      performance.summary.comparison.diff_position <= 0
                        ? 'text-emerald-400'
                        : 'text-rose-400'
                    }`}
                  >
                    {performance.summary.comparison.diff_position <= 0 ? (
                      <ArrowUpRight className="w-3.5 h-3.5" />
                    ) : (
                      <ArrowDownRight className="w-3.5 h-3.5" />
                    )}
                    {performance.summary.comparison.diff_position > 0 ? '+' : ''}
                    {performance.summary.comparison.diff_position}
                  </span>
                )}
              </div>
              <span className="text-[10px] text-zinc-400 mt-1 block">
                Rank in Google SERPs (Lower is better)
              </span>
            </div>
          </div>

          {/* Filter Bar & Controls */}
          <div className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center justify-between gap-3">
            {/* Date Range Tabs */}
            <div className="flex items-center gap-1.5 bg-zinc-900 p-1 rounded-xl border border-zinc-800 text-xs">
              {(['7d', '28d', '3m'] as const).map((r) => (
                <button
                  key={r}
                  onClick={() => setDateRange(r)}
                  className={`px-3 py-1.5 rounded-lg font-semibold transition ${
                    dateRange === r
                      ? 'bg-emerald-600 text-white shadow'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  {r === '7d' ? 'Last 7 Days' : r === '28d' ? 'Last 28 Days' : 'Last 3 Months'}
                </button>
              ))}
            </div>

            {/* Filters: Search, Device, Country */}
            <div className="flex flex-wrap items-center gap-2 text-xs">
              {/* Search query input */}
              <div className="relative">
                <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400" />
                <input
                  type="text"
                  placeholder="Filter query or page..."
                  value={searchFilter}
                  onChange={(e) => setSearchFilter(e.target.value)}
                  className="bg-zinc-900 border border-zinc-800 rounded-xl pl-8 pr-3 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-emerald-500 w-44"
                />
              </div>

              {/* Device Selector */}
              <select
                value={deviceFilter}
                onChange={(e) => setDeviceFilter(e.target.value)}
                className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none"
              >
                <option value="ALL">All Devices</option>
                <option value="DESKTOP">Desktop</option>
                <option value="MOBILE">Mobile</option>
              </select>

              {/* Country Selector */}
              <select
                value={countryFilter}
                onChange={(e) => setCountryFilter(e.target.value)}
                className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none"
              >
                <option value="ALL">All Countries</option>
                <option value="USA">United States</option>
                <option value="GBR">United Kingdom</option>
                <option value="CAN">Canada</option>
                <option value="AUS">Australia</option>
              </select>
            </div>
          </div>

          {/* Interactive Timeseries Visual Trend */}
          {performance?.timeseries && performance.timeseries.length > 0 && (
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
              <div className="flex items-center justify-between mb-4">
                <span className="text-xs font-bold uppercase tracking-wider text-zinc-300">
                  Daily Clicks & Impressions Trend
                </span>
                <div className="flex items-center gap-4 text-xs">
                  <span className="flex items-center gap-1.5 text-emerald-400">
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                    Clicks
                  </span>
                  <span className="flex items-center gap-1.5 text-cyan-400">
                    <span className="w-2.5 h-2.5 rounded-full bg-cyan-500" />
                    Impressions
                  </span>
                </div>
              </div>

              {/* Bar visualization */}
              <div className="h-32 flex items-end gap-1 sm:gap-2 pt-4 overflow-x-auto">
                {performance.timeseries.map((pt, idx) => {
                  const maxClicks = Math.max(...performance.timeseries.map((p) => p.clicks), 1);
                  const heightPercent = Math.min(100, Math.max(10, Math.round((pt.clicks / maxClicks) * 100)));

                  return (
                    <div
                      key={idx}
                      className="flex-1 min-w-[12px] flex flex-col items-center gap-1 group relative"
                    >
                      {/* Hover Tooltip */}
                      <div className="absolute bottom-full mb-2 hidden group-hover:flex flex-col bg-zinc-900 border border-zinc-700 px-2 py-1 rounded shadow-xl text-[10px] whitespace-nowrap z-30">
                        <span className="font-semibold text-zinc-200">{pt.date}</span>
                        <span className="text-emerald-400">{pt.clicks} clicks</span>
                        <span className="text-cyan-400">{pt.impressions} imp</span>
                        <span className="text-zinc-400">Rank: {pt.position}</span>
                      </div>

                      <div
                        style={{ height: `${heightPercent}%` }}
                        className="w-full bg-emerald-500/80 hover:bg-emerald-400 rounded-t transition"
                      />
                      <span className="text-[9px] font-mono text-zinc-400 truncate w-full text-center">
                        {pt.date.slice(-2)}
                      </span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Sub Navigation Tabs */}
          <div className="flex items-center gap-2 border-b border-zinc-800 pb-2 text-xs">
            <button
              onClick={() => setActiveTab('queries')}
              className={`px-3 py-1.5 font-semibold rounded-xl transition ${
                activeTab === 'queries'
                  ? 'bg-zinc-900 text-emerald-400 border border-zinc-800'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              Queries ({performance?.queries?.length || 0})
            </button>
            <button
              onClick={() => setActiveTab('pages')}
              className={`px-3 py-1.5 font-semibold rounded-xl transition ${
                activeTab === 'pages'
                  ? 'bg-zinc-900 text-emerald-400 border border-zinc-800'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              Pages ({performance?.pages?.length || 0})
            </button>
            <button
              onClick={() => setActiveTab('devices')}
              className={`px-3 py-1.5 font-semibold rounded-xl transition ${
                activeTab === 'devices'
                  ? 'bg-zinc-900 text-emerald-400 border border-zinc-800'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              Devices & Countries
            </button>
            <button
              onClick={() => setActiveTab('inspection')}
              className={`px-3 py-1.5 font-semibold rounded-xl transition ${
                activeTab === 'inspection'
                  ? 'bg-zinc-900 text-emerald-400 border border-zinc-800'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              URL Inspection Tool
            </button>
          </div>

          {/* Tab 1: Queries Table */}
          {activeTab === 'queries' && (
            <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-900/60 text-zinc-400 font-semibold border-b border-zinc-800">
                    <tr>
                      <th className="px-4 py-3">Top Search Query</th>
                      <th className="px-4 py-3 text-right">Clicks</th>
                      <th className="px-4 py-3 text-right">Impressions</th>
                      <th className="px-4 py-3 text-right">CTR</th>
                      <th className="px-4 py-3 text-right">Avg Position</th>
                      {onCheckLiveRank && (
                        <th className="px-4 py-3 text-right">Live Google Check</th>
                      )}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/60 text-zinc-200">
                    {performance?.queries && performance.queries.length > 0 ? (
                      performance.queries.map((q, idx) => (
                        <tr key={idx} className="hover:bg-zinc-900/40 transition">
                          <td className="px-4 py-3 font-medium text-zinc-100 flex items-center gap-2">
                            <span className="text-zinc-400 font-mono text-[11px] w-5">
                              {idx + 1}.
                            </span>
                            <span>{q.query}</span>
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-bold text-emerald-400">
                            {q.clicks.toLocaleString()}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-zinc-300">
                            {q.impressions.toLocaleString()}
                          </td>
                          <td className="px-4 py-3 text-right font-mono">
                            <span
                              className={`px-1.5 py-0.5 rounded ${
                                q.ctr >= 5.0
                                  ? 'bg-emerald-500/10 text-emerald-400'
                                  : q.ctr <= 1.5
                                  ? 'bg-amber-500/10 text-amber-400'
                                  : 'text-zinc-300'
                              }`}
                            >
                              {q.ctr}%
                            </span>
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-semibold">
                            <span
                              className={`px-1.5 py-0.5 rounded ${
                                q.position <= 3.0
                                  ? 'text-emerald-400'
                                  : q.position <= 10.0
                                  ? 'text-cyan-400'
                                  : 'text-zinc-400'
                              }`}
                            >
                              {q.position}
                            </span>
                          </td>
                          {onCheckLiveRank && (
                            <td className="px-4 py-3 text-right">
                              <button
                                type="button"
                                onClick={() => onCheckLiveRank(q.query)}
                                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[11px] font-semibold transition whitespace-nowrap"
                              >
                                <Crosshair className="w-3 h-3" />
                                Check Live Rank
                              </button>
                            </td>
                          )}
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan={onCheckLiveRank ? 6 : 5} className="px-4 py-8 text-center text-zinc-400">
                          No query performance rows recorded for this period. Click Sync Data to refresh.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Tab 2: Pages Table */}
          {activeTab === 'pages' && (
            <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-900/60 text-zinc-400 font-semibold border-b border-zinc-800">
                    <tr>
                      <th className="px-4 py-3">Landing Page URL</th>
                      <th className="px-4 py-3 text-right">Clicks</th>
                      <th className="px-4 py-3 text-right">Impressions</th>
                      <th className="px-4 py-3 text-right">CTR</th>
                      <th className="px-4 py-3 text-right">Avg Position</th>
                      <th className="px-4 py-3 text-center">Inspect</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/60 text-zinc-200">
                    {performance?.pages && performance.pages.length > 0 ? (
                      performance.pages.map((p, idx) => (
                        <tr key={idx} className="hover:bg-zinc-900/40 transition">
                          <td className="px-4 py-3 font-medium text-zinc-100 max-w-md truncate">
                            <a
                              href={p.page}
                              target="_blank"
                              rel="noreferrer"
                              className="hover:text-emerald-400 transition"
                            >
                              {p.page.replace(`https://${website.domain}`, '') || '/'}
                            </a>
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-bold text-emerald-400">
                            {p.clicks.toLocaleString()}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-zinc-300">
                            {p.impressions.toLocaleString()}
                          </td>
                          <td className="px-4 py-3 text-right font-mono">
                            {p.ctr}%
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-semibold">
                            {p.position}
                          </td>
                          <td className="px-4 py-3 text-center">
                            <button
                              onClick={() => handleInspectUrl(p.page)}
                              className="px-2 py-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded text-[11px] text-zinc-300 hover:text-white transition"
                              title="Inspect URL Index State"
                            >
                              Inspect
                            </button>
                          </td>
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-zinc-400">
                          No page records found. Click Sync Data to refresh.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Tab 3: Devices & Countries */}
          {activeTab === 'devices' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {/* Devices Card */}
              <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
                <span className="text-xs font-bold uppercase tracking-wider text-zinc-300 block mb-3">
                  Device Distribution
                </span>
                <div className="space-y-3">
                  {performance?.devices && performance.devices.length > 0 ? (
                    performance.devices.map((d, idx) => (
                      <div key={idx} className="flex items-center justify-between text-xs">
                        <span className="flex items-center gap-2 text-zinc-300 capitalize">
                          {d.device.toLowerCase() === 'desktop' ? (
                            <Monitor className="w-4 h-4 text-zinc-400" />
                          ) : (
                            <Smartphone className="w-4 h-4 text-zinc-400" />
                          )}
                          {d.device.toLowerCase()}
                        </span>
                        <div className="flex items-center gap-3">
                          <span className="font-mono font-semibold text-emerald-400">
                            {d.clicks.toLocaleString()} clicks
                          </span>
                          <span className="font-mono text-zinc-400">
                            {d.impressions.toLocaleString()} imp
                          </span>
                        </div>
                      </div>
                    ))
                  ) : (
                    <span className="text-xs text-zinc-400">No device records yet.</span>
                  )}
                </div>
              </div>

              {/* Countries Card */}
              <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
                <span className="text-xs font-bold uppercase tracking-wider text-zinc-300 block mb-3">
                  Top Organic Countries
                </span>
                <div className="space-y-2.5">
                  {performance?.countries && performance.countries.length > 0 ? (
                    performance.countries.map((c, idx) => (
                      <div key={idx} className="flex items-center justify-between text-xs">
                        <span className="font-mono text-zinc-200">{c.country}</span>
                        <div className="flex items-center gap-3">
                          <span className="font-mono font-semibold text-emerald-400">
                            {c.clicks.toLocaleString()} clicks
                          </span>
                          <span className="font-mono text-zinc-400">
                            {c.impressions.toLocaleString()} imp
                          </span>
                        </div>
                      </div>
                    ))
                  ) : (
                    <span className="text-xs text-zinc-400">No country data yet.</span>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Tab 4: URL Inspection Tool */}
          {activeTab === 'inspection' && (
            <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm space-y-5">
              <div>
                <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                  <ShieldCheck className="w-4 h-4 text-emerald-400" />
                  Google URL Inspection API
                </h3>
                <p className="text-xs text-zinc-400 mt-1">
                  Query Google Search Console index status, crawl verdicts, canonical URLs, and robots.txt directives for any page.
                </p>
              </div>

              {/* Input Form */}
              <div className="flex flex-col sm:flex-row gap-2.5">
                <input
                  type="url"
                  placeholder={`https://${website.domain}/example-page`}
                  value={inspectUrlInput}
                  onChange={(e) => setInspectUrlInput(e.target.value)}
                  className="flex-1 bg-zinc-900 border border-zinc-800 rounded-xl px-4 py-2 text-xs text-zinc-200 focus:outline-none focus:border-emerald-500"
                />
                <button
                  onClick={() => handleInspectUrl()}
                  disabled={isInspecting || !inspectUrlInput}
                  className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl text-xs font-semibold shadow transition flex items-center gap-2"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${isInspecting ? 'animate-spin' : ''}`} />
                  <span>{isInspecting ? 'Inspecting...' : 'Inspect URL'}</span>
                </button>
              </div>

              {/* Inspection Output */}
              {inspectionResult && (
                <div className="p-4 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-4">
                  <div className="flex items-center justify-between border-b border-zinc-800 pb-2">
                    <span className="text-xs font-semibold text-zinc-300">
                      Result for: <span className="font-mono text-zinc-100">{inspectionResult.url}</span>
                    </span>
                    <span
                      className={`px-2 py-0.5 rounded text-[11px] font-bold ${
                        inspectionResult.inspection_result.verdict === 'PASS'
                          ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                          : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                      }`}
                    >
                      Verdict: {inspectionResult.inspection_result.verdict}
                    </span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                      <span className="text-zinc-400">Coverage State:</span>{' '}
                      <span className="text-zinc-200 font-medium">
                        {inspectionResult.inspection_result.coverage_state}
                      </span>
                    </div>
                    <div>
                      <span className="text-zinc-400">Indexing State:</span>{' '}
                      <span className="text-zinc-200 font-medium">
                        {inspectionResult.inspection_result.indexing_state}
                      </span>
                    </div>
                    <div>
                      <span className="text-zinc-400">Robots.txt Directive:</span>{' '}
                      <span className="text-zinc-200 font-medium">
                        {inspectionResult.inspection_result.robots_txt_state}
                      </span>
                    </div>
                    <div>
                      <span className="text-zinc-400">Last Crawled by Google:</span>{' '}
                      <span className="text-zinc-200 font-medium">
                        {inspectionResult.inspection_result.last_crawl_time
                          ? new Date(inspectionResult.inspection_result.last_crawl_time).toLocaleString()
                          : 'Not recorded'}
                      </span>
                    </div>
                  </div>

                  {/* Required Disclaimer */}
                  <div className="p-3 bg-zinc-950 border border-zinc-800 rounded-lg text-[11px] text-zinc-400 flex items-start gap-2">
                    <Info className="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
                    <span>{inspectionResult.disclaimer}</span>
                  </div>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}
