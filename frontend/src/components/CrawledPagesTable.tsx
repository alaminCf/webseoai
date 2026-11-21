'use client';

import React, { useState } from 'react';
import {
  ExternalLink,
  Search,
  CheckCircle2,
  XCircle,
  ChevronRight,
  X,
  FileText,
  Image as ImageIcon,
  Link2,
  Shield,
  Code2,
  Share2,
  ArrowRight,
  Cpu,
  Ban,
} from 'lucide-react';
import { CrawledPage } from '@/types';

interface CrawledPagesTableProps {
  pages: CrawledPage[];
  isLoading?: boolean;
}

export function CrawledPagesTable({ pages, isLoading = false }: CrawledPagesTableProps) {
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');
  const [selectedIndexable, setSelectedIndexable] = useState<string>('all');
  const [activePage, setActivePage] = useState<CrawledPage | null>(null);

  const filteredPages = pages.filter((page) => {
    if (selectedStatus === '200' && page.status_code !== 200) return false;
    if (selectedStatus === '3xx' && (page.status_code < 300 || page.status_code >= 400)) return false;
    if (selectedStatus === '4xx' && (page.status_code < 400 || page.status_code >= 500)) return false;
    if (selectedStatus === '5xx' && page.status_code < 500) return false;

    if (selectedIndexable === 'indexable' && !page.is_indexable) return false;
    if (selectedIndexable === 'noindex' && page.is_indexable) return false;

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchUrl = page.url.toLowerCase().includes(q);
      const matchTitle = (page.page_title || '').toLowerCase().includes(q);
      if (!matchUrl && !matchTitle) return false;
    }

    return true;
  });

  const getStatusBadge = (code: number) => {
    if (code >= 200 && code < 300) {
      return (
        <span className="px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-emerald-950/60 text-emerald-400 border border-emerald-800/60">
          {code}
        </span>
      );
    }
    if (code >= 300 && code < 400) {
      return (
        <span className="px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-blue-950/60 text-blue-400 border border-blue-800/60">
          {code}
        </span>
      );
    }
    if (code >= 400 && code < 500) {
      return (
        <span className="px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-rose-950/60 text-rose-400 border border-rose-800/60">
          {code}
        </span>
      );
    }
    if (code >= 500) {
      return (
        <span className="px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-purple-950/60 text-purple-400 border border-purple-800/60">
          {code}
        </span>
      );
    }
    return (
      <span className="px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-zinc-800 text-zinc-400">
        0 Err
      </span>
    );
  };

  return (
    <div className="space-y-4">
      {/* Search & Filter Toolbar */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={selectedStatus}
            onChange={(e) => setSelectedStatus(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-emerald-500 font-medium"
          >
            <option value="all">All Status Codes</option>
            <option value="200">200 OK</option>
            <option value="3xx">3xx Redirects</option>
            <option value="4xx">4xx Client Errors</option>
            <option value="5xx">5xx Server Errors</option>
          </select>

          <select
            value={selectedIndexable}
            onChange={(e) => setSelectedIndexable(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-emerald-500 font-medium"
          >
            <option value="all">Indexability (All)</option>
            <option value="indexable">Indexable Only</option>
            <option value="noindex">Noindex / Blocked</option>
          </select>
        </div>

        <div className="relative min-w-[220px]">
          <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Search crawled URL or title..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-8 pr-3 py-1.5 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-emerald-500"
          />
        </div>
      </div>

      {/* Pages Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
        {isLoading ? (
          <div className="p-12 text-center text-zinc-500 flex flex-col items-center justify-center gap-3">
            <div className="w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full animate-spin" />
            <span className="text-xs">Loading crawled pages graph...</span>
          </div>
        ) : filteredPages.length === 0 ? (
          <div className="p-12 text-center text-zinc-500">
            <FileText className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
            <p className="text-sm font-semibold text-zinc-300">No crawled pages found</p>
            <p className="text-xs text-zinc-500 mt-1">
              Start a new audit crawl to discover and inspect pages on this website.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-zinc-800/80 bg-zinc-900/50 text-zinc-400 font-medium">
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4">URL & Title</th>
                  <th className="py-3 px-3 text-center">Depth</th>
                  <th className="py-3 px-3 text-center">Words</th>
                  <th className="py-3 px-3 text-center">H1</th>
                  <th className="py-3 px-3 text-center">Inbound</th>
                  <th className="py-3 px-3 text-center">Indexable</th>
                  <th className="py-3 px-3 text-right">TTFB</th>
                  <th className="py-3 px-3 text-right">Details</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60">
                {filteredPages.map((page) => (
                  <tr
                    key={page.id}
                    onClick={() => setActivePage(page)}
                    className="hover:bg-zinc-900/40 cursor-pointer transition group"
                  >
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      <div className="flex items-center gap-1.5">
                        {getStatusBadge(page.status_code)}
                        {page.is_js_rendered && (
                          <span
                            title="Hydrated via JS Web Rendering Service"
                            className="px-1.5 py-0.5 text-[9px] font-mono font-bold bg-cyan-950/60 text-cyan-400 border border-cyan-800/50 rounded"
                          >
                            JS
                          </span>
                        )}
                        {page.is_blocked_by_waf && (
                          <span
                            title="Blocked by WAF / Anti-bot challenge"
                            className="px-1.5 py-0.5 text-[9px] font-mono font-bold bg-rose-950/60 text-rose-400 border border-rose-800/50 rounded"
                          >
                            WAF
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="py-3.5 px-4 max-w-sm truncate">
                      <div className="font-mono text-[11px] text-zinc-200 group-hover:text-emerald-400 transition truncate">
                        {page.url}
                      </div>
                      <div className="text-zinc-500 text-[11px] truncate mt-0.5">
                        {page.page_title || '(No page title tag found)'}
                      </div>
                    </td>
                    <td className="py-3.5 px-3 text-center font-mono text-zinc-400">
                      {page.depth}
                    </td>
                    <td className="py-3.5 px-3 text-center font-mono text-zinc-300">
                      {page.word_count ?? 0}
                    </td>
                    <td className="py-3.5 px-3 text-center font-mono">
                      <span
                        className={
                          (page.h1_count ?? page.h1_tags?.length ?? 0) === 1
                            ? 'text-emerald-400'
                            : 'text-amber-400 font-bold'
                        }
                      >
                        {page.h1_count ?? page.h1_tags?.length ?? 0}
                      </span>
                    </td>
                    <td className="py-3.5 px-3 text-center font-mono">
                      <span
                        className={`px-2 py-0.5 rounded text-[11px] ${
                          page.in_degree === 0 && page.depth > 0
                            ? 'bg-amber-950/60 text-amber-400 border border-amber-800/60 font-bold'
                            : 'text-zinc-300'
                        }`}
                      >
                        {page.in_degree}
                      </span>
                    </td>
                    <td className="py-3.5 px-3 text-center">
                      {page.is_indexable ? (
                        <span className="text-emerald-400 font-medium">Yes</span>
                      ) : (
                        <span className="text-rose-400 font-medium">No</span>
                      )}
                    </td>
                    <td className="py-3.5 px-3 text-right font-mono text-zinc-400">
                      {page.ttfb_ms ?? page.load_time_ms}ms
                    </td>
                    <td className="py-3.5 px-3 text-right whitespace-nowrap">
                      <ChevronRight className="w-4 h-4 text-zinc-500 group-hover:text-emerald-400 inline" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Deep Page Inspection Drawer */}
      {activePage && (
        <div className="fixed inset-0 z-50 flex items-center justify-end bg-black/70 backdrop-blur-xs animate-fadeIn">
          <div className="bg-zinc-950 border-l border-zinc-800 w-full max-w-2xl h-full flex flex-col shadow-2xl p-6 overflow-y-auto space-y-5">
            {/* Header */}
            <div className="flex items-start justify-between gap-4 border-b border-zinc-800/80 pb-4">
              <div className="min-w-0">
                <div className="flex items-center gap-2 mb-1.5 flex-wrap">
                  {getStatusBadge(activePage.status_code)}
                  <span className="text-xs font-mono text-zinc-400">Depth: {activePage.depth}</span>
                  <span className="text-xs font-mono text-zinc-400">In-links: {activePage.in_degree}</span>
                  <span className="text-xs font-mono text-zinc-400">
                    HTML: {((activePage.html_size_bytes || 0) / 1024).toFixed(1)} KB
                  </span>
                  {activePage.is_js_rendered && (
                    <span className="inline-flex items-center gap-1 text-[10px] font-mono px-2 py-0.5 rounded bg-cyan-950/60 text-cyan-300 border border-cyan-800/50">
                      <Cpu className="w-3 h-3" /> JS Rendered
                    </span>
                  )}
                  {activePage.is_blocked_by_waf && (
                    <span className="inline-flex items-center gap-1 text-[10px] font-mono px-2 py-0.5 rounded bg-rose-950/60 text-rose-300 border border-rose-800/50">
                      <Ban className="w-3 h-3" /> WAF Blocked
                    </span>
                  )}
                </div>
                <h3 className="text-sm font-bold text-zinc-100 font-mono break-all">
                  {activePage.url}
                </h3>
                {activePage.final_url && activePage.final_url !== activePage.url && (
                  <p className="text-[11px] font-mono text-cyan-400 mt-1 break-all">
                    Final URL: {activePage.final_url}
                  </p>
                )}
              </div>
              <button
                onClick={() => setActivePage(null)}
                className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-lg transition shrink-0"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Redirect Chain */}
            {activePage.redirect_hops && activePage.redirect_hops.length > 0 && (
              <div className="p-3.5 bg-blue-950/20 border border-blue-800/40 rounded-xl space-y-1.5">
                <div className="text-[11px] font-semibold text-blue-300 uppercase tracking-wider">
                  Redirect Chain ({activePage.redirect_hops.length} hop{activePage.redirect_hops.length === 1 ? '' : 's'})
                </div>
                {activePage.redirect_hops.map((hop, idx) => (
                  <div key={idx} className="text-[11px] font-mono text-zinc-300 flex items-center gap-1.5 flex-wrap">
                    <span className="px-1.5 py-0.5 rounded bg-blue-950 text-blue-300 font-bold">{hop.status}</span>
                    <span className="truncate max-w-[200px]">{hop.from}</span>
                    <ArrowRight className="w-3 h-3 text-zinc-500" />
                    <span className="text-emerald-400 break-all">{hop.to}</span>
                  </div>
                ))}
              </div>
            )}

            {/* On-Page Meta & Directives */}
            <div className="space-y-3 p-4 bg-zinc-900/50 border border-zinc-800 rounded-xl">
              <div>
                <div className="flex items-center justify-between text-[11px] font-semibold text-zinc-400 uppercase tracking-wider mb-1">
                  <span>Title Tag</span>
                  <span className="font-mono">{activePage.page_title?.length || 0} / 60 chars</span>
                </div>
                <div className="text-xs font-medium text-zinc-100">
                  {activePage.page_title || <span className="text-rose-400">Missing Title Tag</span>}
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between text-[11px] font-semibold text-zinc-400 uppercase tracking-wider mb-1">
                  <span>Meta Description</span>
                  <span className="font-mono">{activePage.meta_description?.length || 0} / 155 chars</span>
                </div>
                <div className="text-xs text-zinc-300 leading-relaxed">
                  {activePage.meta_description || (
                    <span className="text-amber-400">Missing Meta Description Tag</span>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-zinc-800/70 text-xs">
                <div>
                  <div className="text-[10px] font-semibold text-zinc-500 uppercase mb-0.5">Canonical URL</div>
                  <div className="font-mono text-[11px] text-zinc-300 break-all">
                    {activePage.canonical_url || <span className="text-amber-400">Missing</span>}
                  </div>
                </div>
                <div>
                  <div className="text-[10px] font-semibold text-zinc-500 uppercase mb-0.5">Viewport / Lang</div>
                  <div className="font-mono text-[11px] text-zinc-300">
                    {activePage.viewport ? 'Viewport OK' : 'No Viewport'} · Lang: {activePage.html_lang || 'Missing'}
                  </div>
                </div>
              </div>
            </div>

            {/* Headings Structure Tree */}
            <div className="space-y-2">
              <h4 className="text-xs font-semibold text-zinc-300 uppercase tracking-wider flex items-center gap-1.5">
                <FileText className="w-3.5 h-3.5 text-emerald-400" />
                Headings Outline ({activePage.headings_tree?.length || activePage.h1_tags?.length || 0} headings · {activePage.h1_tags?.length || 0} H1)
              </h4>
              {activePage.headings_tree && activePage.headings_tree.length > 0 ? (
                <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1 max-h-48 overflow-y-auto">
                  {activePage.headings_tree.slice(0, 30).map((h, i) => (
                    <div
                      key={i}
                      style={{ paddingLeft: `${Math.max(0, (h.level - 1) * 14)}px` }}
                      className="flex items-center gap-2 text-xs"
                    >
                      <span
                        className={`px-1.5 py-0.5 text-[10px] font-mono font-bold rounded shrink-0 ${
                          h.level === 1
                            ? 'bg-emerald-950/70 text-emerald-400 border border-emerald-800/50'
                            : 'bg-zinc-800 text-zinc-400'
                        }`}
                      >
                        {h.tag.toUpperCase()}
                      </span>
                      <span className="text-zinc-200 truncate">{h.text}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-xs text-rose-400 p-2.5 bg-rose-950/20 border border-rose-800/40 rounded-lg">
                  No headings detected on this page
                </div>
              )}
            </div>

            {/* Structured Data & Open Graph */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div className="p-3.5 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-2">
                <div className="text-[11px] font-semibold text-zinc-300 uppercase flex items-center gap-1.5">
                  <Code2 className="w-3.5 h-3.5 text-emerald-400" />
                  Schema.org JSON-LD
                </div>
                {activePage.structured_data && activePage.structured_data.count > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {activePage.structured_data.types.map((t, i) => (
                      <span
                        key={i}
                        className="px-2 py-0.5 rounded bg-emerald-950/50 text-emerald-300 border border-emerald-800/50 font-mono text-[10px]"
                      >
                        {t}
                      </span>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-zinc-500">No JSON-LD blocks detected</p>
                )}
              </div>

              <div className="p-3.5 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1.5">
                <div className="text-[11px] font-semibold text-zinc-300 uppercase flex items-center gap-1.5">
                  <Share2 className="w-3.5 h-3.5 text-cyan-400" />
                  Open Graph Tags
                </div>
                <div className="text-[11px] text-zinc-400 space-y-0.5 font-mono">
                  <div>og:title — {activePage.open_graph?.title ? '✓ Set' : '✗ Missing'}</div>
                  <div>og:description — {activePage.open_graph?.description ? '✓ Set' : '✗ Missing'}</div>
                  <div>og:image — {activePage.open_graph?.image ? '✓ Set' : '✗ Missing'}</div>
                </div>
              </div>
            </div>

            {/* Security Headers */}
            {activePage.security_headers && (
              <div className="p-3.5 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-2">
                <div className="text-[11px] font-semibold text-zinc-300 uppercase flex items-center gap-1.5">
                  <Shield className="w-3.5 h-3.5 text-emerald-400" />
                  HTTP Security Headers
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-[11px] font-mono">
                  {[
                    ['HTTPS', activePage.security_headers.https],
                    ['HSTS', activePage.security_headers.hsts],
                    ['X-Content-Type', activePage.security_headers.x_content_type_options],
                    ['X-Frame-Options', activePage.security_headers.x_frame_options],
                    ['CSP', activePage.security_headers.csp],
                    ['Referrer-Policy', activePage.security_headers.referrer_policy],
                  ].map(([label, ok]) => (
                    <div key={String(label)} className="flex items-center gap-1.5">
                      {ok ? (
                        <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                      ) : (
                        <XCircle className="w-3.5 h-3.5 text-zinc-600 shrink-0" />
                      )}
                      <span className={ok ? 'text-zinc-200' : 'text-zinc-500'}>{label}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Media, Links & Content Metrics */}
            <div className="grid grid-cols-3 gap-3 text-xs">
              <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1">
                <div className="flex items-center gap-1.5 text-zinc-400">
                  <ImageIcon className="w-3.5 h-3.5 text-emerald-400" />
                  Images
                </div>
                <div className="text-base font-bold text-zinc-100 font-mono">
                  {activePage.images_count || 0}
                </div>
                <div className="text-[11px] text-zinc-500">
                  Missing Alt: <span className="text-amber-400 font-bold">{activePage.images_missing_alt || 0}</span>
                </div>
              </div>

              <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1">
                <div className="flex items-center gap-1.5 text-zinc-400">
                  <FileText className="w-3.5 h-3.5 text-blue-400" />
                  Words
                </div>
                <div className="text-base font-bold text-zinc-100 font-mono">
                  {activePage.word_count || 0}
                </div>
                <div className="text-[11px] text-zinc-500">
                  TTFB: {activePage.ttfb_ms ?? activePage.load_time_ms}ms
                </div>
              </div>

              <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1">
                <div className="flex items-center gap-1.5 text-zinc-400">
                  <Link2 className="w-3.5 h-3.5 text-indigo-400" />
                  Links Out
                </div>
                <div className="text-base font-bold text-zinc-100 font-mono">
                  {activePage.internal_links_count ?? 0} int
                </div>
                <div className="text-[11px] text-zinc-500">
                  {activePage.external_links_count ?? 0} external
                </div>
              </div>
            </div>

            {/* Sample Images Missing Alt */}
            {activePage.images_missing_alt_samples && activePage.images_missing_alt_samples.length > 0 && (
              <div className="p-3.5 bg-amber-950/15 border border-amber-800/40 rounded-xl space-y-1.5">
                <div className="text-[11px] font-semibold text-amber-300 uppercase">
                  Sample Images Missing Alt Text
                </div>
                <ul className="space-y-1 font-mono text-[11px] text-zinc-300">
                  {activePage.images_missing_alt_samples.map((src, i) => (
                    <li key={i} className="truncate">{src}</li>
                  ))}
                </ul>
              </div>
            )}

            {/* Visit Page */}
            <div className="pt-2">
              <a
                href={activePage.url}
                target="_blank"
                rel="noopener noreferrer"
                className="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl text-xs font-semibold text-zinc-200 transition"
              >
                <span>Visit Page in New Tab</span>
                <ExternalLink className="w-3.5 h-3.5" />
              </a>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
