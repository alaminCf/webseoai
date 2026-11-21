'use client';

import React, { useState } from 'react';
import {
  Sparkles,
  Globe,
  CheckCircle2,
  ArrowRight,
  ArrowLeft,
  X,
  Target,
  Activity,
  Layers,
  Shield,
  FileText,
  AlertCircle,
  Loader2,
} from 'lucide-react';
import { api } from '@/lib/api';
import { Website } from '@/types';
import { useToast } from '@/context/ToastContext';

interface OnboardingWizardModalProps {
  isOpen: boolean;
  onClose: () => void;
  onCompleted: (website: Website) => void;
}

export function OnboardingWizardModal({
  isOpen,
  onClose,
  onCompleted,
}: OnboardingWizardModalProps) {
  const toast = useToast();
  const [step, setStep] = useState<number>(1);
  const [isLoading, setIsLoading] = useState<boolean>(false);

  // Form State
  const [businessName, setBusinessName] = useState<string>('');
  const [targetAudience, setTargetAudience] = useState<string>('');
  const [brandVoice, setBrandVoice] = useState<string>('professional');
  const [websiteDomain, setWebsiteDomain] = useState<string>('');
  const [platform, setPlatform] = useState<string>('custom');
  const [seoGoal, setSeoGoal] = useState<string>('fix_technical');

  // Created website & scan tracking
  const [createdWebsite, setCreatedWebsite] = useState<Website | null>(null);
  const [scanStatus, setScanStatus] = useState<string>('idle'); // idle, running, completed, error

  if (!isOpen) return null;

  const handleNextStep1To2 = () => {
    // Business profile is optional/skippable
    setStep(2);
  };

  const handleCreateWebsite = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!websiteDomain.trim()) {
      toast.error('Please enter a valid website domain or URL.');
      return;
    }

    setIsLoading(true);
    try {
      // 1. Parse and normalize domain + target_url cleanly
      const rawInput = websiteDomain.trim();
      const hasProtocol = /^https?:\/\//i.test(rawInput);
      const normalizedUrlString = hasProtocol ? rawInput : `https://${rawInput}`;

      let cleanDomain = '';
      let targetUrl = '';
      try {
        const parsed = new URL(normalizedUrlString);
        cleanDomain = parsed.hostname.toLowerCase().replace(/^www\./, '');
        targetUrl = `${parsed.protocol}//${parsed.hostname.toLowerCase()}`;
      } catch {
        toast.error('Please enter a valid domain or URL (e.g. example.com or https://example.com).');
        setIsLoading(false);
        return;
      }

      if (!cleanDomain || !cleanDomain.includes('.')) {
        toast.error('Please enter a valid domain name (e.g. example.com).');
        setIsLoading(false);
        return;
      }

      // 2. Create Website via API
      const siteName = businessName.trim() || cleanDomain;
      const res = await api.post<Website>('/websites', {
        name: siteName,
        domain: cleanDomain,
        target_url: targetUrl,
        platform: platform,
        preferred_language: 'en',
      });

      let targetWebsite: Website | null = null;

      if (res.success && res.data) {
        targetWebsite = res.data;
      } else if (res.error?.code === 'WEBSITE_EXISTS') {
        const details = res.error.details as unknown as { existing_website?: Website } | undefined;
        if (details?.existing_website) {
          targetWebsite = details.existing_website;
          toast.info(`${cleanDomain} is already in your workspace — continuing to audit setup.`);
        } else {
          // Fallback: fetch websites list and select matching domain
          const listRes = await api.get<Website[]>('/websites');
          const match = listRes.data?.find((w) => w.domain.toLowerCase() === cleanDomain);
          if (match) {
            targetWebsite = match;
            toast.info(`${cleanDomain} is already in your workspace — continuing to audit setup.`);
          }
        }
      }

      if (!targetWebsite) {
        const firstFieldError = res.error?.details
          ? Object.values(res.error.details).flat().find((v) => typeof v === 'string')
          : null;
        toast.error(firstFieldError || res.error?.message || 'Could not connect website.');
        setIsLoading(false);
        return;
      }

      setCreatedWebsite(targetWebsite);

      // 3. Update Website Context Profile if business profile info was entered
      if (businessName || targetAudience) {
        try {
          await api.put(`/websites/${targetWebsite.id}/context-profile`, {
            business_name: businessName || siteName,
            target_audience: targetAudience || 'General Audience',
            brand_voice: brandVoice,
            business_goals: seoGoal === 'fix_technical' ? 'Fix technical SEO bottlenecks' : 'Grow organic search traffic',
          });
        } catch {
          // Context profile is non-blocking
        }
      }

      setStep(3); // Go to goals
    } catch {
      toast.error('Network error registering website.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleStartScan = async () => {
    if (!createdWebsite) return;
    setStep(4); // Go to scan progress step
    setScanStatus('running');

    try {
      const res = await api.post(`/websites/${createdWebsite.id}/crawl`, {
        max_pages: 50,
        max_depth: 3,
        render_js: 'auto',
      });

      if (res.success) {
        setScanStatus('completed');
        toast.success('Initial SEO scan initiated successfully!');
      } else {
        setScanStatus('error');
        toast.error(res.error?.message || 'Could not start initial audit.');
      }
    } catch {
      setScanStatus('error');
      toast.error('Network error triggering audit.');
    }
  };

  const handleFinish = () => {
    if (createdWebsite) {
      onCompleted(createdWebsite);
    }
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
      <div className="bg-zinc-950 border border-zinc-800 rounded-3xl w-full max-w-xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        {/* Modal Header & Stepper */}
        <div className="px-6 py-5 border-b border-zinc-800/80 flex items-center justify-between bg-zinc-900/40">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-xs">
              {step}/4
            </div>
            <div>
              <h2 className="text-sm font-bold text-zinc-100 flex items-center gap-1.5">
                <span>Guided Onboarding</span>
                <span className="text-emerald-400">•</span>
                <span className="text-xs text-zinc-400 font-normal">
                  {step === 1 && 'Business Profile'}
                  {step === 2 && 'Connect Website'}
                  {step === 3 && 'Configure SEO Goals'}
                  {step === 4 && 'Initial SEO Scan'}
                </span>
              </h2>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800 rounded-xl transition"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Step Progress Bar */}
        <div className="w-full bg-zinc-900 h-1">
          <div
            className="bg-emerald-500 h-1 transition-all duration-300"
            style={{ width: `${(step / 4) * 100}%` }}
          />
        </div>

        {/* Step Content */}
        <div className="p-6 overflow-y-auto space-y-6 flex-1">
          {/* STEP 1: Business Profile (Skippable) */}
          {step === 1 && (
            <div className="space-y-5 animate-in fade-in duration-200">
              <div className="space-y-1">
                <h3 className="text-base font-bold text-zinc-100">
                  Tell us about your business (Optional)
                </h3>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  Our AI SEO Strategist uses your niche and voice to generate tailored content and meta recommendations. You can skip this and fill it out later.
                </p>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Business or Brand Name
                  </label>
                  <input
                    type="text"
                    value={businessName}
                    onChange={(e) => setBusinessName(e.target.value)}
                    placeholder="e.g. Techboloy Solutions"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Target Audience / Ideal Customer
                  </label>
                  <input
                    type="text"
                    value={targetAudience}
                    onChange={(e) => setTargetAudience(e.target.value)}
                    placeholder="e.g. Small business owners, B2B SaaS founders"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Brand Voice & Tone
                  </label>
                  <select
                    value={brandVoice}
                    onChange={(e) => setBrandVoice(e.target.value)}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60"
                  >
                    <option value="professional">Professional & Authoritative</option>
                    <option value="friendly">Friendly & Conversational</option>
                    <option value="technical">Technical & In-depth</option>
                    <option value="bold">Bold & Energetic</option>
                  </select>
                </div>
              </div>

              <div className="flex items-center justify-between pt-4 border-t border-zinc-800/80">
                <button
                  type="button"
                  onClick={handleNextStep1To2}
                  className="text-xs text-zinc-400 hover:text-zinc-200 transition"
                >
                  Skip for now
                </button>
                <button
                  type="button"
                  onClick={handleNextStep1To2}
                  className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold rounded-xl text-xs transition"
                >
                  <span>Continue</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          )}

          {/* STEP 2: Connect Website */}
          {step === 2 && (
            <form onSubmit={handleCreateWebsite} className="space-y-5 animate-in fade-in duration-200">
              <div className="space-y-1">
                <h3 className="text-base font-bold text-zinc-100">
                  Connect your website
                </h3>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  Enter your domain to start an instant read-only audit. No passwords, credentials, or write permissions are required for auditing.
                </p>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Website Domain or URL <span className="text-emerald-400">*</span>
                  </label>
                  <div className="relative">
                    <Globe className="w-4 h-4 text-zinc-500 absolute left-3.5 top-3" />
                    <input
                      type="text"
                      required
                      value={websiteDomain}
                      onChange={(e) => setWebsiteDomain(e.target.value)}
                      placeholder="example.com or https://example.com"
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-10 pr-3.5 py-2.5 text-xs text-zinc-100 focus:outline-none focus:border-emerald-500/60 font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Platform Type
                  </label>
                  <div className="grid grid-cols-3 gap-2.5">
                    {[
                      { id: 'custom', label: 'Custom / Other' },
                      { id: 'wordpress', label: 'WordPress' },
                      { id: 'shopify', label: 'Shopify' },
                    ].map((item) => (
                      <button
                        key={item.id}
                        type="button"
                        onClick={() => setPlatform(item.id)}
                        className={`p-3 rounded-xl border text-center transition text-xs font-medium ${
                          platform === item.id
                            ? 'bg-emerald-500/10 border-emerald-500/60 text-emerald-300 font-semibold'
                            : 'bg-zinc-900/60 border-zinc-800 text-zinc-400 hover:text-zinc-200'
                        }`}
                      >
                        {item.label}
                      </button>
                    ))}
                  </div>
                  <p className="text-[11px] text-zinc-400 mt-2">
                    Scanning is completely read-only. Optional CMS publishing or GSC analytics can be connected later.
                  </p>
                </div>
              </div>

              <div className="flex items-center justify-between pt-4 border-t border-zinc-800/80">
                <button
                  type="button"
                  onClick={() => setStep(1)}
                  className="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-200 transition"
                >
                  <ArrowLeft className="w-3.5 h-3.5" />
                  <span>Back</span>
                </button>
                <button
                  type="submit"
                  disabled={isLoading || !websiteDomain.trim()}
                  className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 disabled:opacity-50 text-zinc-950 font-semibold rounded-xl text-xs transition"
                >
                  {isLoading ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Connecting...</span>
                    </>
                  ) : (
                    <>
                      <span>Next: Goals</span>
                      <ArrowRight className="w-3.5 h-3.5" />
                    </>
                  )}
                </button>
              </div>
            </form>
          )}

          {/* STEP 3: Configure SEO Goals */}
          {step === 3 && (
            <div className="space-y-5 animate-in fade-in duration-200">
              <div className="space-y-1">
                <h3 className="text-base font-bold text-zinc-100">
                  What is your primary SEO focus?
                </h3>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  We customize your AI action priorities based on what matters most for {createdWebsite?.domain}.
                </p>
              </div>

              <div className="space-y-3">
                {[
                  {
                    id: 'fix_technical',
                    title: 'Fix Critical Technical SEO Bottlenecks',
                    desc: 'Crawl errors, missing titles/descriptions, broken links, canonical issues, and speed flags.',
                  },
                  {
                    id: 'grow_content',
                    title: 'Expand Content & Target High-Intent Keywords',
                    desc: 'Discover content gaps, optimize existing pages, and draft AI articles.',
                  },
                  {
                    id: 'automate_all',
                    title: 'Full Autopilot Monitoring & Recommendations',
                    desc: 'Automated weekly crawl, continuous issue alerts, and ready-to-approve drafts.',
                  },
                ].map((item) => (
                  <div
                    key={item.id}
                    onClick={() => setSeoGoal(item.id)}
                    className={`p-4 rounded-xl border cursor-pointer transition text-left ${
                      seoGoal === item.id
                        ? 'bg-emerald-950/30 border-emerald-500/60 shadow-md'
                        : 'bg-zinc-900/60 border-zinc-800 hover:border-zinc-700'
                    }`}
                  >
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-xs font-bold text-zinc-100">{item.title}</span>
                      <div
                        className={`w-4 h-4 rounded-full border flex items-center justify-center ${
                          seoGoal === item.id
                            ? 'border-emerald-500 bg-emerald-500 text-zinc-950'
                            : 'border-zinc-700'
                        }`}
                      >
                        {seoGoal === item.id && <CheckCircle2 className="w-3.5 h-3.5" />}
                      </div>
                    </div>
                    <p className="text-[11px] text-zinc-400 leading-relaxed">{item.desc}</p>
                  </div>
                ))}
              </div>

              <div className="flex items-center justify-between pt-4 border-t border-zinc-800/80">
                <button
                  type="button"
                  onClick={() => setStep(2)}
                  className="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-200 transition"
                >
                  <ArrowLeft className="w-3.5 h-3.5" />
                  <span>Back</span>
                </button>
                <button
                  type="button"
                  onClick={handleStartScan}
                  className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold rounded-xl text-xs transition shadow-lg shadow-emerald-500/20"
                >
                  <Activity className="w-3.5 h-3.5" />
                  <span>Launch Initial Scan</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          )}

          {/* STEP 4: Scan Progress & Results Handoff */}
          {step === 4 && (
            <div className="space-y-6 text-center py-4 animate-in fade-in duration-200">
              <div className="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 mx-auto">
                {scanStatus === 'running' ? (
                  <Loader2 className="w-8 h-8 animate-spin" />
                ) : scanStatus === 'completed' ? (
                  <CheckCircle2 className="w-8 h-8 text-emerald-400" />
                ) : (
                  <AlertCircle className="w-8 h-8 text-amber-400" />
                )}
              </div>

              <div className="space-y-2 max-w-md mx-auto">
                <h3 className="text-base font-bold text-zinc-100">
                  {scanStatus === 'running'
                    ? `Crawling & Auditing ${createdWebsite?.domain}...`
                    : scanStatus === 'completed'
                    ? 'Initial Audit Dispatched Successfully!'
                    : 'Audit queued in background'}
                </h3>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  {scanStatus === 'running'
                    ? 'Dispatched asynchronous crawler engine. Checking robots.txt, discovering sitemap URLs, inspecting meta tags, and detecting technical bottlenecks.'
                    : 'The crawler has begun processing your pages. You can now explore your Executive Dashboard or review the incoming findings in your SEO Action Plan.'}
                </p>
              </div>

              <div className="bg-zinc-900/60 border border-zinc-800 rounded-xl p-4 max-w-md mx-auto text-left text-xs space-y-2">
                <div className="flex items-center gap-2 text-zinc-300">
                  <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                  <span>Domain registered: <strong className="font-mono text-zinc-100">{createdWebsite?.domain}</strong></span>
                </div>
                <div className="flex items-center gap-2 text-zinc-300">
                  <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                  <span>Platform mode: <strong className="capitalize text-zinc-100">{platform} (Read-Only)</strong></span>
                </div>
                <div className="flex items-center gap-2 text-zinc-300">
                  <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                  <span>Goal profile initialized: <strong className="text-emerald-400">Active</strong></span>
                </div>
              </div>

              <div className="pt-4 border-t border-zinc-800/80 flex items-center justify-center">
                <button
                  type="button"
                  onClick={handleFinish}
                  className="inline-flex items-center gap-2 px-6 py-3 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold rounded-xl text-xs transition shadow-xl shadow-emerald-500/20"
                >
                  <span>Go to Overview Dashboard</span>
                  <ArrowRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
