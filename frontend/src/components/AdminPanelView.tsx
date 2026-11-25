'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Shield,
  Users,
  Building2,
  Globe,
  KeyRound,
  Sliders,
  RefreshCw,
  Plus,
  Trash2,
  CheckCircle2,
  AlertTriangle,
  Ban,
  Zap,
  Database,
  Search,
  RotateCcw,
  Save,
  Download,
  Crown,
  Pause,
  Play,
  Activity,
} from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';

type AdminTab = 'overview' | 'keys' | 'settings' | 'users' | 'workspaces';

export function AdminPanelView() {
  const { user: currentUser } = useAuth();
  const toast = useToast();

  const [tab, setTab] = useState<AdminTab>('overview');
  const [isLoading, setIsLoading] = useState(false);

  // Overview state
  const [overview, setOverview] = useState<any>(null);

  // Key pool state
  const [keysData, setKeysData] = useState<any>(null);
  const [newKeyLabel, setNewKeyLabel] = useState('');
  const [newKeyValue, setNewKeyValue] = useState('');
  const [newKeyPriority, setNewKeyPriority] = useState(10);
  const [newKeyLimit, setNewKeyLimit] = useState(2500);
  const [isAddingKey, setIsAddingKey] = useState(false);

  // DataForSEO v3 state
  const [dfsStatus, setDfsStatus] = useState<any>(null);
  const [dfsLogin, setDfsLogin] = useState('');
  const [dfsPassword, setDfsPassword] = useState('');
  const [isSavingDfs, setIsSavingDfs] = useState(false);

  // Settings state
  const [settings, setSettings] = useState<any>(null);
  const [cacheStats, setCacheStats] = useState<any>(null);
  const [isSavingSettings, setIsSavingSettings] = useState(false);
  const [isFlushingCache, setIsFlushingCache] = useState(false);

  // Users state
  const [users, setUsers] = useState<any[]>([]);
  const [userSearch, setUserSearch] = useState('');

  // Workspaces & Websites state
  const [orgs, setOrgs] = useState<any[]>([]);
  const [websites, setWebsites] = useState<any[]>([]);
  const [wsSubView, setWsSubView] = useState<'orgs' | 'sites'>('orgs');
  const [wsSearch, setWsSearch] = useState('');
  const [editingOrgQuotaId, setEditingOrgQuotaId] = useState<number | null>(null);
  const [quotaDailyInput, setQuotaDailyInput] = useState('');
  const [quotaMonthlyInput, setQuotaMonthlyInput] = useState('');

  const loadOverview = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<any>('/admin/overview');
      if (res.success && res.data) setOverview(res.data);
    } finally {
      setIsLoading(false);
    }
  }, []);

  const loadKeys = useCallback(async () => {
    setIsLoading(true);
    try {
      const [res, dfsRes] = await Promise.all([
        api.get<any>('/admin/serp-keys'),
        api.get<any>('/admin/dataforseo'),
      ]);
      if (res.success && res.data) setKeysData(res.data);
      if (dfsRes.success && dfsRes.data) setDfsStatus(dfsRes.data);
    } finally {
      setIsLoading(false);
    }
  }, []);

  const loadSettings = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<any>('/admin/settings');
      if (res.success && res.data) {
        setSettings(res.data.settings);
        setCacheStats(res.data.cache_stats);
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  const loadUsers = useCallback(async (search = '') => {
    setIsLoading(true);
    try {
      const q = search ? `?search=${encodeURIComponent(search)}` : '';
      const res = await api.get<any>(`/admin/users${q}`);
      if (res.success && res.data) setUsers(res.data.users || []);
    } finally {
      setIsLoading(false);
    }
  }, []);

  const loadWorkspaces = useCallback(async (search = '') => {
    setIsLoading(true);
    try {
      const q = search ? `?search=${encodeURIComponent(search)}` : '';
      const [orgRes, siteRes] = await Promise.all([
        api.get<any>(`/admin/organizations${q}`),
        api.get<any>(`/admin/websites${q}`),
      ]);
      if (orgRes.success && orgRes.data) setOrgs(orgRes.data.organizations || []);
      if (siteRes.success && siteRes.data) setWebsites(siteRes.data.websites || []);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (tab === 'overview') loadOverview();
    if (tab === 'keys') loadKeys();
    if (tab === 'settings') loadSettings();
    if (tab === 'users') loadUsers(userSearch);
    if (tab === 'workspaces') loadWorkspaces(wsSearch);
  }, [tab, loadOverview, loadKeys, loadSettings, loadUsers, loadWorkspaces]);

  // --- Key Pool Actions ---
  const handleAddKey = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newKeyLabel.trim() || newKeyValue.trim().length < 10) {
      toast.error('Please enter both a label and valid Serper.dev API key.');
      return;
    }
    setIsAddingKey(true);
    try {
      const res = await api.post<any>('/admin/serp-keys', {
        label: newKeyLabel.trim(),
        api_key: newKeyValue.trim(),
        priority: newKeyPriority,
        monthly_limit: newKeyLimit,
      });
      if (res.success) {
        toast.success(res.data?.message || 'API key verified and added to failover pool!');
        setNewKeyLabel('');
        setNewKeyValue('');
        setNewKeyPriority((p) => p + 10);
        loadKeys();
      } else {
        toast.error(res.error?.message || 'Failed to add key.');
      }
    } finally {
      setIsAddingKey(false);
    }
  };

  const handleImportLegacyKey = async () => {
    const res = await api.post<any>('/admin/serp-keys/import-legacy');
    if (res.success) {
      toast.success(res.data?.message || 'Imported existing Serper key into pool.');
      loadKeys();
    } else {
      toast.error(res.error?.message || 'Could not import legacy key.');
    }
  };

  const handleUpdateKey = async (id: number, payload: Record<string, unknown>) => {
    const res = await api.patch<any>(`/admin/serp-keys/${id}`, payload);
    if (res.success) {
      toast.success(res.data?.message || 'Key updated.');
      loadKeys();
    } else {
      toast.error(res.error?.message || 'Could not update key.');
    }
  };

  const handleDeleteKey = async (id: number, label: string) => {
    if (!confirm(`Remove "${label}" from the SERP failover pool?`)) return;
    const res = await api.delete<any>(`/admin/serp-keys/${id}`);
    if (res.success) {
      toast.info(res.data?.message || 'Key removed.');
      loadKeys();
    } else {
      toast.error(res.error?.message || 'Failed to delete key.');
    }
  };

  const handleSaveDataForSeo = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dfsLogin.trim() || !dfsPassword.trim()) {
      toast.error('Enter both your DataForSEO API login email and API password.');
      return;
    }
    setIsSavingDfs(true);
    try {
      const res = await api.post<any>('/admin/dataforseo', {
        login: dfsLogin.trim(),
        password: dfsPassword.trim(),
      });
      if (res.success) {
        const bal = res.data?.balance !== null && res.data?.balance !== undefined ? ` (Balance: $${res.data.balance})` : '';
        toast.success((res.data?.message || 'DataForSEO connected!') + bal);
        setDfsLogin('');
        setDfsPassword('');
        loadKeys();
      } else {
        toast.error(res.error?.message || 'Could not verify DataForSEO credentials.');
      }
    } finally {
      setIsSavingDfs(false);
    }
  };

  const handleDeleteDataForSeo = async () => {
    if (!confirm('Disconnect DataForSEO v3 credentials?')) return;
    const res = await api.delete<any>('/admin/dataforseo');
    if (res.success) {
      toast.info('DataForSEO credentials disconnected.');
      loadKeys();
    } else {
      toast.error(res.error?.message || 'Could not disconnect DataForSEO.');
    }
  };

  // --- Settings & Cache Actions ---
  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!settings) return;
    setIsSavingSettings(true);
    try {
      const res = await api.put<any>('/admin/settings', settings);
      if (res.success) {
        toast.success(res.data?.message || 'Platform settings updated!');
        if (res.data?.settings) setSettings(res.data.settings);
      } else {
        toast.error(res.error?.message || 'Could not save settings.');
      }
    } finally {
      setIsSavingSettings(false);
    }
  };

  const handleFlushCache = async () => {
    if (!confirm('Flush all Smart Cross-User SERP cache entries?')) return;
    setIsFlushingCache(true);
    try {
      const res = await api.post<any>('/admin/cache/flush');
      if (res.success) {
        toast.success(res.data?.message || 'Cache flushed.');
        loadSettings();
      } else {
        toast.error(res.error?.message || 'Failed to flush cache.');
      }
    } finally {
      setIsFlushingCache(false);
    }
  };

  // --- User Actions ---
  const handleUpdateUser = async (userId: number, payload: Record<string, unknown>) => {
    const res = await api.patch<any>(`/admin/users/${userId}`, payload);
    if (res.success) {
      toast.success(res.data?.message || 'User updated.');
      loadUsers(userSearch);
    } else {
      toast.error(res.error?.message || 'Could not update user.');
    }
  };

  const handleDeleteUser = async (userId: number, email: string) => {
    if (!confirm(`Permanently delete user ${email}? This cannot be undone.`)) return;
    const res = await api.delete<any>(`/admin/users/${userId}`);
    if (res.success) {
      toast.info(res.data?.message || 'User deleted.');
      loadUsers(userSearch);
    } else {
      toast.error(res.error?.message || 'Could not delete user.');
    }
  };

  // --- Organization Actions ---
  const handleUpdateOrg = async (orgId: number, payload: Record<string, unknown>) => {
    const res = await api.patch<any>(`/admin/organizations/${orgId}`, payload);
    if (res.success) {
      toast.success(res.data?.message || 'Workspace updated.');
      setEditingOrgQuotaId(null);
      loadWorkspaces(wsSearch);
    } else {
      toast.error(res.error?.message || 'Could not update workspace.');
    }
  };

  const handleDeleteOrg = async (orgId: number, name: string) => {
    if (!confirm(`Permanently delete workspace "${name}" and ALL its websites & data?`)) return;
    const res = await api.delete<any>(`/admin/organizations/${orgId}`);
    if (res.success) {
      toast.info(res.data?.message || 'Workspace deleted.');
      loadWorkspaces(wsSearch);
    } else {
      toast.error(res.error?.message || 'Could not delete workspace.');
    }
  };

  if (!currentUser?.is_super_admin) {
    return (
      <div className="p-10 bg-zinc-950 border border-rose-500/30 rounded-2xl text-center max-w-xl mx-auto">
        <Shield className="w-10 h-10 text-rose-400 mx-auto mb-3" />
        <h2 className="text-base font-bold text-zinc-100">Super Administrator Access Required</h2>
        <p className="text-xs text-zinc-400 mt-1">
          Only platform administrators can manage global API key pools, quotas, users, and workspaces.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header Banner */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-500/15 text-amber-300 border border-amber-500/30">
              <Crown className="w-3 h-3" />
              Platform Super Admin Control Center
            </span>
          </div>
          <h1 className="text-xl font-extrabold text-zinc-100">
            SaaS Operations, SERP Key Pool &amp; User Control
          </h1>
          <p className="text-xs text-zinc-400 mt-0.5">
            Manage multi-key SERP auto-failover, smart cross-user caching, plan quotas, user accounts, and workspaces from one place.
          </p>
        </div>

        <button
          type="button"
          onClick={() => {
            if (tab === 'overview') loadOverview();
            if (tab === 'keys') loadKeys();
            if (tab === 'settings') loadSettings();
            if (tab === 'users') loadUsers(userSearch);
            if (tab === 'workspaces') loadWorkspaces(wsSearch);
          }}
          disabled={isLoading}
          className="px-3.5 py-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-xs font-semibold text-zinc-200 flex items-center gap-2 transition self-start md:self-auto"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin text-emerald-400' : ''}`} />
          Refresh
        </button>
      </div>

      {/* Navigation Tabs */}
      <div className="flex flex-wrap items-center gap-2 border-b border-zinc-800 pb-3 text-xs">
        {[
          { id: 'overview', label: 'Overview & Telemetry', icon: Activity },
          { id: 'keys', label: 'SERP Key Pool & Failover', icon: KeyRound },
          { id: 'settings', label: 'Quotas, Cache & Signup', icon: Sliders },
          { id: 'users', label: 'Users Management', icon: Users },
          { id: 'workspaces', label: 'Workspaces & Websites', icon: Building2 },
        ].map((item) => {
          const Icon = item.icon;
          const active = tab === item.id;
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => setTab(item.id as AdminTab)}
              className={`px-3.5 py-2 rounded-xl font-semibold transition flex items-center gap-2 ${
                active
                  ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                  : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900/60'
              }`}
            >
              <Icon className="w-3.5 h-3.5" />
              <span>{item.label}</span>
            </button>
          );
        })}
      </div>

      {/* TAB 1: OVERVIEW */}
      {tab === 'overview' && overview?.kpis && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">Total Users</div>
              <div className="text-3xl font-extrabold font-mono text-zinc-100 mt-1">
                {overview.kpis.total_users.toLocaleString()}
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                <span className="text-emerald-400">{overview.kpis.active_users} active</span>
                {overview.kpis.suspended_users > 0 && (
                  <span className="text-rose-400 ml-2">• {overview.kpis.suspended_users} suspended</span>
                )}
              </div>
            </div>

            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">Workspaces &amp; Websites</div>
              <div className="text-3xl font-extrabold font-mono text-zinc-100 mt-1">
                {overview.kpis.total_organizations.toLocaleString()}{' '}
                <span className="text-sm font-normal text-zinc-400">orgs</span>
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                {overview.kpis.total_websites} websites ({overview.kpis.verified_websites} verified)
              </div>
            </div>

            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">SERP Key Pool Health</div>
              <div className="text-3xl font-extrabold font-mono text-emerald-400 mt-1">
                {overview.kpis.active_pool_keys}{' '}
                <span className="text-sm font-normal text-zinc-400">active keys</span>
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                {overview.kpis.pool_capacity > 0
                  ? `${overview.kpis.pool_remaining.toLocaleString()} / ${overview.kpis.pool_capacity.toLocaleString()} pool credits left`
                  : overview.kpis.has_legacy_key || overview.kpis.has_env_key
                    ? 'Running on fallback legacy/env key (import into pool)'
                    : 'No Serper key configured yet'}
              </div>
            </div>

            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">SERP Credits Spent</div>
              <div className="text-3xl font-extrabold font-mono text-amber-300 mt-1">
                {overview.kpis.serp_credits_month.toLocaleString()}
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                This month • <span className="text-zinc-200">{overview.kpis.serp_credits_today}</span> today
              </div>
            </div>

            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">Smart Cache Savings</div>
              <div className="text-3xl font-extrabold font-mono text-cyan-400 mt-1">
                {overview.kpis.total_credits_saved.toLocaleString()}{' '}
                <span className="text-sm font-normal text-zinc-400">credits saved</span>
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                {overview.kpis.cache_hit_rate_pct}% hit rate this month • {overview.kpis.active_cache_entries} cached SERPs
              </div>
            </div>

            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
              <div className="text-[11px] uppercase tracking-wider text-zinc-400 font-semibold">Total Rank Checks (30d)</div>
              <div className="text-3xl font-extrabold font-mono text-zinc-100 mt-1">
                {overview.kpis.serp_checks_month.toLocaleString()}
              </div>
              <div className="text-[11px] text-zinc-400 mt-1">
                {overview.kpis.cache_hits_month} instant free cache hits
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Recent Signups */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
              <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-300">Recent User Signups</h3>
              <div className="divide-y divide-zinc-800/70">
                {(overview.recent_users || []).map((u: any) => (
                  <div key={u.id} className="py-2.5 flex items-center justify-between gap-3 text-xs">
                    <div className="flex items-center gap-2.5 truncate">
                      {u.avatar_url ? (
                        <img
                          src={u.avatar_url}
                          alt={u.name}
                          referrerPolicy="no-referrer"
                          className="w-7 h-7 rounded-full object-cover border border-zinc-700"
                        />
                      ) : (
                        <div className="w-7 h-7 rounded-full bg-zinc-800 flex items-center justify-center text-[11px] font-bold text-zinc-300">
                          {u.name?.charAt(0)?.toUpperCase()}
                        </div>
                      )}
                      <div className="truncate">
                        <div className="font-semibold text-zinc-200 truncate">{u.name}</div>
                        <div className="text-[11px] text-zinc-400 truncate">{u.email}</div>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                      <span className="px-2 py-0.5 rounded-md bg-zinc-900 border border-zinc-800 text-[10px] text-zinc-400">
                        {u.auth_provider}
                      </span>
                      {u.is_super_admin && (
                        <span className="px-2 py-0.5 rounded-md bg-amber-500/15 text-amber-300 border border-amber-500/30 text-[10px] font-bold">
                          ADMIN
                        </span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Top Credit Consumers */}
            <div className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-3">
              <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-300">Top SERP Credit Usage (This Month)</h3>
              {(overview.top_workspaces || []).length === 0 ? (
                <p className="text-xs text-zinc-500 py-6 text-center">No SERP checks recorded this month yet.</p>
              ) : (
                <div className="divide-y divide-zinc-800/70">
                  {overview.top_workspaces.map((w: any) => (
                    <div key={w.organization_id} className="py-2.5 flex items-center justify-between text-xs">
                      <div>
                        <div className="font-semibold text-zinc-200">{w.organization_name}</div>
                        <div className="text-[11px] text-zinc-400">
                          Plan: <span className="font-mono text-zinc-300">{w.billing_plan}</span> • {w.total_checks} checks ({w.cache_hits} cached)
                        </div>
                      </div>
                      <span className="font-mono font-bold text-amber-300">{w.credits_used} credits</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* TAB 2: SERP MULTI-KEY POOL & AUTO-FAILOVER */}
      {tab === 'keys' && (
        <div className="space-y-6">
          <div className="p-4 rounded-2xl bg-emerald-950/20 border border-emerald-500/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="flex items-start gap-3">
              <Zap className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />
              <div className="text-xs text-zinc-300 space-y-0.5">
                <div className="font-bold text-emerald-300">Automatic Zero-Downtime Multi-Key Failover</div>
                <p className="text-zinc-400">
                  Keys run in priority order (lowest number first). If Key #1 runs out of Serper.dev credits (HTTP 400/402/429), it is automatically marked <strong>Exhausted</strong> and requests instantly fail over to Key #2 with zero error for users.
                </p>
              </div>
            </div>
            {(keysData?.legacy_key_available || keysData?.env_key_configured) && (keysData?.keys?.length ?? 0) === 0 && (
              <button
                type="button"
                onClick={handleImportLegacyKey}
                className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold flex items-center gap-1.5 whitespace-nowrap shrink-0"
              >
                <Download className="w-3.5 h-3.5" />
                Import Existing Key into Pool
              </button>
            )}
          </div>

          {/* Add New Key Form */}
          <form onSubmit={handleAddKey} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <div className="text-xs font-bold uppercase tracking-wider text-zinc-300 flex items-center gap-2">
              <Plus className="w-4 h-4 text-emerald-400" />
              Add Serper.dev API Key to Failover Pool
            </div>
            <div className="grid grid-cols-1 md:grid-cols-12 gap-3">
              <div className="md:col-span-3">
                <label className="block text-[11px] text-zinc-400 mb-1">Key Label *</label>
                <input
                  type="text"
                  required
                  value={newKeyLabel}
                  onChange={(e) => setNewKeyLabel(e.target.value)}
                  placeholder="e.g. Serper Primary #1"
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>
              <div className="md:col-span-5">
                <label className="block text-[11px] text-zinc-400 mb-1">Serper.dev API Key *</label>
                <input
                  type="password"
                  required
                  value={newKeyValue}
                  onChange={(e) => setNewKeyValue(e.target.value)}
                  placeholder="Paste Serper API key (verified live before saving)"
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs font-mono text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>
              <div className="md:col-span-2">
                <label className="block text-[11px] text-zinc-400 mb-1">Priority</label>
                <input
                  type="number"
                  min={1}
                  max={999}
                  value={newKeyPriority}
                  onChange={(e) => setNewKeyPriority(parseInt(e.target.value || '10', 10))}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs font-mono text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>
              <div className="md:col-span-2">
                <label className="block text-[11px] text-zinc-400 mb-1">Credit Limit</label>
                <input
                  type="number"
                  min={100}
                  value={newKeyLimit}
                  onChange={(e) => setNewKeyLimit(parseInt(e.target.value || '2500', 10))}
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs font-mono text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>
            <div className="flex justify-end">
              <button
                type="submit"
                disabled={isAddingKey}
                className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold flex items-center gap-2 disabled:opacity-50"
              >
                <CheckCircle2 className="w-3.5 h-3.5" />
                {isAddingKey ? 'Verifying & Adding...' : 'Verify & Add to Pool'}
              </button>
            </div>
          </form>

          {/* Keys Pool List */}
          <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800">
                  <tr>
                    <th className="px-4 py-3">Priority</th>
                    <th className="px-4 py-3">Key Label</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3">Credit Usage</th>
                    <th className="px-4 py-3">Requests</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/70">
                  {(keysData?.keys || []).length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-zinc-400">
                        No keys in managed pool yet. Add 2+ Serper.dev keys above for automatic zero-downtime failover.
                      </td>
                    </tr>
                  ) : (
                    keysData.keys.map((k: any) => (
                      <tr key={k.id} className="hover:bg-zinc-900/40">
                        <td className="px-4 py-3 font-mono font-bold text-zinc-300">#{k.priority}</td>
                        <td className="px-4 py-3">
                          <div className="font-semibold text-zinc-100">{k.label}</div>
                          <div className="text-[11px] font-mono text-zinc-500">{k.key_hint}</div>
                          {k.last_error && (
                            <div className="text-[10px] text-rose-400 mt-0.5 max-w-xs truncate" title={k.last_error}>
                              {k.last_error}
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border ${
                              k.status === 'active'
                                ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                                : k.status === 'exhausted'
                                  ? 'bg-amber-500/15 text-amber-300 border-amber-500/30'
                                  : k.status === 'error'
                                    ? 'bg-rose-500/15 text-rose-300 border-rose-500/30'
                                    : 'bg-zinc-800 text-zinc-400 border-zinc-700'
                            }`}
                          >
                            {k.status}
                          </span>
                        </td>
                        <td className="px-4 py-3 min-w-[180px]">
                          <div className="flex items-center justify-between text-[11px] font-mono mb-1">
                            <span className="text-zinc-200">{k.credits_used.toLocaleString()}</span>
                            <span className="text-zinc-500">/ {k.monthly_limit.toLocaleString()}</span>
                          </div>
                          <div className="h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                            <div
                              style={{ width: `${Math.min(100, k.usage_pct)}%` }}
                              className={`h-full rounded-full ${
                                k.usage_pct >= 90 ? 'bg-rose-500' : k.usage_pct >= 70 ? 'bg-amber-400' : 'bg-emerald-500'
                              }`}
                            />
                          </div>
                        </td>
                        <td className="px-4 py-3 font-mono text-zinc-300">{k.total_requests}</td>
                        <td className="px-4 py-3 text-right">
                          <div className="inline-flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() =>
                                handleUpdateKey(k.id, {
                                  status: k.status === 'active' ? 'disabled' : 'active',
                                })
                              }
                              title={k.status === 'active' ? 'Pause Key' : 'Activate Key'}
                              className="p-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300"
                            >
                              {k.status === 'active' ? <Pause className="w-3.5 h-3.5" /> : <Play className="w-3.5 h-3.5 text-emerald-400" />}
                            </button>
                            <button
                              type="button"
                              onClick={() => handleUpdateKey(k.id, { reset_usage: true })}
                              title="Reset Usage Counter & Reactivate"
                              className="p-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-cyan-400"
                            >
                              <RotateCcw className="w-3.5 h-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={() => handleDeleteKey(k.id, k.label)}
                              title="Delete Key"
                              className="p-1.5 rounded-lg bg-zinc-900 hover:bg-rose-950/50 border border-zinc-800 text-zinc-400 hover:text-rose-400"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* DataForSEO v3 Platform Credentials (Search Volume, KD, CPC & Backlinks) */}
          <form onSubmit={handleSaveDataForSeo} className="p-5 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="text-xs font-bold uppercase tracking-wider text-zinc-200 flex items-center gap-2">
                  <Database className="w-4 h-4 text-cyan-400" />
                  DataForSEO v3 — Keyword Volume, KD, CPC &amp; Backlinks (Optional)
                </div>
                <p className="text-[11px] text-zinc-400 mt-0.5">
                  Connect once here to unlock real monthly Search Volume, Keyword Difficulty (KD), CPC, full Competitor Keyword Databases, and Backlink Profiles for all workspaces. (Without DataForSEO, Keyword Gap still runs live competitor rankings via Serper.dev + Smart Cache.)
                </p>
              </div>
              {dfsStatus?.configured ? (
                <div className="flex items-center gap-2">
                  <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                    Connected ({dfsStatus.login_hint})
                  </span>
                  {dfsStatus.source === 'admin_panel' && (
                    <button
                      type="button"
                      onClick={handleDeleteDataForSeo}
                      className="px-2.5 py-1 rounded-lg bg-zinc-900 hover:bg-rose-950/50 border border-zinc-800 text-rose-300 text-[11px] flex items-center gap-1"
                    >
                      <Trash2 className="w-3 h-3" />
                      Disconnect
                    </button>
                  )}
                </div>
              ) : (
                <span className="px-2.5 py-1 rounded-full text-[11px] font-medium bg-zinc-900 text-zinc-400 border border-zinc-800">
                  Not Connected (Serper Fallback Active)
                </span>
              )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-12 gap-3">
              <div className="md:col-span-5">
                <label className="block text-[11px] text-zinc-400 mb-1">DataForSEO API Login (Email)</label>
                <input
                  type="email"
                  required
                  value={dfsLogin}
                  onChange={(e) => setDfsLogin(e.target.value)}
                  placeholder="your-email@company.com"
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-cyan-500"
                />
              </div>
              <div className="md:col-span-5">
                <label className="block text-[11px] text-zinc-400 mb-1">DataForSEO API Password</label>
                <input
                  type="password"
                  required
                  value={dfsPassword}
                  onChange={(e) => setDfsPassword(e.target.value)}
                  placeholder="API password from app.dataforseo.com"
                  className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs font-mono text-zinc-100 focus:outline-none focus:border-cyan-500"
                />
              </div>
              <div className="md:col-span-2 flex items-end">
                <button
                  type="submit"
                  disabled={isSavingDfs}
                  className="w-full py-2 px-3 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold transition disabled:opacity-50"
                >
                  {isSavingDfs ? 'Verifying...' : 'Verify & Save'}
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {/* TAB 3: QUOTAS, SMART CACHE & REGISTRATION SETTINGS */}
      {tab === 'settings' && settings && (
        <form onSubmit={handleSaveSettings} className="space-y-6 max-w-4xl">
          {/* Plan SERP Credit Quotas */}
          <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <div>
              <h3 className="text-sm font-bold text-zinc-100">Per-Plan SERP Credit Quotas</h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                Protect your shared Serper credits by limiting daily and monthly live searches per workspace tier. Users with their own BYOK key are always unlimited.
              </p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {(['starter', 'agency_pro', 'enterprise', 'agency_internal'] as const).map((planKey) => (
                <div key={planKey} className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-3">
                  <div className="text-xs font-bold uppercase tracking-wider text-emerald-400">
                    {planKey.replace('_', ' ')}
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[11px] text-zinc-400 mb-1">Daily Credit Limit</label>
                      <input
                        type="number"
                        min={0}
                        value={settings.serp_quotas?.[planKey]?.daily ?? 0}
                        onChange={(e) =>
                          setSettings({
                            ...settings,
                            serp_quotas: {
                              ...settings.serp_quotas,
                              [planKey]: {
                                ...settings.serp_quotas?.[planKey],
                                daily: parseInt(e.target.value || '0', 10),
                              },
                            },
                          })
                        }
                        className="w-full px-3 py-1.5 bg-zinc-950 border border-zinc-800 rounded-lg text-xs font-mono text-zinc-100"
                      />
                    </div>
                    <div>
                      <label className="block text-[11px] text-zinc-400 mb-1">Monthly Credit Limit</label>
                      <input
                        type="number"
                        min={0}
                        value={settings.serp_quotas?.[planKey]?.monthly ?? 0}
                        onChange={(e) =>
                          setSettings({
                            ...settings,
                            serp_quotas: {
                              ...settings.serp_quotas,
                              [planKey]: {
                                ...settings.serp_quotas?.[planKey],
                                monthly: parseInt(e.target.value || '0', 10),
                              },
                            },
                          })
                        }
                        className="w-full px-3 py-1.5 bg-zinc-950 border border-zinc-800 rounded-lg text-xs font-mono text-zinc-100"
                      />
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Smart Cross-User SERP Cache & Default Depth */}
          <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                  <Database className="w-4 h-4 text-cyan-400" />
                  Smart Cross-User SERP Cache &amp; Default Depth
                </h3>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Reuses public Google SERP snapshots across all users (0 credits spent) while isolating each workspace&apos;s own domain ranking &amp; GSC comparison.
                </p>
              </div>
              <button
                type="button"
                onClick={handleFlushCache}
                disabled={isFlushingCache}
                className="px-3 py-1.5 rounded-xl bg-zinc-900 hover:bg-rose-950/40 border border-zinc-800 hover:border-rose-500/40 text-xs font-semibold text-zinc-300 hover:text-rose-300 flex items-center gap-1.5"
              >
                <Trash2 className="w-3.5 h-3.5" />
                {isFlushingCache ? 'Flushing...' : `Flush Cache (${cacheStats?.active_entries ?? 0})`}
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <label className="p-3.5 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between cursor-pointer">
                <span className="text-xs font-medium text-zinc-200">Enable Smart Cache</span>
                <input
                  type="checkbox"
                  checked={Boolean(settings.serp_cache?.enabled)}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      serp_cache: { ...settings.serp_cache, enabled: e.target.checked },
                    })
                  }
                  className="rounded border-zinc-700 bg-zinc-950 text-emerald-500"
                />
              </label>

              <div className="p-3.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                <label className="block text-[11px] text-zinc-400 mb-1">Cache Duration (TTL)</label>
                <select
                  value={settings.serp_cache?.ttl_hours ?? 12}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      serp_cache: { ...settings.serp_cache, ttl_hours: parseInt(e.target.value, 10) },
                    })
                  }
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-100"
                >
                  <option value={1}>1 Hour</option>
                  <option value={6}>6 Hours</option>
                  <option value={12}>12 Hours (Recommended)</option>
                  <option value={24}>24 Hours</option>
                  <option value={48}>48 Hours</option>
                </select>
              </div>

              <div className="p-3.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                <label className="block text-[11px] text-zinc-400 mb-1">Default Search Depth</label>
                <select
                  value={settings.serp_cache?.default_depth ?? 30}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      serp_cache: { ...settings.serp_cache, default_depth: parseInt(e.target.value, 10) },
                    })
                  }
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-100"
                >
                  <option value={20}>Top 20 (Max 2 credits)</option>
                  <option value={30}>Top 30 (Pages 1–3 • Recommended)</option>
                  <option value={50}>Top 50 (Pages 1–5)</option>
                  <option value={100}>Top 100 (Pages 1–10)</option>
                </select>
              </div>
            </div>
          </div>

          {/* Public Registration & Default Plan */}
          <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-4">
            <h3 className="text-sm font-bold text-zinc-100">User Registration &amp; Default Plan</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label className="p-3.5 rounded-xl bg-zinc-900/60 border border-zinc-800 flex items-center justify-between cursor-pointer">
                <div>
                  <div className="text-xs font-medium text-zinc-200">Allow Public Registration</div>
                  <div className="text-[11px] text-zinc-400">Enable email &amp; 1-click Google signups</div>
                </div>
                <input
                  type="checkbox"
                  checked={Boolean(settings.registration?.allow_public_registration)}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      registration: {
                        ...settings.registration,
                        allow_public_registration: e.target.checked,
                      },
                    })
                  }
                  className="rounded border-zinc-700 bg-zinc-950 text-emerald-500"
                />
              </label>

              <div className="p-3.5 rounded-xl bg-zinc-900/60 border border-zinc-800">
                <label className="block text-[11px] text-zinc-400 mb-1">Default Plan for New Users</label>
                <select
                  value={settings.registration?.default_signup_plan ?? 'agency_pro'}
                  onChange={(e) =>
                    setSettings({
                      ...settings,
                      registration: { ...settings.registration, default_signup_plan: e.target.value },
                    })
                  }
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-100"
                >
                  <option value="starter">Starter Plan (3 websites • 50 SERP credits/mo)</option>
                  <option value="agency_pro">Agency Pro (15 websites • 200 SERP credits/mo)</option>
                  <option value="enterprise">Enterprise (100 websites • 1,500 SERP credits/mo)</option>
                </select>
              </div>
            </div>
          </div>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={isSavingSettings}
              className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold flex items-center gap-2 shadow disabled:opacity-50"
            >
              <Save className="w-3.5 h-3.5" />
              {isSavingSettings ? 'Saving Settings...' : 'Save Platform Settings'}
            </button>
          </div>
        </form>
      )}

      {/* TAB 4: USERS MANAGEMENT */}
      {tab === 'users' && (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <div className="relative flex-1 max-w-md">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type="text"
                value={userSearch}
                onChange={(e) => {
                  setUserSearch(e.target.value);
                  loadUsers(e.target.value);
                }}
                placeholder="Search users by name or email..."
                className="w-full pl-9 pr-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
              />
            </div>
            <span className="text-xs text-zinc-400 font-mono">{users.length} users</span>
          </div>

          <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800">
                  <tr>
                    <th className="px-4 py-3">User</th>
                    <th className="px-4 py-3">Auth</th>
                    <th className="px-4 py-3">Workspaces / Sites</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Admin Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/70">
                  {users.map((u) => {
                    const isSelf = u.id === currentUser.id;
                    return (
                      <tr key={u.id} className="hover:bg-zinc-900/40">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2.5">
                            {u.avatar_url ? (
                              <img
                                src={u.avatar_url}
                                alt={u.name}
                                referrerPolicy="no-referrer"
                                className="w-8 h-8 rounded-full object-cover border border-zinc-700"
                              />
                            ) : (
                              <div className="w-8 h-8 rounded-full bg-zinc-800 flex items-center justify-center text-xs font-bold text-zinc-300">
                                {u.name?.charAt(0)?.toUpperCase()}
                              </div>
                            )}
                            <div>
                              <div className="font-semibold text-zinc-100 flex items-center gap-1.5">
                                {u.name}
                                {u.is_super_admin && (
                                  <span className="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-300 border border-amber-500/30 text-[9px] font-bold">
                                    SUPER ADMIN
                                  </span>
                                )}
                                {isSelf && (
                                  <span className="text-[10px] text-emerald-400 font-normal">(You)</span>
                                )}
                              </div>
                              <div className="text-[11px] text-zinc-400">{u.email}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span className="px-2 py-0.5 rounded-md bg-zinc-900 border border-zinc-800 text-[10px] text-zinc-300">
                            {u.google_connected ? 'Google' : 'Email'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-zinc-300 font-mono">
                          {u.workspaces_count} orgs • {u.websites_count} sites
                        </td>
                        <td className="px-4 py-3">
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border ${
                              u.status === 'suspended'
                                ? 'bg-rose-500/15 text-rose-300 border-rose-500/30'
                                : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                            }`}
                          >
                            {u.status || 'active'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-right">
                          {!isSelf && (
                            <div className="inline-flex items-center gap-1.5">
                              <button
                                type="button"
                                onClick={() => handleUpdateUser(u.id, { is_super_admin: !u.explicit_super_admin })}
                                className="px-2 py-1 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-[11px] text-amber-300"
                                title="Toggle Super Admin"
                              >
                                {u.explicit_super_admin ? 'Revoke Admin' : 'Make Admin'}
                              </button>
                              <button
                                type="button"
                                onClick={() =>
                                  handleUpdateUser(u.id, {
                                    status: u.status === 'suspended' ? 'active' : 'suspended',
                                  })
                                }
                                className={`px-2 py-1 rounded-lg border text-[11px] flex items-center gap-1 ${
                                  u.status === 'suspended'
                                    ? 'bg-emerald-950/40 border-emerald-700/40 text-emerald-300'
                                    : 'bg-zinc-900 border-zinc-800 text-rose-300 hover:bg-rose-950/40'
                                }`}
                              >
                                <Ban className="w-3 h-3" />
                                {u.status === 'suspended' ? 'Activate' : 'Suspend'}
                              </button>
                              <button
                                type="button"
                                onClick={() => handleDeleteUser(u.id, u.email)}
                                className="p-1.5 rounded-lg bg-zinc-900 hover:bg-rose-950/50 border border-zinc-800 text-zinc-400 hover:text-rose-400"
                                title="Delete User"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* TAB 5: WORKSPACES & WEBSITES */}
      {tab === 'workspaces' && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <div className="inline-flex bg-zinc-900 border border-zinc-800 rounded-xl p-1 text-xs">
                <button
                  type="button"
                  onClick={() => setWsSubView('orgs')}
                  className={`px-3 py-1 rounded-lg font-semibold ${
                    wsSubView === 'orgs' ? 'bg-emerald-600 text-white' : 'text-zinc-400'
                  }`}
                >
                  Workspaces ({orgs.length})
                </button>
                <button
                  type="button"
                  onClick={() => setWsSubView('sites')}
                  className={`px-3 py-1 rounded-lg font-semibold ${
                    wsSubView === 'sites' ? 'bg-emerald-600 text-white' : 'text-zinc-400'
                  }`}
                >
                  All Websites ({websites.length})
                </button>
              </div>
              <div className="relative">
                <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type="text"
                  value={wsSearch}
                  onChange={(e) => {
                    setWsSearch(e.target.value);
                    loadWorkspaces(e.target.value);
                  }}
                  placeholder="Search workspaces or domains..."
                  className="pl-9 pr-3 py-1.5 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 w-60 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>
          </div>

          {wsSubView === 'orgs' ? (
            <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800">
                    <tr>
                      <th className="px-4 py-3">Workspace &amp; Owner</th>
                      <th className="px-4 py-3">Plan Tier</th>
                      <th className="px-4 py-3">Sites / Team</th>
                      <th className="px-4 py-3">SERP Credits Used</th>
                      <th className="px-4 py-3 text-right">Controls</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/70">
                    {orgs.map((o) => (
                      <tr key={o.id} className="hover:bg-zinc-900/40">
                        <td className="px-4 py-3">
                          <div className="font-semibold text-zinc-100">{o.name}</div>
                          <div className="text-[11px] text-zinc-400">
                            {o.owner ? `${o.owner.name} (${o.owner.email})` : o.slug}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <select
                            value={o.billing_plan}
                            onChange={(e) => handleUpdateOrg(o.id, { billing_plan: e.target.value })}
                            className="bg-zinc-900 border border-zinc-800 rounded-lg px-2 py-1 text-xs text-zinc-200 font-mono"
                          >
                            <option value="starter">starter</option>
                            <option value="agency_pro">agency_pro</option>
                            <option value="enterprise">enterprise</option>
                            <option value="agency_internal">agency_internal</option>
                          </select>
                        </td>
                        <td className="px-4 py-3 font-mono text-zinc-300">
                          {o.websites_count} sites • {o.members_count} members
                        </td>
                        <td className="px-4 py-3 font-mono">
                          {editingOrgQuotaId === o.id ? (
                            <div className="flex items-center gap-1.5">
                              <input
                                type="number"
                                placeholder="Daily"
                                value={quotaDailyInput}
                                onChange={(e) => setQuotaDailyInput(e.target.value)}
                                className="w-16 px-2 py-1 bg-zinc-900 border border-zinc-700 rounded text-[11px]"
                              />
                              <input
                                type="number"
                                placeholder="Monthly"
                                value={quotaMonthlyInput}
                                onChange={(e) => setQuotaMonthlyInput(e.target.value)}
                                className="w-20 px-2 py-1 bg-zinc-900 border border-zinc-700 rounded text-[11px]"
                              />
                              <button
                                type="button"
                                onClick={() =>
                                  handleUpdateOrg(o.id, {
                                    serp_quota_override:
                                      quotaDailyInput === '' && quotaMonthlyInput === ''
                                        ? null
                                        : {
                                            daily: quotaDailyInput !== '' ? parseInt(quotaDailyInput, 10) : null,
                                            monthly: quotaMonthlyInput !== '' ? parseInt(quotaMonthlyInput, 10) : null,
                                          },
                                  })
                                }
                                className="px-2 py-1 bg-emerald-600 text-white rounded text-[10px] font-bold"
                              >
                                Save
                              </button>
                            </div>
                          ) : (
                            <div className="text-[11px]">
                              <span className="text-amber-300 font-semibold">
                                {o.serp_quota?.monthly_used ?? 0}
                              </span>
                              <span className="text-zinc-500">
                                {' '}/ {o.serp_quota?.is_unlimited ? '∞' : o.serp_quota?.monthly_limit} mo
                              </span>
                              <span className="block text-[10px] text-zinc-500">
                                {o.serp_quota?.daily_used ?? 0}/{o.serp_quota?.is_unlimited ? '∞' : o.serp_quota?.daily_limit} today
                                {o.serp_quota_override && <span className="text-cyan-400 ml-1">(Custom)</span>}
                              </span>
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <div className="inline-flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => {
                                setEditingOrgQuotaId(editingOrgQuotaId === o.id ? null : o.id);
                                setQuotaDailyInput(o.serp_quota_override?.daily?.toString() ?? '');
                                setQuotaMonthlyInput(o.serp_quota_override?.monthly?.toString() ?? '');
                              }}
                              className="px-2 py-1 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-[11px] text-cyan-300"
                            >
                              Quota
                            </button>
                            <button
                              type="button"
                              onClick={() => handleUpdateOrg(o.id, { reset_serp_usage: true })}
                              title="Reset Monthly SERP Usage"
                              className="p-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300"
                            >
                              <RotateCcw className="w-3.5 h-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={() => handleDeleteOrg(o.id, o.name)}
                              title="Delete Workspace"
                              className="p-1.5 rounded-lg bg-zinc-900 hover:bg-rose-950/50 border border-zinc-800 text-zinc-400 hover:text-rose-400"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-900/70 text-zinc-400 border-b border-zinc-800">
                    <tr>
                      <th className="px-4 py-3">Website Domain</th>
                      <th className="px-4 py-3">Workspace</th>
                      <th className="px-4 py-3">Platform</th>
                      <th className="px-4 py-3">Integrations</th>
                      <th className="px-4 py-3 text-right">Health</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/70">
                    {websites.map((w) => (
                      <tr key={w.id} className="hover:bg-zinc-900/40">
                        <td className="px-4 py-3">
                          <div className="font-semibold text-zinc-100">{w.domain}</div>
                          <div className="text-[11px] text-zinc-400">{w.name}</div>
                        </td>
                        <td className="px-4 py-3 text-zinc-300">{w.organization_name}</td>
                        <td className="px-4 py-3 font-mono text-zinc-400 uppercase">{w.platform}</td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1.5">
                            {w.gsc_connected && (
                              <span className="px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300 text-[10px] font-semibold">
                                GSC
                              </span>
                            )}
                            {w.ga4_connected && (
                              <span className="px-1.5 py-0.5 rounded bg-cyan-500/15 text-cyan-300 text-[10px] font-semibold">
                                GA4
                              </span>
                            )}
                            {!w.gsc_connected && !w.ga4_connected && (
                              <span className="text-zinc-500 text-[11px]">None</span>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right font-mono font-bold text-emerald-400">
                          {w.health_score ?? '—'}
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
    </div>
  );
}
