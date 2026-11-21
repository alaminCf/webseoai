'use client';

import React, { useState, useEffect, useRef } from 'react';
import {
  X,
  Play,
  StopCircle,
  CheckCircle2,
  Layers,
  Terminal,
  RefreshCw,
  ShieldAlert,
  Cpu,
  AlertTriangle,
} from 'lucide-react';
import { Website, CrawlJob } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface CrawlProgressModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
  onCrawlCompleted: () => void;
}

export function CrawlProgressModal({
  website,
  isOpen,
  onClose,
  onCrawlCompleted,
}: CrawlProgressModalProps) {
  const toast = useToast();
  const [maxPages, setMaxPages] = useState<number>(50);
  const [maxDepth, setMaxDepth] = useState<number>(3);
  const [renderJs, setRenderJs] = useState<'auto' | 'always' | 'never'>('auto');
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [isCancelling, setIsCancelling] = useState<boolean>(false);

  const [activeJob, setActiveJob] = useState<CrawlJob | null>(null);
  const pollIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const prevStatusRef = useRef<string | null>(null);
  const logContainerRef = useRef<HTMLDivElement>(null);

  const stopPolling = () => {
    if (pollIntervalRef.current) {
      clearInterval(pollIntervalRef.current);
      pollIntervalRef.current = null;
    }
  };

  // Poll status when modal is open and website is selected
  useEffect(() => {
    if (!isOpen || !website) {
      stopPolling();
      return;
    }

    let cancelled = false;

    const fetchStatus = async () => {
      try {
        const res = await api.get<CrawlJob>(`/websites/${website.id}/crawl/status`);
        if (cancelled) return;
        if (res.success && res.data) {
          const prev = prevStatusRef.current;
          prevStatusRef.current = res.data.status;
          setActiveJob(res.data);

          // Notify parent automatically when transitioning from active -> completed
          if ((prev === 'queued' || prev === 'crawling') && res.data.status === 'completed') {
            onCrawlCompleted();
          }

          if (['completed', 'failed', 'cancelled'].includes(res.data.status)) {
            stopPolling();
          }
        } else {
          setActiveJob(null);
        }
      } catch {
        // Ignore transient polling hiccups
      }
    };

    fetchStatus();
    stopPolling();
    pollIntervalRef.current = setInterval(fetchStatus, 2000);

    return () => {
      cancelled = true;
      stopPolling();
    };
  }, [isOpen, website, onCrawlCompleted]);

  // Auto-scroll live logs
  useEffect(() => {
    if (logContainerRef.current) {
      logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight;
    }
  }, [activeJob?.logs]);

  if (!isOpen || !website) return null;

  const isRunning = activeJob && ['queued', 'crawling'].includes(activeJob.status);

  const startPolling = () => {
    if (!website) return;
    stopPolling();
    pollIntervalRef.current = setInterval(async () => {
      try {
        const res = await api.get<CrawlJob>(`/websites/${website.id}/crawl/status`);
        if (res.success && res.data) {
          const prev = prevStatusRef.current;
          prevStatusRef.current = res.data.status;
          setActiveJob(res.data);
          if ((prev === 'queued' || prev === 'crawling') && res.data.status === 'completed') {
            onCrawlCompleted();
          }
          if (['completed', 'failed', 'cancelled'].includes(res.data.status)) {
            stopPolling();
          }
        }
      } catch {
        // Ignore
      }
    }, 2000);
  };

  const handleStartCrawl = async () => {
    setIsSubmitting(true);
    try {
      const res = await api.post<{ crawl_job_id: number; status: string }>(
        `/websites/${website.id}/crawl`,
        {
          max_pages: maxPages,
          max_depth: maxDepth,
          render_js: renderJs,
        }
      );

      if (res.success) {
        toast.success('Full technical SEO audit crawl started!');
        prevStatusRef.current = 'queued';
        const statusRes = await api.get<CrawlJob>(`/websites/${website.id}/crawl/status`);
        if (statusRes.success && statusRes.data) {
          setActiveJob(statusRes.data);
          prevStatusRef.current = statusRes.data.status;
        }
        startPolling();
      } else {
        toast.error(res.error?.message || 'Could not initiate crawl.');
      }
    } catch {
      toast.error('Network error starting crawl.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleCancelCrawl = async () => {
    if (!activeJob) return;
    setIsCancelling(true);
    try {
      const res = await api.post(`/crawl-jobs/${activeJob.id}/cancel`);
      if (res.success) {
        toast.info('Crawl cancellation requested.');
        const statusRes = await api.get<CrawlJob>(`/websites/${website.id}/crawl/status`);
        if (statusRes.success && statusRes.data) {
          setActiveJob(statusRes.data);
        }
      } else {
        toast.error(res.error?.message || 'Could not cancel crawl.');
      }
    } catch {
      toast.error('Network error cancelling crawl.');
    } finally {
      setIsCancelling(false);
    }
  };

  const calculateProgressPercent = () => {
    if (!activeJob) return 0;
    if (activeJob.status === 'completed') return 100;
    if (activeJob.status === 'failed' || activeJob.status === 'cancelled') return 100;
    if (activeJob.pages_discovered === 0) return 10;
    const denominator = Math.max(1, Math.min(activeJob.pages_discovered, activeJob.max_pages));
    const progress = Math.min(Math.round((activeJob.pages_crawled / denominator) * 100), 95);
    return Math.max(progress, 8);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-800/80 bg-zinc-900/40">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-xl bg-emerald-950/50 border border-emerald-800/40 text-emerald-400">
              <Layers className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                Professional Technical SEO Crawler
                {isRunning && (
                  <span className="inline-flex items-center gap-1.5 px-2 py-0.5 text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-full animate-pulse">
                    Live Crawl Active
                  </span>
                )}
              </h3>
              <p className="text-xs text-zinc-400 font-mono mt-0.5">
                {website.domain} ({website.target_url})
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60 rounded-lg transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="flex-1 overflow-y-auto p-6 space-y-5">
          {/* SSRF & Honest Audit Notice */}
          <div className="flex items-start gap-3 p-3.5 bg-zinc-900/60 border border-zinc-800 rounded-xl text-xs text-zinc-400">
            <ShieldAlert className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
            <div>
              <span className="font-semibold text-zinc-200">Genuine Deep Site Audit:</span>{' '}
              Inspects HTTPS, redirects, <code className="text-zinc-300">robots.txt</code>, nested XML sitemaps, structured data (JSON-LD), Open Graph, security headers, internal link graph, and automatically hydrates client-side React/Vite SPAs — with strict SSRF domain isolation.
            </div>
          </div>

          {/* Configuration Form (if not crawling) */}
          {!isRunning && (
            <div className="p-5 bg-zinc-900/30 border border-zinc-800/80 rounded-xl space-y-4">
              <div className="font-semibold text-xs text-zinc-200 tracking-wide uppercase">
                Crawl Scope & Rendering Engine
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Max Pages</label>
                  <input
                    type="number"
                    min="5"
                    max="500"
                    value={maxPages}
                    onChange={(e) => setMaxPages(Math.min(500, Math.max(5, parseInt(e.target.value, 10) || 50)))}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  />
                  <span className="text-[10px] text-zinc-500 mt-1 block">Recommended: 50–100</span>
                </div>

                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Max Crawl Depth</label>
                  <select
                    value={maxDepth}
                    onChange={(e) => setMaxDepth(parseInt(e.target.value, 10))}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  >
                    <option value={1}>1 (Homepage + Sitemap)</option>
                    <option value={2}>2 (Direct links)</option>
                    <option value={3}>3 (Recommended)</option>
                    <option value={4}>4 (Deep site crawl)</option>
                    <option value={5}>5 (Maximum depth)</option>
                  </select>
                  <span className="text-[10px] text-zinc-500 mt-1 block">BFS click depth</span>
                </div>

                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium flex items-center gap-1">
                    <Cpu className="w-3 h-3 text-emerald-400" />
                    JS Rendering
                  </label>
                  <select
                    value={renderJs}
                    onChange={(e) => setRenderJs(e.target.value as 'auto' | 'always' | 'never')}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  >
                    <option value="auto">Auto (Smart SPA Render)</option>
                    <option value="always">Always Render JS</option>
                    <option value="never">Fast HTTP Only</option>
                  </select>
                  <span className="text-[10px] text-zinc-500 mt-1 block">Hydrates React/Vite/Vue SPAs</span>
                </div>
              </div>

              <div className="pt-2 flex items-center justify-between">
                <div className="text-[11px] text-zinc-500 flex items-center gap-1.5">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
                  Runs 35+ deterministic SEO, performance & indexing rules
                </div>
                <button
                  onClick={handleStartCrawl}
                  disabled={isSubmitting}
                  className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl text-xs font-semibold shadow transition"
                >
                  {isSubmitting ? (
                    <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Play className="w-3.5 h-3.5" />
                  )}
                  {activeJob ? 'Start Fresh Audit Crawl' : 'Launch Audit Crawler'}
                </button>
              </div>
            </div>
          )}

          {/* Active / Recent Crawl Progress Dashboard */}
          {activeJob && (
            <div className="space-y-4">
              <div className="p-4 bg-zinc-900/50 border border-zinc-800 rounded-xl space-y-3">
                <div className="flex items-center justify-between text-xs">
                  <div className="flex items-center gap-2">
                    <span className="text-zinc-400 font-medium">Execution Status:</span>
                    <span
                      className={`font-bold uppercase tracking-wider text-[11px] px-2 py-0.5 rounded-md ${
                        activeJob.status === 'completed'
                          ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/60'
                          : activeJob.status === 'crawling' || activeJob.status === 'queued'
                          ? 'bg-blue-950/60 text-blue-400 border border-blue-800/60 animate-pulse'
                          : activeJob.status === 'failed'
                          ? 'bg-rose-950/60 text-rose-400 border border-rose-800/60'
                          : 'bg-zinc-800 text-zinc-300'
                      }`}
                    >
                      {activeJob.status}
                    </span>
                  </div>

                  {isRunning && (
                    <button
                      onClick={handleCancelCrawl}
                      disabled={isCancelling}
                      className="inline-flex items-center gap-1.5 text-xs text-rose-400 hover:text-rose-300 font-semibold px-2.5 py-1 bg-rose-950/40 border border-rose-800/50 rounded-lg transition"
                    >
                      <StopCircle className="w-3.5 h-3.5" />
                      {isCancelling ? 'Cancelling...' : 'Cancel Crawl'}
                    </button>
                  )}
                </div>

                {activeJob.error_message && (
                  <div className="p-2.5 bg-rose-950/30 border border-rose-800/50 rounded-lg text-xs text-rose-300 flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4 text-rose-400 shrink-0" />
                    <span>{activeJob.error_message}</span>
                  </div>
                )}

                {/* Progress Bar */}
                <div className="space-y-1">
                  <div className="flex justify-between text-[11px] text-zinc-400">
                    <span>Crawl Progress (Limit: {activeJob.max_pages} pages)</span>
                    <span className="font-mono">{calculateProgressPercent()}%</span>
                  </div>
                  <div className="w-full bg-zinc-800 rounded-full h-2 overflow-hidden">
                    <div
                      className={`h-full transition-all duration-500 rounded-full ${
                        activeJob.status === 'completed'
                          ? 'bg-emerald-500'
                          : activeJob.status === 'failed'
                          ? 'bg-rose-500'
                          : 'bg-gradient-to-r from-emerald-500 to-cyan-400'
                      }`}
                      style={{ width: `${calculateProgressPercent()}%` }}
                    />
                  </div>
                </div>

                {/* Counters Grid */}
                <div className="grid grid-cols-4 gap-2 pt-2">
                  <div className="p-2.5 bg-zinc-950 border border-zinc-800/80 rounded-lg text-center">
                    <div className="text-base font-bold text-zinc-100 font-mono">
                      {activeJob.pages_discovered}
                    </div>
                    <div className="text-[10px] text-zinc-500 uppercase">Discovered</div>
                  </div>
                  <div className="p-2.5 bg-zinc-950 border border-zinc-800/80 rounded-lg text-center">
                    <div className="text-base font-bold text-emerald-400 font-mono">
                      {activeJob.pages_crawled}
                    </div>
                    <div className="text-[10px] text-zinc-500 uppercase">Crawled</div>
                  </div>
                  <div className="p-2.5 bg-zinc-950 border border-zinc-800/80 rounded-lg text-center">
                    <div className="text-base font-bold text-rose-400 font-mono">
                      {activeJob.pages_failed}
                    </div>
                    <div className="text-[10px] text-zinc-500 uppercase">Failed</div>
                  </div>
                  <div className="p-2.5 bg-zinc-950 border border-zinc-800/80 rounded-lg text-center">
                    <div className="text-base font-bold text-zinc-300 font-mono">
                      {activeJob.duration_seconds !== null && activeJob.duration_seconds !== undefined
                        ? `${activeJob.duration_seconds}s`
                        : activeJob.started_at
                        ? 'Running'
                        : '0s'}
                    </div>
                    <div className="text-[10px] text-zinc-500 uppercase">Duration</div>
                  </div>
                </div>

                {/* Live Site Checks Pills */}
                {activeJob.site_checks && (
                  <div className="flex flex-wrap gap-1.5 pt-1 text-[10px] font-mono">
                    <span
                      className={`px-2 py-0.5 rounded border ${
                        activeJob.site_checks.robots_txt?.exists
                          ? 'bg-emerald-950/40 text-emerald-400 border-emerald-800/50'
                          : 'bg-amber-950/40 text-amber-400 border-amber-800/50'
                      }`}
                    >
                      robots.txt: {activeJob.site_checks.robots_txt?.exists ? 'OK' : 'Missing'}
                    </span>
                    <span
                      className={`px-2 py-0.5 rounded border ${
                        activeJob.site_checks.sitemap?.found
                          ? 'bg-emerald-950/40 text-emerald-400 border-emerald-800/50'
                          : 'bg-amber-950/40 text-amber-400 border-amber-800/50'
                      }`}
                    >
                      sitemap: {activeJob.site_checks.sitemap?.found ? `${activeJob.site_checks.sitemap.urls_count} URLs` : 'Not found'}
                    </span>
                    {(activeJob.site_checks.js_rendered_pages || 0) > 0 && (
                      <span className="px-2 py-0.5 rounded border bg-cyan-950/40 text-cyan-400 border-cyan-800/50">
                        JS Rendered: {activeJob.site_checks.js_rendered_pages}
                      </span>
                    )}
                    {(activeJob.site_checks.waf_blocked_pages || 0) > 0 && (
                      <span className="px-2 py-0.5 rounded border bg-rose-950/40 text-rose-400 border-rose-800/50">
                        WAF Blocked: {activeJob.site_checks.waf_blocked_pages}
                      </span>
                    )}
                  </div>
                )}
              </div>

              {/* Execution Log Terminal */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between text-xs text-zinc-400 px-1">
                  <span className="flex items-center gap-1.5 font-mono text-[11px]">
                    <Terminal className="w-3.5 h-3.5 text-emerald-400" />
                    Live Engine Stream & Audit Hooks
                  </span>
                  <span className="text-[10px] text-zinc-500 font-mono">
                    {activeJob.logs?.length || 0} events logged
                  </span>
                </div>
                <div
                  ref={logContainerRef}
                  className="h-44 bg-zinc-950 border border-zinc-800 rounded-xl p-3 font-mono text-[11px] overflow-y-auto space-y-1.5 text-zinc-300 shadow-inner"
                >
                  {activeJob.logs && activeJob.logs.length > 0 ? (
                    activeJob.logs.map((log, index) => (
                      <div key={index} className="flex items-start gap-2 leading-relaxed">
                        <span className="text-zinc-600 select-none shrink-0">
                          {log.timestamp ? new Date(log.timestamp).toLocaleTimeString() : 'LOG'}
                        </span>
                        <span className="text-emerald-400 font-semibold shrink-0">
                          {log.step || log.message || 'Event'}:
                        </span>
                        <span className="text-zinc-400 break-all">
                          {log.context && Object.keys(log.context).length > 0
                            ? JSON.stringify(log.context)
                            : ''}
                        </span>
                      </div>
                    ))
                  ) : (
                    <div className="text-zinc-600 italic">Engine awaiting dispatch events...</div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Modal Footer */}
        <div className="px-6 py-4 border-t border-zinc-800/80 bg-zinc-900/40 flex items-center justify-between">
          <div className="text-xs text-zinc-500">
            {activeJob?.status === 'completed' && (
              <span className="text-emerald-400 flex items-center gap-1">
                <CheckCircle2 className="w-3.5 h-3.5" /> Crawl & full SEO audit completed
              </span>
            )}
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-zinc-200 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
            >
              Close
            </button>
            {activeJob?.status === 'completed' && (
              <button
                onClick={() => {
                  onCrawlCompleted();
                  onClose();
                }}
                className="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
              >
                View Audit Findings & Score
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
