'use client';

import React, { useState, useEffect, useCallback } from 'react';
import { Clock, Shield, User, Globe, Key, FileText } from 'lucide-react';
import { api } from '@/lib/api';
import { AuditLog } from '@/types';
import { useAuth } from '@/context/AuthContext';

export function ActivityView() {
  const { currentOrganization } = useAuth();
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const loadLogs = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<AuditLog[]>('/audit-logs');
      if (res.success && res.data) {
        setLogs(res.data);
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadLogs();
  }, [loadLogs, currentOrganization]);

  const getActionBadge = (action: string) => {
    if (action.startsWith('website.')) {
      return { icon: Globe, color: 'text-emerald-400 bg-emerald-950/40 border-emerald-800/40' };
    }
    if (action.startsWith('integration.')) {
      return { icon: Key, color: 'text-sky-400 bg-sky-950/40 border-sky-800/40' };
    }
    if (action.startsWith('member.')) {
      return { icon: User, color: 'text-indigo-400 bg-indigo-950/40 border-indigo-800/40' };
    }
    return { icon: Shield, color: 'text-amber-400 bg-amber-950/40 border-amber-800/40' };
  };

  return (
    <div className="space-y-6">
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <h2 className="text-lg font-bold text-zinc-100 flex items-center gap-2">
          <Clock className="w-5 h-5 text-emerald-400" />
          Workspace Audit Trail
        </h2>
        <p className="text-xs text-zinc-400 mt-1">
          Immutable audit record of all administrative actions, website alterations, and CMS connections in {currentOrganization?.name || 'this workspace'}.
        </p>
      </div>

      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
          <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">
            Recent Activity ({logs.length})
          </h4>
        </div>

        {isLoading ? (
          <div className="p-8 text-center text-xs text-zinc-500">Loading audit log events...</div>
        ) : logs.length === 0 ? (
          <div className="p-8 text-center text-xs text-zinc-500">No activity recorded yet in this workspace.</div>
        ) : (
          <div className="divide-y divide-zinc-800/60">
            {logs.map((log) => {
              const badge = getActionBadge(log.action);
              const Icon = badge.icon;
              return (
                <div key={log.id} className="p-4 sm:px-6 flex items-start justify-between gap-4 hover:bg-zinc-900/30 transition">
                  <div className="flex items-start gap-3">
                    <div className={`p-2 rounded-xl border shrink-0 mt-0.5 ${badge.color}`}>
                      <Icon className="w-4 h-4" />
                    </div>
                    <div>
                      <div className="text-xs font-semibold text-zinc-200">
                        {log.action}
                      </div>
                      <div className="text-[11px] text-zinc-400 mt-0.5 flex items-center gap-2">
                        <span>by <strong>{log.user?.name || 'System'}</strong></span>
                        {log.ip_address && (
                          <span className="text-zinc-600 font-mono text-[10px]">({log.ip_address})</span>
                        )}
                      </div>
                      {log.new_values && (
                        <div className="mt-2 text-[10px] font-mono text-zinc-400 bg-zinc-900/80 p-2 rounded-lg border border-zinc-800/80 max-w-md">
                          {JSON.stringify(log.new_values)}
                        </div>
                      )}
                    </div>
                  </div>
                  <div className="text-[11px] text-zinc-400 shrink-0 font-mono">
                    {new Date(log.created_at).toLocaleString()}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
