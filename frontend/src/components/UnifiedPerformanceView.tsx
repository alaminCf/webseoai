'use client';

import React, { useState } from 'react';
import {
  BarChart3,
  Search,
  Zap,
  Globe,
  TrendingUp,
  ArrowUpRight,
  Filter,
} from 'lucide-react';
import { Website } from '@/types';
import { SearchPerformanceView } from './SearchPerformanceView';
import { SearchOpportunitiesView } from './SearchOpportunitiesView';
import { KeywordResearchView } from './KeywordResearchView';

interface UnifiedPerformanceViewProps {
  website: Website | null;
  onNavigateToContent?: () => void;
}

export function UnifiedPerformanceView({
  website,
  onNavigateToContent,
}: UnifiedPerformanceViewProps) {
  const [subTab, setSubTab] = useState<'gsc' | 'opportunities' | 'keywords'>('gsc');

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <BarChart3 className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
        <p className="text-xs text-zinc-400 mt-1">Please select or connect a website to analyze organic performance.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Sub Tabs Navigation */}
      <div className="flex items-center gap-2 border-b border-zinc-800 pb-3 text-xs">
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
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Search className="w-3.5 h-3.5" />
          <span>Keyword Explorer</span>
        </button>
      </div>

      {/* Render Selected View */}
      {subTab === 'gsc' && (
        <SearchPerformanceView
          website={website}
          onNavigateToOpportunities={() => setSubTab('opportunities')}
          onNavigateToKeywords={() => setSubTab('keywords')}
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
