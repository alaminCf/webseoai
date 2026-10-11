'use client';

import React, { useState } from 'react';
import {
  Globe,
  Plus,
  Search,
  Filter,
  ShieldCheck,
  ShieldAlert,
  Key,
  Archive,
  RotateCcw,
  Trash2,
  ExternalLink,
  Clock,
  MoreVertical,
  Activity,
} from 'lucide-react';
import { Website, Platform, ConnectionStatus } from '@/types';
import { EmptyState } from './EmptyState';

interface WebsiteListViewProps {
  websites: Website[];
  isLoading: boolean;
  onOpenAddModal: () => void;
  onOpenVerifyModal: (website: Website) => void;
  onOpenIntegrationsModal: (website: Website) => void;
  onArchiveWebsite: (websiteId: number) => void;
  onRestoreWebsite: (websiteId: number) => void;
  onDeleteWebsite: (websiteId: number) => void;
  showArchived: boolean;
  setShowArchived: (show: boolean) => void;
}

export function WebsiteListView({
  websites,
  isLoading,
  onOpenAddModal,
  onOpenVerifyModal,
  onOpenIntegrationsModal,
  onArchiveWebsite,
  onRestoreWebsite,
  onDeleteWebsite,
  showArchived,
  setShowArchived,
}: WebsiteListViewProps) {
  const [search, setSearch] = useState('');
  const [platformFilter, setPlatformFilter] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<string>('all');

  const filteredWebsites = websites.filter((w) => {
    const matchesSearch =
      w.name.toLowerCase().includes(search.toLowerCase()) ||
      w.domain.toLowerCase().includes(search.toLowerCase());
    const matchesPlatform = platformFilter === 'all' || w.platform === platformFilter;
    const matchesStatus = statusFilter === 'all' || w.connection_status === statusFilter;
    return matchesSearch && matchesPlatform && matchesStatus;
  });

  const getPlatformBadge = (platform: Platform) => {
    switch (platform) {
      case 'wordpress':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold bg-blue-950/40 text-blue-400 border border-blue-800/40 rounded-lg">
            WordPress
          </span>
        );
      case 'shopify':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold bg-emerald-950/40 text-emerald-400 border border-emerald-800/40 rounded-lg">
            Shopify
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold bg-zinc-900 text-zinc-300 border border-zinc-800 rounded-lg">
            Custom Web
          </span>
        );
    }
  };

  const getConnectionStatusBadge = (status: ConnectionStatus) => {
    switch (status) {
      case 'connected':
        return (
          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-400">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
            Connected
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-400">
            <span className="w-2 h-2 rounded-full bg-amber-400" />
            Pending Handshake
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-rose-400">
            <span className="w-2 h-2 rounded-full bg-rose-400" />
            Connection Error
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-400">
            <span className="w-2 h-2 rounded-full bg-zinc-600" />
            Disconnected
          </span>
        );
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Action Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <div>
          <h2 className="text-lg font-bold text-zinc-100 flex items-center gap-2">
            <Globe className="w-5 h-5 text-emerald-400" />
            Website Registry & Integrations
          </h2>
          <p className="text-xs text-zinc-400 mt-1">
            Manage agency web assets, platforms, ownership verification, and CMS write hooks.
          </p>
        </div>
        <div className="flex items-center gap-2.5">
          <button
            onClick={() => setShowArchived(!showArchived)}
            className={`px-3 py-2 text-xs font-medium rounded-xl border transition ${
              showArchived
                ? 'bg-zinc-800 text-zinc-100 border-zinc-700'
                : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
            }`}
          >
            {showArchived ? 'Viewing Archived' : 'View Archived'}
          </button>
          <button
            onClick={onOpenAddModal}
            className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow transition"
          >
            <Plus className="w-4 h-4" />
            Add Website
          </button>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-col sm:flex-row items-center gap-3">
        <div className="relative flex-1 w-full">
          <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name or domain..."
            className="w-full pl-10 pr-4 py-2.5 bg-zinc-950 border border-zinc-800/80 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500 transition"
          />
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto">
          <select
            value={platformFilter}
            onChange={(e) => setPlatformFilter(e.target.value)}
            className="px-3 py-2.5 bg-zinc-950 border border-zinc-800/80 rounded-xl text-xs text-zinc-300 focus:outline-none"
          >
            <option value="all">All Platforms</option>
            <option value="wordpress">WordPress</option>
            <option value="shopify">Shopify</option>
            <option value="custom">Custom Web</option>
          </select>

          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-3 py-2.5 bg-zinc-950 border border-zinc-800/80 rounded-xl text-xs text-zinc-300 focus:outline-none"
          >
            <option value="all">All Statuses</option>
            <option value="connected">Connected</option>
            <option value="disconnected">Disconnected</option>
            <option value="failed">Failed</option>
          </select>
        </div>
      </div>

      {/* Websites Grid / Cards */}
      {isLoading ? (
        <div className="p-16 text-center text-xs text-zinc-500">
          Loading workspace websites...
        </div>
      ) : filteredWebsites.length === 0 ? (
        <EmptyState
          icon={Globe}
          title={showArchived ? 'No archived websites' : 'No websites registered yet'}
          description={
            showArchived
              ? 'There are currently no archived websites in this workspace.'
              : 'Add your first WordPress, Shopify, or Custom website to start automated SEO auditing.'
          }
          actionLabel={showArchived ? undefined : 'Add Website Now'}
          onAction={showArchived ? undefined : onOpenAddModal}
        />
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {filteredWebsites.map((website) => (
            <div
              key={website.id}
              className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col justify-between hover:border-zinc-700/80 transition shadow-sm group"
            >
              <div>
                {/* Card Top Row */}
                <div className="flex items-start justify-between gap-3 mb-3">
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <h3 className="text-base font-bold text-zinc-100 group-hover:text-emerald-400 transition">
                        {website.name}
                      </h3>
                      {getPlatformBadge(website.platform)}
                    </div>
                    <a
                      href={website.target_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-xs text-zinc-400 hover:text-zinc-200 inline-flex items-center gap-1 font-mono transition"
                    >
                      {website.domain}
                      <ExternalLink className="w-3 h-3 text-zinc-500" />
                    </a>
                  </div>

                  {/* Health Score Badge */}
                  <div className="flex flex-col items-end">
                    <div
                      className={`text-sm font-bold px-2.5 py-1 rounded-xl border ${
                        website.health_score >= 80
                          ? 'bg-emerald-950/40 border-emerald-800/40 text-emerald-400'
                          : website.health_score >= 50
                          ? 'bg-amber-950/40 border-amber-800/40 text-amber-400'
                          : 'bg-zinc-900 border-zinc-800 text-zinc-400'
                      }`}
                    >
                      {website.health_score > 0 ? `${website.health_score}/100` : 'Not Audited'}
                    </div>
                    <span className="text-[10px] text-zinc-500 mt-0.5">Health Score</span>
                  </div>
                </div>

                {/* Status Badges Row */}
                <div className="flex flex-wrap items-center gap-3 py-3 border-y border-zinc-800/60 my-3 text-xs">
                  <div>{getConnectionStatusBadge(website.connection_status)}</div>

                  <div className="h-3 w-px bg-zinc-800" />

                  {/* Verification status */}
                  <button
                    onClick={() => onOpenVerifyModal(website)}
                    className="inline-flex items-center gap-1.5 hover:underline"
                  >
                    {website.verified_at ? (
                      <span className="text-emerald-400 inline-flex items-center gap-1">
                        <ShieldCheck className="w-3.5 h-3.5" />
                        Ownership Verified
                      </span>
                    ) : (
                      <span className="text-amber-400 inline-flex items-center gap-1">
                        <ShieldAlert className="w-3.5 h-3.5" />
                        Unverified (Click to verify)
                      </span>
                    )}
                  </button>

                  <div className="h-3 w-px bg-zinc-800" />

                  {/* Last Crawl Info */}
                  <div className="text-zinc-500 text-[11px] flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    {website.last_crawled_at
                      ? `Crawled ${new Date(website.last_crawled_at).toLocaleDateString()}`
                      : 'Never crawled'}
                  </div>
                </div>
              </div>

              {/* Action Buttons Row */}
              <div className="flex items-center justify-between pt-2">
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => onOpenIntegrationsModal(website)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-200 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
                  >
                    <Key className="w-3.5 h-3.5 text-emerald-400" />
                    Integrations
                  </button>
                  <button
                    onClick={() => onOpenVerifyModal(website)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900/60 hover:bg-zinc-800 rounded-xl transition"
                  >
                    Verify
                  </button>
                </div>

                <div className="flex items-center gap-1.5">
                  {showArchived ? (
                    <button
                      onClick={() => onRestoreWebsite(website.id)}
                      className="p-2 text-zinc-400 hover:text-emerald-400 hover:bg-zinc-900 rounded-lg transition"
                      title="Restore website"
                    >
                      <RotateCcw className="w-4 h-4" />
                    </button>
                  ) : (
                    <button
                      onClick={() => onArchiveWebsite(website.id)}
                      className="p-2 text-zinc-400 hover:text-amber-400 hover:bg-zinc-900 rounded-lg transition"
                      title="Archive website"
                    >
                      <Archive className="w-4 h-4" />
                    </button>
                  )}
                  <button
                    onClick={() => onDeleteWebsite(website.id)}
                    className="p-2 text-zinc-400 hover:text-rose-400 hover:bg-zinc-900 rounded-lg transition"
                    title="Delete permanently"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
