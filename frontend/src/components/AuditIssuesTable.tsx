'use client';

import React, { useState, useMemo } from 'react';
import {
  AlertCircle,
  AlertTriangle,
  Info,
  ExternalLink,
  Search,
  CheckCircle2,
  ChevronRight,
  ChevronDown,
  Code2,
  HelpCircle,
  ShieldCheck,
  X,
  Layers,
  List,
} from 'lucide-react';
import { AuditIssue } from '@/types';

interface AuditIssuesTableProps {
  issues: AuditIssue[];
  isLoading?: boolean;
}

const ISSUE_LABELS: Record<string, string> = {
  STATUS_4XX: '4xx Client Error Page',
  STATUS_5XX: '5xx Server Error Page',
  FETCH_ERROR: 'Page Connection / Fetch Failure',
  WAF_OR_BOT_BLOCKED: 'WAF / Anti-Bot Challenge Blocked Crawl',
  REDIRECT_CHAIN: 'Multi-Hop Redirect Chain',
  TEMPORARY_REDIRECT: 'Temporary 302/307 Redirect',
  NO_HTTPS: 'Site Served Over Insecure HTTP',
  HTTP_NOT_REDIRECTING_TO_HTTPS: 'HTTP Does Not Redirect to HTTPS',
  WWW_DUPLICATE_HOST: 'Duplicate www / Apex Host Serving 200 OK',
  MIXED_CONTENT: 'Mixed Content (HTTP Assets on HTTPS Page)',
  MISSING_VIEWPORT: 'Missing Mobile Viewport Meta Tag',
  SLOW_PAGE_RESPONSE: 'Slow Server Response Time (TTFB)',
  LARGE_HTML_SIZE: 'Oversized HTML Document (> 500 KB)',
  MISSING_SECURITY_HEADERS: 'Missing Recommended Security Headers',
  URL_UPPERCASE_CHARACTERS: 'Uppercase Characters in URL Path',
  SPA_CLIENT_RENDERED: 'Client-Side JavaScript SPA Rendering Dependency',
  MISSING_TITLE: 'Missing <title> Tag',
  DUPLICATE_TITLE: 'Duplicate <title> Tag',
  TITLE_TOO_SHORT: 'Title Tag Too Short (< 30 chars)',
  TITLE_TOO_LONG: 'Title Tag Too Long (> 65 chars)',
  MISSING_META_DESCRIPTION: 'Missing Meta Description',
  DUPLICATE_META_DESCRIPTION: 'Duplicate Meta Description',
  META_DESCRIPTION_TOO_SHORT: 'Meta Description Too Short (< 70 chars)',
  META_DESCRIPTION_TOO_LONG: 'Meta Description Too Long (> 165 chars)',
  MISSING_H1: 'Missing Primary <h1> Heading',
  MULTIPLE_H1: 'Multiple <h1> Headings on Page',
  HEADING_HIERARCHY_SKIPPED: 'Skipped Heading Level Hierarchy',
  THIN_CONTENT: 'Thin Visible Content (< 250 words)',
  DUPLICATE_CONTENT: 'Duplicate Page Body Content',
  MISSING_IMAGE_ALT: 'Images Missing Alt Attributes',
  MISSING_OPEN_GRAPH: 'Incomplete Open Graph Social Metadata',
  MISSING_STRUCTURED_DATA: 'Missing Schema.org JSON-LD Structured Data',
  INVALID_STRUCTURED_DATA: 'Malformed Schema.org JSON-LD Block',
  MISSING_HTML_LANG: 'Missing HTML lang Attribute',
  MISSING_ROBOTS_TXT: 'Missing robots.txt File',
  ROBOTS_BLOCKS_SITE: 'robots.txt Blocks Entire Site',
  MISSING_SITEMAP: 'No XML Sitemap Found',
  MISSING_CANONICAL: 'Missing Canonical Link Tag',
  CANONICAL_MISMATCH: 'Canonical Points to Another URL',
  BROKEN_CANONICAL: 'Canonical URL Returns 4xx/5xx Error',
  NOINDEX_PAGE: 'Page Blocked From Indexing (noindex)',
  NOINDEX_IN_SITEMAP: 'Noindex Page Listed in XML Sitemap',
  PAGE_NOT_IN_SITEMAP: 'Indexable Page Missing From Sitemap',
  META_REFRESH_REDIRECT: 'Deprecated Meta Refresh Redirect',
  BROKEN_INTERNAL_LINK: 'Broken Internal Hyperlink',
  ORPHAN_PAGE: 'Orphan Page (0 Inbound Internal Links)',
  DEEP_CRAWL_DEPTH: 'Deep Crawl Depth (> 3 Clicks)',
  NOFOLLOW_INTERNAL_LINK: 'Internal Link Using rel="nofollow"',
};

function formatIssueTitle(issueType: string): string {
  return ISSUE_LABELS[issueType] || issueType.replace(/_/g, ' ');
}

export function AuditIssuesTable({ issues, isLoading = false }: AuditIssuesTableProps) {
  const [selectedSeverity, setSelectedSeverity] = useState<string>('all');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [viewMode, setViewMode] = useState<'grouped' | 'flat'>('grouped');
  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({});
  const [activeIssue, setActiveIssue] = useState<AuditIssue | null>(null);

  const filteredIssues = useMemo(() => {
    return issues.filter((issue) => {
      if (selectedSeverity !== 'all' && issue.severity !== selectedSeverity) {
        return false;
      }
      if (selectedCategory !== 'all' && issue.category !== selectedCategory) {
        return false;
      }
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchUrl = issue.affected_url.toLowerCase().includes(q);
        const matchType = issue.issue_type.toLowerCase().includes(q);
        const matchLabel = formatIssueTitle(issue.issue_type).toLowerCase().includes(q);
        const matchExpl = issue.explanation.toLowerCase().includes(q);
        if (!matchUrl && !matchType && !matchLabel && !matchExpl) {
          return false;
        }
      }
      return true;
    });
  }, [issues, selectedSeverity, selectedCategory, searchQuery]);

  const groupedIssues = useMemo(() => {
    const map = new Map<
      string,
      {
        issue_type: string;
        severity: AuditIssue['severity'];
        category: AuditIssue['category'];
        explanation: string;
        recommended_fix: string;
        items: AuditIssue[];
      }
    >();

    for (const issue of filteredIssues) {
      const key = `${issue.issue_type}::${issue.severity}`;
      const existing = map.get(key);
      if (existing) {
        existing.items.push(issue);
      } else {
        map.set(key, {
          issue_type: issue.issue_type,
          severity: issue.severity,
          category: issue.category,
          explanation: issue.explanation,
          recommended_fix: issue.recommended_fix,
          items: [issue],
        });
      }
    }

    const sevRank = { critical: 1, warning: 2, info: 3 };
    return Array.from(map.values()).sort((a, b) => {
      const diff = (sevRank[a.severity] ?? 9) - (sevRank[b.severity] ?? 9);
      return diff !== 0 ? diff : b.items.length - a.items.length;
    });
  }, [filteredIssues]);

  const toggleGroup = (key: string) => {
    setExpandedGroups((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const getSeverityBadge = (severity: string) => {
    switch (severity) {
      case 'critical':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-[11px] font-bold bg-rose-950/60 text-rose-400 border border-rose-800/60 rounded-full">
            <AlertCircle className="w-3 h-3 text-rose-400" />
            Critical
          </span>
        );
      case 'warning':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-[11px] font-bold bg-amber-950/60 text-amber-400 border border-amber-800/60 rounded-full">
            <AlertTriangle className="w-3 h-3 text-amber-400" />
            Warning
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-[11px] font-bold bg-blue-950/60 text-blue-400 border border-blue-800/60 rounded-full">
            <Info className="w-3 h-3 text-blue-400" />
            Notice
          </span>
        );
    }
  };

  const getCategoryBadge = (category: string) => (
    <span className="px-2 py-0.5 text-[10px] font-mono uppercase tracking-wider bg-zinc-900 text-zinc-400 border border-zinc-800 rounded-md">
      {category}
    </span>
  );

  return (
    <div className="space-y-4">
      {/* Filtering Toolbar */}
      <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <div className="flex flex-wrap items-center gap-2">
          {/* Severity Filter Tabs */}
          <div className="flex items-center bg-zinc-900/90 border border-zinc-800 rounded-xl p-1 text-xs">
            {['all', 'critical', 'warning', 'info'].map((sev) => (
              <button
                key={sev}
                onClick={() => setSelectedSeverity(sev)}
                className={`px-3 py-1 rounded-lg font-medium capitalize transition ${
                  selectedSeverity === sev
                    ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                    : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {sev === 'all' ? 'All' : sev === 'info' ? 'Notice' : sev}
              </button>
            ))}
          </div>

          {/* Category Filter Dropdown */}
          <select
            value={selectedCategory}
            onChange={(e) => setSelectedCategory(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-emerald-500 font-medium"
          >
            <option value="all">All Categories</option>
            <option value="technical">Technical & Speed</option>
            <option value="content">Content & On-Page</option>
            <option value="indexing">Indexing & Robots</option>
            <option value="links">Links & Architecture</option>
          </select>

          {/* View Mode Toggle */}
          <div className="flex items-center bg-zinc-900/90 border border-zinc-800 rounded-xl p-1 text-xs">
            <button
              onClick={() => setViewMode('grouped')}
              className={`px-2.5 py-1 rounded-lg font-medium flex items-center gap-1.5 transition ${
                viewMode === 'grouped' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              <Layers className="w-3 h-3" />
              Grouped ({groupedIssues.length})
            </button>
            <button
              onClick={() => setViewMode('flat')}
              className={`px-2.5 py-1 rounded-lg font-medium flex items-center gap-1.5 transition ${
                viewMode === 'flat' ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              <List className="w-3 h-3" />
              All ({filteredIssues.length})
            </button>
          </div>
        </div>

        {/* Search Input */}
        <div className="relative min-w-[220px]">
          <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Search URL or issue..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-8 pr-3 py-1.5 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-emerald-500"
          />
        </div>
      </div>

      {/* Issues List */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
        {isLoading ? (
          <div className="p-12 text-center text-zinc-500 flex flex-col items-center justify-center gap-3">
            <div className="w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full animate-spin" />
            <span className="text-xs">Evaluating deterministic audit rules...</span>
          </div>
        ) : filteredIssues.length === 0 ? (
          <div className="p-12 text-center text-zinc-500">
            <CheckCircle2 className="w-8 h-8 text-emerald-400/80 mx-auto mb-2" />
            <p className="text-sm font-semibold text-zinc-300">No matching issues found</p>
            <p className="text-xs text-zinc-500 mt-1">
              All checks passed for this filter, or no audit crawl has been run yet.
            </p>
          </div>
        ) : viewMode === 'grouped' ? (
          <div className="divide-y divide-zinc-800/70">
            {groupedIssues.map((group) => {
              const key = `${group.issue_type}::${group.severity}`;
              const isExpanded = !!expandedGroups[key];

              return (
                <div key={key} className="transition">
                  <button
                    onClick={() => toggleGroup(key)}
                    className="w-full text-left px-5 py-4 hover:bg-zinc-900/40 flex items-center justify-between gap-4 transition"
                  >
                    <div className="flex items-start gap-3 min-w-0">
                      <div className="mt-0.5 text-zinc-500">
                        {isExpanded ? (
                          <ChevronDown className="w-4 h-4 text-emerald-400" />
                        ) : (
                          <ChevronRight className="w-4 h-4" />
                        )}
                      </div>
                      <div className="min-w-0 space-y-1">
                        <div className="flex items-center gap-2 flex-wrap">
                          {getSeverityBadge(group.severity)}
                          {getCategoryBadge(group.category)}
                          <span className="text-xs font-bold text-zinc-100">
                            {formatIssueTitle(group.issue_type)}
                          </span>
                          <span className="text-[10px] font-mono text-zinc-500">
                            {group.issue_type}
                          </span>
                        </div>
                        <p className="text-xs text-zinc-400 line-clamp-1">{group.explanation}</p>
                      </div>
                    </div>

                    <div className="shrink-0 flex items-center gap-2">
                      <span className="px-2.5 py-1 rounded-lg bg-zinc-900 border border-zinc-800 font-mono text-xs font-bold text-zinc-200">
                        {group.items.length} {group.items.length === 1 ? 'URL' : 'URLs'}
                      </span>
                    </div>
                  </button>

                  {isExpanded && (
                    <div className="bg-zinc-900/25 border-t border-zinc-800/60 px-5 py-3 space-y-2">
                      <div className="p-3 rounded-xl bg-emerald-950/15 border border-emerald-800/30 text-xs text-zinc-300">
                        <span className="font-semibold text-emerald-400">Recommended Fix: </span>
                        {group.recommended_fix}
                      </div>
                      <div className="divide-y divide-zinc-800/50 rounded-xl border border-zinc-800/80 bg-zinc-950 overflow-hidden">
                        {group.items.map((item) => (
                          <div
                            key={item.id}
                            onClick={() => setActiveIssue(item)}
                            className="px-3.5 py-2.5 hover:bg-zinc-900/60 cursor-pointer flex items-center justify-between gap-3 text-xs group"
                          >
                            <div className="min-w-0">
                              <div className="font-mono text-[11px] text-zinc-200 group-hover:text-emerald-400 truncate">
                                {item.affected_url}
                              </div>
                              <div className="text-[11px] text-zinc-500 truncate mt-0.5">
                                {item.explanation}
                              </div>
                            </div>
                            <span className="text-[10px] font-semibold text-zinc-400 group-hover:text-emerald-400 shrink-0 flex items-center gap-1">
                              Inspect <ChevronRight className="w-3.5 h-3.5" />
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-zinc-800/80 bg-zinc-900/50 text-zinc-400 font-medium">
                  <th className="py-3 px-4">Severity</th>
                  <th className="py-3 px-4">Category</th>
                  <th className="py-3 px-4">Issue & Diagnostic</th>
                  <th className="py-3 px-4">Affected URL</th>
                  <th className="py-3 px-4 text-right">Inspect</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60">
                {filteredIssues.map((issue) => (
                  <tr
                    key={issue.id}
                    onClick={() => setActiveIssue(issue)}
                    className="hover:bg-zinc-900/40 cursor-pointer transition group"
                  >
                    <td className="py-3.5 px-4 whitespace-nowrap">{getSeverityBadge(issue.severity)}</td>
                    <td className="py-3.5 px-4 whitespace-nowrap">{getCategoryBadge(issue.category)}</td>
                    <td className="py-3.5 px-4 max-w-md">
                      <div className="font-semibold text-zinc-200 group-hover:text-emerald-400 transition text-xs">
                        {formatIssueTitle(issue.issue_type)}
                      </div>
                      <div className="text-zinc-400 text-[11px] mt-0.5 line-clamp-1">
                        {issue.explanation}
                      </div>
                    </td>
                    <td className="py-3.5 px-4 max-w-xs truncate">
                      <span className="font-mono text-[11px] text-zinc-300">
                        {issue.affected_url}
                      </span>
                    </td>
                    <td className="py-3.5 px-4 text-right whitespace-nowrap">
                      <ChevronRight className="w-4 h-4 text-zinc-500 group-hover:text-emerald-400 inline" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Detailed Issue Inspection Drawer */}
      {activeIssue && (
        <div className="fixed inset-0 z-50 flex items-center justify-end bg-black/70 backdrop-blur-xs animate-fadeIn">
          <div className="bg-zinc-950 border-l border-zinc-800 w-full max-w-xl h-full flex flex-col shadow-2xl p-6 overflow-y-auto space-y-6">
            <div className="flex items-start justify-between gap-4 border-b border-zinc-800/80 pb-4">
              <div>
                <div className="flex items-center gap-2 mb-2">
                  {getSeverityBadge(activeIssue.severity)}
                  {getCategoryBadge(activeIssue.category)}
                </div>
                <h3 className="text-base font-bold text-zinc-100">
                  {formatIssueTitle(activeIssue.issue_type)}
                </h3>
                <p className="text-[11px] font-mono text-zinc-500 mt-0.5">{activeIssue.issue_type}</p>
              </div>
              <button
                onClick={() => setActiveIssue(null)}
                className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-lg transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-4 bg-zinc-900/60 border border-zinc-800 rounded-xl space-y-1.5">
              <span className="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider">
                Affected Target URL
              </span>
              <div className="flex items-center justify-between gap-2 font-mono text-xs text-emerald-400 break-all">
                <span>{activeIssue.affected_url}</span>
                <a
                  href={activeIssue.affected_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="p-1 text-zinc-400 hover:text-zinc-200 shrink-0"
                >
                  <ExternalLink className="w-3.5 h-3.5" />
                </a>
              </div>
            </div>

            <div className="space-y-1.5">
              <h4 className="text-xs font-semibold text-zinc-300 uppercase tracking-wider flex items-center gap-1.5">
                <HelpCircle className="w-3.5 h-3.5 text-zinc-400" />
                Audit Diagnostic Explanation
              </h4>
              <p className="text-xs text-zinc-300 leading-relaxed p-3.5 bg-zinc-900/40 border border-zinc-800/80 rounded-xl">
                {activeIssue.explanation}
              </p>
            </div>

            {activeIssue.evidence && (
              <div className="space-y-1.5">
                <h4 className="text-xs font-semibold text-zinc-300 uppercase tracking-wider flex items-center gap-1.5">
                  <Code2 className="w-3.5 h-3.5 text-emerald-400" />
                  Verified Crawler Evidence
                </h4>
                <div className="bg-black border border-zinc-800 rounded-xl p-3.5 font-mono text-xs text-emerald-300 overflow-x-auto shadow-inner">
                  <pre>{JSON.stringify(activeIssue.evidence, null, 2)}</pre>
                </div>
              </div>
            )}

            <div className="space-y-1.5">
              <h4 className="text-xs font-semibold text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
                <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
                Recommended Fix
              </h4>
              <div className="p-4 bg-emerald-950/20 border border-emerald-800/40 rounded-xl text-xs text-zinc-200 leading-relaxed">
                {activeIssue.recommended_fix}
              </div>
              <div className="p-3 bg-zinc-900/80 border border-zinc-800 rounded-xl text-[11px] text-zinc-400 flex items-start gap-2">
                <ShieldCheck className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                <span>
                  <strong>Verification Protocol:</strong> Fixes are marked verified clean only after a subsequent crawl confirms the resolved HTML/HTTP state.
                </span>
              </div>
            </div>

            <div className="border-t border-zinc-800/80 pt-4 text-[11px] text-zinc-500 space-y-1 font-mono">
              <div>First detected: {new Date(activeIssue.first_seen_at).toLocaleString()}</div>
              <div>Last seen: {new Date(activeIssue.last_seen_at).toLocaleString()}</div>
              <div>Status: {activeIssue.current_status} ({activeIssue.verification_status})</div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
