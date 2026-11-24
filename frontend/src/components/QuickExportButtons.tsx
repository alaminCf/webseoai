'use client';

import React, { useState } from 'react';
import { FileDown, FileSpreadsheet, RefreshCw } from 'lucide-react';
import { Website } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { downloadExecutivePdfReport, ReportBundle } from '@/lib/reportPdf';

interface QuickExportButtonsProps {
  website: Website | null;
  section:
    | 'site_audit'
    | 'action_plan'
    | 'search_console'
    | 'rank_checker'
    | 'competitors'
    | 'live_traffic'
    | 'content_studio';
  csvDataset: string;
  reportTitle: string;
  filenamePrefix: string;
  range?: string;
  compact?: boolean;
}

export function QuickExportButtons({
  website,
  section,
  csvDataset,
  reportTitle,
  filenamePrefix,
  range = '28d',
  compact = false,
}: QuickExportButtonsProps) {
  const toast = useToast();
  const [exportingPdf, setExportingPdf] = useState(false);
  const [exportingCsv, setExportingCsv] = useState(false);

  if (!website) return null;

  const handleExportPdf = async () => {
    setExportingPdf(true);
    try {
      const res = await api.get<ReportBundle>(
        `/websites/${website.id}/reports/bundle?range=${encodeURIComponent(range)}&sections=executive_summary,${section}`
      );
      if (res.success && res.data) {
        const fname = downloadExecutivePdfReport(res.data, {
          reportTitle,
          selectedSections: ['executive_summary', section],
          filenamePrefix,
        });
        toast.success(`Downloaded PDF report: ${fname}`);
      } else {
        toast.error(res.error?.message || 'Failed to generate PDF report.');
      }
    } catch {
      toast.error('Error generating PDF report.');
    } finally {
      setExportingPdf(false);
    }
  };

  const handleExportCsv = async () => {
    setExportingCsv(true);
    try {
      const out = await api.downloadFile(
        `/websites/${website.id}/reports/export/csv?dataset=${encodeURIComponent(csvDataset)}&range=${encodeURIComponent(range)}`,
        `${website.domain}-${csvDataset}.csv`
      );
      if (out.ok) {
        toast.success(`Downloaded CSV: ${out.filename}`);
      } else {
        toast.error(out.error || 'Failed to export CSV.');
      }
    } finally {
      setExportingCsv(false);
    }
  };

  return (
    <div className="inline-flex items-center gap-1.5">
      <button
        type="button"
        disabled={exportingPdf}
        onClick={handleExportPdf}
        title={`Download ${reportTitle} as PDF`}
        className={`inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 font-semibold transition disabled:opacity-50 ${
          compact ? 'px-2.5 py-1.5 text-[11px]' : 'px-3 py-1.5 text-xs'
        }`}
      >
        {exportingPdf ? (
          <RefreshCw className="w-3.5 h-3.5 animate-spin" />
        ) : (
          <FileDown className="w-3.5 h-3.5" />
        )}
        <span>PDF</span>
      </button>
      <button
        type="button"
        disabled={exportingCsv}
        onClick={handleExportCsv}
        title={`Export ${reportTitle} as CSV`}
        className={`inline-flex items-center gap-1.5 rounded-xl border border-zinc-700 bg-zinc-900 hover:bg-zinc-800 text-zinc-200 font-semibold transition disabled:opacity-50 ${
          compact ? 'px-2.5 py-1.5 text-[11px]' : 'px-3 py-1.5 text-xs'
        }`}
      >
        {exportingCsv ? (
          <RefreshCw className="w-3.5 h-3.5 animate-spin" />
        ) : (
          <FileSpreadsheet className="w-3.5 h-3.5 text-sky-400" />
        )}
        <span>CSV</span>
      </button>
    </div>
  );
}
