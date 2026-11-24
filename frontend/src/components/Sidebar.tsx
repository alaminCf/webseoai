'use client';

import React, { useState } from 'react';
import {
  LayoutDashboard,
  Globe,
  Sparkles,
  Activity,
  FileText,
  BarChart3,
  Zap,
  Users,
  Settings,
  LogOut,
  ChevronDown,
  Plus,
  Shield,
  Clock,
  Layers,
  Target,
  Radio,
  Crosshair,
  FileDown,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { ThemeToggle } from './ThemeToggle';

interface SidebarProps {
  activeTab: string;
  setActiveTab: (tab: string) => void;
  isOpen: boolean;
  onClose: () => void;
  onOpenCreateOrg: () => void;
}

export function Sidebar({
  activeTab,
  setActiveTab,
  isOpen,
  onClose,
  onOpenCreateOrg,
}: SidebarProps) {
  const { user, organizations, currentOrganization, switchOrganization, logout } = useAuth();
  const [orgDropdownOpen, setOrgDropdownOpen] = useState(false);
  const [avatarError, setAvatarError] = useState(false);

  React.useEffect(() => {
    setAvatarError(false);
  }, [user?.avatar_url]);

  // 9 intuitive, task-oriented sections as designed in Step 4
  const navItems = [
    { id: 'overview', label: 'Overview', icon: LayoutDashboard },
    { id: 'websites', label: 'My Websites', icon: Globe },
    { id: 'action_plan', label: 'SEO Action Plan', icon: Sparkles, badge: 'AI' },
    { id: 'audits', label: 'Site Audit', icon: Activity },
    { id: 'competitors', label: 'Competitor Analysis', icon: Target, badge: 'NEW' },
    { id: 'content', label: 'Content Studio', icon: FileText },
    { id: 'rank_checker', label: 'Rank Checker', icon: Crosshair, badge: 'SERP' },
    { id: 'live_traffic', label: 'Live Traffic', icon: Radio, badge: 'LIVE' },
    { id: 'performance', label: 'Performance', icon: BarChart3 },
    { id: 'reports', label: 'Reports & Export', icon: FileDown, badge: 'PDF/CSV' },
    { id: 'autopilot', label: 'Autopilot', icon: Zap },
    { id: 'team', label: 'Clients & Team', icon: Users },
    { id: 'settings', label: 'Settings', icon: Settings },
  ];

  return (
    <>
      {/* Mobile Backdrop */}
      {isOpen && (
        <div
          onClick={onClose}
          className="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden"
        />
      )}

      {/* Sidebar Container */}
      <aside
        className={`fixed top-0 bottom-0 left-0 z-50 w-72 bg-zinc-950 border-r border-zinc-800/80 flex flex-col transition-transform duration-300 ease-in-out lg:translate-x-0 ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        {/* Brand Header */}
        <div className="h-16 flex items-center justify-between px-6 border-b border-zinc-800/80">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold">
              <Sparkles className="w-4 h-4" />
            </div>
            <div>
              <div className="text-sm font-bold tracking-tight text-zinc-100 flex items-center gap-1.5">
                Web SEO AI
                <span className="text-[10px] font-semibold tracking-wider uppercase px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-400 border border-zinc-700">
                  v2.0
                </span>
              </div>
              <div className="text-[11px] text-zinc-400">Autonomous SEO Platform</div>
            </div>
          </div>
        </div>

        {/* Organization Switcher Dropdown */}
        <div className="p-4 border-b border-zinc-800/80">
          <div className="relative">
            <button
              onClick={() => setOrgDropdownOpen(!orgDropdownOpen)}
              className="w-full flex items-center justify-between p-2.5 rounded-xl bg-zinc-900/80 hover:bg-zinc-900 border border-zinc-800 text-left transition"
            >
              <div className="flex items-center gap-2.5 truncate">
                <div className="w-7 h-7 rounded-lg bg-emerald-950/60 border border-emerald-800/50 flex items-center justify-center text-emerald-400 shrink-0 font-semibold text-xs">
                  {currentOrganization?.name?.charAt(0) || 'O'}
                </div>
                <div className="truncate">
                  <div className="text-xs font-semibold text-zinc-200 truncate">
                    {currentOrganization?.name || 'Select Workspace'}
                  </div>
                  <div className="text-[10px] text-emerald-400 font-medium capitalize">
                    {currentOrganization?.role ? `${currentOrganization.role} Role` : 'Workspace'}
                  </div>
                </div>
              </div>
              <ChevronDown className="w-4 h-4 text-zinc-400 shrink-0" />
            </button>

            {/* Dropdown Menu */}
            {orgDropdownOpen && (
              <div className="absolute top-full left-0 right-0 mt-1.5 bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl p-1 z-30">
                <div className="text-[10px] font-semibold text-zinc-400 px-2 py-1 uppercase tracking-wider">
                  Workspaces
                </div>
                {organizations.map((org) => (
                  <button
                    key={org.id}
                    onClick={() => {
                      switchOrganization(org.id);
                      setOrgDropdownOpen(false);
                    }}
                    className={`w-full flex items-center justify-between px-2.5 py-2 text-xs rounded-lg transition ${
                      org.id === currentOrganization?.id
                        ? 'bg-emerald-500/10 text-emerald-400 font-medium'
                        : 'text-zinc-300 hover:bg-zinc-800'
                    }`}
                  >
                    <span className="truncate">{org.name}</span>
                    <span className="text-[10px] uppercase font-mono text-zinc-400">{org.role}</span>
                  </button>
                ))}
                <div className="h-px bg-zinc-800 my-1" />
                <button
                  onClick={() => {
                    setOrgDropdownOpen(false);
                    onOpenCreateOrg();
                  }}
                  className="w-full flex items-center gap-2 px-2.5 py-2 text-xs text-zinc-300 hover:bg-zinc-800 rounded-lg transition font-medium"
                >
                  <Plus className="w-3.5 h-3.5 text-emerald-400" />
                  <span>Create Workspace</span>
                </button>
              </div>
            )}
          </div>
        </div>

        {/* 9 Intuitive Navigation Items */}
        <nav className="flex-1 p-3 space-y-1 overflow-y-auto">
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => {
                  setActiveTab(item.id);
                  onClose();
                }}
                className={`w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-medium transition ${
                  isActive
                    ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
                    : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900/60'
                }`}
              >
                <div className="flex items-center gap-3">
                  <Icon className={`w-4 h-4 ${isActive ? 'text-emerald-400' : 'text-zinc-400'}`} />
                  <span>{item.label}</span>
                </div>
                {item.badge && (
                  <span
                    className={`text-[9px] font-bold px-1.5 py-0.5 rounded-md uppercase tracking-wide ${
                      isActive
                        ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                        : 'bg-zinc-800 text-zinc-400'
                    }`}
                  >
                    {item.badge}
                  </span>
                )}
              </button>
            );
          })}
        </nav>

        {/* Theme Switcher & User Footer */}
        <div className="p-4 border-t border-zinc-800/80 space-y-3">
          <ThemeToggle variant="segmented" />
          <div className="flex items-center justify-between gap-2">
            <button
              type="button"
              onClick={() => {
                setActiveTab('settings');
                onClose();
              }}
              title="Edit your profile"
              className="flex items-center gap-2.5 truncate flex-1 text-left p-1.5 -m-1.5 rounded-xl hover:bg-zinc-900/80 transition group"
            >
              {user?.avatar_url && !avatarError ? (
                <img
                  src={user.avatar_url}
                  alt={user.name || 'User'}
                  referrerPolicy="no-referrer"
                  onError={() => setAvatarError(true)}
                  className="w-8 h-8 rounded-full object-cover border border-emerald-500/40 shrink-0 bg-zinc-800"
                />
              ) : (
                <div className="w-8 h-8 rounded-full bg-zinc-800 border border-zinc-700 flex items-center justify-center text-xs font-semibold text-zinc-200 shrink-0">
                  {user?.name?.charAt(0)?.toUpperCase() || 'U'}
                </div>
              )}
              <div className="truncate">
                <div className="text-xs font-semibold text-zinc-200 group-hover:text-emerald-400 transition truncate">
                  {user?.name}
                </div>
                <div className="text-[11px] text-zinc-400 truncate">
                  {user?.job_title || user?.email}
                </div>
              </div>
            </button>
            <button
              onClick={logout}
              title="Sign out"
              className="p-1.5 text-zinc-400 hover:text-red-400 hover:bg-zinc-900 rounded-lg transition shrink-0"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
