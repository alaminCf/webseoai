'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Menu,
  Sparkles,
  Globe,
  Activity,
  FileText,
  CheckSquare,
  BarChart3,
  Plus,
  RefreshCw,
  Building2,
  ChevronDown,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';
import { api } from '@/lib/api';
import { Website } from '@/types';
import { AuthView } from '@/components/AuthView';
import { Sidebar } from '@/components/Sidebar';
import { WebsiteListView } from '@/components/WebsiteListView';
import { TeamView } from '@/components/TeamView';
import { ActivityView } from '@/components/ActivityView';
import { AddWebsiteModal } from '@/components/AddWebsiteModal';
import { VerifyWebsiteModal } from '@/components/VerifyWebsiteModal';
import { IntegrationsModal } from '@/components/IntegrationsModal';
import { CreateOrgModal } from '@/components/CreateOrgModal';

export default function DashboardPage() {
  const { user, currentOrganization, isLoading: authLoading } = useAuth();
  const toast = useToast();

  const [activeTab, setActiveTab] = useState<string>('websites');
  const [sidebarOpen, setSidebarOpen] = useState<boolean>(false);
  const [websites, setWebsites] = useState<Website[]>([]);
  const [selectedWebsite, setSelectedWebsite] = useState<Website | null>(null);
  const [isLoadingWebsites, setIsLoadingWebsites] = useState<boolean>(false);
  const [showArchived, setShowArchived] = useState<boolean>(false);

  // Modals state
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isVerifyModalOpen, setIsVerifyModalOpen] = useState(false);
  const [isIntegrationsModalOpen, setIsIntegrationsModalOpen] = useState(false);
  const [isCreateOrgModalOpen, setIsCreateOrgModalOpen] = useState(false);
  const [activeModalWebsite, setActiveModalWebsite] = useState<Website | null>(null);

  const loadWebsites = useCallback(async () => {
    if (!currentOrganization) return;
    setIsLoadingWebsites(true);
    try {
      const res = await api.get<Website[]>(`/websites?archived=${showArchived ? 1 : 0}`);
      if (res.success && res.data) {
        setWebsites(res.data);
        if (!selectedWebsite && res.data.length > 0) {
          setSelectedWebsite(res.data[0]);
        }
      } else {
        toast.error(res.error?.message || 'Could not load websites.');
      }
    } finally {
      setIsLoadingWebsites(false);
    }
  }, [currentOrganization, showArchived, selectedWebsite, toast]);

  useEffect(() => {
    if (user && currentOrganization) {
      loadWebsites();
    }
  }, [user, currentOrganization, showArchived, loadWebsites]);

  if (authLoading) {
    return (
      <div className="min-h-screen bg-black flex flex-col items-center justify-center text-zinc-400 gap-3">
        <div className="w-8 h-8 rounded-full border-2 border-emerald-500 border-t-transparent animate-spin" />
        <div className="text-xs font-mono tracking-wider uppercase text-zinc-500">
          Loading Web SEO AI Workspace...
        </div>
      </div>
    );
  }

  if (!user) {
    return <AuthView />;
  }

  const handleArchive = async (websiteId: number) => {
    try {
      const res = await api.post(`/websites/${websiteId}/archive`);
      if (res.success) {
        toast.info('Website archived.');
        loadWebsites();
      } else {
        toast.error(res.error?.message || 'Failed to archive website.');
      }
    } catch {
      toast.error('Network error archiving website.');
    }
  };

  const handleRestore = async (websiteId: number) => {
    try {
      const res = await api.post(`/websites/${websiteId}/restore`);
      if (res.success) {
        toast.success('Website restored.');
        loadWebsites();
      } else {
        toast.error(res.error?.message || 'Failed to restore website.');
      }
    } catch {
      toast.error('Network error restoring website.');
    }
  };

  const handleDelete = async (websiteId: number) => {
    if (!confirm('Permanently delete this website and all associated audit data? This cannot be undone.')) {
      return;
    }
    try {
      const res = await api.delete(`/websites/${websiteId}`);
      if (res.success) {
        toast.info('Website deleted.');
        loadWebsites();
      } else {
        toast.error(res.error?.message || 'Failed to delete website.');
      }
    } catch {
      toast.error('Network error deleting website.');
    }
  };

  return (
    <div className="min-h-screen bg-black flex">
      {/* Sidebar */}
      <Sidebar
        activeTab={activeTab}
        setActiveTab={setActiveTab}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        onOpenCreateOrg={() => setIsCreateOrgModalOpen(true)}
      />

      {/* Main Content Area */}
      <div className="flex-1 lg:pl-72 flex flex-col min-h-screen">
        {/* Top Navbar */}
        <header className="h-16 border-b border-zinc-800/80 bg-zinc-950/70 backdrop-blur sticky top-0 z-30 px-4 sm:px-8 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <button
              onClick={() => setSidebarOpen(true)}
              className="p-2 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-lg lg:hidden transition"
            >
              <Menu className="w-5 h-5" />
            </button>

            {/* Breadcrumb Workspace */}
            <div className="flex items-center gap-2 text-xs">
              <span className="font-semibold text-zinc-400">Workspace</span>
              <span className="text-zinc-600">/</span>
              <span className="font-bold text-zinc-100 flex items-center gap-1.5">
                <Building2 className="w-3.5 h-3.5 text-emerald-400" />
                {currentOrganization?.name || 'Default Org'}
              </span>
            </div>
          </div>

          {/* Quick Actions & Website Selector */}
          <div className="flex items-center gap-3">
            {websites.length > 0 && (
              <div className="hidden sm:flex items-center gap-2 bg-zinc-900/80 border border-zinc-800 rounded-xl px-2.5 py-1.5 text-xs">
                <Globe className="w-3.5 h-3.5 text-zinc-500" />
                <select
                  value={selectedWebsite?.id || ''}
                  onChange={(e) => {
                    const match = websites.find((w) => w.id === parseInt(e.target.value, 10));
                    if (match) setSelectedWebsite(match);
                  }}
                  className="bg-transparent text-zinc-200 font-medium text-xs focus:outline-none"
                >
                  {websites.map((w) => (
                    <option key={w.id} value={w.id} className="bg-zinc-900 text-zinc-100">
                      {w.name} ({w.domain})
                    </option>
                  ))}
                </select>
              </div>
            )}

            <button
              onClick={() => setIsAddModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
            >
              <Plus className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Add Website</span>
            </button>
          </div>
        </header>

        {/* Tab Main Viewport */}
        <main className="flex-1 p-4 sm:p-8 max-w-7xl w-full mx-auto">
          {activeTab === 'websites' && (
            <WebsiteListView
              websites={websites}
              isLoading={isLoadingWebsites}
              onOpenAddModal={() => setIsAddModalOpen(true)}
              onOpenVerifyModal={(site) => {
                setActiveModalWebsite(site);
                setIsVerifyModalOpen(true);
              }}
              onOpenIntegrationsModal={(site) => {
                setActiveModalWebsite(site);
                setIsIntegrationsModalOpen(true);
              }}
              onArchiveWebsite={handleArchive}
              onRestoreWebsite={handleRestore}
              onDeleteWebsite={handleDelete}
              showArchived={showArchived}
              setShowArchived={setShowArchived}
            />
          )}

          {activeTab === 'team' && <TeamView />}

          {activeTab === 'activity' && <ActivityView />}

          {/* Upcoming Phase Placeholders */}
          {['audits', 'content', 'approvals', 'analytics'].includes(activeTab) && (
            <div className="p-12 border border-zinc-800/80 rounded-2xl bg-zinc-950 text-center max-w-xl mx-auto mt-12">
              <div className="w-12 h-12 rounded-2xl bg-emerald-950/40 border border-emerald-800/40 text-emerald-400 mx-auto mb-4 flex items-center justify-center font-bold">
                <Sparkles className="w-6 h-6" />
              </div>
              <h3 className="text-base font-bold text-zinc-100 mb-1 capitalize">
                {activeTab === 'audits' && 'Phase 3: Technical SEO Crawler & Audit Engine'}
                {activeTab === 'content' && 'Phase 4: AI Content & Meta Tag Generator'}
                {activeTab === 'approvals' && 'Phase 5: Recommendation & Human Approval Board'}
                {activeTab === 'analytics' && 'Phase 6: Google Search Console Sync & Analytics'}
              </h3>
              <p className="text-xs text-zinc-400 leading-relaxed mb-6">
                Phase 1 Core SaaS Foundation (Auth, Multi-Tenant Workspaces, RBAC, Website Registry, Encrypted Integrations, and Audit Trail) is complete. This module is queued for execution in the upcoming phase.
              </p>
              <button
                onClick={() => setActiveTab('websites')}
                className="px-4 py-2 text-xs font-semibold text-zinc-300 bg-zinc-900 hover:bg-zinc-800 rounded-xl border border-zinc-800 transition"
              >
                Back to Website Registry
              </button>
            </div>
          )}
        </main>
      </div>

      {/* Interactive Modals */}
      <AddWebsiteModal
        isOpen={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        onSuccess={() => loadWebsites()}
      />

      <VerifyWebsiteModal
        website={activeModalWebsite}
        isOpen={isVerifyModalOpen}
        onClose={() => {
          setIsVerifyModalOpen(false);
          setActiveModalWebsite(null);
        }}
        onVerified={() => loadWebsites()}
      />

      <IntegrationsModal
        website={activeModalWebsite}
        isOpen={isIntegrationsModalOpen}
        onClose={() => {
          setIsIntegrationsModalOpen(false);
          setActiveModalWebsite(null);
        }}
        onRefreshWebsite={() => loadWebsites()}
      />

      <CreateOrgModal
        isOpen={isCreateOrgModalOpen}
        onClose={() => setIsCreateOrgModalOpen(false)}
      />
    </div>
  );
}
