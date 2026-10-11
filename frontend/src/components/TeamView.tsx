'use client';

import React, { useState, useEffect, useCallback } from 'react';
import { Users, UserPlus, Shield, Trash2, Mail, CheckCircle2 } from 'lucide-react';
import { api } from '@/lib/api';
import { OrganizationMember, Role } from '@/types';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';

export function TeamView() {
  const { currentOrganization } = useAuth();
  const [members, setMembers] = useState<OrganizationMember[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isInviting, setIsInviting] = useState(false);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Role>('seo_manager');
  const [name, setName] = useState('');
  const toast = useToast();

  const loadMembers = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await api.get<OrganizationMember[]>('/organizations/current/members');
      if (res.success && res.data) {
        setMembers(res.data);
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadMembers();
  }, [loadMembers, currentOrganization]);

  const handleInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsInviting(true);

    try {
      const res = await api.post('/organizations/current/members', {
        email,
        role,
        name: name || undefined,
      });

      if (res.success) {
        toast.success(`Member invited as ${role}!`);
        setEmail('');
        setName('');
        await loadMembers();
      } else {
        toast.error(res.error?.message || 'Failed to add member.');
      }
    } finally {
      setIsInviting(false);
    }
  };

  const handleRoleChange = async (memberId: number, newRole: Role) => {
    try {
      const res = await api.patch(`/organizations/current/members/${memberId}`, { role: newRole });
      if (res.success) {
        toast.success('Role updated.');
        await loadMembers();
      } else {
        toast.error(res.error?.message || 'Could not update role.');
      }
    } catch {
      toast.error('Failed to update role.');
    }
  };

  const handleRemove = async (memberId: number, memberName: string) => {
    if (!confirm(`Are you sure you want to remove ${memberName} from this workspace?`)) return;

    try {
      const res = await api.delete(`/organizations/current/members/${memberId}`);
      if (res.success) {
        toast.info('Member removed.');
        await loadMembers();
      } else {
        toast.error(res.error?.message || 'Could not remove member.');
      }
    } catch {
      toast.error('Failed to remove member.');
    }
  };

  const roleColors: Record<Role, string> = {
    owner: 'bg-amber-950/40 text-amber-400 border-amber-800/40',
    admin: 'bg-emerald-950/40 text-emerald-400 border-emerald-800/40',
    seo_manager: 'bg-sky-950/40 text-sky-400 border-sky-800/40',
    editor: 'bg-indigo-950/40 text-indigo-400 border-indigo-800/40',
    viewer: 'bg-zinc-800 text-zinc-400 border-zinc-700',
  };

  return (
    <div className="space-y-6">
      {/* Top Banner */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <div>
          <h2 className="text-lg font-bold text-zinc-100 flex items-center gap-2">
            <Users className="w-5 h-5 text-emerald-400" />
            Workspace Team & RBAC Permissions
          </h2>
          <p className="text-xs text-zinc-400 mt-1">
            Manage who has access to {currentOrganization?.name || 'this organization'} and their administrative privileges.
          </p>
        </div>
      </div>

      {/* Invite Member Card */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl">
        <h3 className="text-sm font-semibold text-zinc-200 mb-3 flex items-center gap-2">
          <UserPlus className="w-4 h-4 text-emerald-400" />
          Add Member to Workspace
        </h3>
        <form onSubmit={handleInvite} className="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Full Name (Optional)"
              className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
            />
          </div>
          <div>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="colleague@techboloy.com"
              className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
            />
          </div>
          <div>
            <select
              value={role}
              onChange={(e) => setRole(e.target.value as Role)}
              className="w-full px-3 py-2 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-200 focus:outline-none focus:border-emerald-500"
            >
              <option value="admin">Admin (Manage members & sites)</option>
              <option value="seo_manager">SEO Manager (Audits & Integrations)</option>
              <option value="editor">Editor (Create & edit websites)</option>
              <option value="viewer">Viewer (Read-only reports)</option>
            </select>
          </div>
          <div>
            <button
              type="submit"
              disabled={isInviting}
              className="w-full py-2 px-4 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 rounded-xl shadow transition"
            >
              {isInviting ? 'Adding...' : 'Add Member'}
            </button>
          </div>
        </form>
      </div>

      {/* Members Table */}
      <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl overflow-hidden">
        <div className="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
          <h4 className="text-xs font-bold uppercase tracking-wider text-zinc-400">
            Active Members ({members.length})
          </h4>
        </div>
        <div className="divide-y divide-zinc-800/60">
          {members.map((m) => (
            <div key={m.id} className="p-4 sm:px-6 flex items-center justify-between gap-4 hover:bg-zinc-900/30 transition">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-full bg-zinc-900 border border-zinc-800 flex items-center justify-center text-xs font-bold text-zinc-300">
                  {m.name.charAt(0)}
                </div>
                <div>
                  <div className="text-sm font-semibold text-zinc-200">{m.name}</div>
                  <div className="text-xs text-zinc-500 flex items-center gap-1.5">
                    <Mail className="w-3 h-3 text-zinc-600" />
                    {m.email}
                  </div>
                </div>
              </div>

              <div className="flex items-center gap-3">
                <span className={`text-[10px] uppercase font-bold tracking-wider px-2.5 py-0.5 rounded-full border ${roleColors[m.role] || ''}`}>
                  {m.role}
                </span>

                {m.role !== 'owner' && (
                  <select
                    value={m.role}
                    onChange={(e) => handleRoleChange(m.id, e.target.value as Role)}
                    className="px-2 py-1 bg-zinc-900 border border-zinc-800 text-[11px] text-zinc-300 rounded-lg focus:outline-none"
                  >
                    <option value="admin">Admin</option>
                    <option value="seo_manager">SEO Manager</option>
                    <option value="editor">Editor</option>
                    <option value="viewer">Viewer</option>
                  </select>
                )}

                {m.role !== 'owner' && (
                  <button
                    onClick={() => handleRemove(m.id, m.name)}
                    className="p-1.5 text-zinc-500 hover:text-rose-400 hover:bg-zinc-900 rounded-lg transition"
                    title="Remove member"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
