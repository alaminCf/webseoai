'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  CreditCard,
  Building2,
  Users,
  Shield,
  Palette,
  CheckCircle,
  AlertTriangle,
  ExternalLink,
  Plus,
  RefreshCw,
  FileText,
  Lock,
  Trash2,
  Copy,
  ChevronRight,
  Sparkles,
  Zap,
  Globe,
  HelpCircle,
  Clock,
  Layers,
  ArrowUpRight,
  Check,
  X
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';
import { api } from '@/lib/api';
import {
  Website,
  SubscriptionPlan,
  BillingUsage,
  WhiteLabelBranding,
  ClientWorkspace,
  ClientApprovalRequest,
  WebsitePermission
} from '@/types';

interface BillingAndAgencyViewProps {
  selectedWebsite: Website | null;
}

export function BillingAndAgencyView({ selectedWebsite }: BillingAndAgencyViewProps) {
  const { currentOrganization, user } = useAuth();
  const toast = useToast();

  const [activeTab, setActiveTab] = useState<'billing' | 'clients' | 'whitelabel' | 'security'>('billing');
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');

  // Billing state
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [usage, setUsage] = useState<BillingUsage | null>(null);
  const [isLoadingBilling, setIsLoadingBilling] = useState(false);
  const [isCheckoutLoading, setIsCheckoutLoading] = useState<string | null>(null);

  // Client Workspaces state
  const [workspaces, setWorkspaces] = useState<ClientWorkspace[]>([]);
  const [approvalRequests, setApprovalRequests] = useState<ClientApprovalRequest[]>([]);
  const [permissions, setPermissions] = useState<WebsitePermission[]>([]);
  const [isWorkspaceModalOpen, setIsWorkspaceModalOpen] = useState(false);
  const [isApprovalModalOpen, setIsApprovalModalOpen] = useState(false);
  const [workspaceForm, setWorkspaceForm] = useState({
    name: '',
    company_name: '',
    client_email: '',
    contact_person: '',
    notes: '',
  });
  const [approvalForm, setApprovalForm] = useState({
    approver_email: '',
    title: '',
    description: '',
    request_type: 'seo_strategy',
  });

  // White-label state
  const [branding, setBranding] = useState<WhiteLabelBranding>({
    brand_name: currentOrganization?.name || 'SEO Agency',
    logo_url: null,
    primary_color: '#10b981',
    contact_email: null,
    footer_text: 'Confidential SEO Strategy & Technical Audit Report',
    remove_platform_branding: false,
  });
  const [isSavingBranding, setIsSavingBranding] = useState(false);
  const [generatedReport, setGeneratedReport] = useState<any | null>(null);
  const [isReportModalOpen, setIsReportModalOpen] = useState(false);
  const [isGeneratingReport, setIsGeneratingReport] = useState(false);

  // Danger zone state
  const [isDeleteOrgModalOpen, setIsDeleteOrgModalOpen] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState('');
  const [isDeletingOrg, setIsDeletingOrg] = useState(false);

  // Load Billing & Quotas
  const loadBillingData = useCallback(async () => {
    setIsBillingLoading(true);
    try {
      const [plansRes, usageRes] = await Promise.all([
        api.get<{ plans: SubscriptionPlan[]; current_plan: any }>('/billing/plans'),
        api.get<BillingUsage>('/billing/usage'),
      ]);

      if (plansRes.success && plansRes.data) {
        setPlans(plansRes.data.plans);
      }
      if (usageRes.success && usageRes.data) {
        setUsage(usageRes.data);
      }
    } catch {
      toast.error('Failed to load subscription and billing details.');
    } finally {
      setIsBillingLoading(false);
    }
  }, [toast]);

  function setIsBillingLoading(val: boolean) {
    setIsLoadingBilling(val);
  }

  // Load Clients and Approvals
  const loadClientData = useCallback(async () => {
    try {
      const [wsRes, appRes] = await Promise.all([
        api.get<ClientWorkspace[]>('/clients/workspaces'),
        api.get<ClientApprovalRequest[]>('/clients/approvals'),
      ]);

      if (wsRes.success && wsRes.data) setWorkspaces(wsRes.data);
      if (appRes.success && appRes.data) setApprovalRequests(appRes.data);

      if (selectedWebsite) {
        const permRes = await api.get<WebsitePermission[]>(`/websites/${selectedWebsite.id}/permissions`);
        if (permRes.success && permRes.data) setPermissions(permRes.data);
      }
    } catch {
      toast.error('Failed to load client management workspaces.');
    }
  }, [selectedWebsite, toast]);

  // Load Branding
  const loadBrandingData = useCallback(async () => {
    try {
      const res = await api.get<WhiteLabelBranding>('/white-label/branding');
      if (res.success && res.data) {
        setBranding(res.data);
      }
    } catch {
      // Default to empty
    }
  }, []);

  useEffect(() => {
    if (activeTab === 'billing') loadBillingData();
    if (activeTab === 'clients') loadClientData();
    if (activeTab === 'whitelabel') loadBrandingData();
  }, [activeTab, loadBillingData, loadClientData, loadBrandingData]);

  // Handle Checkout Upgrade
  const handleUpgradePlan = async (planId: string) => {
    setIsCheckoutLoading(planId);
    try {
      const res = await api.post<{ checkout_url: string }>('/billing/checkout-session', {
        plan_id: planId,
        billing_cycle: billingCycle,
      });

      if (res.success && res.data?.checkout_url) {
        window.open(res.data.checkout_url, '_blank');
        toast.info('Redirecting to secure Stripe Checkout...');
      } else {
        toast.error(res.error?.message || 'Could not initiate checkout.');
      }
    } finally {
      setIsCheckoutLoading(null);
    }
  };

  // Handle Portal Session
  const handleOpenBillingPortal = async () => {
    try {
      const res = await api.post<{ portal_url: string }>('/billing/portal-session');
      if (res.success && res.data?.portal_url) {
        window.open(res.data.portal_url, '_blank');
      } else {
        toast.error(res.error?.message || 'Could not access customer billing portal.');
      }
    } catch {
      toast.error('Failed to open billing portal.');
    }
  };

  // Create Workspace
  const handleCreateWorkspace = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await api.post('/clients/workspaces', workspaceForm);
      if (res.success) {
        toast.success('Client workspace created.');
        setIsWorkspaceModalOpen(false);
        setWorkspaceForm({ name: '', company_name: '', client_email: '', contact_person: '', notes: '' });
        loadClientData();
      } else {
        toast.error(res.error?.message || 'Failed to create workspace.');
      }
    } catch {
      toast.error('Error creating workspace.');
    }
  };

  // Create Client Approval Request
  const handleCreateApproval = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedWebsite) {
      toast.error('Please select an active website first.');
      return;
    }

    try {
      const res = await api.post(`/websites/${selectedWebsite.id}/client-approvals`, approvalForm);
      if (res.success) {
        toast.success('Client approval request created and token generated.');
        setIsApprovalModalOpen(false);
        setApprovalForm({ approver_email: '', title: '', description: '', request_type: 'seo_strategy' });
        loadClientData();
      } else {
        toast.error(res.error?.message || 'Failed to create approval request.');
      }
    } catch {
      toast.error('Error creating approval request.');
    }
  };

  // Save Branding
  const handleSaveBranding = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSavingBranding(true);
    try {
      const res = await api.put('/white-label/branding', branding);
      if (res.success) {
        toast.success('Agency white-label settings updated.');
      } else {
        toast.error(res.error?.message || 'Failed to update branding.');
      }
    } finally {
      setIsSavingBranding(false);
    }
  };

  // Generate White-Label Report
  const handleGenerateReport = async () => {
    if (!selectedWebsite) {
      toast.error('Select a website to generate a report.');
      return;
    }

    setIsGeneratingReport(true);
    try {
      const res = await api.get(`/websites/${selectedWebsite.id}/reports/white-label`);
      if (res.success && res.data) {
        setGeneratedReport(res.data);
        setIsReportModalOpen(true);
      } else {
        toast.error(res.error?.message || 'Failed to generate white-label report.');
      }
    } catch {
      toast.error('Failed to generate report.');
    } finally {
      setIsGeneratingReport(false);
    }
  };

  // Delete Organization
  const handleDeleteOrganization = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsDeletingOrg(true);
    try {
      const res = await api.post('/organizations/current/delete', {
        confirmation: deleteConfirmation,
      });

      if (res.success) {
        toast.success('Organization permanently deleted.');
        window.location.reload();
      } else {
        toast.error(res.error?.message || 'Failed to delete organization.');
      }
    } finally {
      setIsDeletingOrg(false);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Link copied to clipboard!');
  };

  const currentPlanId = usage?.plan?.id || currentOrganization?.billing_plan || 'starter';

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="bg-gradient-to-r from-zinc-900 via-zinc-900 to-black border border-zinc-800 rounded-xl p-6 shadow-2xl relative overflow-hidden">
        <div className="absolute top-0 right-0 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none" />
        <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-3">
              <span className="p-2 bg-emerald-500/20 text-emerald-400 rounded-lg border border-emerald-500/30">
                <CreditCard className="w-6 h-6" />
              </span>
              <div>
                <h1 className="text-2xl font-bold text-white tracking-tight">SaaS & Agency Operations</h1>
                <p className="text-sm text-zinc-400">
                  Manage multi-tenant subscriptions, client workspaces, quotas, and white-label branding.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <div className="px-3 py-1.5 rounded-lg bg-zinc-800 border border-zinc-700 text-xs font-mono text-zinc-300">
              Org: <span className="text-white font-semibold">{currentOrganization?.name}</span>
            </div>
            <div className="px-3 py-1.5 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-xs font-mono text-emerald-400 capitalize font-medium">
              Plan: {currentPlanId.replace('_', ' ')}
            </div>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex items-center gap-2 mt-6 border-b border-zinc-800 pb-2">
          <button
            onClick={() => setActiveTab('billing')}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 ${
              activeTab === 'billing'
                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/60'
            }`}
          >
            <CreditCard className="w-4 h-4" />
            Subscriptions & Quotas
          </button>
          <button
            onClick={() => setActiveTab('clients')}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 ${
              activeTab === 'clients'
                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/60'
            }`}
          >
            <Building2 className="w-4 h-4" />
            Client Workspaces & Approvals
          </button>
          <button
            onClick={() => setActiveTab('whitelabel')}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 ${
              activeTab === 'whitelabel'
                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/60'
            }`}
          >
            <Palette className="w-4 h-4" />
            White-Label Agency Branding
          </button>
          <button
            onClick={() => setActiveTab('security')}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-2 ${
              activeTab === 'security'
                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/60'
            }`}
          >
            <Shield className="w-4 h-4" />
            Launch Security & Purge
          </button>
        </div>
      </div>

      {/* TAB 1: SUBSCRIPTIONS & QUOTAS */}
      {activeTab === 'billing' && (
        <div className="space-y-6">
          {/* Active Usage Quotas */}
          {usage && (
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
              {/* Websites */}
              <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-zinc-400">
                  <span className="flex items-center gap-1.5 font-medium">
                    <Globe className="w-3.5 h-3.5 text-emerald-400" /> Websites
                  </span>
                  <span>{usage.quotas.websites.percentage}%</span>
                </div>
                <div className="text-xl font-bold text-white font-mono">
                  {usage.quotas.websites.used} <span className="text-xs text-zinc-500 font-normal">/ {usage.quotas.websites.limit}</span>
                </div>
                <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-emerald-500 h-full rounded-full transition-all"
                    style={{ width: `${usage.quotas.websites.percentage}%` }}
                  />
                </div>
                <div className="text-[11px] text-zinc-500">
                  {usage.quotas.websites.remaining} slots remaining
                </div>
              </div>

              {/* Crawl Pages */}
              <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-zinc-400">
                  <span className="flex items-center gap-1.5 font-medium">
                    <Layers className="w-3.5 h-3.5 text-blue-400" /> Crawl Pages
                  </span>
                  <span>{usage.quotas.crawl_pages.percentage}%</span>
                </div>
                <div className="text-xl font-bold text-white font-mono">
                  {usage.quotas.crawl_pages.used} <span className="text-xs text-zinc-500 font-normal">/ {usage.quotas.crawl_pages.limit}</span>
                </div>
                <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-blue-500 h-full rounded-full transition-all"
                    style={{ width: `${usage.quotas.crawl_pages.percentage}%` }}
                  />
                </div>
                <div className="text-[11px] text-zinc-500">
                  Reset monthly ({usage.features.crawl_frequency})
                </div>
              </div>

              {/* AI Tokens */}
              <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-zinc-400">
                  <span className="flex items-center gap-1.5 font-medium">
                    <Sparkles className="w-3.5 h-3.5 text-purple-400" /> AI Tokens
                  </span>
                  <span>{usage.quotas.ai_tokens.percentage}%</span>
                </div>
                <div className="text-xl font-bold text-white font-mono">
                  {(usage.quotas.ai_tokens.used / 1000).toFixed(0)}k <span className="text-xs text-zinc-500 font-normal">/ {(usage.quotas.ai_tokens.limit / 1000).toFixed(0)}k</span>
                </div>
                <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-purple-500 h-full rounded-full transition-all"
                    style={{ width: `${usage.quotas.ai_tokens.percentage}%` }}
                  />
                </div>
                <div className="text-[11px] text-zinc-500">
                  Tokens spent this cycle
                </div>
              </div>

              {/* Articles */}
              <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-zinc-400">
                  <span className="flex items-center gap-1.5 font-medium">
                    <FileText className="w-3.5 h-3.5 text-amber-400" /> Articles
                  </span>
                  <span>{usage.quotas.articles.percentage}%</span>
                </div>
                <div className="text-xl font-bold text-white font-mono">
                  {usage.quotas.articles.used} <span className="text-xs text-zinc-500 font-normal">/ {usage.quotas.articles.limit}</span>
                </div>
                <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-amber-500 h-full rounded-full transition-all"
                    style={{ width: `${usage.quotas.articles.percentage}%` }}
                  />
                </div>
                <div className="text-[11px] text-zinc-500">
                  Content Studio drafts
                </div>
              </div>

              {/* Team Seats */}
              <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-zinc-400">
                  <span className="flex items-center gap-1.5 font-medium">
                    <Users className="w-3.5 h-3.5 text-cyan-400" /> Team Seats
                  </span>
                  <span>{usage.quotas.team_seats.percentage}%</span>
                </div>
                <div className="text-xl font-bold text-white font-mono">
                  {usage.quotas.team_seats.used} <span className="text-xs text-zinc-500 font-normal">/ {usage.quotas.team_seats.limit}</span>
                </div>
                <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-cyan-500 h-full rounded-full transition-all"
                    style={{ width: `${usage.quotas.team_seats.percentage}%` }}
                  />
                </div>
                <div className="text-[11px] text-zinc-500">
                  Collaborator seats
                </div>
              </div>
            </div>
          )}

          {/* Billing Controls Bar */}
          <div className="flex flex-col sm:flex-row items-center justify-between bg-zinc-900 border border-zinc-800 rounded-xl p-4 gap-4">
            <div className="flex items-center gap-3">
              <span className="text-xs uppercase font-mono tracking-wider text-zinc-400">Billing Cycle:</span>
              <div className="flex items-center p-1 bg-black rounded-lg border border-zinc-800">
                <button
                  onClick={() => setBillingCycle('monthly')}
                  className={`px-3 py-1 rounded text-xs font-medium transition-all ${
                    billingCycle === 'monthly' ? 'bg-zinc-800 text-white shadow' : 'text-zinc-400 hover:text-white'
                  }`}
                >
                  Monthly
                </button>
                <button
                  onClick={() => setBillingCycle('yearly')}
                  className={`px-3 py-1 rounded text-xs font-medium transition-all flex items-center gap-1 ${
                    billingCycle === 'yearly' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'text-zinc-400 hover:text-white'
                  }`}
                >
                  Yearly <span className="text-[10px] bg-emerald-500 text-black font-bold px-1 rounded">Save 20%</span>
                </button>
              </div>
            </div>

            <div className="flex items-center gap-3">
              <button
                onClick={handleOpenBillingPortal}
                className="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold rounded-lg border border-zinc-700 flex items-center gap-2 transition-all"
              >
                <ExternalLink className="w-3.5 h-3.5" />
                Stripe Customer Portal
              </button>
              <button
                onClick={loadBillingData}
                disabled={isLoadingBilling}
                className="p-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-400 hover:text-white rounded-lg border border-zinc-700 transition-all"
              >
                <RefreshCw className={`w-4 h-4 ${isLoadingBilling ? 'animate-spin' : ''}`} />
              </button>
            </div>
          </div>

          {/* Plans Grid */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {plans.filter(p => p.id !== 'agency_internal').map((plan) => {
              const isCurrent = plan.id === currentPlanId;
              const price = billingCycle === 'yearly' ? Math.round(plan.price_yearly / 12) : plan.price_monthly;

              return (
                <div
                  key={plan.id}
                  className={`relative rounded-xl border p-6 flex flex-col justify-between transition-all ${
                    isCurrent
                      ? 'bg-zinc-900/90 border-emerald-500 shadow-xl shadow-emerald-500/5 ring-1 ring-emerald-500/30'
                      : 'bg-zinc-950/70 border-zinc-800 hover:border-zinc-700'
                  }`}
                >
                  {isCurrent && (
                    <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-emerald-500 text-black text-[11px] font-bold uppercase tracking-wider px-3 py-0.5 rounded-full shadow-lg">
                      Current Plan
                    </div>
                  )}

                  <div>
                    <h3 className="text-xl font-bold text-white">{plan.name}</h3>
                    <p className="text-xs text-zinc-400 mt-1 min-h-[32px]">{plan.description}</p>

                    <div className="mt-4 mb-6">
                      <div className="flex items-baseline gap-1">
                        <span className="text-3xl font-extrabold text-white font-mono">${price}</span>
                        <span className="text-xs text-zinc-500">/ month</span>
                      </div>
                      {billingCycle === 'yearly' && (
                        <div className="text-[11px] text-emerald-400 mt-1">
                          Billed ${plan.price_yearly} annually
                        </div>
                      )}
                    </div>

                    <div className="space-y-2.5 border-t border-zinc-800 pt-4">
                      {plan.features.map((feat, i) => (
                        <div key={i} className="flex items-start gap-2 text-xs text-zinc-300">
                          <Check className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                          <span>{feat}</span>
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="mt-8">
                    {isCurrent ? (
                      <button
                        disabled
                        className="w-full py-2.5 px-4 bg-zinc-800 text-zinc-400 text-xs font-semibold rounded-lg cursor-not-allowed border border-zinc-700"
                      >
                        Active Plan
                      </button>
                    ) : (
                      <button
                        onClick={() => handleUpgradePlan(plan.id)}
                        disabled={isCheckoutLoading === plan.id}
                        className="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-black text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/20"
                      >
                        {isCheckoutLoading === plan.id ? (
                          <RefreshCw className="w-4 h-4 animate-spin" />
                        ) : (
                          <>
                            Upgrade to {plan.name} <ArrowUpRight className="w-4 h-4" />
                          </>
                        )}
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* TAB 2: CLIENT WORKSPACES & APPROVALS */}
      {activeTab === 'clients' && (
        <div className="space-y-6">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-zinc-900 border border-zinc-800 rounded-xl p-4">
            <div>
              <h2 className="text-base font-bold text-white">Agency Client Workspaces</h2>
              <p className="text-xs text-zinc-400">
                Organize client websites into dedicated workspaces and issue external sign-off requests.
              </p>
            </div>
            <div className="flex items-center gap-3">
              <button
                onClick={() => setIsWorkspaceModalOpen(true)}
                className="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 text-black text-xs font-bold rounded-lg flex items-center gap-1.5 transition-all"
              >
                <Plus className="w-4 h-4" /> New Client Workspace
              </button>
              <button
                onClick={() => setIsApprovalModalOpen(true)}
                className="px-3.5 py-2 bg-zinc-800 hover:bg-zinc-700 text-white text-xs font-semibold rounded-lg border border-zinc-700 flex items-center gap-1.5 transition-all"
              >
                <Shield className="w-4 h-4 text-emerald-400" /> Issue Client Sign-Off Link
              </button>
            </div>
          </div>

          {/* Workspaces List */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {workspaces.length === 0 ? (
              <div className="col-span-2 p-8 text-center bg-zinc-900/50 border border-zinc-800 rounded-xl text-zinc-500 text-sm">
                No client workspaces configured yet. Create one to group client websites.
              </div>
            ) : (
              workspaces.map((ws) => (
                <div key={ws.id} className="bg-zinc-900 border border-zinc-800 rounded-xl p-5 space-y-4">
                  <div className="flex items-start justify-between">
                    <div>
                      <h3 className="text-base font-bold text-white flex items-center gap-2">
                        <Building2 className="w-4 h-4 text-emerald-400" />
                        {ws.name}
                      </h3>
                      {ws.company_name && (
                        <div className="text-xs text-zinc-400 mt-0.5">{ws.company_name}</div>
                      )}
                    </div>
                    <span className="text-[11px] px-2 py-0.5 rounded bg-zinc-800 text-zinc-400 border border-zinc-700">
                      {ws.websites?.length || 0} Websites
                    </span>
                  </div>

                  <div className="grid grid-cols-2 gap-2 text-xs text-zinc-400 bg-black/40 p-3 rounded-lg border border-zinc-800/80">
                    <div>
                      <span className="text-zinc-500">Contact:</span> {ws.contact_person || 'N/A'}
                    </div>
                    <div>
                      <span className="text-zinc-500">Email:</span> {ws.client_email || 'N/A'}
                    </div>
                  </div>

                  {ws.websites && ws.websites.length > 0 && (
                    <div className="space-y-1.5">
                      <div className="text-xs font-mono text-zinc-400 uppercase tracking-wider">Assigned Websites:</div>
                      <div className="flex flex-wrap gap-1.5">
                        {ws.websites.map(site => (
                          <span key={site.id} className="text-xs px-2.5 py-1 rounded bg-zinc-800 text-zinc-200 border border-zinc-700 flex items-center gap-1.5">
                            <Globe className="w-3 h-3 text-emerald-400" />
                            {site.name} ({site.domain})
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              ))
            )}
          </div>

          {/* Pending Approval Requests */}
          <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-5 space-y-4">
            <h3 className="text-base font-bold text-white flex items-center gap-2">
              <Shield className="w-4 h-4 text-emerald-400" />
              Client Approval Requests (Sign-Off Records)
            </h3>

            {approvalRequests.length === 0 ? (
              <div className="p-6 text-center text-zinc-500 text-xs">
                No client approval sign-off requests created yet.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-zinc-800/50 text-zinc-400 uppercase font-mono border-b border-zinc-800">
                    <tr>
                      <th className="p-3">Title & Request</th>
                      <th className="p-3">Website</th>
                      <th className="p-3">Approver Email</th>
                      <th className="p-3">Status</th>
                      <th className="p-3">Public Secure Link</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/60 text-zinc-300">
                    {approvalRequests.map(req => {
                      const publicUrl = `${typeof window !== 'undefined' ? window.location.origin : ''}/client-approval/${req.token}`;
                      return (
                        <tr key={req.id} className="hover:bg-zinc-800/30">
                          <td className="p-3 font-medium text-white">{req.title}</td>
                          <td className="p-3 text-zinc-400">{req.website?.domain || '—'}</td>
                          <td className="p-3 font-mono">{req.approver_email}</td>
                          <td className="p-3">
                            <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                              req.status === 'approved'
                                ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                                : req.status === 'rejected'
                                ? 'bg-red-500/20 text-red-400 border border-red-500/30'
                                : 'bg-amber-500/20 text-amber-400 border border-amber-500/30'
                            }`}>
                              {req.status}
                            </span>
                          </td>
                          <td className="p-3">
                            <button
                              onClick={() => copyToClipboard(publicUrl)}
                              className="px-2.5 py-1 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded border border-zinc-700 flex items-center gap-1.5 transition-all text-xs"
                            >
                              <Copy className="w-3 h-3" /> Copy Link
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* TAB 3: WHITE-LABEL AGENCY BRANDING */}
      {activeTab === 'whitelabel' && (
        <div className="space-y-6">
          <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <Palette className="w-4 h-4 text-emerald-400" />
              Agency White-Label Branding Settings
            </h2>
            <p className="text-xs text-zinc-400 mt-1 mb-6">
              Customize exported reports and client portal links with your agency's name, custom logo, theme colors, and report footer.
            </p>

            <form onSubmit={handleSaveBranding} className="space-y-5 max-w-2xl">
              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase tracking-wider mb-1.5">
                  Agency Brand Name
                </label>
                <input
                  type="text"
                  required
                  value={branding.brand_name}
                  onChange={(e) => setBranding({ ...branding, brand_name: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white focus:outline-none focus:border-emerald-500 font-medium"
                  placeholder="e.g. Apex Growth Partners"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase tracking-wider mb-1.5">
                  Logo URL (PNG or SVG)
                </label>
                <input
                  type="url"
                  value={branding.logo_url || ''}
                  onChange={(e) => setBranding({ ...branding, logo_url: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white focus:outline-none focus:border-emerald-500 font-mono"
                  placeholder="https://example.com/logo.png"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 uppercase tracking-wider mb-1.5">
                    Primary Accent Color (Hex)
                  </label>
                  <div className="flex items-center gap-2">
                    <input
                      type="color"
                      value={branding.primary_color}
                      onChange={(e) => setBranding({ ...branding, primary_color: e.target.value })}
                      className="w-9 h-9 rounded border border-zinc-700 bg-black cursor-pointer p-0.5"
                    />
                    <input
                      type="text"
                      value={branding.primary_color}
                      onChange={(e) => setBranding({ ...branding, primary_color: e.target.value })}
                      className="w-full bg-black border border-zinc-800 rounded-lg p-2 text-xs text-white font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-zinc-300 uppercase tracking-wider mb-1.5">
                    Support / Contact Email
                  </label>
                  <input
                    type="email"
                    value={branding.contact_email || ''}
                    onChange={(e) => setBranding({ ...branding, contact_email: e.target.value })}
                    className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white font-mono"
                    placeholder="seo@agency.com"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase tracking-wider mb-1.5">
                  Custom Report Footer Text
                </label>
                <input
                  type="text"
                  value={branding.footer_text}
                  onChange={(e) => setBranding({ ...branding, footer_text: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                  placeholder="Confidential SEO Strategy & Technical Audit Report"
                />
              </div>

              <div className="flex items-center gap-3 pt-2">
                <input
                  type="checkbox"
                  id="removeBadges"
                  checked={branding.remove_platform_branding}
                  onChange={(e) => setBranding({ ...branding, remove_platform_branding: e.target.checked })}
                  className="w-4 h-4 rounded border-zinc-800 text-emerald-500 focus:ring-0 bg-black"
                />
                <label htmlFor="removeBadges" className="text-xs text-zinc-300 select-none">
                  Remove Web SEO AI platform badges from client-facing exports (Agency Pro / Enterprise required)
                </label>
              </div>

              <div className="flex items-center gap-3 pt-4 border-t border-zinc-800">
                <button
                  type="submit"
                  disabled={isSavingBranding}
                  className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-black font-bold text-xs rounded-lg transition-all flex items-center gap-2"
                >
                  {isSavingBranding && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  Save Branding Configuration
                </button>

                <button
                  type="button"
                  onClick={handleGenerateReport}
                  disabled={isGeneratingReport || !selectedWebsite}
                  className="px-5 py-2.5 bg-zinc-800 hover:bg-zinc-700 text-white font-semibold text-xs rounded-lg border border-zinc-700 transition-all flex items-center gap-2"
                >
                  {isGeneratingReport ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <FileText className="w-3.5 h-3.5 text-emerald-400" />}
                  Generate White-Label Audit Report
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* TAB 4: LAUNCH SECURITY & PURGE */}
      {activeTab === 'security' && (
        <div className="space-y-6">
          <div className="bg-zinc-900 border border-zinc-800 rounded-xl p-6 space-y-4">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <Shield className="w-4 h-4 text-emerald-400" />
              SaaS Public Launch Security & Data Governance
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="bg-black/60 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="text-xs font-semibold text-white">Public Registration Status</div>
                <div className="text-xs text-zinc-400">
                  Public self-registration is strictly gated. Organizations must be provisioned internally or through authenticated admin invitations.
                </div>
                <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded bg-amber-500/20 text-amber-400 text-[11px] font-mono border border-amber-500/30">
                  <Lock className="w-3 h-3" /> Invite-Only Deployment Mode
                </div>
              </div>

              <div className="bg-black/60 border border-zinc-800 rounded-xl p-4 space-y-2">
                <div className="text-xs font-semibold text-white">Multi-Tenant Isolation Integrity</div>
                <div className="text-xs text-zinc-400">
                  Every API request, audit run, CMS modification, and crawler job is strictly scoped to the authenticated organization. Cross-organization access is rejected with HTTP 403/404.
                </div>
                <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded bg-emerald-500/20 text-emerald-400 text-[11px] font-mono border border-emerald-500/30">
                  <CheckCircle className="w-3 h-3" /> Tenant Isolation Verified
                </div>
              </div>
            </div>
          </div>

          {/* Danger Zone: Account & Organization Deletion */}
          <div className="bg-red-950/20 border border-red-900/50 rounded-xl p-6 space-y-4">
            <div className="flex items-center gap-2 text-red-400 font-bold text-sm">
              <AlertTriangle className="w-5 h-5" /> Danger Zone: Permanent Organization & Tenant Data Deletion
            </div>
            <p className="text-xs text-zinc-400 leading-relaxed">
              Permanently delete this organization, all connected websites, crawler snapshots, search performance analytics, AI recommendations, CMS credentials, and billing subscriptions. This action is irreversible and compliant with GDPR Article 17 right-to-erasure.
            </p>
            <button
              onClick={() => setIsDeleteOrgModalOpen(true)}
              className="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-xs font-bold rounded-lg transition-all flex items-center gap-2"
            >
              <Trash2 className="w-4 h-4" /> Permanently Delete Organization
            </button>
          </div>
        </div>
      )}

      {/* MODAL 1: Create Client Workspace */}
      {isWorkspaceModalOpen && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-zinc-900 border border-zinc-800 rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-bold text-white">Create Client Workspace</h3>
              <button onClick={() => setIsWorkspaceModalOpen(false)} className="text-zinc-500 hover:text-white">
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateWorkspace} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Client Workspace Name</label>
                <input
                  type="text"
                  required
                  value={workspaceForm.name}
                  onChange={(e) => setWorkspaceForm({ ...workspaceForm, name: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                  placeholder="e.g. Acme Corporation"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Legal Company Name</label>
                <input
                  type="text"
                  value={workspaceForm.company_name}
                  onChange={(e) => setWorkspaceForm({ ...workspaceForm, company_name: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                  placeholder="e.g. Acme Holdings Inc."
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Contact Person</label>
                  <input
                    type="text"
                    value={workspaceForm.contact_person}
                    onChange={(e) => setWorkspaceForm({ ...workspaceForm, contact_person: e.target.value })}
                    className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                    placeholder="Jane Doe"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Client Email</label>
                  <input
                    type="email"
                    value={workspaceForm.client_email}
                    onChange={(e) => setWorkspaceForm({ ...workspaceForm, client_email: e.target.value })}
                    className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white font-mono"
                    placeholder="client@acme.com"
                  />
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsWorkspaceModalOpen(false)}
                  className="px-4 py-2 bg-zinc-800 text-zinc-400 text-xs rounded-lg hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-black font-bold text-xs rounded-lg"
                >
                  Create Workspace
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL 2: Create Client Approval Request */}
      {isApprovalModalOpen && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-zinc-900 border border-zinc-800 rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-bold text-white">Create Client Sign-Off Link</h3>
              <button onClick={() => setIsApprovalModalOpen(false)} className="text-zinc-500 hover:text-white">
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateApproval} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Approver Email</label>
                <input
                  type="email"
                  required
                  value={approvalForm.approver_email}
                  onChange={(e) => setApprovalForm({ ...approvalForm, approver_email: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white font-mono"
                  placeholder="client-decision-maker@company.com"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Request Title</label>
                <input
                  type="text"
                  required
                  value={approvalForm.title}
                  onChange={(e) => setApprovalForm({ ...approvalForm, title: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                  placeholder="e.g. Q4 SEO Strategy & Metadata Redesign"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-zinc-300 uppercase mb-1">Description / Summary</label>
                <textarea
                  rows={3}
                  value={approvalForm.description}
                  onChange={(e) => setApprovalForm({ ...approvalForm, description: e.target.value })}
                  className="w-full bg-black border border-zinc-800 rounded-lg p-2.5 text-xs text-white"
                  placeholder="Briefly describe what the client is approving..."
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsApprovalModalOpen(false)}
                  className="px-4 py-2 bg-zinc-800 text-zinc-400 text-xs rounded-lg hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-black font-bold text-xs rounded-lg"
                >
                  Generate Link
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL 3: White-Label Report Preview */}
      {isReportModalOpen && generatedReport && (
        <div className="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-3xl w-full p-8 max-h-[90vh] overflow-y-auto space-y-6 shadow-2xl">
            {/* Header with Agency Branding */}
            <div className="flex items-center justify-between border-b border-zinc-800 pb-5">
              <div className="flex items-center gap-3">
                {generatedReport.branding.agency_logo_url ? (
                  <img src={generatedReport.branding.agency_logo_url} alt="Logo" className="w-10 h-10 object-contain rounded" />
                ) : (
                  <div
                    className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-black"
                    style={{ backgroundColor: generatedReport.branding.theme_color }}
                  >
                    SEO
                  </div>
                )}
                <div>
                  <h3 className="text-xl font-bold text-white">{generatedReport.branding.agency_name}</h3>
                  <div className="text-xs text-zinc-400">SEO Strategy & Audit Report • ID: {generatedReport.report_id}</div>
                </div>
              </div>

              <button onClick={() => setIsReportModalOpen(false)} className="text-zinc-500 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Target Site & Audit Summary */}
            <div className="grid grid-cols-3 gap-4">
              <div className="bg-zinc-900/60 p-4 rounded-xl border border-zinc-800">
                <div className="text-xs text-zinc-400">Target Website</div>
                <div className="text-base font-bold text-white mt-1">{generatedReport.website.domain}</div>
              </div>
              <div className="bg-zinc-900/60 p-4 rounded-xl border border-zinc-800">
                <div className="text-xs text-zinc-400">Health Score</div>
                <div className="text-base font-bold text-emerald-400 mt-1">{generatedReport.website.health_score} / 100</div>
              </div>
              <div className="bg-zinc-900/60 p-4 rounded-xl border border-zinc-800">
                <div className="text-xs text-zinc-400">Critical Issues</div>
                <div className="text-base font-bold text-red-400 mt-1">{generatedReport.audit_summary.critical_issues} Open</div>
              </div>
            </div>

            {/* Top Audit Findings */}
            <div className="space-y-3">
              <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">Mechanical Audit Findings</h4>
              <div className="space-y-2">
                {generatedReport.audit_summary.top_issues?.slice(0, 5).map((iss: any) => (
                  <div key={iss.id} className="p-3 bg-zinc-900/40 rounded-lg border border-zinc-800 flex items-start justify-between text-xs">
                    <div>
                      <div className="font-semibold text-white">{iss.title}</div>
                      <div className="text-zinc-400 text-[11px] mt-0.5">{iss.description}</div>
                    </div>
                    <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-red-500/20 text-red-400">
                      {iss.severity}
                    </span>
                  </div>
                ))}
              </div>
            </div>

            {/* Strategic Recommendations */}
            {generatedReport.strategic_recommendations?.length > 0 && (
              <div className="space-y-3">
                <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">Strategic Recommendations</h4>
                <div className="space-y-2">
                  {generatedReport.strategic_recommendations.map((rec: any) => (
                    <div key={rec.id} className="p-3 bg-zinc-900/40 rounded-lg border border-zinc-800 flex items-center justify-between text-xs">
                      <div>
                        <div className="font-medium text-white">{rec.title}</div>
                        <div className="text-zinc-400 text-[11px] mt-0.5">{rec.action}</div>
                      </div>
                      <span className="text-emerald-400 font-mono text-[11px]">ICE {rec.ice_score}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Footer */}
            <div className="text-center text-[11px] text-zinc-500 border-t border-zinc-800 pt-4">
              {generatedReport.branding.footer_text}
            </div>
          </div>
        </div>
      )}

      {/* MODAL 4: Delete Organization Confirmation */}
      {isDeleteOrgModalOpen && (
        <div className="fixed inset-0 bg-black/90 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-zinc-950 border border-red-900/60 rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center gap-3 text-red-400 font-bold">
              <AlertTriangle className="w-6 h-6" />
              <span>Confirm Organization Deletion</span>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              This action will permanently delete all websites, audit logs, crawl data, and subscriptions for{' '}
              <strong className="text-white">{currentOrganization?.name}</strong>.
            </p>

            <form onSubmit={handleDeleteOrganization} className="space-y-4">
              <div>
                <label className="block text-xs text-zinc-400 mb-1">
                  Type <span className="text-white font-mono font-bold">{currentOrganization?.name}</span> to confirm:
                </label>
                <input
                  type="text"
                  required
                  value={deleteConfirmation}
                  onChange={(e) => setDeleteConfirmation(e.target.value)}
                  className="w-full bg-black border border-red-900/50 rounded-lg p-2.5 text-xs text-white font-mono"
                  placeholder={currentOrganization?.name}
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsDeleteOrgModalOpen(false)}
                  className="px-4 py-2 bg-zinc-800 text-zinc-400 text-xs rounded-lg hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isDeletingOrg || deleteConfirmation !== currentOrganization?.name}
                  className="px-4 py-2 bg-red-600 hover:bg-red-500 disabled:opacity-40 text-white font-bold text-xs rounded-lg transition-all"
                >
                  {isDeletingOrg ? 'Deleting...' : 'Permanently Delete'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
