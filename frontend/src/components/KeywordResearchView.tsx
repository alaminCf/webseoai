'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Search,
  Globe,
  Sliders,
  Sparkles,
  Layers,
  HelpCircle,
  TrendingUp,
  DollarSign,
  Compass,
  CheckCircle2,
  Info,
  Clock,
  ExternalLink,
  ChevronRight,
  RefreshCw,
  FolderTree,
} from 'lucide-react';
import { Website, KeywordResearchItem, KeywordCluster } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface KeywordResearchViewProps {
  website: Website | null;
  onNavigateToContentStudio?: () => void;
}

export function KeywordResearchView({
  website,
  onNavigateToContentStudio,
}: KeywordResearchViewProps) {
  const toast = useToast();

  const [activeTab, setActiveTab] = useState<'suggestions' | 'clusters' | 'history'>('suggestions');
  const [queryInput, setQueryInput] = useState<string>('technical seo');
  const [country, setCountry] = useState<string>('US');
  const [language, setLanguage] = useState<string>('en');

  const [results, setResults] = useState<KeywordResearchItem[]>([]);
  const [clusters, setClusters] = useState<KeywordCluster[]>([]);
  const [historyItems, setHistoryItems] = useState<KeywordResearchItem[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [selectedProvenance, setSelectedProvenance] = useState<any | null>(null);

  // Search keyword provider
  const handleSearch = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!queryInput.trim()) return;

    setIsLoading(true);
    try {
      const res = await api.get<KeywordResearchItem[]>(
        `/keywords/search?query=${encodeURIComponent(queryInput.trim())}&country=${country}&language=${language}${
          website ? `&website_id=${website.id}` : ''
        }`
      );

      if (res.success && res.data) {
        setResults(res.data);
        toast.success(`Retrieved ${res.data.length} keyword metrics from provider.`);
      } else {
        toast.error(res.error?.message || 'Keyword search failed.');
      }

      // Also fetch clusters in parallel
      const clusterRes = await api.get<KeywordCluster[]>(
        `/keywords/clusters?keyword=${encodeURIComponent(queryInput.trim())}&country=${country}&language=${language}`
      );
      if (clusterRes.success && clusterRes.data) {
        setClusters(clusterRes.data);
      }
    } catch {
      toast.error('Network error during keyword search.');
    } finally {
      setIsLoading(false);
    }
  };

  // Load History
  const loadHistory = useCallback(async () => {
    try {
      const endpoint = website
        ? `/keywords/history?website_id=${website.id}&per_page=50`
        : `/keywords/history?per_page=50`;
      const res = await api.get<KeywordResearchItem[]>(endpoint);
      if (res.success && res.data) {
        setHistoryItems(res.data);
      }
    } catch {
      // Ignore background error
    }
  }, [website]);

  useEffect(() => {
    handleSearch();
    loadHistory();
  }, [website, loadHistory]);

  const getIntentBadge = (intent: string | null) => {
    switch (intent) {
      case 'transactional':
        return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
      case 'commercial':
        return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';
      case 'informational':
        return 'bg-blue-500/10 text-blue-400 border-blue-500/20';
      case 'navigational':
        return 'bg-purple-500/10 text-purple-400 border-purple-500/20';
      default:
        return 'bg-zinc-800 text-zinc-400 border-zinc-700';
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs font-semibold uppercase tracking-wider text-cyan-400">
              Phase 4: Keyword Data Provider
            </span>
          </div>
          <h1 className="text-xl font-extrabold text-zinc-100 flex items-center gap-2">
            Keyword Research & Metric Provenance
          </h1>
          <p className="text-xs text-zinc-400">
            Real search volumes, keyword difficulty, CPC metrics, intent classification, and semantic clustering with strict provenance tracking.
          </p>
        </div>

        {onNavigateToContentStudio && (
          <button
            onClick={onNavigateToContentStudio}
            className="px-3.5 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition flex items-center gap-1.5 shadow"
          >
            <Sparkles className="w-3.5 h-3.5 text-emerald-400" />
            <span>Open Content Studio</span>
          </button>
        )}
      </div>

      {/* Search Input Bar */}
      <form
        onSubmit={handleSearch}
        className="p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-wrap items-center gap-3 shadow-sm"
      >
        <div className="flex-1 min-w-[240px] relative">
          <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-400" />
          <input
            type="text"
            placeholder="Enter seed keyword (e.g. ecommerce seo, cloud hosting, shopify audit)..."
            value={queryInput}
            onChange={(e) => setQueryInput(e.target.value)}
            className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-10 pr-4 py-2 text-xs text-zinc-200 focus:outline-none focus:border-cyan-500 font-medium"
          />
        </div>

        {/* Country Selector */}
        <div className="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1 text-xs">
          <Globe className="w-3.5 h-3.5 text-zinc-400" />
          <select
            value={country}
            onChange={(e) => setCountry(e.target.value)}
            className="bg-transparent text-zinc-300 focus:outline-none"
          >
            <option value="US" className="bg-zinc-900">United States (US)</option>
            <option value="GB" className="bg-zinc-900">United Kingdom (GB)</option>
            <option value="CA" className="bg-zinc-900">Canada (CA)</option>
            <option value="AU" className="bg-zinc-900">Australia (AU)</option>
            <option value="DE" className="bg-zinc-900">Germany (DE)</option>
            <option value="FR" className="bg-zinc-900">France (FR)</option>
          </select>
        </div>

        {/* Language Selector */}
        <select
          value={language}
          onChange={(e) => setLanguage(e.target.value)}
          className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-2 text-xs text-zinc-300 focus:outline-none"
        >
          <option value="en" className="bg-zinc-900">English (en)</option>
          <option value="de" className="bg-zinc-900">German (de)</option>
          <option value="fr" className="bg-zinc-900">French (fr)</option>
          <option value="es" className="bg-zinc-900">Spanish (es)</option>
        </select>

        <button
          type="submit"
          disabled={isLoading || !queryInput.trim()}
          className="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-white rounded-xl text-xs font-semibold shadow transition flex items-center gap-2"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
          <span>{isLoading ? 'Researching...' : 'Get Keywords'}</span>
        </button>
      </form>

      {/* Tabs */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-2 text-xs">
        <button
          onClick={() => setActiveTab('suggestions')}
          className={`px-3 py-1.5 font-semibold rounded-xl transition ${
            activeTab === 'suggestions'
              ? 'bg-zinc-900 text-cyan-400 border border-zinc-800'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Keyword Suggestions ({results.length})
        </button>
        <button
          onClick={() => setActiveTab('clusters')}
          className={`px-3 py-1.5 font-semibold rounded-xl transition ${
            activeTab === 'clusters'
              ? 'bg-zinc-900 text-cyan-400 border border-zinc-800'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Topic Clusters ({clusters.length})
        </button>
        <button
          onClick={() => {
            setActiveTab('history');
            loadHistory();
          }}
          className={`px-3 py-1.5 font-semibold rounded-xl transition ${
            activeTab === 'history'
              ? 'bg-zinc-900 text-cyan-400 border border-zinc-800'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          Saved Research History ({historyItems.length})
        </button>
      </div>

      {/* Tab 1: Keyword Suggestions Table */}
      {activeTab === 'suggestions' && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/60 text-zinc-400 font-semibold border-b border-zinc-800">
                <tr>
                  <th className="px-4 py-3">Keyword</th>
                  <th className="px-4 py-3 text-right">Search Volume</th>
                  <th className="px-4 py-3 text-center">Difficulty</th>
                  <th className="px-4 py-3 text-right">CPC ($)</th>
                  <th className="px-4 py-3 text-center">Search Intent</th>
                  <th className="px-4 py-3 text-center">Competition</th>
                  <th className="px-4 py-3 text-center">Provenance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60 text-zinc-200">
                {results.length > 0 ? (
                  results.map((kw, idx) => (
                    <tr key={idx} className="hover:bg-zinc-900/40 transition">
                      <td className="px-4 py-3 font-medium text-zinc-100 flex items-center gap-2">
                        <span>{kw.keyword}</span>
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-bold text-cyan-400">
                        {kw.search_volume !== null ? (
                          kw.search_volume.toLocaleString()
                        ) : (
                          <span
                            className="text-[10px] text-zinc-400 italic"
                            title="Not returned by provider / unranked"
                          >
                            Low/Unranked
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-center font-mono">
                        {kw.difficulty !== null ? (
                          <div className="inline-flex items-center gap-1.5">
                            <span
                              className={`font-semibold ${
                                kw.difficulty >= 65
                                  ? 'text-rose-400'
                                  : kw.difficulty >= 35
                                  ? 'text-amber-400'
                                  : 'text-emerald-400'
                              }`}
                            >
                              {kw.difficulty}
                            </span>
                            <div className="w-10 h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                              <div
                                style={{ width: `${kw.difficulty}%` }}
                                className={`h-full ${
                                  kw.difficulty >= 65
                                    ? 'bg-rose-500'
                                    : kw.difficulty >= 35
                                    ? 'bg-amber-500'
                                    : 'bg-emerald-500'
                                }`}
                              />
                            </div>
                          </div>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-zinc-300">
                        {kw.cpc !== null ? `$${kw.cpc}` : '—'}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-semibold border capitalize ${getIntentBadge(
                            kw.intent
                          )}`}
                        >
                          {kw.intent || '—'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="text-[10px] font-mono text-zinc-400 uppercase">
                          {kw.competition_level || '—'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <button
                          onClick={() => setSelectedProvenance(kw.provenance)}
                          className="px-2 py-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded text-[10px] text-zinc-300 hover:text-white transition flex items-center gap-1 mx-auto"
                          title="View Data Provenance"
                        >
                          <Info className="w-3 h-3 text-cyan-400" />
                          <span>Provenance</span>
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-zinc-400">
                      No keywords loaded yet. Enter a seed keyword and click &quot;Get Keywords&quot;.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Tab 2: Topic Clusters */}
      {activeTab === 'clusters' && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {clusters.length > 0 ? (
            clusters.map((c, idx) => (
              <div
                key={idx}
                className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm space-y-3"
              >
                <div className="flex items-center justify-between border-b border-zinc-800 pb-2">
                  <h3 className="text-xs font-bold text-zinc-100 flex items-center gap-1.5">
                    <FolderTree className="w-4 h-4 text-cyan-400" />
                    {c.cluster_name}
                  </h3>
                  <span
                    className={`px-2 py-0.5 rounded text-[10px] font-semibold border capitalize ${getIntentBadge(
                      c.intent
                    )}`}
                  >
                    {c.intent}
                  </span>
                </div>

                <div className="space-y-1.5">
                  {c.keywords.map((kw, kidx) => (
                    <div
                      key={kidx}
                      className="p-2 bg-zinc-900/60 border border-zinc-800 rounded-lg text-xs text-zinc-300 hover:text-white hover:border-zinc-700 transition flex items-center justify-between"
                    >
                      <span>{kw}</span>
                      <button
                        onClick={() => {
                          setQueryInput(kw);
                          handleSearch();
                          setActiveTab('suggestions');
                        }}
                        className="text-[10px] text-cyan-400 hover:underline"
                      >
                        Analyze
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            ))
          ) : (
            <div className="col-span-3 p-8 text-center text-zinc-400 bg-zinc-950 border border-zinc-800 rounded-2xl">
              No clusters available. Run a keyword search to generate semantic clusters.
            </div>
          )}
        </div>
      )}

      {/* Tab 3: History */}
      {activeTab === 'history' && (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-zinc-900/60 text-zinc-400 font-semibold border-b border-zinc-800">
                <tr>
                  <th className="px-4 py-3">Keyword</th>
                  <th className="px-4 py-3 text-right">Search Volume</th>
                  <th className="px-4 py-3 text-center">Difficulty</th>
                  <th className="px-4 py-3 text-right">CPC ($)</th>
                  <th className="px-4 py-3 text-center">Provider</th>
                  <th className="px-4 py-3 text-center">Country / Lang</th>
                  <th className="px-4 py-3 text-center">Fetched At</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60 text-zinc-200">
                {historyItems.length > 0 ? (
                  historyItems.map((h, idx) => (
                    <tr key={idx} className="hover:bg-zinc-900/40 transition">
                      <td className="px-4 py-3 font-medium text-zinc-100">{h.keyword}</td>
                      <td className="px-4 py-3 text-right font-mono font-bold text-cyan-400">
                        {h.search_volume !== null ? h.search_volume.toLocaleString() : 'Low/Unranked'}
                      </td>
                      <td className="px-4 py-3 text-center font-mono">
                        {h.difficulty !== null ? `${h.difficulty}/100` : '—'}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-zinc-300">
                        {h.cpc !== null ? `$${h.cpc}` : '—'}
                      </td>
                      <td className="px-4 py-3 text-center font-mono text-[10px] text-zinc-400 uppercase">
                        {h.provider}
                      </td>
                      <td className="px-4 py-3 text-center font-mono text-[10px] text-zinc-300">
                        {h.country}/{h.language}
                      </td>
                      <td className="px-4 py-3 text-center text-[10px] text-zinc-400">
                        {new Date(h.provenance.fetched_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-zinc-400">
                      No saved keyword history found.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Provenance Details Modal */}
      {selectedProvenance && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-2">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Info className="w-4 h-4 text-cyan-400" />
                Keyword Metric Provenance
              </h3>
              <button
                onClick={() => setSelectedProvenance(null)}
                className="text-zinc-400 hover:text-zinc-100 text-xs"
              >
                ✕
              </button>
            </div>

            <div className="space-y-2.5 text-xs">
              <div className="flex justify-between">
                <span className="text-zinc-400">Provider:</span>
                <span className="font-mono text-zinc-200">{selectedProvenance.provider}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-zinc-400">Fetched Timestamp:</span>
                <span className="font-mono text-zinc-200">{new Date(selectedProvenance.fetched_at).toLocaleString()}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-zinc-400">Target Region:</span>
                <span className="font-mono text-zinc-200">{selectedProvenance.country} ({selectedProvenance.language})</span>
              </div>
              <div className="flex justify-between">
                <span className="text-zinc-400">Data Freshness:</span>
                <span className="text-emerald-400">{selectedProvenance.is_stale ? 'Stale' : 'Fresh (Live)'}</span>
              </div>
              <div className="p-3 bg-zinc-900 border border-zinc-800 rounded-xl text-zinc-300">
                <span className="text-zinc-400 block mb-1">Provenance Note:</span>
                {selectedProvenance.provenance_note || 'Actual verified keyword metrics.'}
              </div>
            </div>

            <div className="pt-2">
              <button
                onClick={() => setSelectedProvenance(null)}
                className="w-full py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl text-xs font-semibold text-zinc-200 transition"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
