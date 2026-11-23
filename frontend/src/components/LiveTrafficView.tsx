'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Radio,
  Globe,
  MapPin,
  RefreshCw,
  Copy,
  Check,
  Monitor,
  Smartphone,
  Tablet,
  Code2,
  Play,
  Pause,
  Send,
  FileText,
  Compass,
  Clock,
  Users,
  Eye,
  X,
  CheckCircle2,
  AlertTriangle,
  ShieldCheck,
  Trash2,
  BarChart3,
  Link2,
  Unlink,
} from 'lucide-react';
import { Website } from '@/types';
import { api } from '@/lib/api';
import { countryFlag } from '@/lib/countries';
import { useToast } from '@/context/ToastContext';

interface LiveTrafficViewProps {
  website: Website;
}

export function LiveTrafficView({ website }: LiveTrafficViewProps) {
  const toast = useToast();
  const [range, setRange] = useState<'30m' | '24h' | '7d' | '30d'>('24h');
  const [source, setSource] = useState<'auto' | 'ga4' | 'tracker'>('auto');
  const [includeTest, setIncludeTest] = useState(false);
  const [countryFilter, setCountryFilter] = useState<string | null>(null);
  const [data, setData] = useState<any>(null);
  const [installCheck, setInstallCheck] = useState<any>(null);
  const [isCheckingInstall, setIsCheckingInstall] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [showSetup, setShowSetup] = useState(false);
  const [setupPlatform, setSetupPlatform] = useState<'html' | 'wordpress' | 'shopify' | 'nextjs'>('wordpress');
  const [copied, setCopied] = useState(false);
  const [isPinging, setIsPinging] = useState(false);
  const [isConnectingGa4, setIsConnectingGa4] = useState(false);
  const [manualPropertyId, setManualPropertyId] = useState('');
  const [isSavingProp, setIsSavingProp] = useState(false);

  const loadRealtime = useCallback(
    async (silent = false) => {
      if (!silent) setIsLoading(true);
      try {
        const qs = new URLSearchParams({
          range,
          source,
          include_test: includeTest ? '1' : '0',
        });
        if (countryFilter) qs.set('country', countryFilter);
        const res = await api.get<any>(`/websites/${website.id}/traffic/realtime?${qs.toString()}`);
        if (res.success && res.data) {
          setData(res.data);
          if (res.data.ga4?.selected_property_id && !manualPropertyId) {
            setManualPropertyId(res.data.ga4.selected_property_id);
          }
          const hasLiveSource =
            res.data.data_source === 'google_analytics_4' || Boolean(res.data.tracker?.has_real_events);
          if (!hasLiveSource && !silent) {
            setShowSetup(true);
          }
        }
      } catch {
        if (!silent) toast.error('Failed to load live traffic analytics.');
      } finally {
        if (!silent) setIsLoading(false);
      }
    },
    [website.id, range, source, includeTest, countryFilter, toast, manualPropertyId]
  );

  const runInstallCheck = useCallback(
    async (showToast = false) => {
      setIsCheckingInstall(true);
      try {
        const res = await api.post<any>(`/websites/${website.id}/traffic/verify-install`, {});
        if (res.success && res.data) {
          setInstallCheck(res.data);
          if (showToast) {
            if (res.data.tracker_installed) {
              toast.success(res.data.message);
            } else {
              toast.info(res.data.message);
            }
          }
        }
      } catch {
        if (showToast) toast.error('Could not check live site installation.');
      } finally {
        setIsCheckingInstall(false);
      }
    },
    [website.id, toast]
  );

  useEffect(() => {
    setInstallCheck(null);
    setManualPropertyId('');
    loadRealtime(false);
    runInstallCheck(false);
  }, [website.id]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    loadRealtime(false);
  }, [range, source, includeTest, countryFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  // Real-time 10-second live polling while Live Mode is active
  useEffect(() => {
    if (!autoRefresh) return;
    const timer = setInterval(() => {
      loadRealtime(true);
    }, 10000);
    return () => clearInterval(timer);
  }, [autoRefresh, loadRealtime]);

  const handleCopySnippet = async () => {
    const snippet = data?.tracker?.script_tag || '';
    if (!snippet) return;
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied(true);
      toast.success('Live tracker script copied! Paste it in your website <head>.');
      setTimeout(() => setCopied(false), 2500);
    } catch {
      toast.error('Could not copy to clipboard.');
    }
  };

  const handleTestPing = async () => {
    setIsPinging(true);
    try {
      const res = await api.post(`/websites/${website.id}/traffic/test-ping`, {
        path: '/',
        title: `${website.name} — Live Test Visit`,
        referrer: 'https://www.google.com/',
      });
      if (res.success) {
        setIncludeTest(true);
        toast.success('Test visit recorded separately from real website traffic.');
        await loadRealtime(true);
      } else {
        toast.error(res.error?.message || 'Test ping failed.');
      }
    } finally {
      setIsPinging(false);
    }
  };

  const handleClearTestEvents = async () => {
    const res = await api.delete(`/websites/${website.id}/traffic/test-events`);
    if (res.success) {
      setIncludeTest(false);
      toast.success('Cleared all synthetic test visits.');
      await loadRealtime(true);
    }
  };

  const handleConnectGa4 = async () => {
    setIsConnectingGa4(true);
    try {
      const res = await api.get<any>(`/websites/${website.id}/traffic/ga4/connect`);
      if (res.success && res.data) {
        if (res.data.reused_google_oauth) {
          toast.success('Google Analytics 4 connected using your existing Google authorization!');
          setSource('ga4');
          await loadRealtime(false);
        } else if (res.data.authorization_url) {
          window.location.href = res.data.authorization_url;
        }
      } else {
        toast.error(res.error?.message || 'Could not initiate GA4 connection.');
      }
    } catch {
      toast.error('Network error connecting Google Analytics 4.');
    } finally {
      setIsConnectingGa4(false);
    }
  };

  const handleSaveGa4Property = async (propId: string) => {
    const clean = propId.trim();
    if (!clean) return;
    setIsSavingProp(true);
    try {
      const res = await api.post(`/websites/${website.id}/traffic/ga4/property`, {
        property_id: clean,
      });
      if (res.success) {
        setManualPropertyId(clean.replace(/^properties\//, ''));
        setSource('ga4');
        toast.success('GA4 Property selected! Pulling live traffic...');
        await loadRealtime(false);
      } else {
        toast.error(res.error?.message || 'Could not save GA4 Property ID.');
      }
    } finally {
      setIsSavingProp(false);
    }
  };

  const handleDisconnectGa4 = async () => {
    const res = await api.post(`/websites/${website.id}/traffic/ga4/disconnect`, {});
    if (res.success) {
      toast.info('Disconnected Google Analytics 4.');
      setSource('auto');
      await loadRealtime(false);
    }
  };

  const summary = data?.summary || {};
  const tracker = data?.tracker || {};
  const ga4 = data?.ga4 || {};
  const activeDataSource = data?.data_source || 'first_party_tracker';
  const timeline: Array<{ label: string; pageviews: number; visitors: number }> = data?.timeline || [];
  const byCountry: any[] = data?.by_country || [];
  const byRegion: any[] = data?.by_region || [];
  const topPages: any[] = data?.top_pages || [];
  const topReferrers: any[] = data?.top_referrers || [];
  const devices: any[] = data?.devices || [];
  const liveFeed: any[] = data?.live_feed || [];

  const maxTimelinePv = Math.max(1, ...timeline.map((t) => t.pageviews));
  const maxCountryVisitors = Math.max(1, ...byCountry.map((c) => c.visitors));

  const formatAgo = (secs: number) => {
    if (secs < 5) return 'just now';
    if (secs < 60) return `${secs}s ago`;
    if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
    if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
    return `${Math.floor(secs / 86400)}d ago`;
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Top Control Header */}
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-[11px] font-bold text-emerald-400">
              <span className={`w-2 h-2 rounded-full bg-emerald-400 ${autoRefresh ? 'animate-ping' : ''}`} />
              <span>REAL-TIME LIVE TRAFFIC</span>
            </span>
            <span className="text-xs font-mono text-zinc-300">{website.domain}</span>
            <span
              className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                activeDataSource === 'google_analytics_4'
                  ? 'bg-sky-500/15 text-sky-300 border-sky-500/30'
                  : tracker.has_real_events
                  ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                  : 'bg-amber-500/15 text-amber-300 border-amber-500/30'
              }`}
            >
              {activeDataSource === 'google_analytics_4'
                ? `Source: Google Analytics 4 (${data?.property_name || ga4.selected_property_id})`
                : tracker.has_real_events
                ? `Source: 1st-Party Live Script (${tracker.real_events_all_time} real hits)`
                : 'Awaiting Live Connection'}
            </span>
          </div>
          <p className="text-xs text-zinc-400">
            Exact live visitors right now, Country, Region/State, City, live pages, traffic sources &amp; devices — powered by Google Analytics 4 Realtime or Web SEO AI&apos;s zero-CORS 1st-party live tracker.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
          {/* Source Selector */}
          <select
            value={source}
            onChange={(e) => setSource(e.target.value as 'auto' | 'ga4' | 'tracker')}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-2 text-[11px] text-zinc-200 font-medium"
            aria-label="Live Data Source"
          >
            <option value="auto">Source: Auto (Best Live)</option>
            <option value="ga4">Google Analytics 4 (GA4)</option>
            <option value="tracker">Web SEO AI 1st-Party Script</option>
          </select>

          {/* Range Pills */}
          <div className="inline-flex items-center bg-zinc-900 border border-zinc-800 rounded-xl p-0.5">
            {(
              [
                { id: '30m', label: '30m Live' },
                { id: '24h', label: '24h' },
                { id: '7d', label: '7d' },
                { id: '30d', label: '30d' },
              ] as const
            ).map((opt) => (
              <button
                key={opt.id}
                type="button"
                onClick={() => setRange(opt.id)}
                className={`px-2.5 py-1.5 rounded-lg text-[11px] font-semibold transition ${
                  range === opt.id
                    ? 'bg-emerald-600 text-white'
                    : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>

          <button
            type="button"
            onClick={() => setAutoRefresh((v) => !v)}
            className={`px-3 py-2 rounded-xl border text-xs font-semibold inline-flex items-center gap-1.5 transition ${
              autoRefresh
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                : 'bg-zinc-900 border-zinc-800 text-zinc-400'
            }`}
          >
            {autoRefresh ? <Pause className="w-3.5 h-3.5" /> : <Play className="w-3.5 h-3.5" />}
            <span>{autoRefresh ? 'Live (10s)' : 'Paused'}</span>
          </button>

          <button
            type="button"
            onClick={() => setShowSetup((v) => !v)}
            className="px-3 py-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-200 font-semibold inline-flex items-center gap-1.5 transition"
          >
            <Code2 className="w-3.5 h-3.5 text-sky-400" />
            <span>{showSetup ? 'Hide Setup' : 'Data Sources & Setup'}</span>
          </button>

          <button
            type="button"
            onClick={() => loadRealtime(false)}
            disabled={isLoading}
            className="p-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 transition"
            title="Refresh live traffic now"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-emerald-400' : ''}`} />
          </button>
        </div>
      </div>

      {/* Live Installation & Auto-Detection Banner */}
      {installCheck && activeDataSource !== 'google_analytics_4' && !installCheck.tracker_installed && (
        <div className="p-4 rounded-2xl bg-amber-950/25 border border-amber-600/40 flex flex-col md:flex-row md:items-center justify-between gap-3 text-xs">
          <div className="flex items-start gap-2.5">
            <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
            <div className="space-y-0.5">
              <div className="font-bold text-amber-200">
                {installCheck.detected_ga4_id
                  ? `Google Analytics 4 (${installCheck.detected_ga4_id}) is ALREADY live on ${website.domain}!`
                  : `Live Tracker Script is NOT installed on ${website.domain} yet`}
              </div>
              <p className="text-zinc-300">
                {installCheck.detected_ga4_id
                  ? `Connect Google Analytics 4 below in 1 click to stream exact live visitors immediately (zero code edit), OR install the 1-line Web SEO AI script.`
                  : `Real visitors can only be tracked once the 1-line script is placed in ${website.domain}'s <head> OR Google Analytics 4 is connected.`}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            {installCheck.detected_ga4_id && !ga4.connected && (
              <button
                type="button"
                onClick={handleConnectGa4}
                disabled={isConnectingGa4}
                className="px-3.5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold transition inline-flex items-center gap-1.5"
              >
                <BarChart3 className="w-3.5 h-3.5" />
                <span>{isConnectingGa4 ? 'Connecting...' : 'Connect GA4 Now'}</span>
              </button>
            )}
            <button
              type="button"
              onClick={() => setShowSetup(true)}
              className="px-3 py-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 font-semibold transition"
            >
              Open Setup
            </button>
          </div>
        </div>
      )}

      {/* GA4 Error Notice (if Analytics Data API needs enabling in Google Cloud Console) */}
      {data?.ga4_error && (
        <div className="p-4 rounded-2xl bg-rose-950/25 border border-rose-600/40 text-xs text-rose-200 flex items-start gap-2.5">
          <AlertTriangle className="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
          <div className="space-y-1">
            <div className="font-bold">Google Analytics 4 API Notice</div>
            <p className="text-rose-200/90">{data.ga4_error}</p>
            <p className="text-zinc-400">
              Make sure <strong>Google Analytics Data API</strong> and <strong>Google Analytics Admin API</strong> are enabled in your Google Cloud project, and your numeric GA4 Property ID is selected below.
            </p>
          </div>
        </div>
      )}

      {/* Dual Exact Data Source Setup Panel: Option 1 (GA4 Realtime) + Option 2 (1st-Party Script) */}
      {showSetup && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          {/* Option 1: Native Google Analytics 4 (GA4) Realtime */}
          <div className="p-5 bg-zinc-950 border border-sky-500/30 rounded-2xl space-y-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                  <BarChart3 className="w-4 h-4 text-sky-400" />
                  Option 1: Google Analytics 4 (GA4) Realtime
                  {ga4.connected && (
                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                      Connected
                    </span>
                  )}
                </h3>
                <p className="text-xs text-zinc-400 mt-1">
                  Zero code change! Pull exact live visitors, Country, Region, City, Pages &amp; Sources directly from your site&apos;s existing GA4 property
                  {installCheck?.detected_ga4_id ? ` (Detected: ${installCheck.detected_ga4_id})` : ''}.
                </p>
              </div>
            </div>

            {!ga4.connected ? (
              <button
                type="button"
                onClick={handleConnectGa4}
                disabled={isConnectingGa4}
                className="w-full py-2.5 px-4 rounded-xl bg-sky-600 hover:bg-sky-500 disabled:opacity-50 text-white text-xs font-semibold inline-flex items-center justify-center gap-2 transition"
              >
                <Link2 className="w-4 h-4" />
                <span>{isConnectingGa4 ? 'Connecting Google Analytics 4...' : 'Connect Google Analytics 4 (1-Click OAuth)'}</span>
              </button>
            ) : (
              <div className="space-y-3 text-xs">
                {Array.isArray(ga4.properties) && ga4.properties.length > 0 && (
                  <div>
                    <label className="block text-zinc-400 mb-1 font-medium">Select GA4 Property</label>
                    <select
                      value={ga4.selected_property_id || ''}
                      onChange={(e) => handleSaveGa4Property(e.target.value)}
                      className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100"
                    >
                      <option value="">-- Choose GA4 Property --</option>
                      {ga4.properties.map((p: any) => (
                        <option key={p.property_id} value={p.property_id}>
                          {p.display_name} ({p.property_id}){p.account_name ? ` — ${p.account_name}` : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                )}

                <div>
                  <label className="block text-zinc-400 mb-1 font-medium">
                    Or Enter Numeric GA4 Property ID (Admin → Property Details)
                  </label>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={manualPropertyId}
                      onChange={(e) => setManualPropertyId(e.target.value)}
                      placeholder="e.g. 412345678"
                      className="flex-1 px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-100 font-mono"
                    />
                    <button
                      type="button"
                      disabled={isSavingProp || !manualPropertyId.trim()}
                      onClick={() => handleSaveGa4Property(manualPropertyId)}
                      className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold"
                    >
                      {isSavingProp ? 'Saving...' : 'Save ID'}
                    </button>
                  </div>
                </div>

                <div className="flex items-center justify-between pt-1">
                  <button
                    type="button"
                    onClick={handleConnectGa4}
                    className="text-sky-400 hover:text-sky-300 font-medium"
                  >
                    Re-authorize Google Account
                  </button>
                  <button
                    type="button"
                    onClick={handleDisconnectGa4}
                    className="text-rose-400 hover:text-rose-300 inline-flex items-center gap-1 font-medium"
                  >
                    <Unlink className="w-3 h-3" /> Disconnect GA4
                  </button>
                </div>
              </div>
            )}
          </div>

          {/* Option 2: Web SEO AI 1st-Party Zero-CORS Live Tracker */}
          <div className="p-5 bg-zinc-950 border border-emerald-500/30 rounded-2xl space-y-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                  <Code2 className="w-4 h-4 text-emerald-400" />
                  Option 2: 1st-Party Live Tracker Script
                  {installCheck?.tracker_installed && (
                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 inline-flex items-center gap-1">
                      <CheckCircle2 className="w-3 h-3" /> Installed Live
                    </span>
                  )}
                </h3>
                <p className="text-xs text-zinc-400 mt-1">
                  Zero-CORS, cookie-free (&lt;1.8 KB), bot-filtered live stream. Paste inside <code className="text-emerald-300">&lt;head&gt;</code> on {website.domain}.
                </p>
              </div>
              <button
                type="button"
                onClick={() => runInstallCheck(true)}
                disabled={isCheckingInstall}
                className="shrink-0 px-3 py-1.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 text-[11px] font-semibold inline-flex items-center gap-1.5"
              >
                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                <span>{isCheckingInstall ? 'Checking...' : 'Verify Live'}</span>
              </button>
            </div>

            {/* Copyable Script Box */}
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 p-2.5 rounded-xl bg-zinc-900 border border-zinc-800">
              <code className="flex-1 text-[11px] font-mono text-emerald-400 break-all">
                {tracker.script_tag || 'Loading tracker snippet...'}
              </code>
              <button
                type="button"
                onClick={handleCopySnippet}
                className="shrink-0 px-3 py-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-100 text-xs font-semibold inline-flex items-center justify-center gap-1.5 transition"
              >
                {copied ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copied ? 'Copied!' : 'Copy'}</span>
              </button>
            </div>

            {/* Platform Quick Guides */}
            <div className="space-y-2">
              <div className="flex flex-wrap gap-1.5 text-[11px]">
                {(
                  [
                    { id: 'wordpress', label: 'WordPress' },
                    { id: 'shopify', label: 'Shopify' },
                    { id: 'nextjs', label: 'Next.js' },
                    { id: 'html', label: 'HTML / Laravel' },
                  ] as const
                ).map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => setSetupPlatform(p.id)}
                    className={`px-2.5 py-1 rounded-lg border font-medium transition ${
                      setupPlatform === p.id
                        ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-300'
                        : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-zinc-200'
                    }`}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
              <div className="text-[11px] text-zinc-400 bg-zinc-900/50 border border-zinc-800/80 rounded-xl p-2.5">
                {setupPlatform === 'wordpress' && (
                  <span>WP Admin → <strong>Code Snippets / WPCode → Header &amp; Footer</strong> (or <strong>Appearance → Theme File Editor → header.php</strong>), paste before <code className="text-zinc-200">&lt;/head&gt;</code> &amp; clear WP cache (WP Rocket / LiteSpeed).</span>
                )}
                {setupPlatform === 'shopify' && (
                  <span>Shopify Admin → <strong>Online Store → Themes → Edit Code → theme.liquid</strong>, paste before <code className="text-zinc-200">&lt;/head&gt;</code> and Save.</span>
                )}
                {setupPlatform === 'nextjs' && (
                  <span>Add <code className="text-zinc-200">&lt;Script src=&quot;{tracker.script_url}&quot; strategy=&quot;afterInteractive&quot; /&gt;</code> inside <code className="text-zinc-200">app/layout.tsx</code>.</span>
                )}
                {setupPlatform === 'html' && (
                  <span>Paste the <code className="text-zinc-200">&lt;script defer ...&gt;</code> line directly before <code className="text-zinc-200">&lt;/head&gt;</code> in your main layout file.</span>
                )}
              </div>
            </div>

            {/* Test Controls (Strictly Isolated from Real Traffic) */}
            <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-zinc-800/80 text-[11px]">
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleTestPing}
                  disabled={isPinging}
                  className="px-2.5 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 font-medium inline-flex items-center gap-1"
                >
                  <Send className="w-3 h-3 text-emerald-400" />
                  <span>{isPinging ? 'Sending...' : 'Send Test Visit'}</span>
                </button>
                {(tracker.test_events_all_time ?? 0) > 0 && (
                  <>
                    <label className="inline-flex items-center gap-1.5 text-zinc-400 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={includeTest}
                        onChange={(e) => setIncludeTest(e.target.checked)}
                        className="accent-emerald-500"
                      />
                      <span>Include {tracker.test_events_all_time} test hit(s)</span>
                    </label>
                    <button
                      type="button"
                      onClick={handleClearTestEvents}
                      className="text-rose-400 hover:text-rose-300 inline-flex items-center gap-1"
                    >
                      <Trash2 className="w-3 h-3" /> Clear Tests
                    </button>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Executive Live KPI Strip */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="p-5 bg-zinc-950 border border-emerald-500/30 rounded-2xl relative overflow-hidden">
          <div className="flex items-center justify-between text-xs text-zinc-400 mb-2">
            <span className="font-semibold text-emerald-400 flex items-center gap-1.5">
              <Radio className="w-3.5 h-3.5 animate-pulse" /> Active Visitors Now
            </span>
            <span className="text-[10px] font-mono text-zinc-500">
              {activeDataSource === 'google_analytics_4' ? 'GA4 Live' : 'Last 5 min'}
            </span>
          </div>
          <div className="text-3xl font-black font-mono text-zinc-100">
            {(summary.active_visitors_now ?? 0).toLocaleString()}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1">
            Across {summary.active_pages_now ?? 0} active page(s) right now
          </div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="flex items-center justify-between text-xs text-zinc-400 mb-2">
            <span className="font-semibold flex items-center gap-1.5">
              <Users className="w-3.5 h-3.5 text-sky-400" /> Unique Visitors ({range})
            </span>
          </div>
          <div className="text-3xl font-black font-mono text-zinc-100">
            {(summary.unique_visitors_range ?? 0).toLocaleString()}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {(summary.visitors_30m ?? 0).toLocaleString()} in the last 30 mins
          </div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="flex items-center justify-between text-xs text-zinc-400 mb-2">
            <span className="font-semibold flex items-center gap-1.5">
              <Eye className="w-3.5 h-3.5 text-purple-400" /> Pageviews ({range})
            </span>
          </div>
          <div className="text-3xl font-black font-mono text-zinc-100">
            {(summary.pageviews_range ?? 0).toLocaleString()}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1">
            {(summary.pageviews_30m ?? 0).toLocaleString()} pageviews in last 30 mins
          </div>
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <div className="flex items-center justify-between text-xs text-zinc-400 mb-2">
            <span className="font-semibold flex items-center gap-1.5">
              <Globe className="w-3.5 h-3.5 text-amber-400" /> Top Visitor Country
            </span>
            <span className="text-[10px] font-mono text-zinc-500">{summary.countries_count ?? 0} countries</span>
          </div>
          <div className="text-lg font-extrabold text-zinc-100 truncate">
            {summary.top_country
              ? `${countryFlag(summary.top_country.country_code)} ${summary.top_country.country_name}`
              : 'No real visits yet'}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1 truncate">
            {summary.top_country
              ? `${summary.top_country.visitors} visitors (${summary.top_country.share_pct}%)`
              : 'Connect GA4 or install script above'}
          </div>
        </div>
      </div>

      {/* Real-Time Traffic Activity Bar Chart */}
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Clock className="w-4 h-4 text-emerald-400" />
            Traffic Timeline ({range === '30m' || activeDataSource === 'google_analytics_4' ? 'Minute-by-Minute (Last 30m Live)' : range === '24h' ? 'Hourly (Last 24h)' : `Daily (Last ${range})`})
          </h3>
          {countryFilter && (
            <button
              type="button"
              onClick={() => setCountryFilter(null)}
              className="inline-flex items-center gap-1 text-[11px] px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30"
            >
              <span>Filtered: {countryFlag(countryFilter)} {countryFilter}</span>
              <X className="w-3 h-3" />
            </button>
          )}
        </div>

        <div className="h-36 flex items-end gap-1 pt-4">
          {timeline.map((bucket, idx) => {
            const pct = bucket.pageviews > 0 ? Math.max(8, Math.round((bucket.pageviews / maxTimelinePv) * 100)) : 3;
            return (
              <div
                key={idx}
                className="flex-1 h-full flex flex-col justify-end group relative"
                title={`${bucket.label}: ${bucket.pageviews} pageviews, ${bucket.visitors} visitors`}
              >
                <div
                  className={`w-full rounded-t transition-all ${
                    bucket.pageviews > 0
                      ? 'bg-emerald-500/80 group-hover:bg-emerald-400'
                      : 'bg-zinc-800/60'
                  }`}
                  style={{ height: `${pct}%` }}
                />
              </div>
            );
          })}
        </div>
        <div className="flex items-center justify-between text-[10px] font-mono text-zinc-500">
          <span>{timeline[0]?.label || ''}</span>
          <span>{timeline[Math.floor(timeline.length / 2)]?.label || ''}</span>
          <span>{timeline[timeline.length - 1]?.label || 'Now'}</span>
        </div>
      </div>

      {/* Country & Region/City Geo Intelligence (2-Column Grid) */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Visitors by Country */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <Globe className="w-4 h-4 text-emerald-400" />
              Visitors by Country ({byCountry.length})
            </h3>
            <span className="text-[10px] text-zinc-500">Click country to filter regions</span>
          </div>

          {byCountry.length === 0 ? (
            <div className="p-6 text-center rounded-xl bg-zinc-900/50 border border-dashed border-zinc-800 text-xs text-zinc-400">
              No real visitor country data yet. Connect GA4 or install the tracker script above.
            </div>
          ) : (
            <div className="space-y-2.5 max-h-80 overflow-y-auto pr-1">
              {byCountry.map((c) => {
                const selected = countryFilter === c.country_code;
                const widthPct = Math.max(6, Math.round((c.visitors / maxCountryVisitors) * 100));
                return (
                  <button
                    key={c.country_code}
                    type="button"
                    onClick={() => setCountryFilter(selected ? null : c.country_code)}
                    className={`w-full text-left p-3 rounded-xl border transition space-y-1.5 ${
                      selected
                        ? 'bg-emerald-950/30 border-emerald-500/50'
                        : 'bg-zinc-900/50 border-zinc-800 hover:border-zinc-700'
                    }`}
                  >
                    <div className="flex items-center justify-between text-xs">
                      <div className="flex items-center gap-2 min-w-0">
                        <span className="text-base leading-none">{countryFlag(c.country_code)}</span>
                        <span className="font-semibold text-zinc-100 truncate">{c.country_name}</span>
                        <span className="text-[10px] font-mono text-zinc-500">{c.country_code}</span>
                        {c.active_now > 0 && (
                          <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400">
                            {c.active_now} live
                          </span>
                        )}
                      </div>
                      <div className="font-mono text-[11px] text-zinc-300 shrink-0">
                        <strong>{c.visitors}</strong> visitors • {c.pageviews} views ({c.share_pct}%)
                      </div>
                    </div>
                    <div className="w-full h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                      <div className="h-full bg-emerald-500 rounded-full" style={{ width: `${widthPct}%` }} />
                    </div>
                    {Array.isArray(c.top_regions) && c.top_regions.length > 0 && (
                      <div className="text-[10px] text-zinc-500 truncate">
                        Regions: {c.top_regions.join(', ')}
                      </div>
                    )}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {/* Visitors by Region / State & City */}
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <MapPin className="w-4 h-4 text-sky-400" />
              Visitors by Region / State &amp; City ({byRegion.length})
            </h3>
            {countryFilter && (
              <button
                type="button"
                onClick={() => setCountryFilter(null)}
                className="text-[11px] text-sky-400 hover:underline"
              >
                Show All Countries
              </button>
            )}
          </div>

          {byRegion.length === 0 ? (
            <div className="p-6 text-center rounded-xl bg-zinc-900/50 border border-dashed border-zinc-800 text-xs text-zinc-400">
              No region/city breakdown recorded yet.
            </div>
          ) : (
            <div className="overflow-x-auto max-h-80 overflow-y-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[10px] uppercase">
                  <tr>
                    <th className="px-3 py-2">City / Region</th>
                    <th className="px-3 py-2">Country</th>
                    <th className="px-3 py-2 text-right">Visitors</th>
                    <th className="px-3 py-2 text-right">Views</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/70">
                  {byRegion.map((r, i) => (
                    <tr key={i} className="hover:bg-zinc-900/40">
                      <td className="px-3 py-2.5">
                        <div className="font-semibold text-zinc-100 flex items-center gap-1.5">
                          <span>{r.city}</span>
                          {r.active_now > 0 && (
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" title={`${r.active_now} active now`} />
                          )}
                        </div>
                        <div className="text-[10px] text-zinc-500">{r.region}</div>
                      </td>
                      <td className="px-3 py-2.5 text-zinc-300 whitespace-nowrap">
                        {countryFlag(r.country_code)} {r.country_name}
                      </td>
                      <td className="px-3 py-2.5 text-right font-mono font-semibold text-zinc-200">{r.visitors}</td>
                      <td className="px-3 py-2.5 text-right font-mono text-zinc-400">{r.pageviews}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Top Pages, Traffic Sources & Devices (3-Column Grid) */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <FileText className="w-4 h-4 text-emerald-400" />
            Top Active Pages
          </h3>
          {topPages.length === 0 ? (
            <p className="text-xs text-zinc-500">No pageviews recorded yet.</p>
          ) : (
            <div className="space-y-2 text-xs">
              {topPages.slice(0, 8).map((p, i) => (
                <div key={i} className="p-2.5 rounded-xl bg-zinc-900/50 border border-zinc-800 flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <div className="font-mono text-zinc-100 truncate">{p.path}</div>
                    {p.page_title && p.page_title !== p.path && (
                      <div className="text-[10px] text-zinc-500 truncate">{p.page_title}</div>
                    )}
                  </div>
                  <div className="font-mono text-[11px] text-zinc-300 shrink-0 text-right">
                    <div>{p.pageviews} views</div>
                    {p.active_now > 0 && <div className="text-[10px] text-emerald-400">{p.active_now} live</div>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Compass className="w-4 h-4 text-sky-400" />
            Traffic Sources &amp; Referrers
          </h3>
          {topReferrers.length === 0 ? (
            <p className="text-xs text-zinc-500">No referrers recorded yet.</p>
          ) : (
            <div className="space-y-2 text-xs">
              {topReferrers.map((rf, i) => (
                <div key={i} className="p-2.5 rounded-xl bg-zinc-900/50 border border-zinc-800 flex items-center justify-between gap-2">
                  <span className="font-medium text-zinc-200 truncate">{rf.source}</span>
                  <span className="font-mono text-[11px] text-zinc-400 shrink-0">
                    {rf.visitors} visitors • {rf.pageviews} views
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Monitor className="w-4 h-4 text-purple-400" />
            Devices &amp; Browsers
          </h3>
          <div className="space-y-2.5 text-xs">
            {devices.map((d) => {
              const Icon = d.device === 'mobile' ? Smartphone : d.device === 'tablet' ? Tablet : Monitor;
              return (
                <div key={d.device} className="space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="capitalize text-zinc-200 flex items-center gap-1.5 font-medium">
                      <Icon className="w-3.5 h-3.5 text-zinc-400" /> {d.device}
                    </span>
                    <span className="font-mono text-zinc-400">
                      {d.visitors} ({d.share_pct}%)
                    </span>
                  </div>
                  <div className="w-full h-1.5 bg-zinc-900 rounded-full overflow-hidden">
                    <div className="h-full bg-purple-500 rounded-full" style={{ width: `${Math.max(2, d.share_pct)}%` }} />
                  </div>
                </div>
              );
            })}
          </div>
          {(data?.browsers || []).length > 0 && (
            <div className="pt-2 border-t border-zinc-800/80 flex flex-wrap gap-1.5">
              {data.browsers.map((b: any) => (
                <span key={b.browser} className="text-[10px] font-mono px-2 py-0.5 rounded-md bg-zinc-900 border border-zinc-800 text-zinc-300">
                  {b.browser}: {b.visitors}
                </span>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Live Visitor Stream */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="p-4 border-b border-zinc-800 flex items-center justify-between">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Radio className="w-4 h-4 text-emerald-400 animate-pulse" />
            Live Visitor Stream (Exact Real-Time Hits)
          </h3>
          <span className="text-[11px] text-zinc-500">Showing latest {liveFeed.length} visits</span>
        </div>

        {liveFeed.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-400">
            Waiting for real visitors on <strong className="text-zinc-200">{website.domain}</strong>. Connect <strong>Google Analytics 4</strong> or paste the 1-line tracker script into your site to stream exact real-time visits here!
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800 text-[10px] uppercase">
                <tr>
                  <th className="px-4 py-2.5">When</th>
                  <th className="px-4 py-2.5">Location (City, Region, Country)</th>
                  <th className="px-4 py-2.5">Visited Page</th>
                  <th className="px-4 py-2.5">Source</th>
                  <th className="px-4 py-2.5">Device</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/70">
                {liveFeed.map((ev) => (
                  <tr key={ev.id} className="hover:bg-zinc-900/40">
                    <td className="px-4 py-2.5 font-mono text-[11px] whitespace-nowrap">
                      <span className={`inline-block w-2 h-2 rounded-full mr-1.5 ${ev.is_active ? 'bg-emerald-400' : 'bg-zinc-600'}`} />
                      <span className={ev.is_active ? 'text-emerald-400 font-semibold' : 'text-zinc-400'}>
                        {formatAgo(ev.seconds_ago)}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 whitespace-nowrap">
                      <span className="mr-1.5">{countryFlag(ev.country_code)}</span>
                      <span className="text-zinc-100 font-medium">
                        {[ev.city, ev.region, ev.country_name].filter(Boolean).join(', ')}
                      </span>
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="font-mono text-zinc-200 truncate max-w-xs">{ev.path}</div>
                      {ev.page_title && ev.page_title !== ev.path && (
                        <div className="text-[10px] text-zinc-500 truncate max-w-xs">{ev.page_title}</div>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-zinc-300 font-mono text-[11px]">{ev.referrer_domain}</td>
                    <td className="px-4 py-2.5 text-zinc-400 text-[11px] capitalize whitespace-nowrap">
                      {ev.device_type}{ev.browser ? ` • ${ev.browser}` : ''}{ev.os ? ` / ${ev.os}` : ''}
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
