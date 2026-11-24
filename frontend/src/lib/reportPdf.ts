import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

export interface ReportBundle {
  report_id: string;
  generated_at: string;
  range: string;
  range_label: string;
  sections_included: string[];
  website: {
    id: number;
    name: string;
    domain: string;
    target_url: string;
    platform: string;
    target_country: string;
    target_country_name: string;
    preferred_language: string;
    verification_status: string;
  };
  client: {
    name: string;
    company_name?: string | null;
    contact_person?: string | null;
  } | null;
  branding: {
    agency_name: string;
    agency_logo_url: string | null;
    theme_color: string;
    contact_email: string | null;
    footer_text: string;
    hide_platform_badges: boolean;
  };
  executive_summary: {
    overall_health_score: number | null;
    technical_score: number | null;
    content_score: number | null;
    link_score: number | null;
    indexing_score: number | null;
    pages_crawled: number;
    open_issues_total: number;
    critical_issues: number;
    warning_issues: number;
    info_issues: number;
    gsc_clicks: number;
    gsc_impressions: number;
    gsc_ctr: number;
    gsc_avg_position: number;
    gsc_queries_count: number;
    opportunities_count: number;
    action_plan_total: number;
    action_plan_applied: number;
    action_plan_pending: number;
    rank_checks_count: number;
    rank_top10_count: number;
    competitors_count: number;
    keyword_gaps_count: number;
    content_gaps_count: number;
    traffic_source: string;
    traffic_visitors: number;
    traffic_pageviews: number;
    traffic_active_now: number;
    traffic_top_country: string | null;
    content_articles_count: number;
    content_briefs_count: number;
  };
  site_audit?: {
    audit_run_id: number | null;
    audited_at: string | null;
    overall_score: number | null;
    technical_score: number | null;
    content_score: number | null;
    link_score: number | null;
    indexing_score: number | null;
    pages_crawled: number;
    counts: { total_open: number; critical: number; warning: number; info: number };
    issues: Array<{
      id: number;
      severity: string;
      category: string;
      title: string;
      status: string;
      affected_url: string;
      explanation: string;
      recommended_fix: string;
    }>;
    crawled_pages: Array<{
      url: string;
      status_code: number;
      load_time_ms: number;
      word_count: number;
      page_title: string;
      title_length: number;
      meta_description_length: number;
      h1_count: number;
      is_indexable: boolean;
      in_sitemap: boolean;
      images_count: number;
      images_missing_alt: number;
    }>;
  };
  action_plan?: {
    counts: { total: number; applied: number; pending: number; dismissed: number };
    items: Array<{
      id: number;
      title: string;
      category: string;
      status: string;
      priority_score: number;
      impact_score: number;
      effort_score: number;
      affected_url: string;
      recommended_action: string;
      expected_benefit: string;
    }>;
  };
  search_console?: {
    summary: { clicks: number; impressions: number; ctr: number; avg_position: number };
    queries: Array<{
      query: string;
      clicks: number;
      impressions: number;
      ctr: number;
      avg_position: number;
      estimated_page: number;
    }>;
    pages: Array<{
      page: string;
      clicks: number;
      impressions: number;
      ctr: number;
      avg_position: number;
    }>;
    opportunities: Array<{
      type: string;
      status: string;
      impact_score: number;
      target_identifier: string;
      headline: string;
      recommended_action: string;
    }>;
  };
  rank_checker?: {
    summary: {
      total_checks: number;
      ranked_count: number;
      in_top_3: number;
      in_top_10: number;
      in_top_50: number;
      avg_position: number | null;
    };
    checks: Array<{
      keyword: string;
      country: string;
      country_name: string;
      location: string;
      device: string;
      target_position: number | null;
      google_page: number | null;
      rank_on_page: number | null;
      ranking_url: string;
      results_returned: number;
      gsc_avg_position: number | null;
      in_local_pack: boolean;
      captured_at: string;
    }>;
  };
  competitors?: {
    competitors: Array<{
      domain: string;
      category: string;
      tier: string;
      country: string;
      onpage_seo_score: number | null;
      word_count: number | null;
      load_time_ms: number | null;
      indexed_pages: number | null;
      organic_traffic_estimate: number | null;
      referring_domains_count: number | null;
    }>;
    keyword_gaps: Array<{
      keyword: string;
      gap_type: string;
      country: string;
      search_intent: string;
      target_position: number | null;
      competitor_positions_text: string;
      opportunity_score: number | null;
    }>;
    content_gaps: Array<{
      topic: string;
      suggested_title: string;
      primary_keyword: string;
      priority: string;
      status: string;
      rationale: string;
    }>;
  };
  live_traffic?: {
    source: string;
    summary: {
      active_visitors_now: number;
      unique_visitors: number;
      pageviews: number;
      top_country: string | null;
    };
    countries: Array<{ country_name: string; country_code: string; visitors: number; pageviews: number; share_pct: number }>;
    regions: Array<{ region: string; city: string; country_name: string; visitors: number; pageviews: number }>;
    top_pages: Array<{ path: string; title: string; visitors: number; pageviews: number }>;
    sources: Array<{ source: string; visitors: number; pageviews: number; share_pct: number }>;
  };
  content_studio?: {
    articles: Array<{
      title: string;
      status: string;
      word_count: number;
      quality_score: number | null;
      search_intent: string;
      updated_at: string;
    }>;
    briefs: Array<{
      title: string;
      target_keyword: string;
      status: string;
      target_word_count: number | null;
      search_intent: string;
    }>;
  };
}

export interface PdfCustomOptions {
  reportTitle?: string;
  preparedFor?: string;
  selectedSections?: string[];
  filenamePrefix?: string;
}

function hexToRgb(hex: string): [number, number, number] {
  const clean = (hex || '#10b981').replace('#', '').trim();
  const full = clean.length === 3 ? clean.split('').map((c) => c + c).join('') : clean;
  if (!/^[0-9a-fA-F]{6}$/.test(full)) return [16, 185, 129];
  return [
    parseInt(full.slice(0, 2), 16),
    parseInt(full.slice(2, 4), 16),
    parseInt(full.slice(4, 6), 16),
  ];
}

function slugifyDomain(domain: string): string {
  return (domain || 'website').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'website';
}

function truncateText(str: string | null | undefined, max = 90): string {
  const s = (str || '').replace(/\s+/g, ' ').trim();
  if (!s) return '-';
  return s.length > max ? `${s.slice(0, max - 1)}…` : s;
}

function formatDateReadable(iso: string | null | undefined): string {
  if (!iso) return '-';
  try {
    return new Date(iso).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return iso;
  }
}

interface LoadedLogo {
  dataUrl: string;
  format: 'PNG' | 'JPEG';
  width: number;
  height: number;
}

/**
 * Load a base64 data URI or external HTTPS logo URL into a crisp normalized PNG + dimensions
 * so jsPDF renders it cleanly without distortion or CORS crashes.
 */
async function loadLogoForPdf(logoUrl: string | null | undefined): Promise<LoadedLogo | null> {
  if (!logoUrl || typeof window === 'undefined') return null;
  const trimmed = logoUrl.trim();
  if (!trimmed) return null;

  return new Promise((resolve) => {
    const img = new Image();
    if (!trimmed.startsWith('data:')) {
      img.crossOrigin = 'anonymous';
    }
    const timeoutId = window.setTimeout(() => resolve(null), 4000);

    img.onload = () => {
      window.clearTimeout(timeoutId);
      const natW = img.naturalWidth || img.width || 0;
      const natH = img.naturalHeight || img.height || 0;
      if (natW <= 0 || natH <= 0) {
        resolve(null);
        return;
      }

      try {
        const maxW = 480;
        const maxH = 160;
        const scale = Math.min(1, maxW / natW, maxH / natH);
        const w = Math.max(1, Math.round(natW * scale));
        const h = Math.max(1, Math.round(natH * scale));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          resolve(null);
          return;
        }
        ctx.drawImage(img, 0, 0, w, h);
        const dataUrl = canvas.toDataURL('image/png');
        resolve({ dataUrl, format: 'PNG', width: w, height: h });
      } catch {
        // Fallback if already a PNG/JPEG data URI
        if (trimmed.startsWith('data:image/png')) {
          resolve({ dataUrl: trimmed, format: 'PNG', width: natW, height: natH });
        } else if (trimmed.startsWith('data:image/jpeg') || trimmed.startsWith('data:image/jpg')) {
          resolve({ dataUrl: trimmed, format: 'JPEG', width: natW, height: natH });
        } else {
          resolve(null);
        }
      }
    };

    img.onerror = () => {
      window.clearTimeout(timeoutId);
      resolve(null);
    };

    img.src = trimmed;
  });
}

function fitLogoBox(logo: LoadedLogo, maxW: number, maxH: number): { w: number; h: number } {
  const ratio = logo.width / Math.max(1, logo.height);
  let w = maxW;
  let h = w / ratio;
  if (h > maxH) {
    h = maxH;
    w = h * ratio;
  }
  return { w, h };
}

/**
 * Generate and download a crisp, executive vector PDF report for Web SEO AI.
 */
export async function downloadExecutivePdfReport(
  bundle: ReportBundle,
  options: PdfCustomOptions = {}
): Promise<string> {
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const margin = 14;
  const contentWidth = pageWidth - margin * 2;

  const brandRgb = hexToRgb(bundle.branding.theme_color || '#10b981');
  const darkSlate: [number, number, number] = [15, 23, 42];
  const mutedText: [number, number, number] = [100, 116, 139];
  const lightBg: [number, number, number] = [248, 250, 252];
  const borderColor: [number, number, number] = [226, 232, 240];

  const loadedLogo = await loadLogoForPdf(bundle.branding.agency_logo_url);

  const sections = options.selectedSections && options.selectedSections.length > 0
    ? options.selectedSections
    : bundle.sections_included;

  const agencyName = bundle.branding.agency_name || 'Web SEO AI';
  const reportTitle = options.reportTitle?.trim() || 'Comprehensive SEO Performance & Technical Report';
  const preparedFor =
    options.preparedFor?.trim() ||
    bundle.client?.company_name ||
    bundle.client?.name ||
    bundle.website.name;

  let y = 0;

  // --- Executive Top Banner ---
  const bannerHeight = loadedLogo ? 50 : 44;
  doc.setFillColor(...darkSlate);
  doc.rect(0, 0, pageWidth, bannerHeight, 'F');
  doc.setFillColor(...brandRgb);
  doc.rect(0, bannerHeight, pageWidth, 2.2, 'F');

  let titleStartY = 22;
  let metaStartY = 30;
  let preparedStartY = 36.5;

  if (loadedLogo) {
    const { w, h } = fitLogoBox(loadedLogo, 34, 12);
    // Clean white rounded container behind logo so both dark and transparent logos look crisp
    const padX = 2.5;
    const padY = 1.6;
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(margin, 6.5, w + padX * 2, h + padY * 2, 2, 2, 'F');
    doc.addImage(loadedLogo.dataUrl, loadedLogo.format, margin + padX, 6.5 + padY, w, h, undefined, 'FAST');

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9.5);
    doc.setTextColor(...brandRgb);
    doc.text(agencyName.toUpperCase(), margin + w + padX * 2 + 4, 6.5 + (h + padY * 2) / 2 + 1.2);

    titleStartY = 28;
    metaStartY = 36;
    preparedStartY = 42.5;
  } else {
    // Agency Pill
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(...brandRgb);
    doc.text(agencyName.toUpperCase(), margin, 12);
  }

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(148, 163, 184);
  doc.text(`Report ID: ${bundle.report_id}  |  Period: ${bundle.range_label}`, pageWidth - margin, 12, {
    align: 'right',
  });

  // Main Title
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  doc.setTextColor(255, 255, 255);
  doc.text(truncateText(reportTitle, 58), margin, titleStartY);

  // Domain & Prepared For
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9.5);
  doc.setTextColor(203, 213, 225);
  doc.text(`Website: ${bundle.website.domain}  (${bundle.website.platform.toUpperCase()})`, margin, metaStartY);
  doc.text(`Prepared for: ${truncateText(preparedFor, 45)}`, margin, preparedStartY);

  doc.setFontSize(8.5);
  doc.setTextColor(148, 163, 184);
  doc.text(`Generated: ${formatDateReadable(bundle.generated_at)}`, pageWidth - margin, preparedStartY, {
    align: 'right',
  });

  y = bannerHeight + 10;

  const ensureSpace = (neededMm: number) => {
    if (y + neededMm > pageHeight - 18) {
      doc.addPage();
      y = 20;
    }
  };

  const drawSectionHeading = (numLabel: string, title: string, subtitle?: string) => {
    ensureSpace(22);
    doc.setFillColor(...brandRgb);
    doc.roundedRect(margin, y - 4, 2.5, subtitle ? 11 : 7, 1, 1, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.setTextColor(...darkSlate);
    doc.text(`${numLabel}. ${title}`, margin + 5.5, y + 1.5);
    if (subtitle) {
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text(subtitle, margin + 5.5, y + 6.5);
      y += 12;
    } else {
      y += 8;
    }
  };

  const getFinalY = (fallback: number): number => {
    const last = (doc as unknown as { lastAutoTable?: { finalY?: number } }).lastAutoTable;
    return (last?.finalY ?? fallback) + 8;
  };

  const tableBaseStyles = {
    fontSize: 8,
    cellPadding: 2.3,
    textColor: [30, 41, 59] as [number, number, number],
    lineColor: borderColor,
    lineWidth: 0.15,
    overflow: 'linebreak' as const,
  };

  const tableHeadStyles = {
    fillColor: darkSlate,
    textColor: [255, 255, 255] as [number, number, number],
    fontStyle: 'bold' as const,
    fontSize: 8,
  };

  let sectionCounter = 1;

  // --- SECTION 1: EXECUTIVE SUMMARY KPI CARDS ---
  if (sections.includes('executive_summary')) {
    const ex = bundle.executive_summary;
    drawSectionHeading(
      String(sectionCounter++),
      'Executive Overview & Key Performance Indicators',
      `Verified metrics for ${bundle.website.domain} (${bundle.range_label})`
    );

    const kpis = [
      {
        label: 'SEO Health Score',
        value: ex.overall_health_score !== null ? `${ex.overall_health_score}/100` : 'N/A',
        sub: `${ex.pages_crawled} pages crawled`,
        accent: (ex.overall_health_score ?? 0) >= 80 ? brandRgb : [245, 158, 11] as [number, number, number],
      },
      {
        label: 'GSC Organic Clicks',
        value: ex.gsc_clicks.toLocaleString(),
        sub: `${ex.gsc_impressions.toLocaleString()} impressions (${ex.gsc_ctr}% CTR)`,
        accent: [14, 165, 233] as [number, number, number],
      },
      {
        label: 'Avg Google Position',
        value: ex.gsc_avg_position > 0 ? `#${ex.gsc_avg_position}` : 'N/A',
        sub: `${ex.gsc_queries_count} tracked queries`,
        accent: [99, 102, 241] as [number, number, number],
      },
      {
        label: 'Website Visitors',
        value: ex.traffic_visitors.toLocaleString(),
        sub: `${ex.traffic_pageviews.toLocaleString()} views (${ex.traffic_source})`,
        accent: brandRgb,
      },
      {
        label: 'Open Audit Issues',
        value: String(ex.open_issues_total),
        sub: `${ex.critical_issues} critical • ${ex.warning_issues} warning`,
        accent: ex.critical_issues > 0 ? [239, 68, 68] as [number, number, number] : brandRgb,
      },
      {
        label: 'SEO Action Plan',
        value: String(ex.action_plan_total),
        sub: `${ex.action_plan_applied} applied • ${ex.action_plan_pending} pending`,
        accent: [168, 85, 247] as [number, number, number],
      },
      {
        label: 'Live Rank Checks',
        value: String(ex.rank_checks_count),
        sub: `${ex.rank_top10_count} ranking in Top 10`,
        accent: [20, 184, 166] as [number, number, number],
      },
      {
        label: 'Competitors & Gaps',
        value: String(ex.competitors_count),
        sub: `${ex.keyword_gaps_count} kw gaps • ${ex.content_gaps_count} content gaps`,
        accent: [245, 158, 11] as [number, number, number],
      },
    ];

    const cardGap = 3.5;
    const cardW = (contentWidth - cardGap * 3) / 4;
    const cardH = 21;

    kpis.forEach((kpi, idx) => {
      const col = idx % 4;
      const row = Math.floor(idx / 4);
      const x = margin + col * (cardW + cardGap);
      const cy = y + row * (cardH + 3.5);

      doc.setFillColor(...lightBg);
      doc.setDrawColor(...borderColor);
      doc.roundedRect(x, cy, cardW, cardH, 2.2, 2.2, 'FD');

      doc.setFillColor(...kpi.accent);
      doc.roundedRect(x, cy, cardW, 1.5, 1, 1, 'F');

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(7);
      doc.setTextColor(...mutedText);
      doc.text(kpi.label.toUpperCase(), x + 3, cy + 6);

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(13.5);
      doc.setTextColor(...darkSlate);
      doc.text(kpi.value, x + 3, cy + 13);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(6.8);
      doc.setTextColor(...mutedText);
      doc.text(truncateText(kpi.sub, 28), x + 3, cy + 18.2);
    });

    y += cardH * 2 + 10;

    // Score Breakdown Progress Bars
    if (ex.overall_health_score !== null) {
      ensureSpace(22);
      const subScores = [
        { label: 'Technical SEO', score: ex.technical_score ?? ex.overall_health_score },
        { label: 'On-Page Content', score: ex.content_score ?? ex.overall_health_score },
        { label: 'Link Architecture', score: ex.link_score ?? ex.overall_health_score },
        { label: 'Indexing & Crawl', score: ex.indexing_score ?? ex.overall_health_score },
      ];

      const barW = (contentWidth - 9) / 4;
      subScores.forEach((s, i) => {
        const bx = margin + i * (barW + 3);
        const val = Math.max(0, Math.min(100, s.score ?? 0));
        const fillRgb: [number, number, number] =
          val >= 80 ? [16, 185, 129] : val >= 60 ? [245, 158, 11] : [239, 68, 68];

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7.5);
        doc.setTextColor(...darkSlate);
        doc.text(s.label, bx, y);
        doc.text(`${val}%`, bx + barW, y, { align: 'right' });

        doc.setFillColor(226, 232, 240);
        doc.roundedRect(bx, y + 2, barW, 2.8, 1.2, 1.2, 'F');
        doc.setFillColor(...fillRgb);
        doc.roundedRect(bx, y + 2, Math.max(2, (barW * val) / 100), 2.8, 1.2, 1.2, 'F');
      });
      y += 12;
    }
  }

  // --- SECTION 2: SITE AUDIT & TECHNICAL ISSUES ---
  if (sections.includes('site_audit') && bundle.site_audit) {
    const audit = bundle.site_audit;
    drawSectionHeading(
      String(sectionCounter++),
      'Technical Site Audit & Crawled Pages',
      `Health Score: ${audit.overall_score ?? 'N/A'}/100 • ${audit.pages_crawled} pages crawled • ${audit.counts.critical} Critical, ${audit.counts.warning} Warning`
    );

    if (audit.issues.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Severity', 'Category', 'Issue', 'Affected URL', 'Recommended Fix']],
        body: audit.issues.slice(0, 40).map((i) => [
          i.severity.toUpperCase(),
          i.category,
          i.title,
          truncateText(i.affected_url.replace(/^https?:\/\//, ''), 38),
          truncateText(i.recommended_fix || i.explanation, 68),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 19, fontStyle: 'bold' },
          1: { cellWidth: 20 },
          2: { cellWidth: 34 },
          3: { cellWidth: 42 },
          4: { cellWidth: 'auto' },
        },
        didParseCell: (data) => {
          if (data.section === 'body' && data.column.index === 0) {
            const val = String(data.cell.raw || '').toLowerCase();
            if (val === 'critical') data.cell.styles.textColor = [220, 38, 38];
            else if (val === 'warning') data.cell.styles.textColor = [217, 119, 6];
            else data.cell.styles.textColor = [2, 132, 199];
          }
        },
      });
      y = getFinalY(y);
    } else {
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text('No open technical audit issues recorded for this website.', margin, y);
      y += 8;
    }

    if (audit.crawled_pages.length > 0) {
      ensureSpace(26);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      doc.setTextColor(...darkSlate);
      doc.text(`Crawled Pages Inventory (Top ${Math.min(25, audit.crawled_pages.length)} of ${audit.crawled_pages.length})`, margin, y);
      y += 3;

      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Page URL', 'HTTP', 'Load', 'Words', 'H1', 'Indexable', 'Missing Alt']],
        body: audit.crawled_pages.slice(0, 25).map((p) => [
          truncateText(p.url.replace(/^https?:\/\//, ''), 56),
          String(p.status_code || '-'),
          `${p.load_time_ms}ms`,
          String(p.word_count),
          String(p.h1_count),
          p.is_indexable ? 'Yes' : 'No',
          String(p.images_missing_alt),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 74 },
          1: { cellWidth: 14, halign: 'center' },
          2: { cellWidth: 17, halign: 'right' },
          3: { cellWidth: 16, halign: 'right' },
          4: { cellWidth: 12, halign: 'center' },
          5: { cellWidth: 18, halign: 'center' },
          6: { cellWidth: 20, halign: 'center' },
        },
      });
      y = getFinalY(y);
    }
  }

  // --- SECTION 3: SEO ACTION PLAN ---
  if (sections.includes('action_plan') && bundle.action_plan) {
    const plan = bundle.action_plan;
    drawSectionHeading(
      String(sectionCounter++),
      'Prioritized SEO Action Plan & AI Recommendations',
      `${plan.counts.total} total tasks • ${plan.counts.applied} applied • ${plan.counts.pending} active`
    );

    if (plan.items.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Priority', 'Status', 'Recommendation', 'Target URL', 'Action Steps']],
        body: plan.items.slice(0, 35).map((r) => [
          `${r.priority_score}`,
          r.status.replace(/_/g, ' '),
          truncateText(r.title, 42),
          truncateText(r.affected_url.replace(/^https?:\/\//, ''), 34),
          truncateText(r.recommended_action, 65),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 16, halign: 'center', fontStyle: 'bold' },
          1: { cellWidth: 21 },
          2: { cellWidth: 42 },
          3: { cellWidth: 36 },
          4: { cellWidth: 'auto' },
        },
      });
      y = getFinalY(y);
    } else {
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text('No SEO Action Plan recommendations generated yet.', margin, y);
      y += 8;
    }
  }

  // --- SECTION 4: GOOGLE SEARCH CONSOLE PERFORMANCE ---
  if (sections.includes('search_console') && bundle.search_console) {
    const gsc = bundle.search_console;
    drawSectionHeading(
      String(sectionCounter++),
      'Google Search Console Organic Performance',
      `${gsc.summary.clicks.toLocaleString()} Clicks • ${gsc.summary.impressions.toLocaleString()} Impressions • ${gsc.summary.ctr}% CTR • Avg Pos #${gsc.summary.avg_position}`
    );

    if (gsc.queries.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['#', 'Top Search Query', 'Clicks', 'Impressions', 'CTR', 'Avg Position', 'Est. Page']],
        body: gsc.queries.slice(0, 35).map((q, idx) => [
          String(idx + 1),
          truncateText(q.query, 64),
          q.clicks.toLocaleString(),
          q.impressions.toLocaleString(),
          `${q.ctr}%`,
          `#${q.avg_position}`,
          `Page ${q.estimated_page}`,
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 10, halign: 'center' },
          1: { cellWidth: 'auto' },
          2: { cellWidth: 18, halign: 'right', fontStyle: 'bold' },
          3: { cellWidth: 24, halign: 'right' },
          4: { cellWidth: 16, halign: 'right' },
          5: { cellWidth: 22, halign: 'right' },
          6: { cellWidth: 18, halign: 'center' },
        },
      });
      y = getFinalY(y);
    }

    if (gsc.pages.length > 0) {
      ensureSpace(26);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      doc.setTextColor(...darkSlate);
      doc.text(`Top Organic Landing Pages (Top ${Math.min(20, gsc.pages.length)})`, margin, y);
      y += 3;

      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Landing Page URL', 'Clicks', 'Impressions', 'CTR', 'Avg Position']],
        body: gsc.pages.slice(0, 20).map((p) => [
          truncateText(p.page.replace(/^https?:\/\//, ''), 76),
          p.clicks.toLocaleString(),
          p.impressions.toLocaleString(),
          `${p.ctr}%`,
          `#${p.avg_position}`,
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 'auto' },
          1: { cellWidth: 18, halign: 'right', fontStyle: 'bold' },
          2: { cellWidth: 24, halign: 'right' },
          3: { cellWidth: 16, halign: 'right' },
          4: { cellWidth: 22, halign: 'right' },
        },
      });
      y = getFinalY(y);
    }

    if (gsc.queries.length === 0 && gsc.pages.length === 0) {
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text('No Google Search Console records synced for this date range.', margin, y);
      y += 8;
    }
  }

  // --- SECTION 5: LIVE GEO-LOCATED GOOGLE RANK CHECKS ---
  if (sections.includes('rank_checker') && bundle.rank_checker) {
    const rk = bundle.rank_checker;
    drawSectionHeading(
      String(sectionCounter++),
      'Geo-Located Live Google Rank Tracking',
      `${rk.summary.total_checks} verified live checks • ${rk.summary.in_top_3} in Top 3 • ${rk.summary.in_top_10} in Top 10 • ${rk.summary.in_top_50} in Top 50`
    );

    if (rk.checks.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Keyword', 'Location', 'Device', 'Live Rank', 'Page', 'GSC Avg', 'Ranking URL']],
        body: rk.checks.slice(0, 40).map((c) => [
          truncateText(c.keyword, 34),
          truncateText(`${c.location} (${c.country})`, 24),
          c.device,
          c.target_position !== null ? `#${c.target_position}` : `>${c.results_returned || 100}`,
          c.google_page !== null ? `P${c.google_page} (#${c.rank_on_page})` : '-',
          c.gsc_avg_position !== null ? `#${c.gsc_avg_position}` : '-',
          truncateText((c.ranking_url || '-').replace(/^https?:\/\//, ''), 38),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
        columnStyles: {
          0: { cellWidth: 38, fontStyle: 'bold' },
          1: { cellWidth: 26 },
          2: { cellWidth: 15 },
          3: { cellWidth: 18, halign: 'center', fontStyle: 'bold' },
          4: { cellWidth: 18, halign: 'center' },
          5: { cellWidth: 16, halign: 'center' },
          6: { cellWidth: 'auto' },
        },
        didParseCell: (data) => {
          if (data.section === 'body' && data.column.index === 3) {
            const raw = String(data.cell.raw || '');
            if (raw.startsWith('#')) {
              const num = parseInt(raw.slice(1), 10);
              data.cell.styles.textColor = num <= 10 ? [5, 150, 105] : [2, 132, 199];
            } else {
              data.cell.styles.textColor = [217, 119, 6];
            }
          }
        },
      });
      y = getFinalY(y);
    } else {
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text('No live geo-located Google rank checks recorded yet.', margin, y);
      y += 8;
    }
  }

  // --- SECTION 6: COMPETITOR INTELLIGENCE & GAPS ---
  if (sections.includes('competitors') && bundle.competitors) {
    const comp = bundle.competitors;
    drawSectionHeading(
      String(sectionCounter++),
      'Competitor Benchmark, Keyword Gaps & Content Gaps',
      `${comp.competitors.length} tracked rivals • ${comp.keyword_gaps.length} keyword gap opportunities • ${comp.content_gaps.length} content gap topics`
    );

    if (comp.competitors.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Competitor Domain', 'Category', 'Country', 'SEO Score', 'Words', 'Speed', 'Indexed Pages']],
        body: comp.competitors.map((c) => [
          c.domain,
          c.category,
          c.country,
          c.onpage_seo_score !== null ? `${c.onpage_seo_score}/100` : '-',
          c.word_count !== null ? c.word_count.toLocaleString() : '-',
          c.load_time_ms !== null ? `${c.load_time_ms}ms` : '-',
          c.indexed_pages !== null ? c.indexed_pages.toLocaleString() : '-',
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    }

    if (comp.keyword_gaps.length > 0) {
      ensureSpace(26);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      doc.setTextColor(...darkSlate);
      doc.text(`Top Keyword Gap Opportunities (${Math.min(25, comp.keyword_gaps.length)} of ${comp.keyword_gaps.length})`, margin, y);
      y += 3;

      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Keyword', 'Gap Type', 'Your Pos', 'Competitor Positions', 'Opportunity']],
        body: comp.keyword_gaps.slice(0, 25).map((k) => [
          truncateText(k.keyword, 44),
          k.gap_type.toUpperCase(),
          k.target_position !== null ? `#${k.target_position}` : 'Not Ranked',
          truncateText(k.competitor_positions_text || '-', 46),
          k.opportunity_score !== null ? `${k.opportunity_score}` : '-',
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    }

    if (comp.content_gaps.length > 0) {
      ensureSpace(26);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      doc.setTextColor(...darkSlate);
      doc.text(`Content Gap Topics (${Math.min(20, comp.content_gaps.length)})`, margin, y);
      y += 3;

      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Topic / Title', 'Primary Keyword', 'Priority', 'Rationale']],
        body: comp.content_gaps.slice(0, 20).map((g) => [
          truncateText(g.suggested_title || g.topic, 44),
          truncateText(g.primary_keyword, 32),
          g.priority.toUpperCase(),
          truncateText(g.rationale, 60),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    }
  }

  // --- SECTION 7: LIVE TRAFFIC & GEOGRAPHIC AUDIENCE ---
  if (sections.includes('live_traffic') && bundle.live_traffic) {
    const tr = bundle.live_traffic;
    drawSectionHeading(
      String(sectionCounter++),
      'Audience Traffic & Geographic Breakdown',
      `Source: ${tr.source} • ${tr.summary.unique_visitors.toLocaleString()} Visitors • ${tr.summary.pageviews.toLocaleString()} Pageviews`
    );

    if (tr.countries.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Country', 'Code', 'Visitors', 'Pageviews', 'Traffic Share']],
        body: tr.countries.slice(0, 20).map((c) => [
          c.country_name,
          c.country_code,
          c.visitors.toLocaleString(),
          c.pageviews.toLocaleString(),
          `${c.share_pct}%`,
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    }

    if (tr.top_pages.length > 0) {
      ensureSpace(26);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      doc.setTextColor(...darkSlate);
      doc.text('Top Visited Pages', margin, y);
      y += 3;

      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Page Path', 'Title', 'Visitors', 'Pageviews']],
        body: tr.top_pages.slice(0, 15).map((p) => [
          truncateText(p.path, 52),
          truncateText(p.title, 44),
          p.visitors.toLocaleString(),
          p.pageviews.toLocaleString(),
        ]),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    }
  }

  // --- SECTION 8: CONTENT STUDIO ---
  if (sections.includes('content_studio') && bundle.content_studio) {
    const cs = bundle.content_studio;
    drawSectionHeading(
      String(sectionCounter++),
      'Content Studio Articles & Briefs',
      `${cs.articles.length} articles • ${cs.briefs.length} content briefs`
    );

    const combinedRows = [
      ...cs.articles.map((a) => [
        'Article',
        truncateText(a.title, 54),
        a.status.replace(/_/g, ' '),
        `${a.word_count.toLocaleString()} words`,
        a.quality_score !== null ? `${a.quality_score}/100` : '-',
      ]),
      ...cs.briefs.map((b) => [
        'Brief',
        truncateText(b.title || b.target_keyword, 54),
        b.status.replace(/_/g, ' '),
        b.target_word_count ? `${b.target_word_count.toLocaleString()} target` : '-',
        '-',
      ]),
    ];

    if (combinedRows.length > 0) {
      autoTable(doc, {
        startY: y,
        margin: { left: margin, right: margin },
        head: [['Type', 'Title', 'Status', 'Words', 'Quality Score']],
        body: combinedRows.slice(0, 30),
        styles: tableBaseStyles,
        headStyles: tableHeadStyles,
        alternateRowStyles: { fillColor: lightBg },
      });
      y = getFinalY(y);
    } else {
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8.5);
      doc.setTextColor(...mutedText);
      doc.text('No Content Studio articles or briefs created yet.', margin, y);
      y += 8;
    }
  }

  // --- Running Headers (pages 2+) & Footers (all pages) ---
  const totalPages = doc.getNumberOfPages();
  for (let p = 1; p <= totalPages; p++) {
    doc.setPage(p);
    if (p > 1) {
      doc.setFillColor(...lightBg);
      doc.rect(0, 0, pageWidth, 12, 'F');
      doc.setDrawColor(...borderColor);
      doc.line(0, 12, pageWidth, 12);

      let headerTextX = margin;
      if (loadedLogo) {
        const mini = fitLogoBox(loadedLogo, 18, 7.5);
        doc.addImage(loadedLogo.dataUrl, loadedLogo.format, margin, (12 - mini.h) / 2, mini.w, mini.h, undefined, 'FAST');
        headerTextX = margin + mini.w + 3;
      }

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(7.5);
      doc.setTextColor(...darkSlate);
      doc.text(`${agencyName}  •  ${bundle.website.domain}`, headerTextX, 7.5);
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(...mutedText);
      doc.text(`${bundle.range_label}  |  ${bundle.report_id}`, pageWidth - margin, 7.5, { align: 'right' });
    }

    doc.setDrawColor(...borderColor);
    doc.line(margin, pageHeight - 12, pageWidth - margin, pageHeight - 12);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.2);
    doc.setTextColor(...mutedText);
    const footerLeft = bundle.branding.contact_email
      ? `${bundle.branding.footer_text}  •  ${bundle.branding.contact_email}`
      : bundle.branding.footer_text;
    doc.text(truncateText(footerLeft, 85), margin, pageHeight - 7);
    doc.text(`Page ${p} of ${totalPages}`, pageWidth - margin, pageHeight - 7, { align: 'right' });
  }

  const dateStamp = new Date().toISOString().slice(0, 10);
  const prefix = options.filenamePrefix || 'seo-report';
  const filename = `${slugifyDomain(bundle.website.domain)}-${prefix}-${bundle.range}-${dateStamp}.pdf`;
  doc.save(filename);
  return filename;
}

/**
 * Download a dedicated PDF report for a single live geo-located Google Rank Check (SERP #1..#N).
 */
export async function downloadLiveSerpPdf(
  serp: {
    keyword: string;
    country: string;
    country_name: string;
    location: string;
    device: string;
    language: string;
    target_domain: string;
    results_returned: number;
    captured_at: string | null;
    target_ranking: {
      found: boolean;
      position: number | null;
      google_page: number | null;
      rank_on_page: number | null;
      primary_match?: { url: string; title: string } | null;
      in_local_pack: boolean;
      local_pack_position: number | null;
    };
    gsc_comparison: {
      has_gsc_data: boolean;
      gsc_avg_position: number | null;
      gsc_clicks: number;
      gsc_impressions: number;
      gsc_ctr: number;
      verdict: string;
    };
    organic_results: Array<{
      position: number;
      google_page: number;
      rank_on_page: number;
      domain: string;
      url: string;
      title: string;
      is_target: boolean;
      is_competitor: boolean;
    }>;
  },
  branding?: {
    brand_name?: string;
    logo_url?: string | null;
    primary_color?: string;
    footer_text?: string;
  } | null
): Promise<string> {
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const margin = 14;
  const brandRgb = hexToRgb(branding?.primary_color || '#10b981');
  const loadedLogo = await loadLogoForPdf(branding?.logo_url);

  const bannerH = loadedLogo ? 44 : 38;
  doc.setFillColor(15, 23, 42);
  doc.rect(0, 0, pageWidth, bannerH, 'F');
  doc.setFillColor(...brandRgb);
  doc.rect(0, bannerH, pageWidth, 2, 'F');

  if (loadedLogo) {
    const box = fitLogoBox(loadedLogo, 30, 10);
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(margin, 5.5, box.w + 4, box.h + 2.8, 1.8, 1.8, 'F');
    doc.addImage(loadedLogo.dataUrl, loadedLogo.format, margin + 2, 5.5 + 1.4, box.w, box.h, undefined, 'FAST');

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(...brandRgb);
    doc.text(
      `${(branding?.brand_name || 'WEB SEO AI').toUpperCase()}  •  LIVE GOOGLE SERP REPORT`,
      margin + box.w + 8,
      5.5 + (box.h + 2.8) / 2 + 1
    );
  } else {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(...brandRgb);
    doc.text(
      `${branding?.brand_name ? `${branding.brand_name.toUpperCase()}  •  ` : ''}LIVE GEO-LOCATED GOOGLE SERP REPORT`,
      margin,
      11
    );
  }

  doc.setFontSize(15);
  doc.setTextColor(255, 255, 255);
  doc.text(`Keyword: "${truncateText(serp.keyword, 48)}"`, margin, loadedLogo ? 25 : 20);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(203, 213, 225);
  doc.text(
    `Target: ${serp.target_domain}  |  Location: ${serp.location} (${serp.country})  |  Device: ${serp.device.toUpperCase()}`,
    margin,
    loadedLogo ? 34 : 29
  );
  doc.setFontSize(8);
  doc.text(`Checked: ${formatDateReadable(serp.captured_at)}`, pageWidth - margin, loadedLogo ? 34 : 29, { align: 'right' });

  let y = bannerH + 10;

  // Summary box
  doc.setFillColor(248, 250, 252);
  doc.setDrawColor(226, 232, 240);
  doc.roundedRect(margin, y, pageWidth - margin * 2, 24, 2, 2, 'FD');

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.setTextColor(15, 23, 42);
  const rankHeadline = serp.target_ranking.found
    ? `Live Google Rank: #${serp.target_ranking.position}  (Google Page ${serp.target_ranking.google_page}, #${serp.target_ranking.rank_on_page} on Page)`
    : `Not Ranked in Top ${serp.results_returned} Results`;
  doc.text(rankHeadline, margin + 4, y + 8);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8.5);
  doc.setTextColor(71, 85, 105);
  const gscLine = serp.gsc_comparison.has_gsc_data
    ? `GSC 28d Avg: #${serp.gsc_comparison.gsc_avg_position}  •  Clicks: ${serp.gsc_comparison.gsc_clicks}  •  Impressions: ${serp.gsc_comparison.gsc_impressions}`
    : 'No 28-day GSC data recorded for this exact keyword.';
  doc.text(gscLine, margin + 4, y + 14.5);
  if (serp.target_ranking.primary_match?.url) {
    doc.text(`Ranking URL: ${truncateText(serp.target_ranking.primary_match.url, 95)}`, margin + 4, y + 20);
  }

  y += 30;

  autoTable(doc, {
    startY: y,
    margin: { left: margin, right: margin },
    head: [['Pos', 'Page', 'Domain', 'Page Title & URL', 'Status']],
    body: serp.organic_results.map((r) => [
      `#${r.position}`,
      `P${r.google_page} (#${r.rank_on_page})`,
      r.domain,
      `${truncateText(r.title, 58)}\n${truncateText(r.url.replace(/^https?:\/\//, ''), 64)}`,
      r.is_target ? 'YOUR SITE' : r.is_competitor ? 'COMPETITOR' : '',
    ]),
    styles: { fontSize: 7.8, cellPadding: 2.2, lineColor: [226, 232, 240], lineWidth: 0.15 },
    headStyles: { fillColor: [15, 23, 42], textColor: [255, 255, 255], fontStyle: 'bold' },
    columnStyles: {
      0: { cellWidth: 13, halign: 'center', fontStyle: 'bold' },
      1: { cellWidth: 18, halign: 'center' },
      2: { cellWidth: 36 },
      3: { cellWidth: 'auto' },
      4: { cellWidth: 22, halign: 'center', fontStyle: 'bold' },
    },
    didParseCell: (data) => {
      if (data.section === 'body') {
        const row = serp.organic_results[data.row.index];
        if (row?.is_target) {
          data.cell.styles.fillColor = [236, 253, 245];
          if (data.column.index === 4 || data.column.index === 0) {
            data.cell.styles.textColor = [5, 150, 105];
          }
        }
      }
    },
  });

  const totalPages = doc.getNumberOfPages();
  for (let p = 1; p <= totalPages; p++) {
    doc.setPage(p);
    doc.setFontSize(7.5);
    doc.setTextColor(100, 116, 139);
    doc.text(
      `Live Google SERP Snapshot — ${serp.target_domain} (${serp.location})`,
      margin,
      pageHeight - 7
    );
    doc.text(`Page ${p} of ${totalPages}`, pageWidth - margin, pageHeight - 7, { align: 'right' });
  }

  const kwSlug = slugifyDomain(serp.keyword).slice(0, 35);
  const filename = `${slugifyDomain(serp.target_domain)}-serp-${kwSlug}-${serp.country.toLowerCase()}.pdf`;
  doc.save(filename);
  return filename;
}

/**
 * Download a single live Google SERP check directly as UTF-8 BOM CSV.
 */
export function downloadLiveSerpCsv(serp: {
  keyword: string;
  country: string;
  location: string;
  device: string;
  target_domain: string;
  organic_results: Array<{
    position: number;
    google_page: number;
    rank_on_page: number;
    domain: string;
    url: string;
    title: string;
    snippet: string;
    is_target: boolean;
    is_competitor: boolean;
  }>;
}): string {
  const esc = (v: unknown) => {
    const s = String(v ?? '').replace(/\r?\n/g, ' ').trim();
    const safe = /^[=+\-@\t]/.test(s) && Number.isNaN(Number(s)) ? `'${s}` : s;
    return `"${safe.replace(/"/g, '""')}"`;
  };

  const headers = ['Position', 'Google Page', 'Rank on Page', 'Domain', 'URL', 'Title', 'Snippet', 'Tag', 'Keyword', 'Location', 'Device'];
  const lines = [headers.map(esc).join(',')];

  for (const r of serp.organic_results) {
    lines.push(
      [
        r.position,
        r.google_page,
        r.rank_on_page,
        r.domain,
        r.url,
        r.title,
        r.snippet,
        r.is_target ? 'YOUR SITE' : r.is_competitor ? 'COMPETITOR' : '',
        serp.keyword,
        serp.location,
        serp.device,
      ]
        .map(esc)
        .join(',')
    );
  }

  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const kwSlug = slugifyDomain(serp.keyword).slice(0, 35);
  const filename = `${slugifyDomain(serp.target_domain)}-serp-${kwSlug}-${serp.country.toLowerCase()}.csv`;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
  return filename;
}
