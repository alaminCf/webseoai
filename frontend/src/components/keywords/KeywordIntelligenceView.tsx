'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  LayoutDashboard,
  Search,
  Table2,
  Layers,
  Compass,
  Sparkles,
  HelpCircle,
  GitBranch,
  Clock,
  Bookmark,
  Zap,
  AlertTriangle,
  Brain,
  Info,
  Globe,
} from 'lucide-react';
import { api } from '@/lib/api';
import { Website } from '@/types';
import { KiOverviewData } from './types';
import {
  KeywordOverviewTab,
  KeywordDiscoveryTab,
  KeywordExplorerTab,
  KeywordHistoryTab,
} from './KeywordDiscoveryAndExplorerTabs';
import {
  KeywordClusteringTab,
  SearchIntentTab,
  KeywordOpportunitiesTab,
  QuestionKeywordsTab,
  LongTailKeywordsTab,
  CannibalizationTab,
} from './KeywordIntelligenceTabs';
import {
  KeywordAutomationTab,
  AiKeywordStrategistTab,
} from './KeywordAutomationAndStrategistTabs';

interface KeywordIntelligenceViewProps {
  website: Website | null;
  onNavigateToActionPlan?: () => void;
  onNavigateToContent?: () => void;
  onCheckLiveRank?: (keyword: string) => void;
}

const SECTIONS = [
  { id: 'overview', label: 'Overview', icon: LayoutDashboard },
  { id: 'discovery', label: 'Keyword Discovery', icon: Search },
  { id: 'explorer', label: 'Keyword Explorer', icon: Table2 },
  { id: 'clustering', label: 'Keyword Clustering', icon: Layers },
  { id: 'intent', label: 'Search Intent', icon: Compass },
  { id: 'opportunities', label: 'Opportunities', icon: Sparkles },
  { id: 'questions', label: 'Question Keywords', icon: HelpCircle },
  { id: 'long_tail', label: 'Long-tail Keywords', icon: GitBranch },
  { id: 'history', label: 'Keyword History', icon: Clock },
  { id: 'saved', label: 'Saved Keywords', icon: Bookmark },
  { id: 'automation', label: 'Automation', icon: Zap },
  { id: 'cannibalization', label: 'Cannibalization', icon: AlertTriangle },
  { id: 'strategist', label: 'AI Strategist', icon: Brain },
];

export function KeywordIntelligenceView({
  website,
  onNavigateToActionPlan,
  onNavigateToContent,
  onCheckLiveRank,
}: KeywordIntelligenceViewProps) {
  const [section, setSection] = useState<string>('overview');
  const [overview, setOverview] = useState<KiOverviewData | null>(null);
  const [loadingOverview, setLoadingOverview] = useState<boolean>(false);
  const [pendingSeed, setPendingSeed] = useState<string | null>(null);

  const loadOverview = useCallback(async () => {
    if (!website) return;
    setLoadingOverview(true);
    try {
      const res = await api.get<KiOverviewData>(`/websites/${website.id}/keyword-intelligence/overview`);
      if (res.success && res.data) {
        setOverview(res.data);
      }
    } finally {
      setLoadingOverview(false);
    }
  }, [website]);

  useEffect(() => {
    loadOverview();
  }, [loadOverview]);

  if (!website) {
    return (
      <div className="bg-zinc-900/70 border border-zinc-800 rounded-2xl p-10 text-center space-y-3">
        <Globe className="w-8 h-8 text-emerald-400 mx-auto" />
        <h2 className="text-lg font-bold text-zinc-100">Connect a Website First</h2>
        <p className="text-xs text-zinc-400 max-w-md mx-auto">
          Select or connect a website in the top bar to launch AI Keyword Research, Clustering, Opportunity Scoring, and Automation.
        </p>
      </div>
    );
  }

  const unreadAlerts = overview?.metrics?.unread_alerts_count ?? 0;

  return (
    <div className="space-y-5">
      {/* Page Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-100 tracking-tight">Keyword Research</h1>
          <p className="text-xs text-zinc-400 mt-0.5">
            AI Keyword Intelligence &amp; Automation System for <span className="text-emerald-400 font-semibold">{website.domain}</span>
          </p>
        </div>

        {/* Connected Provider Status Pills */}
        {overview?.provider_status && (
          <div className="flex flex-wrap items-center gap-1.5">
            {overview.provider_status.providers.map((p) => (
              <span
                key={p.name}
                title={p.capabilities.join(', ')}
                className={`px-2 py-1 rounded-lg text-[10px] font-semibold border ${
                  p.configured
                    ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                    : 'bg-zinc-900 text-zinc-500 border-zinc-800'
                }`}
              >
                {p.configured ? '● ' : '○ '}
                {p.name === 'gsc'
                  ? 'GSC'
                  : p.name === 'google_autocomplete'
                  ? 'Autocomplete'
                  : p.name === 'serper'
                  ? 'Serper SERP'
                  : p.name === 'dataforseo'
                  ? 'DataForSEO'
                  : 'Semrush'}
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Honest Data Availability Banner */}
      {overview?.provider_status && !overview.provider_status.has_volume_provider && (
        <div className="px-4 py-2.5 rounded-xl bg-amber-500/10 border border-amber-500/25 text-xs text-amber-200/90 flex items-start gap-2">
          <Info className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
          <span>{overview.provider_status.data_notice}</span>
        </div>
      )}

      {/* 11 Primary Sections + Cannibalization & AI Strategist Bar */}
      <div className="flex flex-wrap gap-1.5 pb-1 border-b border-zinc-800/80">
        {SECTIONS.map((tab) => {
          const Icon = tab.icon;
          const active = section === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setSection(tab.id)}
              className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition border ${
                active
                  ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'
                  : 'bg-zinc-900/60 text-zinc-400 border-zinc-800/80 hover:text-zinc-200 hover:bg-zinc-900'
              }`}
            >
              <Icon className="w-3.5 h-3.5" />
              <span>{tab.label}</span>
              {tab.id === 'automation' && unreadAlerts > 0 && (
                <span className="px-1.5 py-0.2 rounded-full bg-emerald-500 text-zinc-950 text-[9px] font-bold">
                  {unreadAlerts}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* Active Section Viewport */}
      {section === 'overview' && (
        <KeywordOverviewTab
          website={website}
          overview={overview}
          loading={loadingOverview}
          onStartResearch={() => setSection('discovery')}
          onSwitchSection={setSection}
          onRefresh={loadOverview}
        />
      )}

      {section === 'discovery' && (
        <KeywordDiscoveryTab
          website={website}
          initialSeed={pendingSeed}
          onConsumeInitialSeed={() => setPendingSeed(null)}
          onDiscovered={loadOverview}
          onOpenExplorer={() => setSection('explorer')}
        />
      )}

      {section === 'explorer' && (
        <KeywordExplorerTab
          website={website}
          savedOnly={false}
          onRefreshOverview={loadOverview}
          onNavigateToActionPlan={onNavigateToActionPlan}
          onNavigateToContent={onNavigateToContent}
          onCheckLiveRank={onCheckLiveRank}
        />
      )}

      {section === 'saved' && (
        <KeywordExplorerTab
          website={website}
          savedOnly={true}
          onRefreshOverview={loadOverview}
          onNavigateToActionPlan={onNavigateToActionPlan}
          onNavigateToContent={onNavigateToContent}
          onCheckLiveRank={onCheckLiveRank}
        />
      )}

      {section === 'clustering' && (
        <KeywordClusteringTab
          website={website}
          onNavigateToContent={onNavigateToContent}
          onRefreshOverview={loadOverview}
        />
      )}

      {section === 'intent' && <SearchIntentTab website={website} />}

      {section === 'opportunities' && (
        <KeywordOpportunitiesTab
          website={website}
          onNavigateToActionPlan={onNavigateToActionPlan}
          onRefreshOverview={loadOverview}
        />
      )}

      {section === 'questions' && <QuestionKeywordsTab website={website} />}

      {section === 'long_tail' && (
        <LongTailKeywordsTab website={website} onNavigateToContent={onNavigateToContent} />
      )}

      {section === 'history' && <KeywordHistoryTab website={website} />}

      {section === 'automation' && (
        <KeywordAutomationTab website={website} onRefreshOverview={loadOverview} />
      )}

      {section === 'cannibalization' && <CannibalizationTab website={website} />}

      {section === 'strategist' && (
        <AiKeywordStrategistTab
          website={website}
          onResearchSeed={(seed) => {
            setPendingSeed(seed);
            setSection('discovery');
          }}
        />
      )}
    </div>
  );
}
