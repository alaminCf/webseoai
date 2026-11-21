'use client';

import React, { useState, useEffect } from 'react';
import {
  X,
  TrendingUp,
  TrendingDown,
  CheckCircle2,
  AlertCircle,
  ArrowRight,
  GitCompare,
  Calendar,
  Layers,
} from 'lucide-react';
import { Website, AuditRun, AuditComparison } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface AuditCompareModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
}

export function AuditCompareModal({ website, isOpen, onClose }: AuditCompareModalProps) {
  const toast = useToast();
  const [runs, setRuns] = useState<AuditRun[]>([]);
  const [selectedRun1, setSelectedRun1] = useState<number | null>(null);
  const [selectedRun2, setSelectedRun2] = useState<number | null>(null);
  const [comparison, setComparison] = useState<AuditComparison | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);

  useEffect(() => {
    if (!isOpen || !website) return;

    const fetchRuns = async () => {
      try {
        const res = await api.get<AuditRun[]>(`/websites/${website.id}/audits`);
        if (res.success && res.data) {
          setRuns(res.data);
          if (res.data.length >= 2) {
            setSelectedRun1(res.data[1].id); // older
            setSelectedRun2(res.data[0].id); // newer
          } else if (res.data.length === 1) {
            setSelectedRun2(res.data[0].id);
          }
        }
      } catch {
        toast.error('Could not load audit runs for comparison.');
      }
    };

    fetchRuns();
  }, [isOpen, website, toast]);

  useEffect(() => {
    if (!website || !selectedRun1 || !selectedRun2) return;

    const compare = async () => {
      setIsLoading(true);
      try {
        const res = await api.get<AuditComparison>(
          `/websites/${website.id}/audits/compare?run1=${selectedRun1}&run2=${selectedRun2}`
        );
        if (res.success && res.data) {
          setComparison(res.data);
        } else {
          setComparison(null);
        }
      } catch {
        toast.error('Failed to compare selected audits.');
      } finally {
        setIsLoading(false);
      }
    };

    compare();
  }, [website, selectedRun1, selectedRun2, toast]);

  if (!isOpen || !website) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-3xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-800/80 bg-zinc-900/40">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-xl bg-cyan-950/50 border border-cyan-800/40 text-cyan-400">
              <GitCompare className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                Before / After Audit Comparison
              </h3>
              <p className="text-xs text-zinc-400 font-mono mt-0.5">
                Analyze verified fixes, score progression, and new SEO regressions
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
        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          {runs.length < 2 ? (
            <div className="p-12 text-center text-zinc-500">
              <Layers className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
              <p className="text-sm font-semibold text-zinc-300">Insufficient Audit History</p>
              <p className="text-xs text-zinc-500 mt-1 max-w-md mx-auto">
                Comparison requires at least two separate crawl audits. Run an additional crawl after making SEO improvements to benchmark progress.
              </p>
            </div>
          ) : (
            <>
              {/* Selectors Bar */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 bg-zinc-900/40 border border-zinc-800 rounded-xl text-xs">
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium flex items-center gap-1.5">
                    <Calendar className="w-3.5 h-3.5 text-zinc-500" />
                    Baseline Audit (Older Run)
                  </label>
                  <select
                    value={selectedRun1 || ''}
                    onChange={(e) => setSelectedRun1(parseInt(e.target.value, 10))}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  >
                    {runs.map((r) => (
                      <option key={r.id} value={r.id}>
                        Run #{r.id} ({new Date(r.created_at).toLocaleDateString()}) — Score: {r.overall_score}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium flex items-center gap-1.5">
                    <Calendar className="w-3.5 h-3.5 text-emerald-400" />
                    Comparison Audit (Newer Run)
                  </label>
                  <select
                    value={selectedRun2 || ''}
                    onChange={(e) => setSelectedRun2(parseInt(e.target.value, 10))}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  >
                    {runs.map((r) => (
                      <option key={r.id} value={r.id}>
                        Run #{r.id} ({new Date(r.created_at).toLocaleDateString()}) — Score: {r.overall_score}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Comparison Stats Cards */}
              {isLoading ? (
                <div className="p-8 text-center text-zinc-500">
                  <div className="w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                  <span className="text-xs">Computing diff between audit graphs...</span>
                </div>
              ) : comparison ? (
                <div className="space-y-6">
                  {/* Score Delta Banner */}
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="p-4 bg-zinc-900/60 border border-zinc-800 rounded-xl flex items-center justify-between">
                      <div>
                        <div className="text-[11px] text-zinc-400 uppercase font-semibold">
                          Health Score Delta
                        </div>
                        <div className="text-2xl font-bold font-mono mt-1 flex items-center gap-1.5">
                          {comparison.score_diff >= 0 ? (
                            <span className="text-emerald-400 flex items-center gap-1">
                              <TrendingUp className="w-5 h-5" />
                              +{comparison.score_diff}
                            </span>
                          ) : (
                            <span className="text-rose-400 flex items-center gap-1">
                              <TrendingDown className="w-5 h-5" />
                              {comparison.score_diff}
                            </span>
                          )}
                        </div>
                      </div>
                      <div className="text-right text-xs text-zinc-500 font-mono">
                        {(comparison.run1 ?? comparison.before)?.score ?? 0}{' '}
                        <ArrowRight className="w-3 h-3 inline mx-1" />{' '}
                        {(comparison.run2 ?? comparison.after)?.score ?? 0}
                      </div>
                    </div>

                    <div className="p-4 bg-emerald-950/20 border border-emerald-800/40 rounded-xl">
                      <div className="text-[11px] text-emerald-400 uppercase font-semibold">
                        Resolved Issues
                      </div>
                      <div className="text-2xl font-bold font-mono text-emerald-400 mt-1 flex items-center gap-1.5">
                        <CheckCircle2 className="w-5 h-5" />
                        {comparison.resolved_issues_count}
                      </div>
                      <div className="text-[10px] text-zinc-400 mt-0.5">
                        Fixed and verified clean
                      </div>
                    </div>

                    <div className="p-4 bg-rose-950/20 border border-rose-800/40 rounded-xl">
                      <div className="text-[11px] text-rose-400 uppercase font-semibold">
                        New Issues / Regressions
                      </div>
                      <div className="text-2xl font-bold font-mono text-rose-400 mt-1 flex items-center gap-1.5">
                        <AlertCircle className="w-5 h-5" />
                        {comparison.new_issues_count}
                      </div>
                      <div className="text-[10px] text-zinc-400 mt-0.5">
                        Newly detected defects
                      </div>
                    </div>
                  </div>

                  {/* Resolved Issues List */}
                  {comparison.resolved_issues.length > 0 && (
                    <div className="space-y-2">
                      <h4 className="text-xs font-semibold text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
                        <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                        Resolved & Verified Fixes ({comparison.resolved_issues.length})
                      </h4>
                      <div className="bg-zinc-950 border border-zinc-800 rounded-xl divide-y divide-zinc-800/60 max-h-48 overflow-y-auto">
                        {comparison.resolved_issues.map((issue, idx) => (
                          <div key={idx} className="p-3 text-xs flex items-start justify-between gap-3">
                            <div>
                              <div className="font-semibold text-zinc-200 font-mono text-[11px]">
                                {issue.issue_type}
                              </div>
                              <div className="text-zinc-500 font-mono text-[10px] truncate max-w-md mt-0.5">
                                {issue.affected_url}
                              </div>
                            </div>
                            <span className="px-2 py-0.5 text-[10px] bg-emerald-950/60 text-emerald-400 border border-emerald-800/60 rounded-md font-semibold shrink-0">
                              Verified Clean
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* New Issues List */}
                  {comparison.new_issues.length > 0 && (
                    <div className="space-y-2">
                      <h4 className="text-xs font-semibold text-rose-400 uppercase tracking-wider flex items-center gap-1.5">
                        <AlertCircle className="w-4 h-4 text-rose-400" />
                        Newly Detected Issues ({comparison.new_issues.length})
                      </h4>
                      <div className="bg-zinc-950 border border-zinc-800 rounded-xl divide-y divide-zinc-800/60 max-h-48 overflow-y-auto">
                        {comparison.new_issues.map((issue, idx) => (
                          <div key={idx} className="p-3 text-xs flex items-start justify-between gap-3">
                            <div>
                              <div className="font-semibold text-zinc-200 font-mono text-[11px]">
                                {issue.issue_type}
                              </div>
                              <div className="text-zinc-500 font-mono text-[10px] truncate max-w-md mt-0.5">
                                {issue.affected_url}
                              </div>
                            </div>
                            <span className="px-2 py-0.5 text-[10px] bg-rose-950/60 text-rose-400 border border-rose-800/60 rounded-md font-semibold shrink-0">
                              {issue.severity.toUpperCase()}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              ) : null}
            </>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-zinc-800/80 bg-zinc-900/40 flex justify-end">
          <button
            onClick={onClose}
            className="px-4 py-2 text-xs font-semibold text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
          >
            Close Comparison
          </button>
        </div>
      </div>
    </div>
  );
}
