'use client';

import React, { useState, useEffect, useCallback } from 'react';
import { X, Key, ShieldCheck, CheckCircle2, AlertCircle, RefreshCw, Unlink, Lock } from 'lucide-react';
import { api } from '@/lib/api';
import { Website, WebsiteIntegration } from '@/types';
import { useToast } from '@/context/ToastContext';

interface IntegrationsModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
  onRefreshWebsite: () => void;
}

export function IntegrationsModal({
  website,
  isOpen,
  onClose,
  onRefreshWebsite,
}: IntegrationsModalProps) {
  const [integrations, setIntegrations] = useState<WebsiteIntegration[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [testingProvider, setTestingProvider] = useState<string | null>(null);
  const [savingProvider, setSavingProvider] = useState<string | null>(null);

  // WordPress credentials state
  const [wpUsername, setWpUsername] = useState('');
  const [wpAppPassword, setWpAppPassword] = useState('');

  // Shopify credentials state
  const [shopifyDomain, setShopifyDomain] = useState('');
  const [shopifyAccessToken, setShopifyAccessToken] = useState('');

  const toast = useToast();

  const loadIntegrations = useCallback(async () => {
    if (!website) return;
    setIsLoading(true);
    try {
      const res = await api.get<WebsiteIntegration[]>(`/websites/${website.id}/integrations`);
      if (res.success && res.data) {
        setIntegrations(res.data);
      }
    } finally {
      setIsLoading(false);
    }
  }, [website]);

  useEffect(() => {
    if (isOpen && website) {
      loadIntegrations();
    }
  }, [isOpen, website, loadIntegrations]);

  if (!isOpen || !website) return null;

  const wpIntegration = integrations.find((i) => i.provider === 'wordpress_rest');
  const shopifyIntegration = integrations.find((i) => i.provider === 'shopify_graphql');

  const handleSaveWordPress = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website.verified_at) {
      toast.error('Website ownership must be verified before connecting write integrations.');
      return;
    }

    setSavingProvider('wordpress_rest');
    try {
      const res = await api.post(`/websites/${website.id}/integrations`, {
        provider: 'wordpress_rest',
        credentials: {
          username: wpUsername,
          application_password: wpAppPassword,
        },
      });

      if (res.success) {
        toast.success('WordPress credentials encrypted and stored!');
        setWpAppPassword(''); // Clear input from state
        await loadIntegrations();
        onRefreshWebsite();
      } else {
        toast.error(res.error?.message || 'Failed to save WordPress integration.');
      }
    } finally {
      setSavingProvider(null);
    }
  };

  const handleSaveShopify = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!website.verified_at) {
      toast.error('Website ownership must be verified before connecting write integrations.');
      return;
    }

    setSavingProvider('shopify_graphql');
    try {
      const res = await api.post(`/websites/${website.id}/integrations`, {
        provider: 'shopify_graphql',
        credentials: {
          shop_domain: shopifyDomain,
          access_token: shopifyAccessToken,
        },
      });

      if (res.success) {
        toast.success('Shopify access token encrypted and stored!');
        setShopifyAccessToken(''); // Clear input from state
        await loadIntegrations();
        onRefreshWebsite();
      } else {
        toast.error(res.error?.message || 'Failed to save Shopify integration.');
      }
    } finally {
      setSavingProvider(null);
    }
  };

  const handleTestConnection = async (provider: string) => {
    setTestingProvider(provider);
    try {
      const res = await api.post<{
        connected: boolean;
        status: string;
        last_error?: string;
      }>(`/websites/${website.id}/integrations/${provider}/test`);

      if (res.success && res.data?.connected) {
        toast.success('Live connection handshake succeeded!');
      } else {
        toast.error(res.data?.last_error || 'Connection failed.');
      }
      await loadIntegrations();
      onRefreshWebsite();
    } finally {
      setTestingProvider(null);
    }
  };

  const handleDisconnect = async (provider: string) => {
    if (!confirm('Are you sure you want to disconnect and delete stored credentials for this integration?')) {
      return;
    }

    try {
      const res = await api.delete(`/websites/${website.id}/integrations/${provider}`);
      if (res.success) {
        toast.info('Integration disconnected and credentials wiped.');
        await loadIntegrations();
        onRefreshWebsite();
      }
    } catch {
      toast.error('Failed to disconnect integration.');
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in">
      <div className="w-full max-w-2xl bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-zinc-800">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-emerald-950/40 border border-emerald-800/40 rounded-xl text-emerald-400">
              <Key className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-zinc-100">CMS & Platform Integrations</h3>
              <p className="text-xs text-zinc-400 mt-0.5">{website.name} ({website.domain})</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1 text-zinc-400 hover:text-zinc-200 rounded-lg hover:bg-zinc-900 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-6 overflow-y-auto">
          {!website.verified_at && (
            <div className="p-3.5 bg-rose-950/20 border border-rose-800/40 rounded-xl text-xs text-rose-300 flex items-center gap-2.5">
              <Lock className="w-4 h-4 shrink-0 text-rose-400" />
              <span>
                <strong>Ownership Unverified:</strong> You must complete website ownership verification before write integrations can be enabled.
              </span>
            </div>
          )}

          {/* WordPress Integration Card */}
          <div className="p-5 bg-zinc-900/60 border border-zinc-800 rounded-2xl space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <h4 className="text-sm font-semibold text-zinc-100 flex items-center gap-2">
                  WordPress REST API
                  <span
                    className={`text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full ${
                      wpIntegration?.status === 'connected'
                        ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/50'
                        : wpIntegration?.status === 'failed'
                        ? 'bg-rose-950 text-rose-400 border border-rose-800/50'
                        : 'bg-zinc-800 text-zinc-400 border border-zinc-700'
                    }`}
                  >
                    {wpIntegration?.status || 'disconnected'}
                  </span>
                </h4>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Connect via WordPress Application Passwords to automatically update meta tags and publish posts.
                </p>
              </div>

              {wpIntegration && (
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => handleTestConnection('wordpress_rest')}
                    disabled={testingProvider === 'wordpress_rest'}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${testingProvider === 'wordpress_rest' ? 'animate-spin' : ''}`} />
                    Test Connection
                  </button>
                  <button
                    onClick={() => handleDisconnect('wordpress_rest')}
                    className="p-1.5 text-zinc-400 hover:text-rose-400 hover:bg-zinc-800 rounded-lg transition"
                    title="Disconnect"
                  >
                    <Unlink className="w-4 h-4" />
                  </button>
                </div>
              )}
            </div>

            {wpIntegration?.last_error && (
              <div className="p-2.5 bg-rose-950/40 border border-rose-900 text-[11px] text-rose-300 rounded-lg">
                Last Error: {wpIntegration.last_error}
              </div>
            )}

            {/* Credential form */}
            <form onSubmit={handleSaveWordPress} className="space-y-3 pt-2">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-medium text-zinc-400 mb-1">WP Username</label>
                  <input
                    type="text"
                    required
                    value={wpUsername}
                    onChange={(e) => setWpUsername(e.target.value)}
                    placeholder="admin_seo"
                    disabled={!website.verified_at}
                    className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
                  />
                </div>
                <div>
                  <label className="block text-[11px] font-medium text-zinc-400 mb-1">Application Password</label>
                  <input
                    type="password"
                    required
                    value={wpAppPassword}
                    onChange={(e) => setWpAppPassword(e.target.value)}
                    placeholder="xxxx xxxx xxxx xxxx"
                    disabled={!website.verified_at}
                    className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
                  />
                </div>
              </div>
              <div className="flex justify-end">
                <button
                  type="submit"
                  disabled={savingProvider === 'wordpress_rest' || !website.verified_at}
                  className="px-4 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-lg transition"
                >
                  {savingProvider === 'wordpress_rest' ? 'Saving...' : 'Save WP Credentials'}
                </button>
              </div>
            </form>
          </div>

          {/* Shopify Integration Card */}
          <div className="p-5 bg-zinc-900/60 border border-zinc-800 rounded-2xl space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <h4 className="text-sm font-semibold text-zinc-100 flex items-center gap-2">
                  Shopify Admin GraphQL
                  <span
                    className={`text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full ${
                      shopifyIntegration?.status === 'connected'
                        ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/50'
                        : shopifyIntegration?.status === 'failed'
                        ? 'bg-rose-950 text-rose-400 border border-rose-800/50'
                        : 'bg-zinc-800 text-zinc-400 border border-zinc-700'
                    }`}
                  >
                    {shopifyIntegration?.status || 'disconnected'}
                  </span>
                </h4>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Update product titles, collection descriptions, and blog posts via Shopify Admin API.
                </p>
              </div>

              {shopifyIntegration && (
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => handleTestConnection('shopify_graphql')}
                    disabled={testingProvider === 'shopify_graphql'}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${testingProvider === 'shopify_graphql' ? 'animate-spin' : ''}`} />
                    Test Connection
                  </button>
                  <button
                    onClick={() => handleDisconnect('shopify_graphql')}
                    className="p-1.5 text-zinc-400 hover:text-rose-400 hover:bg-zinc-800 rounded-lg transition"
                    title="Disconnect"
                  >
                    <Unlink className="w-4 h-4" />
                  </button>
                </div>
              )}
            </div>

            {shopifyIntegration?.last_error && (
              <div className="p-2.5 bg-rose-950/40 border border-rose-900 text-[11px] text-rose-300 rounded-lg">
                Last Error: {shopifyIntegration.last_error}
              </div>
            )}

            {/* Credential form */}
            <form onSubmit={handleSaveShopify} className="space-y-3 pt-2">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-medium text-zinc-400 mb-1">Shop Domain</label>
                  <input
                    type="text"
                    required
                    value={shopifyDomain}
                    onChange={(e) => setShopifyDomain(e.target.value)}
                    placeholder="my-store.myshopify.com"
                    disabled={!website.verified_at}
                    className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
                  />
                </div>
                <div>
                  <label className="block text-[11px] font-medium text-zinc-400 mb-1">Admin Access Token</label>
                  <input
                    type="password"
                    required
                    value={shopifyAccessToken}
                    onChange={(e) => setShopifyAccessToken(e.target.value)}
                    placeholder="shpat_xxxxxxxxxxxxxxxx"
                    disabled={!website.verified_at}
                    className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
                  />
                </div>
              </div>
              <div className="flex justify-end">
                <button
                  type="submit"
                  disabled={savingProvider === 'shopify_graphql' || !website.verified_at}
                  className="px-4 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-lg transition"
                >
                  {savingProvider === 'shopify_graphql' ? 'Saving...' : 'Save Shopify Credentials'}
                </button>
              </div>
            </form>
          </div>
        </div>

        {/* Footer */}
        <div className="p-4 border-t border-zinc-800 bg-zinc-950/90 flex justify-end">
          <button
            onClick={onClose}
            className="px-4 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 hover:bg-zinc-800 rounded-xl transition"
          >
            Done
          </button>
        </div>
      </div>
    </div>
  );
}
