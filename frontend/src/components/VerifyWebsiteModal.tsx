'use client';

import React, { useState, useEffect } from 'react';
import {
  X,
  ShieldCheck,
  Copy,
  Check,
  AlertTriangle,
  Download,
  Globe,
  KeyRound,
  Code2,
  FileText,
  Server,
  Sparkles,
  CheckCircle2,
  Zap,
} from 'lucide-react';
import { api } from '@/lib/api';
import { Website, SearchConsoleStatus } from '@/types';
import { useToast } from '@/context/ToastContext';

interface VerifyWebsiteModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
  onVerified: (updatedWebsite: Website) => void;
}

type VerifyMethod =
  | 'google_search_console'
  | 'cms_credentials'
  | 'meta_tag'
  | 'file_token'
  | 'dns_txt';

export function VerifyWebsiteModal({
  website,
  isOpen,
  onClose,
  onVerified,
}: VerifyWebsiteModalProps) {
  const [method, setMethod] = useState<VerifyMethod>('google_search_console');
  const [detailedWebsite, setDetailedWebsite] = useState<Website | null>(null);
  const [gscStatus, setGscStatus] = useState<SearchConsoleStatus | null>(null);
  const [isLoadingMeta, setIsLoadingMeta] = useState(false);

  // CMS one-step verify inputs
  const [cmsProvider, setCmsProvider] = useState<'wordpress_rest' | 'shopify_graphql'>('wordpress_rest');
  const [wpUsername, setWpUsername] = useState('');
  const [wpAppPassword, setWpAppPassword] = useState('');
  const [shopifyDomain, setShopifyDomain] = useState('');
  const [shopifyToken, setShopifyToken] = useState('');

  const [copied, setCopied] = useState<string | null>(null);
  const [isVerifying, setIsVerifying] = useState(false);
  const [isConnectingGsc, setIsConnectingGsc] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  // Load full website verification token (guarantees never "undefined") + GSC connection state
  useEffect(() => {
    if (!isOpen || !website) return;

    setError(null);
    setDetailedWebsite(website);
    setCmsProvider(website.platform === 'shopify' ? 'shopify_graphql' : 'wordpress_rest');
    setShopifyDomain(website.domain);

    let cancelled = false;
    setIsLoadingMeta(true);

    Promise.all([
      website.verification?.token
        ? Promise.resolve({ success: true, data: website })
        : api.get<Website>(`/websites/${website.id}`),
      api.get<SearchConsoleStatus>(`/websites/${website.id}/search-console/status`),
    ])
      .then(([siteRes, gscRes]) => {
        if (cancelled) return;
        if (siteRes.success && siteRes.data) {
          setDetailedWebsite(siteRes.data);
        }
        if (gscRes.success && gscRes.data) {
          setGscStatus(gscRes.data);
        }
      })
      .finally(() => {
        if (!cancelled) setIsLoadingMeta(false);
      });

    return () => {
      cancelled = true;
    };
  }, [isOpen, website]);

  if (!isOpen || !website) return null;

  const activeSite = detailedWebsite || website;
  const token = activeSite.verification?.token || '';
  const metaTagSnippet =
    activeSite.verification?.meta_tag ||
    (token ? `<meta name="web-seo-ai-verification" content="${token}" />` : 'Loading verification token...');
  const dnsTxtSnippet =
    activeSite.verification?.dns_txt ||
    (token ? `web-seo-ai-site-verification=${token}` : 'Loading verification token...');
  const fileSnippet = activeSite.verification?.file_token || {
    filename: token ? `web-seo-ai-${token.slice(-12)}.txt` : 'web-seo-ai-verify.txt',
    content: token,
  };

  const copyToClipboard = (text: string, label: string) => {
    if (!text || text.startsWith('Loading')) return;
    navigator.clipboard.writeText(text);
    setCopied(label);
    toast.info(`Copied ${label} to clipboard!`);
    setTimeout(() => setCopied(null), 2500);
  };

  const handleDownloadVerificationFile = () => {
    if (!fileSnippet.content) return;
    const blob = new Blob([fileSnippet.content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileSnippet.filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    toast.success(`Downloaded ${fileSnippet.filename} — upload it to ${activeSite.target_url}/`);
  };

  const handleConnectGscAndVerify = async () => {
    setIsConnectingGsc(true);
    setError(null);
    try {
      const res = await api.get<{ authorization_url: string }>(
        `/websites/${activeSite.id}/search-console/connect`
      );
      if (res.success && res.data?.authorization_url) {
        const authUrl = res.data.authorization_url;
        if (authUrl.includes('/sandbox-callback?')) {
          const relativeEndpoint = authUrl.replace(/^https?:\/\/[^/]+/, '').replace(/^\/api\/v1/, '');
          const cbRes = await api.get(relativeEndpoint);
          if (cbRes.success) {
            await handleVerify('google_search_console');
          } else {
            setError(cbRes.error?.message || 'Google Search Console connection failed.');
          }
        } else {
          // Real live Google OAuth — auto-verifies ownership on callback!
          window.location.href = authUrl;
        }
      } else {
        setError(res.error?.message || 'Could not start Google Search Console OAuth.');
      }
    } catch {
      setError('Could not initiate Google Search Console connection.');
    } finally {
      setIsConnectingGsc(false);
    }
  };

  const handleVerify = async (selectedMethod: string) => {
    setIsVerifying(true);
    setError(null);

    try {
      const payload: Record<string, unknown> = { method: selectedMethod };

      if (selectedMethod === 'cms_credentials') {
        payload.provider = cmsProvider;
        if (cmsProvider === 'wordpress_rest') {
          if (!wpUsername.trim() || !wpAppPassword.trim()) {
            setError('Please enter your WordPress username and Application Password.');
            setIsVerifying(false);
            return;
          }
          payload.credentials = {
            username: wpUsername.trim(),
            application_password: wpAppPassword.trim(),
          };
        } else {
          if (!shopifyDomain.trim() || !shopifyToken.trim()) {
            setError('Please enter your Shopify store domain and Admin API access token.');
            setIsVerifying(false);
            return;
          }
          payload.credentials = {
            shop_domain: shopifyDomain.trim(),
            access_token: shopifyToken.trim(),
          };
        }
      }

      const res = await api.post<{
        verified: boolean;
        website: Website;
        message: string;
      }>(`/websites/${activeSite.id}/verify`, payload);

      if (res.success && res.data?.verified) {
        toast.success(res.data.message || 'Website ownership verified successfully!');
        onVerified(res.data.website);
        onClose();
      } else {
        setError(res.error?.message || 'Verification could not be confirmed on the live site yet.');
      }
    } catch {
      setError('An error occurred during verification check.');
    } finally {
      setIsVerifying(false);
    }
  };

  const tabs: Array<{ id: VerifyMethod; label: string; icon: React.ReactNode; badge?: string }> = [
    { id: 'google_search_console', label: 'Google GSC', icon: <Globe className="w-3.5 h-3.5" />, badge: '1-Click' },
    { id: 'cms_credentials', label: 'CMS Login', icon: <KeyRound className="w-3.5 h-3.5" /> },
    { id: 'meta_tag', label: 'Meta Tag', icon: <Code2 className="w-3.5 h-3.5" /> },
    { id: 'file_token', label: 'File Upload', icon: <FileText className="w-3.5 h-3.5" /> },
    { id: 'dns_txt', label: 'DNS TXT', icon: <Server className="w-3.5 h-3.5" /> },
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm animate-in fade-in">
      <div className="w-full max-w-2xl bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
        {/* Header */}
        <div className="flex items-center justify-between p-5 border-b border-zinc-800">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-emerald-950/50 border border-emerald-800/50 rounded-xl text-emerald-400">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-zinc-100">Verify Website Ownership</h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                {activeSite.name} ({activeSite.domain})
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-zinc-400 hover:text-zinc-200 rounded-lg hover:bg-zinc-900 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="p-5 space-y-4 overflow-y-auto">
          <div className="p-3 bg-emerald-950/20 border border-emerald-800/40 rounded-xl text-xs text-emerald-200/90 flex items-start gap-2.5">
            <Sparkles className="w-4 h-4 shrink-0 mt-0.5 text-emerald-400" />
            <p className="leading-relaxed">
              Choose the fastest method below to unlock WordPress/Shopify publishing &amp; autopilot fixes.{' '}
              <strong className="text-emerald-300">Google Search Console</strong> verifies in 1 click with zero code changes.
            </p>
          </div>

          {error && (
            <div className="p-3 text-xs bg-rose-950/40 border border-rose-800/60 rounded-xl text-rose-300 flex items-start gap-2">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5 text-rose-400" />
              <span>{error}</span>
            </div>
          )}

          {/* Verification Method Tabs */}
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-1 rounded-xl bg-zinc-900 p-1 border border-zinc-800">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => {
                  setMethod(tab.id);
                  setError(null);
                }}
                className={`py-2 px-2 text-xs font-medium rounded-lg transition flex items-center justify-center gap-1.5 ${
                  method === tab.id
                    ? 'bg-zinc-800 text-zinc-100 shadow-sm border border-zinc-700/60'
                    : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {tab.icon}
                <span className="truncate">{tab.label}</span>
              </button>
            ))}
          </div>

          {/* Method 1: 1-Click Google Search Console */}
          {method === 'google_search_console' && (
            <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-3">
              <div className="flex items-center justify-between gap-2">
                <div className="text-xs font-semibold text-zinc-100 flex items-center gap-1.5">
                  <Globe className="w-4 h-4 text-emerald-400" />
                  1-Click Zero-Code Verification via Google Search Console
                </div>
                <span className="px-2 py-0.5 text-[10px] font-bold uppercase rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                  Recommended
                </span>
              </div>

              {gscStatus?.connected ? (
                <div className="space-y-3">
                  <div className="p-3 rounded-lg bg-emerald-950/30 border border-emerald-800/40 text-xs text-emerald-200 flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                    <span>
                      Google Search Console is connected (Property:{' '}
                      <code className="font-mono text-emerald-300">{gscStatus.selected_property}</code>). Click{' '}
                      <strong>Verify Now</strong> to verify ownership instantly!
                    </span>
                  </div>
                </div>
              ) : (
                <div className="space-y-3">
                  <p className="text-xs text-zinc-400 leading-relaxed">
                    Sign in with the Google account that owns <code className="text-zinc-200">{activeSite.domain}</code> in
                    Google Search Console. Ownership will be verified automatically as soon as Google redirects back — no
                    meta tags or DNS edits required.
                  </p>
                  <button
                    type="button"
                    onClick={handleConnectGscAndVerify}
                    disabled={isConnectingGsc || isVerifying}
                    className="w-full py-2.5 px-4 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl transition flex items-center justify-center gap-2 shadow"
                  >
                    <Globe className="w-4 h-4" />
                    <span>
                      {isConnectingGsc
                        ? 'Redirecting to Google...'
                        : 'Connect Google Search Console & Auto-Verify'}
                    </span>
                  </button>
                </div>
              )}
            </div>
          )}

          {/* Method 2: One-Step WordPress / Shopify CMS Verify & Connect */}
          {method === 'cms_credentials' && (
            <div className="p-4 rounded-xl bg-zinc-900/60 border border-zinc-800 space-y-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-zinc-100">
                  One-Step CMS Verify &amp; Connect
                </span>
                <div className="flex gap-1 bg-zinc-950 p-0.5 rounded-lg border border-zinc-800 text-[11px]">
                  <button
                    type="button"
                    onClick={() => setCmsProvider('wordpress_rest')}
                    className={`px-2.5 py-1 rounded-md font-medium transition ${
                      cmsProvider === 'wordpress_rest'
                        ? 'bg-emerald-600 text-white'
                        : 'text-zinc-400 hover:text-zinc-200'
                    }`}
                  >
                    WordPress
                  </button>
                  <button
                    type="button"
                    onClick={() => setCmsProvider('shopify_graphql')}
                    className={`px-2.5 py-1 rounded-md font-medium transition ${
                      cmsProvider === 'shopify_graphql'
                        ? 'bg-emerald-600 text-white'
                        : 'text-zinc-400 hover:text-zinc-200'
                    }`}
                  >
                    Shopify
                  </button>
                </div>
              </div>

              <p className="text-xs text-zinc-400 leading-relaxed">
                Authenticating directly against your CMS API proves site ownership and connects publishing in one step.
              </p>

              {cmsProvider === 'wordpress_rest' ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] font-medium text-zinc-300 mb-1">
                      WordPress Username
                    </label>
                    <input
                      type="text"
                      value={wpUsername}
                      onChange={(e) => setWpUsername(e.target.value)}
                      placeholder="admin"
                      className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                  <div>
                    <label className="block text-[11px] font-medium text-zinc-300 mb-1">
                      Application Password (WP Users → Profile)
                    </label>
                    <input
                      type="password"
                      value={wpAppPassword}
                      onChange={(e) => setWpAppPassword(e.target.value)}
                      placeholder="xxxx xxxx xxxx xxxx"
                      className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] font-medium text-zinc-300 mb-1">
                      Shopify Store Domain
                    </label>
                    <input
                      type="text"
                      value={shopifyDomain}
                      onChange={(e) => setShopifyDomain(e.target.value)}
                      placeholder="your-store.myshopify.com"
                      className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                  <div>
                    <label className="block text-[11px] font-medium text-zinc-300 mb-1">
                      Admin API Access Token
                    </label>
                    <input
                      type="password"
                      value={shopifyToken}
                      onChange={(e) => setShopifyToken(e.target.value)}
                      placeholder="shpat_..."
                      className="w-full px-3 py-2 bg-zinc-950 border border-zinc-800 rounded-lg text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Method 3: HTML Meta Tag */}
          {method === 'meta_tag' && (
            <div className="space-y-2.5">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Paste this meta tag inside the <code className="text-emerald-400 font-mono">&lt;head&gt;</code> section of{' '}
                <code className="text-zinc-200">{activeSite.target_url}</code>:
              </p>
              <div className="flex items-center justify-between p-3 bg-zinc-900 border border-zinc-800 rounded-xl font-mono text-xs text-zinc-200 break-all">
                <span>{isLoadingMeta && !token ? 'Loading verification token...' : metaTagSnippet}</span>
                <button
                  type="button"
                  onClick={() => copyToClipboard(metaTagSnippet, 'Meta Tag')}
                  disabled={!token}
                  className="p-1.5 ml-2 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded-lg transition shrink-0 disabled:opacity-40"
                >
                  {copied === 'Meta Tag' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                </button>
              </div>
            </div>
          )}

          {/* Method 4: HTML / TXT File Upload with 1-Click Download */}
          {method === 'file_token' && (
            <div className="space-y-3">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Download the verification file and upload it to your website root so it is reachable at{' '}
                <code className="text-emerald-400 font-mono break-all">
                  {activeSite.target_url}/{fileSnippet.filename}
                </code>
                :
              </p>
              <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <button
                  type="button"
                  onClick={handleDownloadVerificationFile}
                  disabled={!fileSnippet.content}
                  className="px-4 py-2 text-xs font-semibold text-emerald-300 bg-emerald-950/50 hover:bg-emerald-900/60 border border-emerald-700/50 rounded-xl transition flex items-center justify-center gap-2 disabled:opacity-40"
                >
                  <Download className="w-3.5 h-3.5" />
                  <span>Download {fileSnippet.filename}</span>
                </button>
                <div className="flex-1 p-2.5 bg-zinc-900 border border-zinc-800 rounded-xl font-mono text-[11px] text-zinc-300 truncate flex justify-between items-center">
                  <span className="truncate">{fileSnippet.content || 'Loading...'}</span>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(fileSnippet.content, 'Token')}
                    disabled={!fileSnippet.content}
                    className="p-1 ml-2 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded transition shrink-0"
                  >
                    {copied === 'Token' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Method 5: DNS TXT Record */}
          {method === 'dns_txt' && (
            <div className="space-y-2.5">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Add a DNS TXT record in your DNS provider (Cloudflare, Namecheap, GoDaddy, Route53):
              </p>
              <div className="space-y-2 text-xs font-mono">
                <div className="p-2.5 bg-zinc-900 border border-zinc-800 rounded-lg text-zinc-300 flex justify-between items-center">
                  <span>Type: TXT &nbsp;•&nbsp; Host / Name: @</span>
                </div>
                <div className="p-2.5 bg-zinc-900 border border-zinc-800 rounded-lg text-zinc-200 flex justify-between items-center gap-2">
                  <span className="truncate">{dnsTxtSnippet}</span>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(dnsTxtSnippet, 'DNS TXT')}
                    disabled={!token}
                    className="p-1 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded transition shrink-0 disabled:opacity-40"
                  >
                    {copied === 'DNS TXT' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Verification Actions */}
          <div className="pt-3 border-t border-zinc-800 flex flex-col sm:flex-row items-center justify-between gap-3">
            <button
              type="button"
              onClick={() => handleVerify('manual_override')}
              disabled={isVerifying}
              className="inline-flex items-center gap-1.5 text-[11px] font-medium text-amber-400/90 hover:text-amber-300 underline underline-offset-4 transition"
            >
              <Zap className="w-3.5 h-3.5" />
              <span>Instant Agency Verify (Admin / SEO Manager)</span>
            </button>
            <div className="flex items-center gap-2.5 w-full sm:w-auto">
              <button
                type="button"
                onClick={onClose}
                className="flex-1 sm:flex-none px-4 py-2 text-xs font-medium text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-xl transition"
              >
                Close
              </button>
              <button
                type="button"
                disabled={isVerifying || (method === 'google_search_console' && !gscStatus?.connected)}
                onClick={() => handleVerify(method)}
                className="flex-1 sm:flex-none px-5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 rounded-xl shadow transition"
              >
                {isVerifying
                  ? 'Verifying...'
                  : method === 'cms_credentials'
                    ? 'Verify & Connect CMS'
                    : 'Verify Now'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
