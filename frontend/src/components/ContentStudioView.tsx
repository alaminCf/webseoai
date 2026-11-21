'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  FileText,
  Plus,
  Sparkles,
  Layers,
  CheckCircle2,
  AlertCircle,
  Clock,
  ExternalLink,
  ChevronRight,
  BookOpen,
  Edit3,
  HelpCircle,
  BarChart2,
  X,
  History,
  ShieldCheck,
  Send,
  Save,
  DollarSign,
  Cpu,
} from 'lucide-react';
import { Website, ContentBrief, ContentArticle, AiUsageSummary } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';

interface ContentStudioViewProps {
  website: Website | null;
}

export function ContentStudioView({ website }: ContentStudioViewProps) {
  const toast = useToast();
  const [activeTab, setActiveTab] = useState<'briefs' | 'articles' | 'tools' | 'usage'>('briefs');

  // Brief states
  const [briefs, setBriefs] = useState<ContentBrief[]>([]);
  const [isBriefModalOpen, setIsBriefModalOpen] = useState(false);
  const [briefTopic, setBriefTopic] = useState('');
  const [briefIntent, setBriefIntent] = useState<'informational' | 'commercial' | 'transactional' | 'navigational'>('informational');
  const [briefWordCount, setBriefWordCount] = useState(1200);
  const [isGeneratingBrief, setIsGeneratingBrief] = useState(false);
  const [activeBrief, setActiveBrief] = useState<ContentBrief | null>(null);

  // Article states
  const [articles, setArticles] = useState<ContentArticle[]>([]);
  const [activeArticle, setActiveArticle] = useState<ContentArticle | null>(null);
  const [isDraftingArticle, setIsDraftingArticle] = useState(false);
  const [editTitle, setEditTitle] = useState('');
  const [editMeta, setEditMeta] = useState('');
  const [editBody, setEditBody] = useState('');
  const [isSavingArticle, setIsSavingArticle] = useState(false);

  // Tools state (Title & Meta, FAQ, Gaps)
  const [toolTopic, setToolTopic] = useState('');
  const [titleMetaCandidates, setTitleMetaCandidates] = useState<any[]>([]);
  const [faqs, setFaqs] = useState<any[]>([]);
  const [contentGaps, setContentGaps] = useState<any[]>([]);
  const [isGeneratingTool, setIsGeneratingTool] = useState(false);

  // Usage state
  const [usageStats, setUsageStats] = useState<AiUsageSummary | null>(null);

  // Loading
  const [isLoading, setIsLoading] = useState(false);

  const loadData = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      const [briefsRes, articlesRes, usageRes] = await Promise.all([
        api.get<ContentBrief[]>(`/websites/${website.id}/content/briefs`),
        api.get<ContentArticle[]>(`/websites/${website.id}/content/articles`),
        api.get<AiUsageSummary>(`/websites/${website.id}/ai/usage`),
      ]);

      if (briefsRes.success && briefsRes.data) setBriefs(briefsRes.data);
      if (articlesRes.success && articlesRes.data) setArticles(articlesRes.data);
      if (usageRes.success && usageRes.data) setUsageStats(usageRes.data);
    } catch {
      toast.error('Could not load content studio records.');
    } finally {
      setIsLoading(false);
    }
  }, [website, toast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  if (!website) {
    return (
      <div className="p-12 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <FileText className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
        <p className="text-sm font-semibold text-zinc-300">No Website Selected</p>
      </div>
    );
  }

  const handleCreateBrief = async () => {
    if (!briefTopic.trim()) {
      toast.error('Please enter a target topic.');
      return;
    }
    setIsGeneratingBrief(true);
    try {
      const res = await api.post<ContentBrief>(`/websites/${website.id}/content/briefs`, {
        topic: briefTopic,
        search_intent: briefIntent,
        target_word_count: briefWordCount,
      });
      if (res.success && res.data) {
        toast.success('Content Brief synthesized successfully!');
        setBriefTopic('');
        setIsBriefModalOpen(false);
        setActiveBrief(res.data);
        loadData();
      } else {
        toast.error(res.error?.message || 'Could not generate brief.');
      }
    } catch {
      toast.error('Network error creating brief.');
    } finally {
      setIsGeneratingBrief(false);
    }
  };

  const handleDraftFromBrief = async (briefId: number) => {
    setIsDraftingArticle(true);
    try {
      const res = await api.post<ContentArticle>(`/websites/${website.id}/content/articles`, {
        content_brief_id: briefId,
      });
      if (res.success && res.data) {
        toast.success('Article drafted! Saved to Editorial Workbench as draft.');
        setActiveBrief(null);
        openArticleEditor(res.data);
        loadData();
      } else {
        toast.error(res.error?.message || 'Could not draft article.');
      }
    } catch {
      toast.error('Network error drafting article.');
    } finally {
      setIsDraftingArticle(false);
    }
  };

  const openArticleEditor = (article: ContentArticle) => {
    setActiveArticle(article);
    setEditTitle(article.title);
    setEditMeta(article.meta_description || '');
    setEditBody(article.content_body);
  };

  const handleSaveArticle = async () => {
    if (!activeArticle) return;
    setIsSavingArticle(true);
    try {
      const res = await api.put<ContentArticle>(`/content/articles/${activeArticle.id}`, {
        title: editTitle,
        meta_description: editMeta,
        content_body: editBody,
        change_summary: `Manual revision update v${activeArticle.version + 1}`,
      });
      if (res.success && res.data) {
        toast.success('Article revisions saved and version updated.');
        setActiveArticle(res.data);
        loadData();
      } else {
        toast.error(res.error?.message || 'Could not save article.');
      }
    } catch {
      toast.error('Network error saving article.');
    } finally {
      setIsSavingArticle(false);
    }
  };

  const handleUpdateArticleStatus = async (status: string) => {
    if (!activeArticle) return;
    try {
      const res = await api.post<ContentArticle>(`/content/articles/${activeArticle.id}/status`, {
        status,
      });
      if (res.success && res.data) {
        toast.success(`Workflow status updated to: ${status}`);
        setActiveArticle(res.data);
        loadData();
      } else {
        toast.error(res.error?.message || 'Status transition failed.');
      }
    } catch {
      toast.error('Network error updating status.');
    }
  };

  const handleGenerateTitleMeta = async () => {
    if (!toolTopic.trim()) return;
    setIsGeneratingTool(true);
    try {
      const res = await api.post<{ candidates: any[] }>(
        `/websites/${website.id}/content/generate-titles-meta`,
        { topic: toolTopic }
      );
      if (res.success && res.data) {
        setTitleMetaCandidates(res.data.candidates);
        toast.success('Candidate Title and Meta tags generated!');
      }
    } catch {
      toast.error('Failed to generate titles.');
    } finally {
      setIsGeneratingTool(false);
    }
  };

  const handleGenerateFaq = async () => {
    if (!toolTopic.trim()) return;
    setIsGeneratingTool(true);
    try {
      const res = await api.post<{ faq: any[] }>(
        `/websites/${website.id}/content/generate-faq`,
        { topic: toolTopic }
      );
      if (res.success && res.data) {
        setFaqs(res.data.faq);
        toast.success('Structured FAQs generated!');
      }
    } catch {
      toast.error('Failed to generate FAQ.');
    } finally {
      setIsGeneratingTool(false);
    }
  };

  const handleAnalyzeGaps = async () => {
    setIsGeneratingTool(true);
    try {
      const res = await api.post<{ gaps: any[] }>(
        `/websites/${website.id}/content/gaps`
      );
      if (res.success && res.data) {
        setContentGaps(res.data.gaps);
        toast.success('Content gaps identified from site crawl inventory!');
      }
    } catch {
      toast.error('Failed to analyze gaps.');
    } finally {
      setIsGeneratingTool(false);
    }
  };

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Header Banner */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-sm">
        <div>
          <div className="flex items-center gap-2 mb-1.5">
            <span className="p-1 rounded-md bg-emerald-950/60 border border-emerald-800/60 text-emerald-400">
              <FileText className="w-4 h-4" />
            </span>
            <h2 className="text-lg font-bold text-zinc-100">AI Content Studio & Workbench</h2>
            <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
              Gated Human Approval
            </span>
          </div>
          <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
            Synthesize search-intent aligned briefs, draft articles, generate length-bounded title tags, and review draft revisions. Publication is strictly gated behind human sign-off.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => setIsBriefModalOpen(true)}
            className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Create Content Brief</span>
          </button>
        </div>
      </div>

      {/* Studio Navigation Tabs */}
      <div className="flex items-center justify-between border-b border-zinc-800/80 pb-2 text-xs">
        <div className="flex items-center gap-2">
          <button
            onClick={() => setActiveTab('briefs')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'briefs'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <BookOpen className="w-3.5 h-3.5 text-emerald-400" />
            <span>Content Briefs ({briefs.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('articles')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'articles'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <Edit3 className="w-3.5 h-3.5 text-cyan-400" />
            <span>Drafts & Articles ({articles.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('tools')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'tools'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <Sparkles className="w-3.5 h-3.5 text-purple-400" />
            <span>AI Copy Tools (Title, FAQ, Gaps)</span>
          </button>

          <button
            onClick={() => setActiveTab('usage')}
            className={`px-3 py-1.5 rounded-xl font-semibold flex items-center gap-2 transition ${
              activeTab === 'usage'
                ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            <Cpu className="w-3.5 h-3.5 text-amber-400" />
            <span>AI Usage & Cost</span>
          </button>
        </div>
      </div>

      {/* Tab 1: Briefs List */}
      {activeTab === 'briefs' && (
        <div className="space-y-4">
          {briefs.length === 0 ? (
            <div className="p-16 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <BookOpen className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
              <p className="text-sm font-semibold text-zinc-300">No Content Briefs Yet</p>
              <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto mb-4">
                Generate your first data-backed brief with headings outline, internal links, and FAQs.
              </p>
              <button
                onClick={() => setIsBriefModalOpen(true)}
                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow transition"
              >
                <Plus className="w-3.5 h-3.5" />
                Create Content Brief
              </button>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {briefs.map((brief) => (
                <div
                  key={brief.id}
                  onClick={() => setActiveBrief(brief)}
                  className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3 shadow-sm hover:border-zinc-700/80 cursor-pointer transition group"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-[10px] uppercase font-bold font-mono tracking-wider px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-400">
                          {brief.search_intent}
                        </span>
                        <span className="text-[10px] font-mono text-zinc-500">
                          ~{brief.word_count_target} words
                        </span>
                      </div>
                      <h3 className="text-sm font-bold text-zinc-100 group-hover:text-emerald-400 transition">
                        {brief.suggested_title}
                      </h3>
                    </div>
                    <ChevronRight className="w-4 h-4 text-zinc-600 group-hover:text-emerald-400 transition shrink-0 mt-1" />
                  </div>

                  <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed">
                    {brief.suggested_meta_description}
                  </p>

                  <div className="pt-2 border-t border-zinc-800/60 flex items-center justify-between text-[11px] text-zinc-500 font-mono">
                    <span>Target: {brief.target_keyword}</span>
                    <span>{new Date(brief.created_at).toLocaleDateString()}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Tab 2: Articles List */}
      {activeTab === 'articles' && (
        <div className="space-y-4">
          {articles.length === 0 ? (
            <div className="p-16 text-center text-zinc-500 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <Edit3 className="w-8 h-8 text-zinc-600 mx-auto mb-2" />
              <p className="text-sm font-semibold text-zinc-300">No Draft Articles</p>
              <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
                Generate an article from a brief in the Content Briefs tab to start writing.
              </p>
            </div>
          ) : (
            <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="border-b border-zinc-800/80 bg-zinc-900/50 text-zinc-400 font-medium">
                      <th className="py-3 px-4">Title & Intent</th>
                      <th className="py-3 px-4">Status</th>
                      <th className="py-3 px-4 text-center">Version</th>
                      <th className="py-3 px-4 text-center">Word Count</th>
                      <th className="py-3 px-4 text-center">Quality Score</th>
                      <th className="py-3 px-4 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/60">
                    {articles.map((article) => (
                      <tr
                        key={article.id}
                        onClick={() => openArticleEditor(article)}
                        className="hover:bg-zinc-900/40 cursor-pointer transition group"
                      >
                        <td className="py-3.5 px-4 max-w-md">
                          <div className="font-semibold text-zinc-200 group-hover:text-emerald-400 transition">
                            {article.title}
                          </div>
                          <div className="text-[11px] text-zinc-500 font-mono mt-0.5">
                            Intent: {article.search_intent}
                          </div>
                        </td>
                        <td className="py-3.5 px-4 whitespace-nowrap">
                          <span
                            className={`px-2 py-0.5 text-[10px] font-bold uppercase rounded ${
                              article.status === 'approved' || article.status === 'ready_for_publish'
                                ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/60'
                                : article.status === 'in_review'
                                ? 'bg-blue-950/60 text-blue-400 border border-blue-800/60'
                                : 'bg-zinc-900 text-zinc-400 border border-zinc-800'
                            }`}
                          >
                            {article.status.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 text-center font-mono text-zinc-400">
                          v{article.version}
                        </td>
                        <td className="py-3.5 px-4 text-center font-mono text-zinc-300">
                          {article.word_count}
                        </td>
                        <td className="py-3.5 px-4 text-center font-mono">
                          <span className="text-emerald-400 font-bold">{article.quality_score}/100</span>
                        </td>
                        <td className="py-3.5 px-4 text-right whitespace-nowrap">
                          <button className="text-zinc-500 group-hover:text-emerald-400 transition p-1">
                            <ChevronRight className="w-4 h-4" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Tab 3: AI Copy Tools (Title, FAQ, Gaps) */}
      {activeTab === 'tools' && (
        <div className="space-y-6">
          <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4 shadow-sm">
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-purple-400" />
              Target Topic & AI Generators
            </h3>
            <div className="flex gap-3">
              <input
                type="text"
                value={toolTopic}
                onChange={(e) => setToolTopic(e.target.value)}
                placeholder="Enter target topic or keyword (e.g. 'Cloud Migration Automation')..."
                className="flex-1 bg-zinc-900 border border-zinc-800 rounded-xl px-4 py-2 text-xs text-zinc-100 focus:outline-none focus:border-purple-500"
              />
              <button
                onClick={handleGenerateTitleMeta}
                disabled={isGeneratingTool || !toolTopic.trim()}
                className="px-3.5 py-2 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 rounded-xl transition shadow"
              >
                Generate Title & Meta
              </button>
              <button
                onClick={handleGenerateFaq}
                disabled={isGeneratingTool || !toolTopic.trim()}
                className="px-3.5 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 disabled:opacity-50 border border-zinc-800 rounded-xl transition"
              >
                Generate FAQs
              </button>
              <button
                onClick={handleAnalyzeGaps}
                disabled={isGeneratingTool}
                className="px-3.5 py-2 text-xs font-semibold text-cyan-400 hover:text-cyan-300 bg-cyan-950/40 hover:bg-cyan-900/40 disabled:opacity-50 border border-cyan-800/40 rounded-xl transition"
              >
                Analyze Content Gaps
              </button>
            </div>
          </div>

          {/* Title & Meta Candidates Output */}
          {titleMetaCandidates.length > 0 && (
            <div className="space-y-3">
              <h4 className="text-xs font-bold text-zinc-300 uppercase tracking-wider">
                Length-Bounded Title & Meta Description Candidates
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                {titleMetaCandidates.map((c, i) => (
                  <div key={i} className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl space-y-2">
                    <div className="flex items-center justify-between text-[10px] font-mono text-zinc-500">
                      <span className="uppercase font-bold text-purple-400">{c.style}</span>
                      <span>Title: {c.title_length}/60 chars</span>
                    </div>
                    <div className="font-bold text-zinc-100">{c.title}</div>
                    <div className="text-zinc-400 text-[11px] leading-relaxed pt-1 border-t border-zinc-800/60">
                      {c.meta_description}
                    </div>
                    <div className="text-right text-[10px] text-zinc-500 font-mono">
                      Meta: {c.meta_length}/160 chars
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* FAQs Output */}
          {faqs.length > 0 && (
            <div className="space-y-3">
              <h4 className="text-xs font-bold text-zinc-300 uppercase tracking-wider">
                Structured FAQ Drafts
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                {faqs.map((f, i) => (
                  <div key={i} className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl space-y-1.5">
                    <div className="font-bold text-zinc-100 flex items-start gap-1.5">
                      <HelpCircle className="w-3.5 h-3.5 text-emerald-400 shrink-0 mt-0.5" />
                      <span>{f.question}</span>
                    </div>
                    <p className="text-zinc-400 text-xs leading-relaxed pl-5">{f.answer}</p>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Content Gaps Output */}
          {contentGaps.length > 0 && (
            <div className="space-y-3">
              <h4 className="text-xs font-bold text-zinc-300 uppercase tracking-wider">
                Content Gap Opportunities (Uncovered Topics)
              </h4>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                {contentGaps.map((g, i) => (
                  <div key={i} className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl space-y-1.5">
                    <div className="flex items-center justify-between">
                      <span className="font-bold text-zinc-100">{g.topic}</span>
                      <span className="px-2 py-0.5 text-[10px] font-mono uppercase bg-zinc-900 border border-zinc-800 rounded text-cyan-400">
                        {g.priority} priority
                      </span>
                    </div>
                    <p className="text-zinc-400 text-xs leading-relaxed">{g.reasoning}</p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Tab 4: AI Usage & Token Cost Tracker */}
      {activeTab === 'usage' && usageStats && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
            <div className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl">
              <span className="text-[10px] text-zinc-500 uppercase font-semibold">Total Tokens</span>
              <div className="text-2xl font-bold font-mono text-zinc-100 mt-1">
                {usageStats.summary.total_tokens.toLocaleString()}
              </div>
            </div>
            <div className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl">
              <span className="text-[10px] text-zinc-500 uppercase font-semibold">Estimated Cost</span>
              <div className="text-2xl font-bold font-mono text-emerald-400 mt-1">
                ${usageStats.summary.total_cost_usd}
              </div>
            </div>
            <div className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl">
              <span className="text-[10px] text-zinc-500 uppercase font-semibold">Successful Generations</span>
              <div className="text-2xl font-bold font-mono text-zinc-100 mt-1">
                {usageStats.summary.successful_requests}
              </div>
            </div>
            <div className="p-4 bg-zinc-950 border border-zinc-800 rounded-xl">
              <span className="text-[10px] text-zinc-500 uppercase font-semibold">Failed Generations</span>
              <div className="text-2xl font-bold font-mono text-rose-400 mt-1">
                {usageStats.summary.failed_requests}
              </div>
            </div>
          </div>

          <div className="bg-zinc-950 border border-zinc-800 rounded-xl overflow-hidden text-xs">
            <div className="px-4 py-3 bg-zinc-900/50 border-b border-zinc-800 font-semibold text-zinc-300">
              Recent AI Generation Logs (Redacted Secrets)
            </div>
            <div className="divide-y divide-zinc-800/60 max-h-80 overflow-y-auto font-mono text-[11px]">
              {usageStats.recent_logs.map((log) => (
                <div key={log.id} className="p-3 flex items-center justify-between gap-3 text-zinc-400">
                  <div className="flex items-center gap-2">
                    <span className="text-zinc-200 font-bold">{log.feature}</span>
                    <span className="text-zinc-600">•</span>
                    <span>{log.model}</span>
                  </div>
                  <div className="flex items-center gap-4 text-right">
                    <span>{log.tokens} tokens</span>
                    <span>{log.duration_ms}ms</span>
                    <span
                      className={`px-1.5 py-0.5 rounded text-[10px] uppercase font-bold ${
                        log.status === 'success' ? 'text-emerald-400' : 'text-rose-400'
                      }`}
                    >
                      {log.status}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Brief Drawer / Modal */}
      {activeBrief && (
        <div className="fixed inset-0 z-50 flex items-center justify-end bg-black/70 backdrop-blur-xs animate-fadeIn">
          <div className="bg-zinc-950 border-l border-zinc-800 w-full max-w-xl h-full flex flex-col shadow-2xl p-6 overflow-y-auto space-y-5">
            <div className="flex items-start justify-between border-b border-zinc-800/80 pb-4">
              <div>
                <span className="text-[10px] uppercase font-mono font-bold px-2 py-0.5 bg-zinc-900 border border-zinc-800 rounded text-emerald-400">
                  {activeBrief.search_intent} Intent
                </span>
                <h3 className="text-base font-bold text-zinc-100 mt-1">
                  {activeBrief.suggested_title}
                </h3>
              </div>
              <button
                onClick={() => setActiveBrief(null)}
                className="p-1.5 text-zinc-400 hover:text-zinc-200 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="text-xs text-zinc-400 p-3.5 bg-zinc-900/40 border border-zinc-800 rounded-xl leading-relaxed">
              <strong>Meta Description:</strong> {activeBrief.suggested_meta_description}
            </div>

            {/* Outline Sections */}
            <div className="space-y-2 text-xs">
              <h4 className="font-bold text-zinc-300 uppercase tracking-wider">
                Article Outline Structure
              </h4>
              <div className="space-y-2">
                {activeBrief.outline?.map((sec, i) => (
                  <div key={i} className="p-3 bg-zinc-900/50 border border-zinc-800 rounded-lg">
                    <div className="font-bold text-zinc-200">
                      H{sec.level}: {sec.heading}
                    </div>
                    <ul className="list-disc list-inside text-zinc-400 text-[11px] mt-1 space-y-0.5">
                      {sec.key_points.map((pt, j) => (
                        <li key={j}>{pt}</li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            </div>

            {/* Internal Links & FAQs */}
            {activeBrief.internal_link_suggestions && (
              <div className="space-y-2 text-xs">
                <h4 className="font-bold text-zinc-300 uppercase tracking-wider">
                  Internal Linking Opportunities
                </h4>
                <div className="p-3 bg-zinc-900/40 border border-zinc-800 rounded-lg space-y-1 text-[11px]">
                  {activeBrief.internal_link_suggestions.map((link, i) => (
                    <div key={i} className="text-zinc-400">
                      Anchor: <strong className="text-emerald-400">{link.anchor}</strong> → {link.target_hint}
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div className="pt-4 border-t border-zinc-800 flex justify-end gap-2">
              <button
                onClick={() => setActiveBrief(null)}
                className="px-4 py-2 text-xs text-zinc-400 hover:text-zinc-200"
              >
                Close
              </button>
              <button
                onClick={() => handleDraftFromBrief(activeBrief.id)}
                disabled={isDraftingArticle}
                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow transition"
              >
                <Edit3 className="w-3.5 h-3.5" />
                <span>{isDraftingArticle ? 'Drafting...' : 'Draft Article from Brief'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Article Editor Modal */}
      {activeArticle && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-4xl max-h-[92vh] flex flex-col shadow-2xl overflow-hidden">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-800/80 bg-zinc-900/40">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-xl bg-cyan-950/50 border border-cyan-800/40 text-cyan-400">
                  <Edit3 className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                    Editorial Workbench
                    <span className="text-[10px] font-mono px-2 py-0.5 bg-zinc-800 rounded text-zinc-300">
                      v{activeArticle.version}
                    </span>
                    <span
                      className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded ${
                        activeArticle.status === 'approved'
                          ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/60'
                          : activeArticle.status === 'in_review'
                          ? 'bg-blue-950/60 text-blue-400 border border-blue-800/60'
                          : 'bg-zinc-900 text-zinc-400 border border-zinc-800'
                      }`}
                    >
                      {activeArticle.status.replace('_', ' ')}
                    </span>
                  </h3>
                  <p className="text-xs text-zinc-400 font-mono mt-0.5">
                    Quality Score: {activeArticle.quality_score}/100 • Word Count: {activeArticle.word_count}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setActiveArticle(null)}
                className="p-1.5 text-zinc-400 hover:text-zinc-200 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto p-6 space-y-4 text-xs">
              {/* Publishing Gate Notice */}
              <div className="p-3 bg-zinc-900/60 border border-zinc-800 rounded-xl flex items-center justify-between">
                <div className="flex items-center gap-2 text-zinc-400">
                  <ShieldCheck className="w-4 h-4 text-emerald-400" />
                  <span>
                    <strong>Human Approval Gated:</strong> Draft will not be automatically published. Sign-off by SEO Manager is mandatory.
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  {activeArticle.status === 'draft' && (
                    <button
                      onClick={() => handleUpdateArticleStatus('in_review')}
                      className="px-3 py-1 bg-blue-600 hover:bg-blue-500 text-white rounded-lg font-medium transition"
                    >
                      Request Review
                    </button>
                  )}
                  {activeArticle.status === 'in_review' && (
                    <button
                      onClick={() => handleUpdateArticleStatus('approved')}
                      className="px-3 py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-medium transition"
                    >
                      Approve Publication
                    </button>
                  )}
                </div>
              </div>

              {/* Title & Meta Editor */}
              <div className="space-y-3">
                <div>
                  <label className="block text-zinc-400 mb-1 font-medium">Article Title</label>
                  <input
                    type="text"
                    value={editTitle}
                    onChange={(e) => setEditTitle(e.target.value)}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-zinc-400 mb-1 font-medium">Meta Description</label>
                  <textarea
                    rows={2}
                    value={editMeta}
                    onChange={(e) => setEditMeta(e.target.value)}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  />
                </div>
              </div>

              {/* Markdown Content Body */}
              <div>
                <label className="block text-zinc-400 mb-1 font-medium">Content Body (Markdown)</label>
                <textarea
                  rows={12}
                  value={editBody}
                  onChange={(e) => setEditBody(e.target.value)}
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-lg p-3 text-zinc-200 font-mono text-xs focus:outline-none focus:border-emerald-500 leading-relaxed"
                />
              </div>

              {/* Revisions History */}
              {activeArticle.revisions && activeArticle.revisions.length > 0 && (
                <div className="space-y-2 pt-2 border-t border-zinc-800">
                  <h4 className="font-bold text-zinc-300 uppercase tracking-wider flex items-center gap-1.5">
                    <History className="w-3.5 h-3.5 text-zinc-400" />
                    Revision History Timeline
                  </h4>
                  <div className="space-y-1 font-mono text-[11px] text-zinc-500 max-h-24 overflow-y-auto">
                    {activeArticle.revisions.map((rev) => (
                      <div key={rev.id} className="flex items-center justify-between p-2 bg-zinc-900/30 rounded">
                        <span>v{rev.version} — {rev.change_summary}</span>
                        <span>{new Date(rev.created_at).toLocaleTimeString()}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="px-6 py-4 border-t border-zinc-800/80 bg-zinc-900/40 flex justify-end gap-2">
              <button
                onClick={() => setActiveArticle(null)}
                className="px-4 py-2 text-xs text-zinc-400 hover:text-zinc-200"
              >
                Close
              </button>
              <button
                onClick={handleSaveArticle}
                disabled={isSavingArticle}
                className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow transition"
              >
                <Save className="w-3.5 h-3.5" />
                <span>Save Revision (v{activeArticle.version + 1})</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Create Brief Modal */}
      {isBriefModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-lg p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                <Plus className="w-4 h-4 text-emerald-400" />
                Synthesize New Content Brief
              </h3>
              <button onClick={() => setIsBriefModalOpen(false)} className="text-zinc-400 hover:text-zinc-200">
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div>
                <label className="block text-zinc-400 mb-1 font-medium">Target Topic / Keyword</label>
                <input
                  type="text"
                  value={briefTopic}
                  onChange={(e) => setBriefTopic(e.target.value)}
                  placeholder="e.g. Enterprise Cloud Security Frameworks"
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-zinc-400 mb-1 font-medium">Search Intent</label>
                  <select
                    value={briefIntent}
                    onChange={(e) => setBriefIntent(e.target.value as any)}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  >
                    <option value="informational">Informational</option>
                    <option value="commercial">Commercial</option>
                    <option value="transactional">Transactional</option>
                    <option value="navigational">Navigational</option>
                  </select>
                </div>

                <div>
                  <label className="block text-zinc-400 mb-1 font-medium">Word Count Target</label>
                  <input
                    type="number"
                    min="300"
                    max="5000"
                    step="100"
                    value={briefWordCount}
                    onChange={(e) => setBriefWordCount(parseInt(e.target.value, 10) || 1200)}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-emerald-500"
                  />
                </div>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-3 border-t border-zinc-800">
              <button
                onClick={() => setIsBriefModalOpen(false)}
                className="px-4 py-2 text-xs text-zinc-400 hover:text-zinc-200"
              >
                Cancel
              </button>
              <button
                onClick={handleCreateBrief}
                disabled={isGeneratingBrief}
                className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl text-xs font-semibold shadow transition"
              >
                {isGeneratingBrief ? 'Synthesizing...' : 'Generate Brief'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
