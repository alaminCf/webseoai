'use client';

import React, { useState } from 'react';
import {
  UserCircle2,
  CreditCard,
  Users,
  Clock,
} from 'lucide-react';
import { Website } from '@/types';
import { ProfileSettingsView } from './ProfileSettingsView';
import { BillingAndAgencyView } from './BillingAndAgencyView';
import { TeamView } from './TeamView';
import { ActivityView } from './ActivityView';

interface UnifiedSettingsViewProps {
  selectedWebsite: Website | null;
}

export function UnifiedSettingsView({
  selectedWebsite,
}: UnifiedSettingsViewProps) {
  const [subTab, setSubTab] = useState<'profile' | 'billing' | 'team' | 'audit_trail'>('profile');

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Sub Tabs Navigation */}
      <div className="flex flex-wrap items-center gap-2 border-b border-zinc-800 pb-3 text-xs">
        <button
          onClick={() => setSubTab('profile')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'profile'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <UserCircle2 className="w-3.5 h-3.5" />
          <span>My Profile</span>
        </button>

        <button
          onClick={() => setSubTab('billing')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'billing'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <CreditCard className="w-3.5 h-3.5" />
          <span>Plans, Billing &amp; Agency Hub</span>
        </button>

        <button
          onClick={() => setSubTab('team')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'team'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Users className="w-3.5 h-3.5" />
          <span>Team Members &amp; Roles</span>
        </button>

        <button
          onClick={() => setSubTab('audit_trail')}
          className={`px-3.5 py-2 rounded-xl font-medium transition flex items-center gap-2 ${
            subTab === 'audit_trail'
              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'
              : 'text-zinc-400 hover:text-zinc-200'
          }`}
        >
          <Clock className="w-3.5 h-3.5" />
          <span>Audit Trail &amp; Activity</span>
        </button>
      </div>

      {/* Render Selected Sub View */}
      {subTab === 'profile' && <ProfileSettingsView />}

      {subTab === 'billing' && (
        <BillingAndAgencyView selectedWebsite={selectedWebsite} />
      )}

      {subTab === 'team' && (
        <TeamView />
      )}

      {subTab === 'audit_trail' && (
        <ActivityView />
      )}
    </div>
  );
}
