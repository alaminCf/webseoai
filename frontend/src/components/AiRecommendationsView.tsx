'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Sparkles,
  Play,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  Clock,
  ExternalLink,
  ShieldAlert,
  Code2,
  HelpCircle,
  Settings,
  RefreshCw,
  Sliders,
  Filter,
  Check,
  X,
  ChevronDown,
} from 'lucide-react';
import { Website, AiRecommendation } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { ContextProfileModal } from './ContextProfileModal';

interface AiRecommendationsViewProps {
  website: Website | null;
}

export function AiRecommendationsView({ website }: AiRecommendationsViewProps) {
  const toast = useToast();
  const [recommendations, setRecommendations] = useState<AiRecommendation[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isGenerating, setIsGenerating] = useState<boolean>(false);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [isProfileModalOpen, setIsProfileModalOpen] = useState<boolean>(false);

  const loadRecommendations = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      let endpoint = `/websites/${website.id}/recommendations?per_page=50`;
      if (statusFilter !== 'all') endpoint += `&status=${statusFilter}`;
      if (categoryFilter !== 'all') endpoint += `&category=${categoryFilter}`;

      const res = await api.get<AiRecommendation[]>(endpoint);
      if (res.success && res.data) {
        setRecommendations(res.data);
      } else {
        setRecommendations([]);
      }
    } catch {
      toast.error('Could not load AI recommendations.');
    } finally {
      setIsLoading(false);
    }
  }, [website, statusFilter, categoryFilter, toast]);

  useEffect(() => {
    loadRecommendations();
  }, [loadRecommendations]);

  const handleGenerate = async () => {
    if (!website) return;
    setIsGenerating(true);
    try {
      const res = await api.post<{ count: number; items: AiRecommendation[] }>(
        `/websites/${website.id}/recommendations/generate`
      );
      if (res.success && res.data) {
        toast.success(`Generated ${res.data.count} prioritized AI SEO recommendations!`);
        loadRecommendations();
      } else {
        toast.error(res.error?.message || 'Failed to generate recommendations.');
      }
    } catch {
      toast.error('Network error running AI Strategist.');
    } finally {
      setIsGenerating(false);
    }
  };

  const handleUpdateStatus = async (id: number, newStatus: string) => {
    try {
      const res = await api.patch<AiRecommendation>(`/recommendations/${id}/status`, {
        status: newStatus,
      });
      if (res.success) {
        toast.success(`Recommendation marked as ${newStatus}.`);
        setRecommendations((prev) =>
          prev.map((r) => (r.id === id ? { ...r, status: newStatus as any } : r))
        );
      } else {
        toast.error(res.error?.message || 'Could not update status.');
      }
    } catch {
      toast.error('Network error updating recommendation.');
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <Sparkles className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Header Banner */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
        <div>
          <div className="flex items-center gap-2 mb-1.5">
            <span className="p-1 rounded-md bg-purple-950/60 border border-purple-800/60 text-purple-400">
              <Sparkles className="w-4 h-4" />
            </span>
            <h2 className="text-lg font-bold text-zinc-100">AI SEO Strategist & Priority Matrix</h2>
            <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
              Grounded Evidence
            </span>
          </div>
          <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
            Prioritizes technical fixes and content tasks using the quantitative ICE formula:{' '}
            <code className="text-purple-300 font-mono text-[11px] bg-purple-950/40 px-1.5 py-0.5 rounded border border-purple-800/40">
              Score = (Impact × Confidence) / (Effort + Risk × 0.5)
            </code>
            . All benefits are formulated as realistic estimates.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => setIsProfileModalOpen(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
          >
            <Settings className="w-3.5 h-3.5 text-purple-400" />
            <span>Brand & Context</span>
          </button>

          <button
            onClick={handleGenerate}
            disabled={isGenerating}
            className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 rounded-xl shadow transition"
          >
            {isGenerating ? (
              <RefreshCw className="w-3.5 h-3.5 animate-spin" />
            ) : (
              <Play className="w-3.5 h-3.5" />
            )}
            <span>{isGenerating ? 'Analyzing Site...' : 'Run AI Strategist'}</span>
          </button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3 p-4 bg-zinc-950 border border-zinc-800/80 rounded-2xl text-xs">
        <div className="flex flex-wrap items-center gap-2">
          {/* Status Filter */}
          <div className="flex items-center bg-zinc-900 border border-zinc-800 rounded-xl p-1">
            {['all', 'proposed', 'accepted', 'in_progress', 'applied'].map((st) => (
              <button
                key={st}
                onClick={() => setStatusFilter(st)}
                className={`px-3 py-1 rounded-lg capitalize font-medium transition ${
                  statusFilter === st
                    ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                    : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {st === 'all' ? 'All Status' : st.replace('_', ' ')}
              </button>
            ))}
          </div>

          {/* Category Filter */}
          <select
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-purple-500 font-medium"
          >
            <option value="all">All Categories</option>
            <option value="technical">Technical</option>
            <option value="content">Content</option>
            <option value="links">Links & Architecture</option>
          </select>
        </div>

        <div className="text-[11px] text-zinc-500 font-mono">
          Showing {recommendations.length} prioritized tasks
        </div>
      </div>

      {/* Recommendations Cards Grid */}
      {isLoading ? (
        <div className="p-16 text-center text-zinc-500 flex flex-col items-center justify-center gap-3">
          <div className="w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full animate-spin" />
          <span className="text-xs">Evaluating site graph and priority ranking...</span>
        </div>
      ) : recommendations.length === 0 ? (
        <div className="p-16 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
          <Sparkles className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
          <p className="text-sm font-semibold text-zinc-300">No AI Recommendations Generated</p>
          <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto mb-4">
            Click &quot;Run AI Strategist&quot; to synthesize actionable high-impact tasks from your crawl and audit findings.
          </p>
          <button
            onClick={handleGenerate}
            disabled={isGenerating}
            className="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-semibold shadow transition"
          >
            <Play className="w-3.5 h-3.5" />
            Synthesize Recommendations
          </button>
        </div>
      ) : (
        <div className="space-y-4">
          {recommendations.map((rec) => (
            <div
              key={rec.id}
              className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4 shadow-sm hover:border-zinc-700/80 transition group"
            >
              {/* Card Header */}
              <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] uppercase font-bold font-mono tracking-wider px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                      {rec.category}
                    </span>
                    <span
                      className={`text-[10px] uppercase font-bold font-mono px-2 py-0.5 rounded ${
                        rec.status === 'applied'
                          ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/60'
                          : rec.status === 'accepted'
                          ? 'bg-blue-950/60 text-blue-400 border border-blue-800/60'
                          : 'bg-zinc-900 text-zinc-400 border border-zinc-800'
                      }`}
                    >
                      {rec.status.replace('_', ' ')}
                    </span>
                  </div>
                  <h3 className="text-base font-bold text-zinc-100 group-hover:text-purple-300 transition">
                    {rec.title}
                  </h3>
                  <div className="text-xs font-mono text-zinc-400 flex items-center gap-1.5 truncate max-w-xl">
                    <span>{rec.affected_url}</span>
                    <a
                      href={rec.affected_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-zinc-500 hover:text-zinc-300"
                    >
                      <ExternalLink className="w-3 h-3" />
                    </a>
                  </div>
                </div>

                {/* Priority Score Badge */}
                <div className="flex items-center sm:flex-col sm:items-end gap-2 shrink-0">
                  <div className="text-right">
                    <div className="flex items-baseline gap-1">
                      <span className="text-2xl font-extrabold font-mono text-purple-400">
                        {rec.priority_score}
                      </span>
                      <span className="text-[10px] text-zinc-500 font-mono">/100</span>
                    </div>
                    <span className="text-[10px] text-zinc-500 uppercase font-semibold block">
                      Priority Rank
                    </span>
                  </div>
                </div>
              </div>

              {/* Action & Benefit Box */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                <div className="p-3.5 bg-zinc-900/40 border border-zinc-800 rounded-xl space-y-1">
                  <span className="font-semibold text-zinc-300 uppercase tracking-wider text-[11px] block">
                    Recommended Implementation Action
                  </span>
                  <p className="text-zinc-200 leading-relaxed">{rec.recommended_action}</p>
                </div>

                <div className="p-3.5 bg-emerald-950/15 border border-emerald-800/30 rounded-xl space-y-1">
                  <span className="font-semibold text-emerald-400 uppercase tracking-wider text-[11px] block">
                    Expected Benefit (Estimated Gain)
                  </span>
                  <p className="text-zinc-300 leading-relaxed">{rec.expected_benefit}</p>
                </div>
              </div>

              {/* Reasoning & Verification Drawer */}
              <div className="p-3.5 bg-zinc-900/20 border border-zinc-800/60 rounded-xl text-xs space-y-2 text-zinc-400">
                <div>
                  <strong className="text-zinc-300">Strategic Reasoning:</strong>{' '}
                  {rec.reasoning_summary}
                </div>
                <div className="text-[11px] text-zinc-500 flex items-center gap-2 pt-1 border-t border-zinc-800/40 font-mono">
                  <span>Verification: {rec.verification_procedure}</span>
                  <span>•</span>
                  <span>Required Role: {rec.required_permissions}</span>
                </div>
              </div>

              {/* Quantitative Metrics Bar & Action Buttons */}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2 border-t border-zinc-800/60 text-xs">
                {/* Scoring Pills */}
                <div className="flex items-center gap-2 text-[11px] font-mono">
                  <span className="px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-300">
                    Impact: <strong className="text-purple-400">{rec.impact_score}/10</strong>
                  </span>
                  <span className="px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-300">
                    Confidence: <strong className="text-cyan-400">{rec.confidence_score}/10</strong>
                  </span>
                  <span className="px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-300">
                    Effort: <strong className="text-zinc-400">{rec.effort_score}/10</strong>
                  </span>
                  <span className="px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-300">
                    Risk: <strong className="text-zinc-400">{rec.risk_score}/10</strong>
                  </span>
                </div>

                {/* Status Switcher Actions */}
                <div className="flex items-center gap-2">
                  {rec.status === 'proposed' && (
                    <button
                      onClick={() => handleUpdateStatus(rec.id, 'accepted')}
                      className="px-3 py-1 bg-purple-950/60 hover:bg-purple-900/60 text-purple-300 border border-purple-800/60 rounded-lg font-medium transition"
                    >
                      Accept Task
                    </button>
                  )}
                  {rec.status === 'accepted' && (
                    <button
                      onClick={() => handleUpdateStatus(rec.id, 'applied')}
                      className="px-3 py-1 bg-emerald-950/60 hover:bg-emerald-900/60 text-emerald-300 border border-emerald-800/60 rounded-lg font-medium transition"
                    >
                      Mark Applied
                    </button>
                  )}
                  {rec.status !== 'dismissed' && (
                    <button
                      onClick={() => handleUpdateStatus(rec.id, 'dismissed')}
                      className="px-2.5 py-1 text-zinc-500 hover:text-zinc-300 rounded-lg transition"
                    >
                      Dismiss
                    </button>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Brand Context Profile Modal */}
      <ContextProfileModal
        website={website}
        isOpen={isProfileModalOpen}
        onClose={() => setIsProfileModalOpen(false)}
        onProfileUpdated={loadRecommendations}
      />
    </div>
  );
}
