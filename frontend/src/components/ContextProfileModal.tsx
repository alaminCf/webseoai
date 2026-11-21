'use client';

import React, { useState, useEffect } from 'react';
import {
  X,
  Building,
  Target,
  Sparkles,
  ShieldCheck,
  CheckCircle2,
  AlertCircle,
  Plus,
  Trash2,
  Save,
  Clock,
  Layers,
} from 'lucide-react';
import { Website, WebsiteContextProfile } from '@/types';
import { api } from '@/lib/api';
import { useToast } from '@/context/ToastContext';
import { useAuth } from '@/context/AuthContext';

interface ContextProfileModalProps {
  website: Website | null;
  isOpen: boolean;
  onClose: () => void;
  onProfileUpdated?: () => void;
}

export function ContextProfileModal({
  website,
  isOpen,
  onClose,
  onProfileUpdated,
}: ContextProfileModalProps) {
  const toast = useToast();
  const { currentOrganization } = useAuth();
  const [profile, setProfile] = useState<WebsiteContextProfile | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isSaving, setIsSaving] = useState<boolean>(false);
  const [isApproving, setIsApproving] = useState<boolean>(false);

  // Form states
  const [businessName, setBusinessName] = useState<string>('');
  const [businessDescription, setBusinessDescription] = useState<string>('');
  const [targetAudience, setTargetAudience] = useState<string>('');
  const [productsAndServices, setProductsAndServices] = useState<string>('');
  const [brandVoice, setBrandVoice] = useState<string>('');
  const [goalInput, setGoalInput] = useState<string>('');
  const [businessGoals, setBusinessGoals] = useState<string[]>([]);

  useEffect(() => {
    if (!isOpen || !website) return;

    const fetchProfile = async () => {
      setIsLoading(true);
      try {
        const res = await api.get<{ profile: WebsiteContextProfile; connected_summary: any }>(
          `/websites/${website.id}/context-profile`
        );
        if (res.success && res.data) {
          const p = res.data.profile;
          setProfile(p);
          setBusinessName(p.business_name || website.name || '');
          setBusinessDescription(p.business_description || '');
          setTargetAudience(p.target_audience || '');
          setProductsAndServices(p.products_and_services || '');
          setBrandVoice(p.brand_voice || 'Professional, authoritative, and helpful');
          setBusinessGoals(p.business_goals || ['Increase organic visibility', 'Drive qualified search traffic']);
        }
      } catch {
        toast.error('Failed to load website context profile.');
      } finally {
        setIsLoading(false);
      }
    };

    fetchProfile();
  }, [isOpen, website, toast]);

  if (!isOpen || !website) return null;

  const handleSave = async () => {
    setIsSaving(true);
    try {
      const res = await api.put<WebsiteContextProfile>(
        `/websites/${website.id}/context-profile`,
        {
          business_name: businessName,
          business_description: businessDescription,
          target_audience: targetAudience,
          products_and_services: productsAndServices,
          brand_voice: brandVoice,
          business_goals: businessGoals,
        }
      );

      if (res.success && res.data) {
        setProfile(res.data);
        toast.success('Website context profile saved successfully.');
        onProfileUpdated?.();
      } else {
        toast.error(res.error?.message || 'Could not save profile.');
      }
    } catch {
      toast.error('Network error saving context profile.');
    } finally {
      setIsSaving(false);
    }
  };

  const handleApprove = async () => {
    setIsApproving(true);
    try {
      const res = await api.post<WebsiteContextProfile>(
        `/websites/${website.id}/context-profile/approve`
      );

      if (res.success && res.data) {
        setProfile(res.data);
        toast.success('Website context profile approved for AI generation.');
        onProfileUpdated?.();
      } else {
        toast.error(res.error?.message || 'Could not approve profile.');
      }
    } catch {
      toast.error('Network error approving context profile.');
    } finally {
      setIsApproving(false);
    }
  };

  const addGoal = () => {
    if (goalInput.trim() && !businessGoals.includes(goalInput.trim())) {
      setBusinessGoals([...businessGoals, goalInput.trim()]);
      setGoalInput('');
    }
  };

  const removeGoal = (index: number) => {
    setBusinessGoals(businessGoals.filter((_, i) => i !== index));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-zinc-950 border border-zinc-800 rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-800/80 bg-zinc-900/40">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-xl bg-purple-950/50 border border-purple-800/40 text-purple-400">
              <Sparkles className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
                Website Context & Brand Profile
              </h3>
              <p className="text-xs text-zinc-400 font-mono mt-0.5">
                {website.domain} — Grounding AI Strategist in your business identity
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60 rounded-lg transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="flex-1 overflow-y-auto p-6 space-y-5">
          {isLoading ? (
            <div className="p-12 text-center text-zinc-500">
              <div className="w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
              <span className="text-xs">Loading context profile...</span>
            </div>
          ) : (
            <>
              {/* Approval Status Banner */}
              <div className="p-4 bg-zinc-900/40 border border-zinc-800 rounded-xl flex items-center justify-between text-xs">
                <div className="flex items-center gap-2.5">
                  {profile?.approved_at ? (
                    <>
                      <ShieldCheck className="w-5 h-5 text-emerald-400" />
                      <div>
                        <span className="font-bold text-emerald-400 block">
                          Verified & Approved Profile
                        </span>
                        <span className="text-zinc-500 text-[11px]">
                          Approved by {profile.approver?.name || 'SEO Manager'} on{' '}
                          {new Date(profile.approved_at).toLocaleDateString()}
                        </span>
                      </div>
                    </>
                  ) : (
                    <>
                      <AlertCircle className="w-5 h-5 text-amber-400" />
                      <div>
                        <span className="font-bold text-amber-400 block">
                          Draft Context Profile (Pending Approval)
                        </span>
                        <span className="text-zinc-500 text-[11px]">
                          Requires review by an SEO Manager or Admin to lock brand voice.
                        </span>
                      </div>
                    </>
                  )}
                </div>

                {!profile?.approved_at && (
                  <button
                    onClick={handleApprove}
                    disabled={isApproving}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-semibold transition"
                  >
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    Approve Profile
                  </button>
                )}
              </div>

              {/* Business Name & Voice */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Business / Brand Name</label>
                  <input
                    type="text"
                    value={businessName}
                    onChange={(e) => setBusinessName(e.target.value)}
                    placeholder="e.g. Acme Tech Solutions"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-purple-500"
                  />
                </div>
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Brand Tone of Voice</label>
                  <input
                    type="text"
                    value={brandVoice}
                    onChange={(e) => setBrandVoice(e.target.value)}
                    placeholder="e.g. Authoritative, educational, concise"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              {/* Description */}
              <div className="text-xs">
                <label className="block text-zinc-400 mb-1.5 font-medium">Business Description & Mission</label>
                <textarea
                  rows={3}
                  value={businessDescription}
                  onChange={(e) => setBusinessDescription(e.target.value)}
                  placeholder="What does your company do? What primary problems do you solve for customers?"
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-purple-500 leading-relaxed"
                />
              </div>

              {/* Target Audience & Products */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Target Audience / ICP</label>
                  <textarea
                    rows={2}
                    value={targetAudience}
                    onChange={(e) => setTargetAudience(e.target.value)}
                    placeholder="e.g. B2B DevOps engineers, small business owners"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-purple-500"
                  />
                </div>
                <div>
                  <label className="block text-zinc-400 mb-1.5 font-medium">Core Products & Offerings</label>
                  <textarea
                    rows={2}
                    value={productsAndServices}
                    onChange={(e) => setProductsAndServices(e.target.value)}
                    placeholder="e.g. Cloud backup SaaS, Managed SEO migrations"
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-zinc-100 focus:outline-none focus:border-purple-500"
                  />
                </div>
              </div>

              {/* Business Goals Tags */}
              <div className="space-y-2 text-xs">
                <label className="block text-zinc-400 font-medium">Strategic SEO & Business Goals</label>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={goalInput}
                    onChange={(e) => setGoalInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        addGoal();
                      }
                    }}
                    placeholder="Add a target goal (e.g. 'Rank for enterprise cloud backup')..."
                    className="flex-1 bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-1.5 text-zinc-100 focus:outline-none focus:border-purple-500"
                  />
                  <button
                    onClick={addGoal}
                    className="px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 rounded-lg font-medium transition"
                  >
                    <Plus className="w-4 h-4" />
                  </button>
                </div>
                <div className="flex flex-wrap gap-2 pt-1">
                  {businessGoals.map((goal, idx) => (
                    <span
                      key={idx}
                      className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-950/40 text-purple-300 border border-purple-800/50 rounded-lg text-xs"
                    >
                      {goal}
                      <button
                        onClick={() => removeGoal(idx)}
                        className="hover:text-rose-400 transition"
                      >
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  ))}
                </div>
              </div>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 border-t border-zinc-800/80 bg-zinc-900/40 flex items-center justify-between">
          <span className="text-[11px] text-zinc-500 font-mono">
            Grounds AI Strategist, Briefs & Titles
          </span>
          <div className="flex items-center gap-2">
            <button
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-zinc-200 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-xl transition"
            >
              Cancel
            </button>
            <button
              onClick={handleSave}
              disabled={isSaving}
              className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-500 disabled:opacity-50 rounded-xl shadow transition"
            >
              <Save className="w-3.5 h-3.5" />
              Save Profile
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
