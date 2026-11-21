'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Zap,
  TrendingDown,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Clock,
  ArrowRight,
  Filter,
  RefreshCw,
  Sliders,
  ExternalLink,
  ChevronDown,
  Sparkles,
} from 'lucide-react';
import { Website, SearchOpportunity } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface SearchOpportunitiesViewProps {
  website: Website | null;
  onNavigateToPerformance?: () => void;
}

export function SearchOpportunitiesView({
  website,
  onNavigateToPerformance,
}: SearchOpportunitiesViewProps) {
  const toast = useToast();

  const [opportunities, setOpportunities] = useState<SearchOpportunity[]>([]);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isDetecting, setIsDetecting] = useState<boolean>(false);

  const loadOpportunities = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      let endpoint = `/websites/${website.id}/opportunities?per_page=50`;
      if (statusFilter !== 'all') endpoint += `&status=${statusFilter}`;
      if (typeFilter !== 'all') endpoint += `&type=${typeFilter}`;

      const res = await api.get<SearchOpportunity[]>(endpoint);
      if (res.success && res.data) {
        setOpportunities(res.data);
      }
    } catch {
      toast.error('Could not load search performance opportunities.');
    } finally {
      setIsLoading(false);
    }
  }, [website, statusFilter, typeFilter, toast]);

  useEffect(() => {
    loadOpportunities();
  }, [loadOpportunities]);

  const handleDetect = async () => {
    if (!website) return;
    setIsDetecting(true);
    try {
      const res = await api.post<SearchOpportunity[]>(`/websites/${website.id}/opportunities/detect`);
      if (res.success) {
        toast.success(res.message || 'Scanned search performance opportunities.');
        loadOpportunities();
      } else {
        toast.error(res.error?.message || 'Detection failed.');
      }
    } catch {
      toast.error('Detection failed due to a network error.');
    } finally {
      setIsDetecting(false);
    }
  };

  const handleStatusUpdate = async (id: number, newStatus: 'acknowledged' | 'dismissed' | 'resolved') => {
    if (!website) return;
    try {
      const res = await api.patch<SearchOpportunity>(`/websites/${website.id}/opportunities/${id}/status`, {
        status: newStatus,
      });

      if (res.success) {
        toast.success(`Opportunity marked as ${newStatus}.`);
        setOpportunities((prev) =>
          prev.map((o) => (o.id === id ? { ...o, status: newStatus } : o))
        );
      } else {
        toast.error(res.error?.message || 'Failed to update status.');
      }
    } catch {
      toast.error('Status update failed.');
    }
  };

  const getTypeBadge = (type: string) => {
    switch (type) {
      case 'low_ctr':
        return { label: 'Striking Distance (Low CTR)', color: 'text-amber-400 bg-amber-500/10 border-amber-500/20' };
      case 'click_decay':
        return { label: 'Click Decay Alert', color: 'text-rose-400 bg-rose-500/10 border-rose-500/20' };
      case 'rising_query':
        return { label: 'Rising Fast Mover', color: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' };
      case 'keyword_gap':
        return { label: 'Technical Coverage Gap', color: 'text-cyan-400 bg-cyan-500/10 border-cyan-500/20' };
      default:
        return { label: type, color: 'text-zinc-400 bg-zinc-800' };
    }
  };

  if (!website) {
    return <div className="p-8 text-center text-zinc-400">Please select a website.</div>;
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs font-semibold uppercase tracking-wider text-amber-400">
              Phase 4: Opportunity Engine
            </span>
          </div>
          <h1 className="text-xl font-extrabold text-zinc-100 flex items-center gap-2">
            Actionable Search Performance Queue
          </h1>
          <p className="text-xs text-zinc-400">
            Algorithmic detection of striking distance queries, traffic decay, fast movers, and high-impact landing page gaps.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          {onNavigateToPerformance && (
            <button
              onClick={onNavigateToPerformance}
              className="px-3 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
            >
              Search Analytics
            </button>
          )}

          <button
            onClick={handleDetect}
            disabled={isDetecting}
            className="px-4 py-2 text-xs font-semibold text-white bg-amber-600 hover:bg-amber-500 disabled:opacity-50 rounded-xl transition flex items-center gap-2 shadow"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isDetecting ? 'animate-spin' : ''}`} />
            <span>{isDetecting ? 'Scanning...' : 'Detect Opportunities'}</span>
          </button>
        </div>
      </div>

      {/* Filters Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl text-xs">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-semibold text-zinc-400">Status:</span>
          {['all', 'detected', 'acknowledged', 'resolved', 'dismissed'].map((s) => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className={`px-2.5 py-1 rounded-lg capitalize font-medium transition ${
                statusFilter === s
                  ? 'bg-zinc-800 text-zinc-100 border border-zinc-700'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              {s}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="font-semibold text-zinc-400">Rule Type:</span>
          <select
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-1 text-xs text-zinc-300 focus:outline-none"
          >
            <option value="all">All Rules</option>
            <option value="low_ctr">Low CTR (Striking Distance)</option>
            <option value="click_decay">Click Decay Alert</option>
            <option value="rising_query">Rising Fast Mover</option>
            <option value="keyword_gap">Technical Coverage Gap</option>
          </select>
        </div>
      </div>

      {/* Opportunity Cards List */}
      <div className="space-y-4">
        {isLoading ? (
          <div className="p-12 text-center text-zinc-500 text-xs">
            <RefreshCw className="w-5 h-5 animate-spin mx-auto mb-2 text-emerald-500" />
            Loading opportunity queue...
          </div>
        ) : opportunities.length === 0 ? (
          <div className="p-12 text-center bg-zinc-950 border border-zinc-800 rounded-2xl text-zinc-400 space-y-2">
            <p className="text-sm font-semibold text-zinc-200">No opportunities detected matching filters.</p>
            <p className="text-xs">
              Click &quot;Detect Opportunities&quot; to scan recent search console performance and audit crawl data.
            </p>
          </div>
        ) : (
          opportunities.map((opp) => {
            const badge = getTypeBadge(opp.type);

            return (
              <div
                key={opp.id}
                className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm hover:border-zinc-700/80 transition space-y-3"
              >
                {/* Header Row */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-800/60 pb-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold border ${badge.color}`}>
                      {badge.label}
                    </span>
                    <span className="text-xs font-mono font-bold text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded">
                      Impact Score: {opp.impact_score}/100
                    </span>
                    <span className="text-[11px] text-zinc-400 capitalize">
                      Status: <strong className="text-zinc-300">{opp.status}</strong>
                    </span>
                  </div>

                  <span className="text-[11px] text-zinc-400 font-mono">
                    {new Date(opp.detected_at).toLocaleDateString()}
                  </span>
                </div>

                {/* Headline & Target */}
                <div>
                  <h3 className="text-sm font-bold text-zinc-100 mb-1">{opp.headline}</h3>
                  <div className="flex items-center gap-2 text-xs text-zinc-400">
                    <span className="capitalize text-zinc-400">{opp.target_type}:</span>
                    <span className="font-mono text-zinc-300 bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800">
                      {opp.target_identifier}
                    </span>
                  </div>
                </div>

                {/* Reasoning & Recommended Action */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs bg-zinc-900/40 p-3 rounded-xl border border-zinc-800/60">
                  <div>
                    <span className="font-semibold text-zinc-400 block mb-1">Observation & Reasoning:</span>
                    <p className="text-zinc-300 leading-relaxed">{opp.reasoning}</p>
                  </div>
                  <div>
                    <span className="font-semibold text-emerald-400 block mb-1">Recommended Action:</span>
                    <p className="text-zinc-200 leading-relaxed">{opp.recommended_action}</p>
                  </div>
                </div>

                {/* Footer Controls */}
                <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                  {/* Metric Chips */}
                  <div className="flex flex-wrap items-center gap-2 text-[11px] font-mono text-zinc-400">
                    {opp.metrics_snapshot.impressions !== undefined && (
                      <span className="bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800">
                        Imp: {opp.metrics_snapshot.impressions.toLocaleString()}
                      </span>
                    )}
                    {opp.metrics_snapshot.clicks !== undefined && (
                      <span className="bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800 text-emerald-400">
                        Clicks: {opp.metrics_snapshot.clicks}
                      </span>
                    )}
                    {opp.metrics_snapshot.lost_clicks !== undefined && (
                      <span className="bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800 text-rose-400">
                        Lost: -{opp.metrics_snapshot.lost_clicks}
                      </span>
                    )}
                    {opp.metrics_snapshot.avg_position !== undefined && (
                      <span className="bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800">
                        Rank: {opp.metrics_snapshot.avg_position}
                      </span>
                    )}
                  </div>

                  {/* Status Action Buttons */}
                  <div className="flex items-center gap-2 text-xs">
                    {opp.status !== 'acknowledged' && opp.status !== 'resolved' && (
                      <button
                        onClick={() => handleStatusUpdate(opp.id, 'acknowledged')}
                        className="px-2.5 py-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 rounded-lg transition"
                      >
                        Acknowledge
                      </button>
                    )}
                    {opp.status !== 'resolved' && (
                      <button
                        onClick={() => handleStatusUpdate(opp.id, 'resolved')}
                        className="px-2.5 py-1 bg-emerald-950/60 hover:bg-emerald-900/60 border border-emerald-800/60 text-emerald-300 rounded-lg transition flex items-center gap-1 font-semibold"
                      >
                        <CheckCircle2 className="w-3 h-3" />
                        <span>Resolve</span>
                      </button>
                    )}
                    {opp.status !== 'dismissed' && (
                      <button
                        onClick={() => handleStatusUpdate(opp.id, 'dismissed')}
                        className="px-2 py-1 text-zinc-400 hover:text-zinc-200 text-[11px] transition"
                      >
                        Dismiss
                      </button>
                    )}
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
