'use client';

import React, { useState, useEffect, useRef } from 'react';
import {
  User as UserIcon,
  Mail,
  Briefcase,
  Phone,
  FileText,
  Upload,
  Trash2,
  Save,
  Lock,
  ShieldCheck,
  CheckCircle2,
  Eye,
  EyeOff,
  Camera,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { useToast } from '@/context/ToastContext';
import { api } from '@/lib/api';
import { User } from '@/types';

export function ProfileSettingsView() {
  const { user, currentOrganization, updateUserProfile } = useAuth();
  const toast = useToast();

  // Profile form state
  const [name, setName] = useState(user?.name || '');
  const [jobTitle, setJobTitle] = useState(user?.job_title || '');
  const [phone, setPhone] = useState(user?.phone || '');
  const [bio, setBio] = useState(user?.bio || '');
  const [avatarUrl, setAvatarUrl] = useState<string>(user?.avatar_url || '');
  const [avatarFailed, setAvatarFailed] = useState(false);
  const [isSavingProfile, setIsSavingProfile] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  // Password form state
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmNewPassword, setConfirmNewPassword] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);
  const [isSavingPassword, setIsSavingPassword] = useState(false);

  useEffect(() => {
    if (user) {
      setName(user.name || '');
      setJobTitle(user.job_title || '');
      setPhone(user.phone || '');
      setBio(user.bio || '');
      setAvatarUrl(user.avatar_url || '');
      setAvatarFailed(false);
    }
  }, [user]);

  const isGooglePhoto = Boolean(avatarUrl && avatarUrl.includes('googleusercontent.com'));
  const isCustomPhoto = Boolean(avatarUrl && avatarUrl.startsWith('data:image/'));
  const isGoogleOnly = user?.auth_provider === 'google';

  const handleAvatarUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Please upload a PNG, JPG, or WebP image.');
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      toast.error('Image must be smaller than 4 MB.');
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const rawDataUrl = reader.result as string;
      const img = new window.Image();
      img.onload = () => {
        // Center-crop and resize to crisp 256x256 PNG for fast loading and small payload
        const size = 256;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          setAvatarUrl(rawDataUrl);
          setAvatarFailed(false);
          return;
        }
        const minSide = Math.min(img.width, img.height);
        const sx = (img.width - minSide) / 2;
        const sy = (img.height - minSide) / 2;
        ctx.drawImage(img, sx, sy, minSide, minSide, 0, 0, size, size);
        const normalized = canvas.toDataURL('image/png', 0.9);
        setAvatarUrl(normalized);
        setAvatarFailed(false);
        toast.info('Photo ready! Click "Save Profile Changes" to apply.');
      };
      img.onerror = () => {
        toast.error('Could not read the selected image file.');
      };
      img.src = rawDataUrl;
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  };

  const handleSaveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    if (name.trim().length < 2) {
      toast.error('Full name must be at least 2 characters.');
      return;
    }

    setIsSavingProfile(true);
    try {
      const res = await api.put<{ message: string; user: User }>('/auth/profile', {
        name: name.trim(),
        job_title: jobTitle.trim(),
        phone: phone.trim(),
        bio: bio.trim(),
        avatar_url: avatarUrl || null,
      });

      if (res.success && res.data?.user) {
        updateUserProfile(res.data.user);
        toast.success(res.data.message || 'Profile saved successfully!');
      } else {
        toast.error(res.error?.message || 'Failed to update profile.');
      }
    } catch {
      toast.error('Could not update profile right now.');
    } finally {
      setIsSavingProfile(false);
    }
  };

  const handleUpdatePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword.length < 8 || !/[a-zA-Z]/.test(newPassword) || !/\d/.test(newPassword)) {
      toast.error('New password must be at least 8 characters and include letters and numbers.');
      return;
    }
    if (newPassword !== confirmNewPassword) {
      toast.error('New passwords do not match.');
      return;
    }

    setIsSavingPassword(true);
    try {
      const res = await api.put<{ message: string; user: User }>('/auth/password', {
        current_password: isGoogleOnly ? undefined : currentPassword,
        password: newPassword,
        password_confirmation: confirmNewPassword,
      });

      if (res.success && res.data) {
        if (res.data.user) {
          updateUserProfile(res.data.user);
        }
        setCurrentPassword('');
        setNewPassword('');
        setConfirmNewPassword('');
        toast.success(res.data.message || 'Password updated successfully!');
      } else {
        toast.error(res.error?.message || 'Failed to update password.');
      }
    } catch {
      toast.error('Could not update password right now.');
    } finally {
      setIsSavingPassword(false);
    }
  };

  return (
    <div className="space-y-6 max-w-4xl">
      {/* Header Summary Card */}
      <div className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-5">
        <div className="flex items-center gap-4">
          <div className="relative group">
            {avatarUrl && !avatarFailed ? (
              <img
                src={avatarUrl}
                alt={name || 'Profile photo'}
                referrerPolicy="no-referrer"
                onError={() => setAvatarFailed(true)}
                className="w-20 h-20 rounded-full object-cover border-2 border-emerald-500/40 bg-zinc-900 shadow-lg"
              />
            ) : (
              <div className="w-20 h-20 rounded-full bg-emerald-950/70 border-2 border-emerald-500/40 flex items-center justify-center text-2xl font-bold text-emerald-300 shadow-lg">
                {(name || user?.name || 'U').charAt(0).toUpperCase()}
              </div>
            )}
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              title="Change Profile Picture"
              className="absolute bottom-0 right-0 w-7 h-7 rounded-full bg-emerald-600 hover:bg-emerald-500 text-white border-2 border-zinc-950 flex items-center justify-center shadow transition"
            >
              <Camera className="w-3.5 h-3.5" />
            </button>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/png,image/jpeg,image/webp,image/gif"
              onChange={handleAvatarUpload}
              className="hidden"
            />
          </div>

          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-bold text-zinc-100">{name || user?.name}</h2>
              {user?.google_connected && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                  <CheckCircle2 className="w-3 h-3" />
                  Google Account Linked
                </span>
              )}
              {isGooglePhoto && (
                <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-zinc-900 text-zinc-300 border border-zinc-800">
                  Google Profile Photo
                </span>
              )}
              {isCustomPhoto && (
                <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
                  Custom Uploaded Photo
                </span>
              )}
            </div>
            <p className="text-xs text-zinc-400 mt-0.5">{user?.email}</p>
            <p className="text-[11px] text-zinc-500 mt-1">
              Workspace: <span className="text-zinc-300 font-medium">{currentOrganization?.name}</span>{' '}
              ({currentOrganization?.role || 'owner'})
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            className="px-3.5 py-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-xs font-semibold text-zinc-200 flex items-center gap-1.5 transition"
          >
            <Upload className="w-3.5 h-3.5 text-emerald-400" />
            Upload Photo
          </button>
          {avatarUrl && (
            <button
              type="button"
              onClick={() => {
                setAvatarUrl('');
                setAvatarFailed(false);
                toast.info('Photo removed. Click "Save Profile Changes" to confirm.');
              }}
              className="px-3 py-2 rounded-xl bg-zinc-900 hover:bg-rose-950/50 border border-zinc-800 hover:border-rose-500/30 text-xs font-semibold text-zinc-400 hover:text-rose-300 flex items-center gap-1.5 transition"
            >
              <Trash2 className="w-3.5 h-3.5" />
              Remove
            </button>
          )}
        </div>
      </div>

      {/* Personal Information Form */}
      <form
        onSubmit={handleSaveProfile}
        className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-5"
      >
        <div className="border-b border-zinc-800 pb-3">
          <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
            <UserIcon className="w-4 h-4 text-emerald-400" />
            Personal Information
          </h3>
          <p className="text-xs text-zinc-400 mt-0.5">
            Update your display name, role, contact details, and profile picture anytime.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Full Name <span className="text-rose-400">*</span>
            </label>
            <div className="relative">
              <UserIcon className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Your full name"
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Email Address
            </label>
            <div className="relative">
              <Mail className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type="email"
                value={user?.email || ''}
                disabled
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900/50 border border-zinc-800/70 rounded-xl text-xs text-zinc-400 cursor-not-allowed"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Job Title / Role
            </label>
            <div className="relative">
              <Briefcase className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type="text"
                value={jobTitle}
                onChange={(e) => setJobTitle(e.target.value)}
                placeholder="e.g. SEO Strategist, Agency Founder"
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Phone Number
            </label>
            <div className="relative">
              <Phone className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type="text"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="e.g. +65 9123 4567"
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>
        </div>

        <div>
          <label className="block text-xs font-medium text-zinc-300 mb-1.5">
            Bio / Notes
          </label>
          <div className="relative">
            <FileText className="w-4 h-4 absolute left-3.5 top-3 text-zinc-500" />
            <textarea
              rows={3}
              maxLength={500}
              value={bio}
              onChange={(e) => setBio(e.target.value)}
              placeholder="Tell your team a bit about your role or SEO focus..."
              className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500 resize-none"
            />
          </div>
          <div className="text-[10px] text-zinc-500 text-right mt-1">{bio.length}/500</div>
        </div>

        <div className="flex justify-end">
          <button
            type="submit"
            disabled={isSavingProfile}
            className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow flex items-center gap-2 transition disabled:opacity-50"
          >
            <Save className="w-3.5 h-3.5" />
            <span>{isSavingProfile ? 'Saving...' : 'Save Profile Changes'}</span>
          </button>
        </div>
      </form>

      {/* Password & Sign-In Security Card */}
      <form
        onSubmit={handleUpdatePassword}
        className="p-6 bg-zinc-950 border border-zinc-800/80 rounded-2xl space-y-5"
      >
        <div className="flex items-start justify-between gap-3 border-b border-zinc-800 pb-3">
          <div>
            <h3 className="text-sm font-bold text-zinc-100 flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-emerald-400" />
              {isGoogleOnly ? 'Set Account Password (Optional)' : 'Change Password'}
            </h3>
            <p className="text-xs text-zinc-400 mt-0.5">
              {isGoogleOnly
                ? 'You signed in with Google. Set a password if you also want to log in directly with your email.'
                : 'Keep your workspace secure with a strong password (min. 8 characters with letters & numbers).'}
            </p>
          </div>
          <button
            type="button"
            onClick={() => setShowPasswords((p) => !p)}
            className="text-xs text-zinc-400 hover:text-zinc-200 flex items-center gap-1"
          >
            {showPasswords ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
            <span>{showPasswords ? 'Hide' : 'Show'}</span>
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {!isGoogleOnly && (
            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                Current Password
              </label>
              <div className="relative">
                <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type={showPasswords ? 'text' : 'password'}
                  required
                  value={currentPassword}
                  onChange={(e) => setCurrentPassword(e.target.value)}
                  placeholder="Current password"
                  className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>
          )}

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              New Password
            </label>
            <div className="relative">
              <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type={showPasswords ? 'text' : 'password'}
                required
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Min. 8 chars + number"
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-zinc-300 mb-1.5">
              Confirm New Password
            </label>
            <div className="relative">
              <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
              <input
                type={showPasswords ? 'text' : 'password'}
                required
                value={confirmNewPassword}
                onChange={(e) => setConfirmNewPassword(e.target.value)}
                placeholder="Repeat new password"
                className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>
        </div>

        <div className="flex justify-end">
          <button
            type="submit"
            disabled={isSavingPassword}
            className="px-5 py-2.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-zinc-100 text-xs font-semibold flex items-center gap-2 transition disabled:opacity-50"
          >
            <Lock className="w-3.5 h-3.5 text-emerald-400" />
            <span>
              {isSavingPassword
                ? 'Updating...'
                : isGoogleOnly
                  ? 'Set Password'
                  : 'Update Password'}
            </span>
          </button>
        </div>
      </form>
    </div>
  );
}
