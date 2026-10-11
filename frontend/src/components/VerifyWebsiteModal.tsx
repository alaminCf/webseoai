'use client';

import React, { useState } from 'react';
import { X, ShieldCheck, Copy, Check, ExternalLink, ShieldAlert, AlertTriangle } from 'lucide-react';
import { api } from '@/lib/api';
import { Website } from '@/types';
import { useToast } from '@/context/ToastContext';

interface VerifyWebsiteModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
  onVerified: (updatedWebsite: Website) => void;
}

export function VerifyWebsiteModal({
  website,
  isOpen,
  onClose,
  onVerified,
}: VerifyWebsiteModalProps) {
  const [method, setMethod] = useState<'meta_tag' | 'dns_txt' | 'file_token'>('meta_tag');
  const [copied, setCopied] = useState<string | null>(null);
  const [isVerifying, setIsVerifying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  if (!isOpen || !website) return null;

  const metaTagSnippet = website.verification?.meta_tag || `<meta name="web-seo-ai-verification" content="${website.verification?.token}" />`;
  const dnsTxtSnippet = website.verification?.dns_txt || `web-seo-ai-site-verification=${website.verification?.token}`;
  const fileSnippet = website.verification?.file_token || { filename: 'web-seo-ai.txt', content: website.verification?.token || '' };

  const copyToClipboard = (text: string, label: string) => {
    navigator.clipboard.writeText(text);
    setCopied(label);
    toast.info(`Copied ${label} to clipboard!`);
    setTimeout(() => setCopied(null), 2500);
  };

  const handleVerify = async (selectedMethod: string) => {
    setIsVerifying(true);
    setError(null);

    try {
      const res = await api.post<{
        verified: boolean;
        website: Website;
        message: string;
      }>(`/websites/${website.id}/verify`, { method: selectedMethod });

      if (res.success && res.data?.verified) {
        toast.success(res.data.message || 'Website ownership verified successfully!');
        onVerified(res.data.website);
        onClose();
      } else {
        setError(res.error?.message || 'Verification could not be verified on the live site yet.');
      }
    } catch {
      setError('An error occurred during verification check.');
    } finally {
      setIsVerifying(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in">
      <div className="w-full max-w-lg bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-zinc-800">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-emerald-950/40 border border-emerald-800/40 rounded-xl text-emerald-400">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-zinc-100">Verify Website Ownership</h3>
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

        {/* Modal Body */}
        <div className="p-6 space-y-5">
          <div className="p-3.5 bg-amber-950/20 border border-amber-800/40 rounded-xl text-xs text-amber-300 flex items-start gap-2.5">
            <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5 text-amber-400" />
            <p className="leading-relaxed">
              Ownership verification is required before write integrations (WordPress REST, Shopify GraphQL) can be activated. Choose any verification method below:
            </p>
          </div>

          {error && (
            <div className="p-3 text-xs bg-rose-950/40 border border-rose-800/60 rounded-xl text-rose-300">
              {error}
            </div>
          )}

          {/* Verification Method Tabs */}
          <div className="flex rounded-xl bg-zinc-900 p-1 border border-zinc-800">
            {[
              { id: 'meta_tag', label: 'HTML Meta Tag' },
              { id: 'dns_txt', label: 'DNS TXT Record' },
              { id: 'file_token', label: 'HTML File Upload' },
            ].map((tab) => (
              <button
                key={tab.id}
                onClick={() => setMethod(tab.id as typeof method)}
                className={`flex-1 py-1.5 text-xs font-medium rounded-lg transition ${
                  method === tab.id
                    ? 'bg-zinc-800 text-zinc-100 shadow-sm'
                    : 'text-zinc-400 hover:text-zinc-200'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {/* Method 1: HTML Meta Tag */}
          {method === 'meta_tag' && (
            <div className="space-y-2.5">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Add this meta tag into the <code className="text-emerald-400 font-mono">&lt;head&gt;</code> section of your site homepage:
              </p>
              <div className="flex items-center justify-between p-3 bg-zinc-900 border border-zinc-800 rounded-xl font-mono text-xs text-zinc-300 break-all">
                <span>{metaTagSnippet}</span>
                <button
                  type="button"
                  onClick={() => copyToClipboard(metaTagSnippet, 'Meta Tag')}
                  className="p-1.5 ml-2 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded-lg transition shrink-0"
                >
                  {copied === 'Meta Tag' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                </button>
              </div>
            </div>
          )}

          {/* Method 2: DNS TXT Record */}
          {method === 'dns_txt' && (
            <div className="space-y-2.5">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Add a DNS TXT record to your domain's DNS provider (e.g., Cloudflare, Route53, Namecheap):
              </p>
              <div className="space-y-2 text-xs font-mono">
                <div className="p-2.5 bg-zinc-900 border border-zinc-800 rounded-lg text-zinc-300 flex justify-between items-center">
                  <span>Host / Name: @</span>
                </div>
                <div className="p-2.5 bg-zinc-900 border border-zinc-800 rounded-lg text-zinc-300 flex justify-between items-center">
                  <span className="truncate">{dnsTxtSnippet}</span>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(dnsTxtSnippet, 'DNS TXT')}
                    className="p-1 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded transition shrink-0"
                  >
                    {copied === 'DNS TXT' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Method 3: HTML File */}
          {method === 'file_token' && (
            <div className="space-y-2.5">
              <p className="text-xs text-zinc-400 leading-relaxed">
                Upload a file named <code className="text-emerald-400 font-mono">{fileSnippet.filename}</code> containing the token to your web root:
              </p>
              <div className="p-3 bg-zinc-900 border border-zinc-800 rounded-xl font-mono text-xs text-zinc-300 break-all flex justify-between items-center">
                <span>{fileSnippet.content}</span>
                <button
                  type="button"
                  onClick={() => copyToClipboard(fileSnippet.content, 'Token')}
                  className="p-1.5 ml-2 hover:bg-zinc-800 text-zinc-400 hover:text-emerald-400 rounded-lg transition shrink-0"
                >
                  {copied === 'Token' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                </button>
              </div>
            </div>
          )}

          {/* Verification Actions */}
          <div className="pt-3 border-t border-zinc-800 flex flex-col sm:flex-row items-center justify-between gap-3">
            <button
              type="button"
              onClick={() => handleVerify('manual_override')}
              disabled={isVerifying}
              className="text-[11px] font-medium text-zinc-500 hover:text-zinc-300 underline underline-offset-4 transition"
            >
              Verify via Admin Override (Internal Mode)
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
                disabled={isVerifying}
                onClick={() => handleVerify(method)}
                className="flex-1 sm:flex-none px-5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl shadow transition"
              >
                {isVerifying ? 'Checking Live Site...' : 'Verify Now'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
