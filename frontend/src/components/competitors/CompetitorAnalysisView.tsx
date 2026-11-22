'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Target,
  LayoutDashboard,
  Users,
  BarChart3,
  KeyRound,
  FileText,
  Link2,
  Search,
  Sparkles,
  History,
  RefreshCw,
} from 'lucide-react';
import { Website, CompetitorDomainItem } from '@/types';
import { api } from '@/lib/api';
import { ALL_LANGUAGES } from '@/lib/countries';
import { useToast } from '@/context/ToastContext';
import { CountryMultiSelect } from './CountryMultiSelect';
import { CompetitorOverviewTab, MyCompetitorsTab } from './CompetitorDiscoveryAndListTabs';
import { DomainComparisonTab, KeywordGapTab, ContentGapTab } from './DomainComparisonAndGapsTabs';
import {
  BacklinkGapTab,
  SerpAnalysisTab,
  AiStrategyTab,
  CompetitorHistoryTab,
} from './BacklinkSerpStrategyHistoryTabs';

interface CompetitorAnalysisViewProps {
  website: Website | null;
  onNavigateToActionPlan?: () => void;
  onNavigateToContent?: () => void;
}

type SubTabId =
  | 'overview'
  | 'my_competitors'
  | 'comparison'
  | 'keyword_gap'
  | 'content_gap'
  | 'backlink_gap'
  | 'serp_analysis'
  | 'ai_strategy'
  | 'history';

const SUB_TABS: Array<{ id: SubTabId; label: string; icon: React.ComponentType<{ className?: string }> }> = [
  { id: 'overview', label: 'Overview', icon: LayoutDashboard },
  { id: 'my_competitors', label: 'My Competitors', icon: Users },
  { id: 'comparison', label: 'Domain Comparison', icon: BarChart3 },
  { id: 'keyword_gap', label: 'Keyword Gap', icon: KeyRound },
  { id: 'content_gap', label: 'Content Gap', icon: FileText },
  { id: 'backlink_gap', label: 'Backlink Gap', icon: Link2 },
  { id: 'serp_analysis', label: 'SERP Analysis', icon: Search },
  { id: 'ai_strategy', label: 'AI Strategy', icon: Sparkles },
  { id: 'history', label: 'Competitor History', icon: History },
];

export function CompetitorAnalysisView({
  website,
  onNavigateToActionPlan,
  onNavigateToContent,
}: CompetitorAnalysisViewProps) {
  const toast = useToast();
  const [activeSubTab, setActiveSubTab] = useState<SubTabId>('overview');
  const [countries, setCountries] = useState<string[]>(['US']);
  const country = countries[0] || 'US';
  const [language, setLanguage] = useState<string>('en');
  const [overview, setOverview] = useState<any>(null);
  const [competitors, setCompetitors] = useState<CompetitorDomainItem[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isAnalyzing, setIsAnalyzing] = useState<boolean>(false);

  useEffect(() => {
    if (website) {
      setCountries([(website.target_country || 'US').toUpperCase()]);
      setLanguage((website.preferred_language || 'en').toLowerCase());
    }
  }, [website]);

  const loadCoreData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      const [ovRes, compRes] = await Promise.all([
        api.get(`/websites/${website.id}/competitors/overview`),
        api.get<CompetitorDomainItem[]>(`/websites/${website.id}/competitors`),
      ]);
      if (ovRes.success && ovRes.data) setOverview(ovRes.data);
      if (compRes.success && compRes.data) setCompetitors(compRes.data);
    } catch {
      toast.error('Failed to load Competitor Analysis workspace.');
    } finally {
      setIsLoading(false);
    }
  }, [website, toast]);

  useEffect(() => {
    loadCoreData();
  }, [loadCoreData]);

  const handleLaunchFullAnalysis = async () => {
    if (!website) return;
    setIsAnalyzing(true);
    try {
      const res = await api.post<{ status: string; id: number }>(
        `/websites/${website.id}/competitors/analyze`,
        {
          job_type: 'full_analysis',
          country,
          language,
          force_refresh: true,
        }
      );
      if (res.success) {
        toast.success('Full Competitor Analysis completed! Snapshots, gaps, and AI strategy updated.');
        await loadCoreData();
      } else {
        toast.error(res.error?.message || 'Could not launch competitor analysis.');
      }
    } catch {
      toast.error('Network error while running competitor analysis.');
    } finally {
      setIsAnalyzing(false);
    }
  };

  if (!website) {
    return (
      <div className="p-12 text-center bg-zinc-950 border border-zinc-800/80 rounded-2xl text-zinc-400">
        <Target className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-200">No Website Selected</p>
        <p className="text-xs mt-1">Select or connect a website to launch Competitor Analysis.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Top Header */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-2 mb-1">
            <span className="p-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
              <Target className="w-4 h-4" />
            </span>
            <h1 className="text-lg font-bold text-zinc-100">Competitor Analysis Intelligence</h1>
            <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded-full bg-zinc-900 border border-zinc-800 text-zinc-400">
              {website.domain}
            </span>
          </div>
          <p className="text-xs text-zinc-400 max-w-2xl">
            Discover true rivals, benchmark up to 5 competitors side-by-side, uncover Keyword, Content &amp; Backlink gaps, inspect SERPs, and send evidence-backed opportunities straight to SEO Action Plan or Content Studio.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
          <CountryMultiSelect selected={countries} onChange={setCountries} maxSelection={5} />

          <select
            value={language}
            onChange={(e) => setLanguage(e.target.value)}
            className="bg-zinc-900 border border-zinc-800 rounded-xl px-2.5 py-2 text-zinc-200"
            aria-label="Target Language"
          >
            {ALL_LANGUAGES.map((l) => (
              <option key={l.code} value={l.code}>
                {l.label}
              </option>
            ))}
          </select>

          <button
            onClick={handleLaunchFullAnalysis}
            disabled={isAnalyzing}
            className="inline-flex items-center gap-1.5 px-4 py-2 font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl shadow transition"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} />
            <span>{isAnalyzing ? 'Analyzing...' : 'Run Full Analysis'}</span>
          </button>
        </div>
      </div>

      {/* 9-Submodule Navigation Bar */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-2 border-b border-zinc-800">
        {SUB_TABS.map((tab) => {
          const Icon = tab.icon;
          const active = activeSubTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveSubTab(tab.id)}
              className={`shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium transition border ${
                active
                  ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                  : 'bg-transparent border-transparent text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900/60'
              }`}
            >
              <Icon className="w-3.5 h-3.5" />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* Active Submodule Content */}
      {activeSubTab === 'overview' && (
        <CompetitorOverviewTab
          website={website}
          overview={overview}
          isLoading={isLoading}
          isAnalyzing={isAnalyzing}
          onLaunchAnalysis={handleLaunchFullAnalysis}
          onSwitchSubTab={(t) => setActiveSubTab(t as SubTabId)}
          onRefresh={loadCoreData}
        />
      )}

      {activeSubTab === 'my_competitors' && (
        <MyCompetitorsTab
          website={website}
          country={country}
          countries={countries}
          onCountriesChange={setCountries}
          language={language}
          competitors={competitors}
          onRefresh={loadCoreData}
        />
      )}

      {activeSubTab === 'comparison' && (
        <DomainComparisonTab
          website={website}
          competitors={competitors}
          onLaunchAnalysis={handleLaunchFullAnalysis}
          isAnalyzing={isAnalyzing}
        />
      )}

      {activeSubTab === 'keyword_gap' && (
        <KeywordGapTab
          website={website}
          competitors={competitors}
          country={country}
          language={language}
          onNavigateToActionPlan={onNavigateToActionPlan}
        />
      )}

      {activeSubTab === 'content_gap' && (
        <ContentGapTab
          website={website}
          onNavigateToContent={onNavigateToContent}
        />
      )}

      {activeSubTab === 'backlink_gap' && <BacklinkGapTab website={website} />}

      {activeSubTab === 'serp_analysis' && (
        <SerpAnalysisTab website={website} country={country} language={language} />
      )}

      {activeSubTab === 'ai_strategy' && (
        <AiStrategyTab website={website} onNavigateToActionPlan={onNavigateToActionPlan} />
      )}

      {activeSubTab === 'history' && <CompetitorHistoryTab website={website} />}
    </div>
  );
}
