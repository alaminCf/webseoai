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
  ExternalLink,
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
  const [countryFilter, setCountryFilter] = useState<string | null>(null);
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [showSetup, setShowSetup] = useState(false);
  const [setupPlatform, setSetupPlatform] = useState<'html' | 'wordpress' | 'shopify' | 'nextjs'>('html');
  const [copied, setCopied] = useState(false);
  const [isPinging, setIsPinging] = useState(false);

  const loadRealtime = useCallback(
    async (silent = false) => {
      if (!silent) setIsLoading(true);
      try {
        const qs = new URLSearchParams({ range });
        if (countryFilter) qs.set('country', countryFilter);
        const res = await api.get<any>(`/websites/${website.id}/traffic/realtime?${qs.toString()}`);
        if (res.success && res.data) {
          setData(res.data);
          if (!res.data.tracker?.has_events && !silent) {
            setShowSetup(true);
          }
        }
      } catch {
        if (!silent) toast.error('Failed to load live traffic analytics.');
      } finally {
        if (!silent) setIsLoading(false);
      }
    },
    [website.id, range, countryFilter, toast]
  );

  useEffect(() => {
    loadRealtime(false);
  }, [loadRealtime]);

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
        title: `${website.name} — Live Verification Visit`,
        referrer: 'https://www.google.com/',
      });
      if (res.success) {
        toast.success('Live test visit recorded with your real Country & Region!');
        await loadRealtime(true);
      } else {
        toast.error(res.error?.message || 'Test ping failed.');
      }
    } finally {
      setIsPinging(false);
    }
  };

  const summary = data?.summary || {};
  const tracker = data?.tracker || {};
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
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-[11px] font-bold text-emerald-400">
              <span className={`w-2 h-2 rounded-full bg-emerald-400 ${autoRefresh ? 'animate-ping' : ''}`} />
              <span>REAL-TIME LIVE TRAFFIC</span>
            </span>
            <span className="text-xs font-mono text-zinc-400">{website.domain}</span>
            {tracker.has_events && (
              <span className="text-[10px] px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-300">
                Tracker Active
              </span>
            )}
          </div>
          <p className="text-xs text-zinc-400">
            Track active visitors right now, exact Country, Region/State, City, live pages, traffic sources, and devices — auto-updating every 10 seconds.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
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
            <span>{showSetup ? 'Hide Setup' : 'Install Tracker'}</span>
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

      {/* 1-Line Live Tracker Setup & Verification Panel */}
      {showSetup && (
        <div className="p-5 bg-zinc-950 border border-sky-500/30 rounded-2xl space-y-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Code2 className="w-4 h-4 text-sky-400" />
                Install 1-Line Real-Time Traffic Tracker on {website.domain}
              </h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                Ultra-lightweight (&lt;1.5 KB), cookie-free, privacy-safe script. Paste once inside your website&apos;s <code className="text-sky-300">&lt;head&gt;</code> tag to stream live visitors, Country, Region, and City instantly.
              </p>
            </div>
            <button
              type="button"
              onClick={handleTestPing}
              disabled={isPinging}
              className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
              <Send className="w-3.5 h-3.5" />
              <span>{isPinging ? 'Sending Test Visit...' : 'Send Live Test Visit Now'}</span>
            </button>
          </div>

          {/* Copyable Script Box */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 p-3 rounded-xl bg-zinc-900 border border-zinc-800">
            <code className="flex-1 text-xs font-mono text-emerald-400 break-all">
              {tracker.script_tag || 'Loading tracker snippet...'}
            </code>
            <button
              type="button"
              onClick={handleCopySnippet}
              className="shrink-0 px-3 py-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-100 text-xs font-semibold inline-flex items-center justify-center gap-1.5 transition"
            >
              {copied ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
              <span>{copied ? 'Copied!' : 'Copy Script'}</span>
            </button>
          </div>

          {/* Platform Quick Guides */}
          <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5 text-[11px]">
              {(
                [
                  { id: 'html', label: 'Custom HTML / Laravel' },
                  { id: 'wordpress', label: 'WordPress' },
                  { id: 'shopify', label: 'Shopify' },
                  { id: 'nextjs', label: 'Next.js / React' },
                ] as const
              ).map((p) => (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => setSetupPlatform(p.id)}
                  className={`px-2.5 py-1 rounded-lg border font-medium transition ${
                    setupPlatform === p.id
                      ? 'bg-sky-500/15 border-sky-500/40 text-sky-300'
                      : 'bg-zinc-900 border-zinc-800 text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
            <div className="text-xs text-zinc-400 bg-zinc-900/50 border border-zinc-800/80 rounded-xl p-3">
              {setupPlatform === 'html' && (
                <span>Paste the <code className="text-zinc-200">&lt;script defer ...&gt;</code> line directly before <code className="text-zinc-200">&lt;/head&gt;</code> in your main layout file.</span>
              )}
              {setupPlatform === 'wordpress' && (
                <span>In WordPress Admin → <strong>Appearance → Theme File Editor → header.php</strong> (or via <em>WPCode / Insert Headers and Footers</em> plugin), paste the script into the <strong>Header</strong> box and click Save.</span>
              )}
              {setupPlatform === 'shopify' && (
                <span>In Shopify Admin → <strong>Online Store → Themes → Edit Code → theme.liquid</strong>, paste the script just before <code className="text-zinc-200">&lt;/head&gt;</code> and Save.</span>
              )}
              {setupPlatform === 'nextjs' && (
                <span>Add <code className="text-zinc-200">&lt;Script src=&quot;{tracker.script_url}&quot; strategy=&quot;afterInteractive&quot; /&gt;</code> inside your root <code className="text-zinc-200">app/layout.tsx</code>. SPA route changes are tracked automatically!</span>
              )}
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
            <span className="text-[10px] font-mono text-zinc-500">Last 5 min</span>
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
              : 'Waiting for visits'}
          </div>
          <div className="text-[11px] text-zinc-400 mt-1 truncate">
            {summary.top_country
              ? `${summary.top_country.visitors} visitors (${summary.top_country.share_pct}%)`
              : 'Install script or click Send Test Visit'}
          </div>
        </div>
      </div>

      {/* Real-Time Traffic Activity Bar Chart */}
      <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <Clock className="w-4 h-4 text-emerald-400" />
            Traffic Timeline ({range === '30m' ? 'Minute-by-Minute (Last 30m)' : range === '24h' ? 'Hourly (Last 24h)' : `Daily (Last ${range})`})
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
              No country traffic recorded in this window yet.
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
        {/* Top Pages */}
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
                    {p.page_title && <div className="text-[10px] text-zinc-500 truncate">{p.page_title}</div>}
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

        {/* Traffic Sources / Referrers */}
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

        {/* Device Breakdown */}
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
            Live Visitor Stream (Most Recent Pageviews)
          </h3>
          <span className="text-[11px] text-zinc-500">Showing latest {liveFeed.length} visits</span>
        </div>

        {liveFeed.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-400">
            Waiting for live visitor events on <strong className="text-zinc-200">{website.domain}</strong>. Click <strong>Send Live Test Visit Now</strong> above or paste the tracker script into your site to see real-time visitors appear here!
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
                      {ev.page_title && <div className="text-[10px] text-zinc-500 truncate max-w-xs">{ev.page_title}</div>}
                    </td>
                    <td className="px-4 py-2.5 text-zinc-300 font-mono text-[11px]">{ev.referrer_domain}</td>
                    <td className="px-4 py-2.5 text-zinc-400 text-[11px] capitalize whitespace-nowrap">
                      {ev.device_type} • {ev.browser} / {ev.os}
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
