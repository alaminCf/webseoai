'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  X,
  Key,
  RefreshCw,
  Unlink,
  Lock,
  Webhook,
  Code2,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
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

  // Custom Website REST / Webhook credentials state
  const [customEndpointUrl, setCustomEndpointUrl] = useState('');
  const [customApiKey, setCustomApiKey] = useState('');
  const [customAuthHeader, setCustomAuthHeader] = useState('Authorization');
  const [customWebhookSecret, setCustomWebhookSecret] = useState('');
  const [showWebhookSpec, setShowWebhookSpec] = useState(false);

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
        const existingCustom = res.data.find((i) => i.provider === 'custom_webhook');
        if (existingCustom?.metadata) {
          if (typeof existingCustom.metadata.endpoint_url === 'string') {
            setCustomEndpointUrl(existingCustom.metadata.endpoint_url);
          }
          if (typeof existingCustom.metadata.auth_header === 'string') {
            setCustomAuthHeader(existingCustom.metadata.auth_header);
          }
        }
      }
    } finally {
      setIsLoading(false);
    }
  }, [website]);

  useEffect(() => {
    if (isOpen && website) {
      setCustomEndpointUrl(`https://${website.domain}/api/seo-webhook`);
      setCustomAuthHeader('Authorization');
      setCustomApiKey('');
      setCustomWebhookSecret('');
      setShopifyDomain(website.domain);
      loadIntegrations();
    }
  }, [isOpen, website, loadIntegrations]);

  if (!isOpen || !website) return null;

  const isVerified = website.verification_status === 'verified' || Boolean(website.verified_at);
  const customIntegration = integrations.find((i) => i.provider === 'custom_webhook');
  const wpIntegration = integrations.find((i) => i.provider === 'wordpress_rest');
  const shopifyIntegration = integrations.find((i) => i.provider === 'shopify_graphql');

  const handleSaveCustomWebhook = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isVerified) {
      toast.error('Website ownership must be verified before connecting write integrations.');
      return;
    }

    setSavingProvider('custom_webhook');
    try {
      const res = await api.post(`/websites/${website.id}/integrations`, {
        provider: 'custom_webhook',
        credentials: {
          endpoint_url: customEndpointUrl.trim(),
          api_key: customApiKey.trim(),
          auth_header: customAuthHeader.trim() || 'Authorization',
          ...(customWebhookSecret.trim() ? { webhook_secret: customWebhookSecret.trim() } : {}),
        },
      });

      if (res.success) {
        toast.success('Custom Website Webhook credentials encrypted and stored!');
        setCustomApiKey('');
        setCustomWebhookSecret('');
        await loadIntegrations();
        onRefreshWebsite();
      } else {
        toast.error(res.error?.message || 'Failed to save Custom Website integration.');
      }
    } finally {
      setSavingProvider(null);
    }
  };

  const handleSaveWordPress = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isVerified) {
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
        setWpAppPassword('');
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
    if (!isVerified) {
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
        setShopifyAccessToken('');
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
        toast.error(res.data?.last_error || res.error?.message || 'Connection failed.');
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

  const statusBadge = (status?: string) => (
    <span
      className={`text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full ${
        status === 'connected'
          ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/50'
          : status === 'failed'
          ? 'bg-rose-950 text-rose-400 border border-rose-800/50'
          : status === 'pending'
          ? 'bg-amber-950 text-amber-400 border border-amber-800/50'
          : 'bg-zinc-800 text-zinc-400 border border-zinc-700'
      }`}
    >
      {status || 'disconnected'}
    </span>
  );

  const customCard = (
    <div
      key="custom_webhook"
      className={`p-5 rounded-2xl space-y-4 border ${
        website.platform === 'custom'
          ? 'bg-emerald-950/10 border-emerald-800/50'
          : 'bg-zinc-900/60 border-zinc-800'
      }`}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 className="text-sm font-semibold text-zinc-100 flex items-center gap-2 flex-wrap">
            <Webhook className="w-4 h-4 text-emerald-400" />
            Custom Website REST API / Webhook
            {statusBadge(customIntegration?.status)}
            {website.platform === 'custom' && (
              <span className="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                Active Platform
              </span>
            )}
          </h4>
          <p className="text-xs text-zinc-400 mt-1">
            Connect any Next.js, Laravel, React, Node.js, or custom backend via an authenticated webhook endpoint to push approved SEO titles, meta descriptions, and content updates.
          </p>
        </div>

        {customIntegration && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => handleTestConnection('custom_webhook')}
              disabled={testingProvider === 'custom_webhook'}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${testingProvider === 'custom_webhook' ? 'animate-spin' : ''}`} />
              Test Connection
            </button>
            <button
              type="button"
              onClick={() => handleDisconnect('custom_webhook')}
              className="p-1.5 text-zinc-400 hover:text-rose-400 hover:bg-zinc-800 rounded-lg transition"
              title="Disconnect"
            >
              <Unlink className="w-4 h-4" />
            </button>
          </div>
        )}
      </div>

      {customIntegration?.last_error && (
        <div className="p-2.5 bg-rose-950/40 border border-rose-900 text-[11px] text-rose-300 rounded-lg">
          Last Error: {customIntegration.last_error}
        </div>
      )}

      <form onSubmit={handleSaveCustomWebhook} className="space-y-3 pt-1">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">
              Webhook / API Endpoint URL *
            </label>
            <input
              type="url"
              required
              value={customEndpointUrl}
              onChange={(e) => setCustomEndpointUrl(e.target.value)}
              placeholder={`https://${website.domain}/api/seo-webhook`}
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">
              API Key / Bearer Token *
            </label>
            <input
              type="password"
              required
              value={customApiKey}
              onChange={(e) => setCustomApiKey(e.target.value)}
              placeholder="sk_live_xxxxxxxxxxxxxxxx"
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">
              Auth Header Name
            </label>
            <input
              type="text"
              value={customAuthHeader}
              onChange={(e) => setCustomAuthHeader(e.target.value)}
              placeholder="Authorization or X-API-Key"
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">
              HMAC Signing Secret (Optional)
            </label>
            <input
              type="password"
              value={customWebhookSecret}
              onChange={(e) => setCustomWebhookSecret(e.target.value)}
              placeholder="whsec_optional_signature_key"
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
          <button
            type="button"
            onClick={() => setShowWebhookSpec(!showWebhookSpec)}
            className="inline-flex items-center gap-1.5 text-[11px] text-zinc-400 hover:text-emerald-400 transition"
          >
            <Code2 className="w-3.5 h-3.5" />
            <span>View JSON Payload Spec</span>
            {showWebhookSpec ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
          </button>

          <button
            type="submit"
            disabled={savingProvider === 'custom_webhook' || !isVerified}
            className="px-4 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-lg transition"
          >
            {savingProvider === 'custom_webhook' ? 'Saving...' : 'Save Custom API Credentials'}
          </button>
        </div>

        {showWebhookSpec && (
          <pre className="p-3 bg-zinc-950 border border-zinc-800 rounded-xl text-[11px] font-mono text-zinc-300 overflow-x-auto leading-relaxed">
{`POST ${customEndpointUrl || `https://${website.domain}/api/seo-webhook`}
${customAuthHeader || 'Authorization'}: ${customAuthHeader.toLowerCase() === 'authorization' ? 'Bearer <API_KEY>' : '<API_KEY>'}
X-WebSEOAI-Signature: <HMAC-SHA256 hex if secret set>

{
  "event": "ping" | "update_metadata" | "update_content",
  "domain": "${website.domain}",
  "target_identifier": "/about",
  "metadata": { "meta_title": "...", "meta_description": "..." }
}`}
          </pre>
        )}
      </form>
    </div>
  );

  const wordpressCard = (
    <div
      key="wordpress_rest"
      className={`p-5 rounded-2xl space-y-4 border ${
        website.platform === 'wordpress'
          ? 'bg-emerald-950/10 border-emerald-800/50'
          : 'bg-zinc-900/60 border-zinc-800'
      }`}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 className="text-sm font-semibold text-zinc-100 flex items-center gap-2 flex-wrap">
            WordPress REST API
            {statusBadge(wpIntegration?.status)}
            {website.platform === 'wordpress' && (
              <span className="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                Active Platform
              </span>
            )}
          </h4>
          <p className="text-xs text-zinc-400 mt-0.5">
            Connect via WordPress Application Passwords to automatically update meta tags and publish posts.
          </p>
        </div>

        {wpIntegration && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => handleTestConnection('wordpress_rest')}
              disabled={testingProvider === 'wordpress_rest'}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${testingProvider === 'wordpress_rest' ? 'animate-spin' : ''}`} />
              Test Connection
            </button>
            <button
              type="button"
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

      <form onSubmit={handleSaveWordPress} className="space-y-3 pt-2">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">WP Username</label>
            <input
              type="text"
              required
              value={wpUsername}
              onChange={(e) => setWpUsername(e.target.value)}
              placeholder="admin_seo"
              disabled={!isVerified}
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
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
        </div>
        <div className="flex justify-end">
          <button
            type="submit"
            disabled={savingProvider === 'wordpress_rest' || !isVerified}
            className="px-4 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-lg transition"
          >
            {savingProvider === 'wordpress_rest' ? 'Saving...' : 'Save WP Credentials'}
          </button>
        </div>
      </form>
    </div>
  );

  const shopifyCard = (
    <div
      key="shopify_graphql"
      className={`p-5 rounded-2xl space-y-4 border ${
        website.platform === 'shopify'
          ? 'bg-emerald-950/10 border-emerald-800/50'
          : 'bg-zinc-900/60 border-zinc-800'
      }`}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h4 className="text-sm font-semibold text-zinc-100 flex items-center gap-2 flex-wrap">
            Shopify Admin GraphQL
            {statusBadge(shopifyIntegration?.status)}
            {website.platform === 'shopify' && (
              <span className="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                Active Platform
              </span>
            )}
          </h4>
          <p className="text-xs text-zinc-400 mt-0.5">
            Update product titles, collection descriptions, and blog posts via Shopify Admin API.
          </p>
        </div>

        {shopifyIntegration && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => handleTestConnection('shopify_graphql')}
              disabled={testingProvider === 'shopify_graphql'}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-200 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${testingProvider === 'shopify_graphql' ? 'animate-spin' : ''}`} />
              Test Connection
            </button>
            <button
              type="button"
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

      <form onSubmit={handleSaveShopify} className="space-y-3 pt-2">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className="block text-[11px] font-medium text-zinc-400 mb-1">Shop Domain</label>
            <input
              type="text"
              required
              value={shopifyDomain}
              onChange={(e) => setShopifyDomain(e.target.value)}
              placeholder="my-store.myshopify.com"
              disabled={!isVerified}
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
              disabled={!isVerified}
              className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 disabled:opacity-50"
            />
          </div>
        </div>
        <div className="flex justify-end">
          <button
            type="submit"
            disabled={savingProvider === 'shopify_graphql' || !isVerified}
            className="px-4 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-lg transition"
          >
            {savingProvider === 'shopify_graphql' ? 'Saving...' : 'Save Shopify Credentials'}
          </button>
        </div>
      </form>
    </div>
  );

  // Put the website's active platform card first for best UX
  const orderedCards =
    website.platform === 'shopify'
      ? [shopifyCard, wordpressCard, customCard]
      : website.platform === 'wordpress'
      ? [wordpressCard, customCard, shopifyCard]
      : [customCard, wordpressCard, shopifyCard];

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
              <h3 className="text-base font-semibold text-zinc-100">CMS &amp; Platform Integrations</h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                {website.name} ({website.domain}) • Platform: <span className="capitalize text-zinc-300">{website.platform}</span>
              </p>
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
        <div className="p-6 space-y-5 overflow-y-auto">
          {!isVerified && (
            <div className="p-3.5 bg-rose-950/20 border border-rose-800/40 rounded-xl text-xs text-rose-300 flex items-center gap-2.5">
              <Lock className="w-4 h-4 shrink-0 text-rose-400" />
              <span>
                <strong>Ownership Unverified:</strong> You must complete website ownership verification before write integrations can be enabled.
              </span>
            </div>
          )}

          {isLoading && integrations.length === 0 ? (
            <div className="py-8 text-center text-xs text-zinc-500">Loading integrations...</div>
          ) : (
            orderedCards
          )}
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
