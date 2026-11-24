'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  FileDown,
  FileSpreadsheet,
  RefreshCw,
  CheckSquare,
  Square,
  Activity,
  Sparkles,
  BarChart3,
  Crosshair,
  Target,
  Radio,
  FileText,
  Sliders,
  Palette,
  Save,
  Calendar,
  Eye,
  CheckCircle2,
  ShieldCheck,
  Building2,
  Upload,
  ImageIcon,
  Trash2,
} from 'lucide-react';
import { Website } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { downloadExecutivePdfReport, ReportBundle } from '@/lib/reportPdf';

interface ReportsCenterViewProps {
  website: Website | null;
}

type SectionKey =
  | 'executive_summary'
  | 'site_audit'
  | 'action_plan'
  | 'search_console'
  | 'rank_checker'
  | 'competitors'
  | 'live_traffic'
  | 'content_studio';

const SECTION_META: Array<{
  key: SectionKey;
  label: string;
  description: string;
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  csvExports: Array<{ dataset: string; label: string }>;
}> = [
  {
    key: 'executive_summary',
    label: 'Executive Summary',
    description: 'Overall SEO Health KPIs, sub-score bars & cross-channel totals',
    icon: ShieldCheck,
    accent: 'text-emerald-400',
    csvExports: [{ dataset: 'full_summary', label: 'Full Report CSV' }],
  },
  {
    key: 'site_audit',
    label: 'Site Audit & Technical Health',
    description: 'All open critical/warning issues, recommended fixes & crawled pages',
    icon: Activity,
    accent: 'text-amber-400',
    csvExports: [
      { dataset: 'audit_issues', label: 'Issues CSV' },
      { dataset: 'crawled_pages', label: 'Crawled Pages CSV' },
    ],
  },
  {
    key: 'action_plan',
    label: 'SEO Action Plan',
    description: 'Prioritized AI recommendations with ICE scores & execution status',
    icon: Sparkles,
    accent: 'text-purple-400',
    csvExports: [{ dataset: 'action_plan', label: 'Action Plan CSV' }],
  },
  {
    key: 'search_console',
    label: 'Search Console Analytics',
    description: 'Verified GSC queries, landing pages, clicks, impressions, CTR & opportunities',
    icon: BarChart3,
    accent: 'text-sky-400',
    csvExports: [
      { dataset: 'gsc_queries', label: 'Queries CSV' },
      { dataset: 'gsc_pages', label: 'Pages CSV' },
      { dataset: 'search_opportunities', label: 'Opportunities CSV' },
    ],
  },
  {
    key: 'rank_checker',
    label: 'Live Geo Rank Checker',
    description: 'Geo-located Google positions, page numbers, Local Pack & GSC delta',
    icon: Crosshair,
    accent: 'text-teal-400',
    csvExports: [{ dataset: 'rank_checker', label: 'Rankings CSV' }],
  },
  {
    key: 'competitors',
    label: 'Competitor Intelligence',
    description: 'Tracked rivals benchmark, Keyword Gaps & Content Gap topics',
    icon: Target,
    accent: 'text-rose-400',
    csvExports: [
      { dataset: 'competitors', label: 'Rivals CSV' },
      { dataset: 'keyword_gap', label: 'Keyword Gap CSV' },
      { dataset: 'content_gap', label: 'Content Gap CSV' },
    ],
  },
  {
    key: 'live_traffic',
    label: 'Live Traffic & Audience Geo',
    description: 'Visitors, pageviews, countries, regions/cities, top pages & sources',
    icon: Radio,
    accent: 'text-emerald-400',
    csvExports: [{ dataset: 'live_traffic', label: 'Traffic Geo CSV' }],
  },
  {
    key: 'content_studio',
    label: 'Content Studio',
    description: 'SEO articles, content briefs, quality scores & word counts',
    icon: FileText,
    accent: 'text-indigo-400',
    csvExports: [{ dataset: 'content_studio', label: 'Content CSV' }],
  },
];

const BRAND_COLOR_PRESETS = ['#10b981', '#0ea5e9', '#6366f1', '#8b5cf6', '#f59e0b', '#e11d48', '#0f172a'];

export function ReportsCenterView({ website }: ReportsCenterViewProps) {
  const toast = useToast();

  const [range, setRange] = useState<'7d' | '28d' | '30d' | '90d' | 'all'>('28d');
  const [selectedSections, setSelectedSections] = useState<SectionKey[]>(
    SECTION_META.map((s) => s.key)
  );
  const [reportTitle, setReportTitle] = useState<string>('Comprehensive SEO Performance & Technical Report');
  const [preparedFor, setPreparedFor] = useState<string>('');

  const [bundle, setBundle] = useState<ReportBundle | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [downloadingKey, setDownloadingKey] = useState<string | null>(null);

  // White-label branding editor
  const [showBrandingPanel, setShowBrandingPanel] = useState<boolean>(false);
  const [brandName, setBrandName] = useState<string>('');
  const [brandLogoUrl, setBrandLogoUrl] = useState<string>('');
  const [brandColor, setBrandColor] = useState<string>('#10b981');
  const [contactEmail, setContactEmail] = useState<string>('');
  const [footerText, setFooterText] = useState<string>('Confidential SEO Performance, Technical Audit & Growth Report');
  const [isSavingBranding, setIsSavingBranding] = useState<boolean>(false);
  const [isProcessingLogo, setIsProcessingLogo] = useState<boolean>(false);

  const loadBundle = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      const res = await api.get<ReportBundle>(
        `/websites/${website.id}/reports/bundle?range=${range}&sections=all`
      );
      if (res.success && res.data) {
        setBundle(res.data);
        setBrandName(res.data.branding.agency_name || '');
        setBrandLogoUrl(res.data.branding.agency_logo_url || '');
        setBrandColor(res.data.branding.theme_color || '#10b981');
        setContactEmail(res.data.branding.contact_email || '');
        setFooterText(
          res.data.branding.footer_text || 'Confidential SEO Performance, Technical Audit & Growth Report'
        );
        setPreparedFor((prev) => prev || res.data?.client?.company_name || res.data?.website.name || '');
      } else {
        toast.error(res.error?.message || 'Could not load report data.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [website, range, toast]);

  useEffect(() => {
    setPreparedFor('');
    loadBundle();
  }, [loadBundle]);

  const toggleSection = (key: SectionKey) => {
    setSelectedSections((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    );
  };

  const handleLogoFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Please upload an image file (PNG, JPG, WebP, or SVG).');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error('Logo image must be under 5 MB.');
      return;
    }

    setIsProcessingLogo(true);
    const reader = new FileReader();
    reader.onload = () => {
      const rawDataUrl = typeof reader.result === 'string' ? reader.result : '';
      if (!rawDataUrl) {
        setIsProcessingLogo(false);
        toast.error('Could not read logo image.');
        return;
      }

      const img = new Image();
      img.onload = () => {
        try {
          const maxW = 480;
          const maxH = 160;
          const natW = img.naturalWidth || img.width || 240;
          const natH = img.naturalHeight || img.height || 80;
          const scale = Math.min(1, maxW / natW, maxH / natH);
          const w = Math.max(1, Math.round(natW * scale));
          const h = Math.max(1, Math.round(natH * scale));
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d');
          if (!ctx) {
            setIsProcessingLogo(false);
            toast.error('Browser could not process logo image.');
            return;
          }
          ctx.drawImage(img, 0, 0, w, h);
          const optimizedPng = canvas.toDataURL('image/png');
          setBrandLogoUrl(optimizedPng);
          toast.success('Logo loaded! Click "Save Branding" to apply it to all PDF reports.');
        } catch {
          toast.error('Could not optimize logo image.');
        } finally {
          setIsProcessingLogo(false);
        }
      };
      img.onerror = () => {
        setIsProcessingLogo(false);
        toast.error('Invalid image file. Try PNG, JPG, or WebP.');
      };
      img.src = rawDataUrl;
    };
    reader.onerror = () => {
      setIsProcessingLogo(false);
      toast.error('Failed to read selected logo file.');
    };
    reader.readAsDataURL(file);
  };

  const handleSaveBranding = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!brandName.trim()) {
      toast.error('Agency / Brand name is required.');
      return;
    }
    setIsSavingBranding(true);
    try {
      const res = await api.put('/white-label/branding', {
        brand_name: brandName.trim(),
        logo_url: brandLogoUrl.trim() || null,
        primary_color: brandColor,
        contact_email: contactEmail.trim() || null,
        footer_text: footerText.trim() || 'Confidential SEO Performance & Technical Report',
      });
      if (res.success) {
        toast.success('White-label logo & branding saved! It will appear on all PDF reports.');
        setShowBrandingPanel(false);
        loadBundle();
      } else {
        toast.error(res.error?.message || 'Could not save branding settings.');
      }
    } finally {
      setIsSavingBranding(false);
    }
  };

  const handleDownloadFullPdf = async () => {
    if (!bundle) return;
    if (selectedSections.length === 0) {
      toast.error('Select at least one report section to include in your PDF.');
      return;
    }
    setDownloadingKey('full_pdf');
    try {
      const effectiveBundle: ReportBundle = {
        ...bundle,
        branding: {
          ...bundle.branding,
          agency_name: brandName.trim() || bundle.branding.agency_name,
          agency_logo_url: brandLogoUrl.trim() || null,
          theme_color: brandColor || bundle.branding.theme_color,
          contact_email: contactEmail.trim() || bundle.branding.contact_email,
          footer_text: footerText.trim() || bundle.branding.footer_text,
        },
      };
      const filename = await downloadExecutivePdfReport(effectiveBundle, {
        reportTitle,
        preparedFor,
        selectedSections,
        filenamePrefix: 'full-seo-report',
      });
      toast.success(`Executive PDF downloaded: ${filename}`);
    } catch {
      toast.error('Could not generate PDF file.');
    } finally {
      setDownloadingKey(null);
    }
  };

  const handleDownloadSingleSectionPdf = async (sectionKey: SectionKey, label: string) => {
    if (!bundle) return;
    setDownloadingKey(`pdf_${sectionKey}`);
    try {
      const effectiveBundle: ReportBundle = {
        ...bundle,
        branding: {
          ...bundle.branding,
          agency_name: brandName.trim() || bundle.branding.agency_name,
          agency_logo_url: brandLogoUrl.trim() || null,
          theme_color: brandColor || bundle.branding.theme_color,
          contact_email: contactEmail.trim() || bundle.branding.contact_email,
          footer_text: footerText.trim() || bundle.branding.footer_text,
        },
      };
      const sectionsToInclude =
        sectionKey === 'executive_summary' ? ['executive_summary'] : ['executive_summary', sectionKey];
      const filename = await downloadExecutivePdfReport(effectiveBundle, {
        reportTitle: `${label} Report`,
        preparedFor,
        selectedSections: sectionsToInclude,
        filenamePrefix: sectionKey.replace(/_/g, '-'),
      });
      toast.success(`Downloaded PDF: ${filename}`);
    } catch {
      toast.error('Could not generate section PDF.');
    } finally {
      setDownloadingKey(null);
    }
  };

  const handleDownloadCsv = async (dataset: string) => {
    if (!website) return;
    setDownloadingKey(`csv_${dataset}`);
    try {
      const out = await api.downloadFile(
        `/websites/${website.id}/reports/export/csv?dataset=${encodeURIComponent(dataset)}&range=${range}`,
        `${website.domain}-${dataset}-${range}.csv`
      );
      if (out.ok) {
        toast.success(`Downloaded CSV: ${out.filename}`);
      } else {
        toast.error(out.error || 'CSV export failed.');
      }
    } finally {
      setDownloadingKey(null);
    }
  };

  const getSectionStatBadge = (key: SectionKey): string => {
    if (!bundle) return '—';
    const ex = bundle.executive_summary;
    switch (key) {
      case 'executive_summary':
        return ex.overall_health_score !== null ? `Health ${ex.overall_health_score}%` : 'Ready';
      case 'site_audit':
        return `${ex.open_issues_total} issues • ${ex.pages_crawled} pages`;
      case 'action_plan':
        return `${ex.action_plan_total} tasks (${ex.action_plan_applied} done)`;
      case 'search_console':
        return `${ex.gsc_clicks.toLocaleString()} clicks • ${ex.gsc_queries_count} queries`;
      case 'rank_checker':
        return `${ex.rank_checks_count} checks (${ex.rank_top10_count} Top 10)`;
      case 'competitors':
        return `${ex.competitors_count} rivals • ${ex.keyword_gaps_count} kw gaps`;
      case 'live_traffic':
        return `${ex.traffic_visitors.toLocaleString()} visitors • ${ex.traffic_pageviews.toLocaleString()} views`;
      case 'content_studio':
        return `${ex.content_articles_count} articles • ${ex.content_briefs_count} briefs`;
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <FileDown className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-400 mt-1">
          Select or connect a website to generate executive PDF and CSV SEO reports.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Executive Header & Builder Card */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-5 sm:p-6 shadow-sm space-y-5">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <div className="flex flex-wrap items-center gap-2 mb-1.5">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                <FileDown className="w-3 h-3" />
                Executive Reporting &amp; Export Center
              </span>
              <span className="text-xs font-mono text-zinc-400 bg-zinc-900 border border-zinc-800 px-2 py-0.5 rounded-md">
                {website.domain}
              </span>
              {bundle?.report_id && (
                <span className="text-[11px] font-mono text-zinc-500">
                  ID: {bundle.report_id}
                </span>
              )}
            </div>
            <h1 className="text-lg sm:text-xl font-bold text-zinc-100">
              Download Complete SEO Reports in PDF &amp; CSV
            </h1>
            <p className="text-xs text-zinc-400 mt-1 max-w-3xl">
              Generate agency-ready white-label PDF reports with KPI cards, health score bars, and structured tables — or export clean UTF-8 CSV spreadsheets for Site Audit, Search Console, Live Rank Checker, Competitors, Traffic, Action Plan, and Content Studio.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* Date Range */}
            <div className="inline-flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs">
              <Calendar className="w-3.5 h-3.5 text-zinc-400" />
              <select
                value={range}
                onChange={(e) => setRange(e.target.value as '7d' | '28d' | '30d' | '90d' | 'all')}
                className="bg-transparent text-zinc-200 font-semibold focus:outline-none"
              >
                <option value="7d" className="bg-zinc-900">Last 7 Days</option>
                <option value="28d" className="bg-zinc-900">Last 28 Days</option>
                <option value="30d" className="bg-zinc-900">Last 30 Days</option>
                <option value="90d" className="bg-zinc-900">Last 90 Days</option>
                <option value="all" className="bg-zinc-900">All Time</option>
              </select>
            </div>

            <button
              type="button"
              onClick={() => setShowBrandingPanel((v) => !v)}
              className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border transition ${
                showBrandingPanel
                  ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40'
                  : 'bg-zinc-900 hover:bg-zinc-800 text-zinc-300 border-zinc-800'
              }`}
            >
              <Palette className="w-3.5 h-3.5" />
              White-Label Branding
            </button>

            <button
              type="button"
              onClick={loadBundle}
              disabled={isLoading}
              className="p-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-400 hover:text-zinc-200 transition"
              title="Refresh live report data"
            >
              <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-emerald-400' : ''}`} />
            </button>
          </div>
        </div>

        {/* Collapsible White-Label Branding Editor */}
        {showBrandingPanel && (
          <form
            onSubmit={handleSaveBranding}
            className="p-4 rounded-xl bg-zinc-900/70 border border-zinc-800 space-y-4"
          >
            <div className="flex items-center justify-between">
              <div className="text-xs font-bold text-zinc-200 flex items-center gap-1.5">
                <Building2 className="w-3.5 h-3.5 text-emerald-400" />
                Agency White-Label PDF Branding
              </div>
              <span className="text-[11px] text-zinc-500">Applied to cover banner, running headers &amp; footers</span>
            </div>

            {/* Agency Logo Upload Row */}
            <div className="p-3.5 rounded-xl bg-zinc-950/80 border border-zinc-800/90 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex items-center gap-3.5">
                <div className="w-28 h-12 rounded-lg bg-white border border-zinc-700 flex items-center justify-center p-1.5 shrink-0 overflow-hidden">
                  {brandLogoUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={brandLogoUrl}
                      alt="Agency Logo Preview"
                      className="max-w-full max-h-full object-contain"
                    />
                  ) : (
                    <div className="flex flex-col items-center text-zinc-400">
                      <ImageIcon className="w-4 h-4" />
                      <span className="text-[9px] font-medium mt-0.5">No Logo</span>
                    </div>
                  )}
                </div>
                <div className="space-y-0.5">
                  <div className="text-xs font-semibold text-zinc-200">
                    Agency / Company Logo (PDF Cover &amp; Page Headers)
                  </div>
                  <p className="text-[11px] text-zinc-400">
                    Upload PNG, JPG, WebP, or SVG (auto-resized for crisp 300-DPI PDF rendering).
                  </p>
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <label className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/40 text-emerald-300 text-xs font-semibold cursor-pointer transition">
                  {isProcessingLogo ? (
                    <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Upload className="w-3.5 h-3.5" />
                  )}
                  <span>{brandLogoUrl ? 'Change Logo' : 'Upload Logo'}</span>
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/svg+xml"
                    onChange={handleLogoFileChange}
                    className="hidden"
                  />
                </label>

                {brandLogoUrl && (
                  <button
                    type="button"
                    onClick={() => setBrandLogoUrl('')}
                    className="inline-flex items-center gap-1 px-2.5 py-2 rounded-xl bg-red-950/40 hover:bg-red-900/50 border border-red-800/60 text-red-300 text-xs font-medium transition"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                    Remove
                  </button>
                )}
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
              <div>
                <label className="block text-[11px] text-zinc-400 mb-1">Agency / Brand Name *</label>
                <input
                  type="text"
                  value={brandName}
                  onChange={(e) => setBrandName(e.target.value)}
                  placeholder="e.g. Techboloy Agency"
                  className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                />
              </div>
              <div>
                <label className="block text-[11px] text-zinc-400 mb-1">Contact Email (Footer)</label>
                <input
                  type="email"
                  value={contactEmail}
                  onChange={(e) => setContactEmail(e.target.value)}
                  placeholder="seo@agency.com"
                  className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                />
              </div>
              <div>
                <label className="block text-[11px] text-zinc-400 mb-1">Footer Confidentiality Text</label>
                <input
                  type="text"
                  value={footerText}
                  onChange={(e) => setFooterText(e.target.value)}
                  className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                />
              </div>
              <div>
                <label className="block text-[11px] text-zinc-400 mb-1">Brand Accent Color</label>
                <div className="flex items-center gap-1.5 pt-0.5">
                  {BRAND_COLOR_PRESETS.map((c) => (
                    <button
                      key={c}
                      type="button"
                      onClick={() => setBrandColor(c)}
                      style={{ backgroundColor: c }}
                      className={`w-6 h-6 rounded-full border-2 transition ${
                        brandColor === c ? 'border-white scale-110' : 'border-transparent opacity-75 hover:opacity-100'
                      }`}
                    />
                  ))}
                </div>
              </div>
            </div>
            <div className="flex justify-end">
              <button
                type="submit"
                disabled={isSavingBranding || isProcessingLogo}
                className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-semibold transition"
              >
                <Save className="w-3.5 h-3.5" />
                {isSavingBranding ? 'Saving...' : 'Save Branding'}
              </button>
            </div>
          </form>
        )}

        {/* Custom Title, Client Name & Primary Download CTAs */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-3 pt-1">
          <div className="lg:col-span-4">
            <label className="block text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mb-1">
              PDF Report Title
            </label>
            <input
              type="text"
              value={reportTitle}
              onChange={(e) => setReportTitle(e.target.value)}
              className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
            />
          </div>
          <div className="lg:col-span-3">
            <label className="block text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mb-1">
              Prepared For (Client / Company)
            </label>
            <input
              type="text"
              value={preparedFor}
              onChange={(e) => setPreparedFor(e.target.value)}
              placeholder={website.name}
              className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
            />
          </div>
          <div className="lg:col-span-5 flex flex-wrap items-end gap-2.5">
            <button
              type="button"
              disabled={!bundle || isLoading || downloadingKey === 'full_pdf'}
              onClick={handleDownloadFullPdf}
              className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold text-xs shadow-lg shadow-emerald-950/40 transition"
            >
              {downloadingKey === 'full_pdf' ? (
                <RefreshCw className="w-4 h-4 animate-spin" />
              ) : (
                <FileDown className="w-4 h-4" />
              )}
              Download Full PDF ({selectedSections.length} Sections)
            </button>

            <button
              type="button"
              disabled={isLoading || downloadingKey === 'csv_full_summary'}
              onClick={() => handleDownloadCsv('full_summary')}
              className="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-100 font-semibold text-xs transition"
            >
              {downloadingKey === 'csv_full_summary' ? (
                <RefreshCw className="w-4 h-4 animate-spin text-sky-400" />
              ) : (
                <FileSpreadsheet className="w-4 h-4 text-sky-400" />
              )}
              Full CSV
            </button>
          </div>
        </div>

        {/* Selectable Sections Bar */}
        <div className="pt-3 border-t border-zinc-800/80 space-y-2">
          <div className="flex items-center justify-between text-xs">
            <span className="font-semibold text-zinc-300 flex items-center gap-1.5">
              <Sliders className="w-3.5 h-3.5 text-emerald-400" />
              Customize Sections Included in Your PDF Report
            </span>
            <div className="flex items-center gap-2 text-[11px]">
              <button
                type="button"
                onClick={() => setSelectedSections(SECTION_META.map((s) => s.key))}
                className="text-emerald-400 hover:underline font-medium"
              >
                Select All
              </button>
              <span className="text-zinc-600">•</span>
              <button
                type="button"
                onClick={() => setSelectedSections(['executive_summary'])}
                className="text-zinc-400 hover:text-zinc-200"
              >
                Summary Only
              </button>
            </div>
          </div>

          <div className="flex flex-wrap gap-1.5">
            {SECTION_META.map((s) => {
              const checked = selectedSections.includes(s.key);
              return (
                <button
                  key={s.key}
                  type="button"
                  onClick={() => toggleSection(s.key)}
                  className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs border transition ${
                    checked
                      ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/40 font-semibold'
                      : 'bg-zinc-900/60 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                  }`}
                >
                  {checked ? (
                    <CheckSquare className="w-3.5 h-3.5 text-emerald-400" />
                  ) : (
                    <Square className="w-3.5 h-3.5 text-zinc-500" />
                  )}
                  <span>{s.label}</span>
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* Module-by-Module Individual PDF & CSV Export Grid */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-bold text-zinc-200">
            Module-by-Module 1-Click PDF &amp; CSV Exports
          </h2>
          <span className="text-[11px] text-zinc-500">
            UTF-8 BOM Excel-ready CSV &amp; Vector Executive PDF
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          {SECTION_META.map((mod) => {
            const Icon = mod.icon;
            return (
              <div
                key={mod.key}
                className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-4 flex flex-col justify-between gap-3.5 hover:border-zinc-700 transition"
              >
                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2 min-w-0">
                      <div className="w-8 h-8 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center shrink-0">
                        <Icon className={`w-4 h-4 ${mod.accent}`} />
                      </div>
                      <h3 className="text-xs font-bold text-zinc-100 truncate">{mod.label}</h3>
                    </div>
                  </div>

                  <p className="text-[11px] text-zinc-400 leading-relaxed">{mod.description}</p>

                  <div className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-zinc-900 border border-zinc-800 text-[11px] font-mono text-zinc-300">
                    <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                    {getSectionStatBadge(mod.key)}
                  </div>
                </div>

                <div className="pt-2 border-t border-zinc-800/80 flex flex-wrap items-center gap-1.5">
                  <button
                    type="button"
                    disabled={!bundle || downloadingKey === `pdf_${mod.key}`}
                    onClick={() => handleDownloadSingleSectionPdf(mod.key, mod.label)}
                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-500/30 text-emerald-300 text-[11px] font-semibold transition disabled:opacity-50"
                  >
                    {downloadingKey === `pdf_${mod.key}` ? (
                      <RefreshCw className="w-3 h-3 animate-spin" />
                    ) : (
                      <FileDown className="w-3 h-3" />
                    )}
                    PDF
                  </button>

                  {mod.csvExports.map((csv) => (
                    <button
                      key={csv.dataset}
                      type="button"
                      disabled={downloadingKey === `csv_${csv.dataset}`}
                      onClick={() => handleDownloadCsv(csv.dataset)}
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-200 text-[11px] font-medium transition disabled:opacity-50"
                    >
                      {downloadingKey === `csv_${csv.dataset}` ? (
                        <RefreshCw className="w-3 h-3 animate-spin text-sky-400" />
                      ) : (
                        <FileSpreadsheet className="w-3 h-3 text-sky-400" />
                      )}
                      {csv.label}
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Live On-Screen Report Preview */}
      {isLoading && !bundle ? (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl p-12 text-center space-y-3">
          <div className="w-8 h-8 rounded-full border-2 border-emerald-500 border-t-transparent animate-spin mx-auto" />
          <div className="text-xs text-zinc-400">Aggregating verified data across all 8 SEO modules...</div>
        </div>
      ) : bundle ? (
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
          <div className="px-5 py-4 border-b border-zinc-800 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              {brandLogoUrl ? (
                <div className="h-8 px-2 rounded-lg bg-white border border-zinc-700 flex items-center justify-center shrink-0">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={brandLogoUrl}
                    alt={brandName || 'Agency Logo'}
                    className="max-h-6 max-w-[110px] object-contain"
                  />
                </div>
              ) : (
                <Eye className="w-4 h-4 text-emerald-400" />
              )}
              <h2 className="text-sm font-bold text-zinc-100">
                Live Executive Report Preview — {bundle.website.domain}
              </h2>
            </div>
            <span className="text-[11px] text-zinc-400 font-mono">
              {bundle.range_label} • Generated {new Date(bundle.generated_at).toLocaleString()}
            </span>
          </div>

          <div className="p-5 space-y-6">
            {/* Preview KPI Strip */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div className="bg-zinc-900/70 border border-zinc-800 rounded-xl p-3.5">
                <div className="text-[10px] uppercase font-bold text-zinc-400">SEO Health Score</div>
                <div className="text-2xl font-black font-mono text-emerald-400 mt-1">
                  {bundle.executive_summary.overall_health_score !== null
                    ? `${bundle.executive_summary.overall_health_score}%`
                    : 'N/A'}
                </div>
                <div className="text-[11px] text-zinc-500 mt-0.5">
                  {bundle.executive_summary.pages_crawled} pages • {bundle.executive_summary.open_issues_total} open issues
                </div>
              </div>

              <div className="bg-zinc-900/70 border border-zinc-800 rounded-xl p-3.5">
                <div className="text-[10px] uppercase font-bold text-zinc-400">GSC Organic Search</div>
                <div className="text-2xl font-black font-mono text-sky-400 mt-1">
                  {bundle.executive_summary.gsc_clicks.toLocaleString()}
                </div>
                <div className="text-[11px] text-zinc-500 mt-0.5">
                  {bundle.executive_summary.gsc_impressions.toLocaleString()} impr • Avg #{bundle.executive_summary.gsc_avg_position}
                </div>
              </div>

              <div className="bg-zinc-900/70 border border-zinc-800 rounded-xl p-3.5">
                <div className="text-[10px] uppercase font-bold text-zinc-400">
                  Visitors ({bundle.executive_summary.traffic_source})
                </div>
                <div className="text-2xl font-black font-mono text-emerald-300 mt-1">
                  {bundle.executive_summary.traffic_visitors.toLocaleString()}
                </div>
                <div className="text-[11px] text-zinc-500 mt-0.5">
                  {bundle.executive_summary.traffic_pageviews.toLocaleString()} pageviews
                  {bundle.executive_summary.traffic_top_country
                    ? ` • Top: ${bundle.executive_summary.traffic_top_country}`
                    : ''}
                </div>
              </div>

              <div className="bg-zinc-900/70 border border-zinc-800 rounded-xl p-3.5">
                <div className="text-[10px] uppercase font-bold text-zinc-400">Rank &amp; Competitor Intel</div>
                <div className="text-2xl font-black font-mono text-purple-400 mt-1">
                  {bundle.executive_summary.rank_checks_count} / {bundle.executive_summary.competitors_count}
                </div>
                <div className="text-[11px] text-zinc-500 mt-0.5">
                  {bundle.executive_summary.rank_top10_count} Top-10 ranks • {bundle.executive_summary.keyword_gaps_count} kw gaps
                </div>
              </div>
            </div>

            {/* Quick Data Highlights Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
              {/* Top GSC Queries Preview */}
              <div className="bg-zinc-900/40 border border-zinc-800 rounded-xl overflow-hidden">
                <div className="px-3.5 py-2.5 bg-zinc-900/80 border-b border-zinc-800 flex items-center justify-between">
                  <span className="font-bold text-zinc-200">Top Search Console Queries</span>
                  <span className="text-[10px] text-zinc-500">
                    {bundle.search_console?.queries.length || 0} total
                  </span>
                </div>
                {bundle.search_console && bundle.search_console.queries.length > 0 ? (
                  <div className="divide-y divide-zinc-800/60">
                    {bundle.search_console.queries.slice(0, 6).map((q) => (
                      <div key={q.query} className="px-3.5 py-2 flex items-center justify-between gap-2">
                        <span className="text-zinc-200 truncate">{q.query}</span>
                        <div className="flex items-center gap-3 shrink-0 font-mono text-[11px]">
                          <span className="text-emerald-400 font-bold">{q.clicks} clicks</span>
                          <span className="text-zinc-400">{q.impressions} impr</span>
                          <span className="text-cyan-400">#{q.avg_position}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="p-4 text-zinc-500 text-center">No GSC queries synced for this range.</div>
                )}
              </div>

              {/* Latest Geo Rank Checks Preview */}
              <div className="bg-zinc-900/40 border border-zinc-800 rounded-xl overflow-hidden">
                <div className="px-3.5 py-2.5 bg-zinc-900/80 border-b border-zinc-800 flex items-center justify-between">
                  <span className="font-bold text-zinc-200">Recent Live Google Rank Checks</span>
                  <span className="text-[10px] text-zinc-500">
                    {bundle.rank_checker?.checks.length || 0} saved
                  </span>
                </div>
                {bundle.rank_checker && bundle.rank_checker.checks.length > 0 ? (
                  <div className="divide-y divide-zinc-800/60">
                    {bundle.rank_checker.checks.slice(0, 6).map((c, idx) => (
                      <div key={idx} className="px-3.5 py-2 flex items-center justify-between gap-2">
                        <div className="min-w-0">
                          <div className="text-zinc-200 truncate">{c.keyword}</div>
                          <div className="text-[10px] text-zinc-500">
                            {c.location} ({c.country}) • {c.device}
                          </div>
                        </div>
                        <span
                          className={`px-2 py-0.5 rounded-md font-mono text-[11px] font-bold shrink-0 ${
                            c.target_position !== null
                              ? c.target_position <= 10
                                ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                : 'bg-cyan-500/15 text-cyan-300 border border-cyan-500/30'
                              : 'bg-zinc-800 text-zinc-400'
                          }`}
                        >
                          {c.target_position !== null
                            ? `#${c.target_position} (P${c.google_page})`
                            : 'Not Found'}
                        </span>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="p-4 text-zinc-500 text-center">No live rank checks saved yet.</div>
                )}
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
