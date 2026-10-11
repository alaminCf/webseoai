'use client';

import React, { useState } from 'react';
import { X, Globe, Layers, CheckCircle2 } from 'lucide-react';
import { api } from '@/lib/api';
import { Website, Platform } from '@/types';
import { useToast } from '@/context/ToastContext';

interface AddWebsiteModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (newWebsite: Website) => void;
}

export function AddWebsiteModal({ isOpen, onClose, onSuccess }: AddWebsiteModalProps) {
  const [name, setName] = useState('');
  const [targetUrl, setTargetUrl] = useState('');
  const [domain, setDomain] = useState('');
  const [platform, setPlatform] = useState<Platform>('wordpress');
  const [timezone, setTimezone] = useState('UTC');
  const [preferredLanguage, setPreferredLanguage] = useState('en');
  const [targetCountry, setTargetCountry] = useState('US');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  if (!isOpen) return null;

  const handleUrlChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setTargetUrl(val);
    try {
      if (val.includes('.')) {
        const urlToParse = val.startsWith('http') ? val : `https://${val}`;
        const parsed = new URL(urlToParse);
        setDomain(parsed.hostname);
        if (!name) {
          setName(parsed.hostname.replace('www.', '').split('.')[0].toUpperCase() + ' Website');
        }
      }
    } catch {
      // Ignore during typing
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);

    let formattedUrl = targetUrl.trim();
    if (!formattedUrl.startsWith('http://') && !formattedUrl.startsWith('https://')) {
      formattedUrl = `https://${formattedUrl}`;
    }

    try {
      const res = await api.post<Website>('/websites', {
        name,
        target_url: formattedUrl,
        domain: domain || new URL(formattedUrl).hostname,
        platform,
        timezone,
        preferred_language: preferredLanguage,
        target_country: targetCountry,
      });

      if (res.success && res.data) {
        toast.success(`Website ${res.data.name} added successfully!`);
        onSuccess(res.data);
        onClose();
        // Reset form
        setName('');
        setTargetUrl('');
        setDomain('');
      } else {
        setError(res.error?.message || 'Failed to add website. Please verify details.');
      }
    } catch {
      setError('An unexpected error occurred while communicating with the server.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in">
      <div className="w-full max-w-lg bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-zinc-800">
          <div>
            <h3 className="text-base font-semibold text-zinc-100">Add New Website</h3>
            <p className="text-xs text-zinc-400 mt-0.5">Register a site for technical SEO audits & tracking.</p>
          </div>
          <button
            onClick={onClose}
            className="p-1 text-zinc-400 hover:text-zinc-200 rounded-lg hover:bg-zinc-900 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {error && (
            <div className="p-3 text-xs bg-rose-950/40 border border-rose-800/60 rounded-xl text-rose-300">
              {error}
            </div>
          )}

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Website Target URL <span className="text-emerald-400">*</span>
            </label>
            <input
              type="text"
              required
              value={targetUrl}
              onChange={handleUrlChange}
              placeholder="https://example.com"
              className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                Website Display Name <span className="text-emerald-400">*</span>
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="My Brand Site"
                className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                Domain Hostname
              </label>
              <input
                type="text"
                required
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
                placeholder="example.com"
                className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-sm text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition"
              />
            </div>
          </div>

          {/* Platform Selector Cards */}
          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Platform Type <span className="text-emerald-400">*</span>
            </label>
            <div className="grid grid-cols-3 gap-2.5">
              {[
                { id: 'wordpress', label: 'WordPress', desc: 'REST API' },
                { id: 'shopify', label: 'Shopify', desc: 'GraphQL' },
                { id: 'custom', label: 'Custom / HTML', desc: 'Passive Crawl' },
              ].map((p) => (
                <button
                  type="button"
                  key={p.id}
                  onClick={() => setPlatform(p.id as Platform)}
                  className={`p-3 rounded-xl border text-left transition flex flex-col justify-between ${
                    platform === p.id
                      ? 'bg-emerald-950/20 border-emerald-500/50 text-emerald-400'
                      : 'bg-zinc-900/60 border-zinc-800 text-zinc-400 hover:border-zinc-700'
                  }`}
                >
                  <div className="text-xs font-semibold">{p.label}</div>
                  <div className="text-[10px] text-zinc-400 mt-1">{p.desc}</div>
                </button>
              ))}
            </div>
          </div>

          {/* Localization & Region */}
          <div className="grid grid-cols-3 gap-2.5 pt-1">
            <div>
              <label className="block text-[11px] font-medium text-zinc-400 mb-1">Timezone</label>
              <select
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                className="w-full px-2.5 py-2 bg-zinc-900 border border-zinc-800 rounded-lg text-xs text-zinc-200 focus:outline-none"
              >
                <option value="UTC">UTC</option>
                <option value="Asia/Dhaka">Asia/Dhaka</option>
                <option value="America/New_York">America/New_York</option>
                <option value="Europe/London">Europe/London</option>
              </select>
            </div>
            <div>
              <label className="block text-[11px] font-medium text-zinc-400 mb-1">Language</label>
              <select
                value={preferredLanguage}
                onChange={(e) => setPreferredLanguage(e.target.value)}
                className="w-full px-2.5 py-2 bg-zinc-900 border border-zinc-800 rounded-lg text-xs text-zinc-200 focus:outline-none"
              >
                <option value="en">English (en)</option>
                <option value="bn">Bengali (bn)</option>
                <option value="es">Spanish (es)</option>
                <option value="fr">French (fr)</option>
              </select>
            </div>
            <div>
              <label className="block text-[11px] font-medium text-zinc-400 mb-1">Country</label>
              <select
                value={targetCountry}
                onChange={(e) => setTargetCountry(e.target.value)}
                className="w-full px-2.5 py-2 bg-zinc-900 border border-zinc-800 rounded-lg text-xs text-zinc-200 focus:outline-none"
              >
                <option value="US">United States (US)</option>
                <option value="BD">Bangladesh (BD)</option>
                <option value="GB">United Kingdom (GB)</option>
                <option value="CA">Canada (CA)</option>
              </select>
            </div>
          </div>

          {/* Action Buttons */}
          <div className="flex items-center justify-end gap-3 pt-4 border-t border-zinc-800">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-xs font-medium text-zinc-400 hover:text-zinc-200 hover:bg-zinc-900 rounded-xl transition"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="px-5 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl shadow transition"
            >
              {isSubmitting ? 'Registering...' : 'Add Website'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
