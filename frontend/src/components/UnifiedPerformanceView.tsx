'use client';

import React, { useState, useEffect } from 'react';
import {
  BarChart3,
  Search,
  Zap,
  Radio,
} from 'lucide-react';
import { Website } from '@/types';
import { SearchPerformanceView } from './SearchPerformanceView';
import { SearchOpportunitiesView } from './SearchOpportunitiesView';
import { KeywordResearchView } from './KeywordResearchView';
import { LiveTrafficView } from './LiveTrafficView';

interface UnifiedPerformanceViewProps {
  website: Website | null;
  initialSubTab?: 'live' | 'gsc' | 'opportunities' | 'keywords';
  onNavigateToContent?: () => void;
  onCheckLiveRank?: (keyword: string) => void;
}

export function UnifiedPerformanceView({
  website,
  initialSubTab = 'live',
  onNavigateToContent,
  onCheckLiveRank,
}: UnifiedPerformanceViewProps) {
  const [subTab, setSubTab] = useState<'live' | 'gsc' | 'opportunities' | 'keywords'>(initialSubTab);

  useEffect(() => {
    setSubTab(initialSubTab);
  }, [initialSubTab]);

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <BarChart3 className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-400 mt-1">Please select or connect a website to analyze live &amp; organic performance.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Sub Tabs Navigation */}
      <div className="flex flex-wrap items-center gap-2 border-b border-zinc-800 pb-3 text-xs">
        <button
          onClick={() => setSubTab('live')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'live'
              ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Radio className="w-3.5 h-3.5 text-emerald-400 animate-pulse" />
          <span>Live Traffic (Realtime)</span>
        </button>

        <button
          onClick={() => setSubTab('gsc')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'gsc'
              ? 'bg-sky-500/10 text-sky-400 border border-sky-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <BarChart3 className="w-3.5 h-3.5" />
          <span>Search Console Analytics</span>
        </button>

        <button
          onClick={() => setSubTab('opportunities')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'opportunities'
              ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Zap className="w-3.5 h-3.5" />
          <span>Opportunity Detector</span>
        </button>

        <button
          onClick={() => setSubTab('keywords')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'keywords'
              ? 'bg-purple-500/10 text-purple-400 border border-purple-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Search className="w-3.5 h-3.5" />
          <span>Keyword Explorer</span>
        </button>
      </div>

      {/* Render Selected View */}
      {subTab === 'live' && <LiveTrafficView website={website} />}

      {subTab === 'gsc' && (
        <SearchPerformanceView
          website={website}
          onNavigateToOpportunities={() => setSubTab('opportunities')}
          onNavigateToKeywords={() => setSubTab('keywords')}
          onCheckLiveRank={onCheckLiveRank}
        />
      )}

      {subTab === 'opportunities' && (
        <SearchOpportunitiesView
          website={website}
          onNavigateToPerformance={() => setSubTab('gsc')}
        />
      )}

      {subTab === 'keywords' && (
        <KeywordResearchView
          website={website}
          onNavigateToContentStudio={onNavigateToContent}
        />
      )}
    </div>
  );
}
