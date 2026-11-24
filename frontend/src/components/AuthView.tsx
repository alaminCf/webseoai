'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  Sparkles,
  Lock,
  Mail,
  ArrowRight,
  User,
  Building2,
  Eye,
  EyeOff,
  CheckCircle2,
  AlertCircle,
  ShieldCheck,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { ThemeToggle } from '@/components/ThemeToggle';

export function AuthView() {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [name, setName] = useState('');
  const [orgName, setOrgName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { login, register } = useAuth();

  const isRegister = mode === 'register';

  // Live password strength checks matching Laravel Password::min(8)->letters()->numbers()
  const hasMinLength = password.length >= 8;
  const hasLetter = /[a-zA-Z]/.test(password);
  const hasNumber = /\d/.test(password);
  const passwordsMatch = password.length > 0 && password === confirmPassword;

  const switchMode = (nextMode: 'login' | 'register') => {
    setMode(nextMode);
    setFormError(null);
    setConfirmPassword('');
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);

    if (isRegister) {
      if (name.trim().length < 2) {
        setFormError('Please enter your full name (at least 2 characters).');
        return;
      }
      if (!hasMinLength || !hasLetter || !hasNumber) {
        setFormError('Password must be at least 8 characters and include both letters and numbers.');
        return;
      }
      if (password !== confirmPassword) {
        setFormError('Passwords do not match. Please re-enter your password.');
        return;
      }
    }

    setIsSubmitting(true);
    try {
      const result = isRegister
        ? await register(name, email, password, confirmPassword, orgName)
        : await login(email, password);

      if (!result.success && result.error) {
        setFormError(result.error);
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen bg-black text-zinc-100 flex flex-col justify-center items-center p-4 sm:p-6 relative">
      <div className="fixed top-4 right-4 z-20">
        <ThemeToggle variant="compact" />
      </div>

      <div className="w-full max-w-md">
        {/* Brand Banner */}
        <div className="text-center mb-7">
          <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-400 mb-3 shadow-lg shadow-emerald-950/40">
            <Sparkles className="w-6 h-6" />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-zinc-100">
            Web SEO AI
          </h1>
          <p className="text-xs text-zinc-400 mt-1">
            Autonomous SEO Intelligence, Live Rank Tracking &amp; Reporting Platform
          </p>
        </div>

        {/* Auth Card */}
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-2xl p-7">
          {/* Segmented Mode Switcher */}
          <div className="grid grid-cols-2 gap-1 p-1 bg-zinc-900 border border-zinc-800 rounded-xl mb-6">
            <button
              type="button"
              onClick={() => switchMode('login')}
              className={`py-2 text-xs font-semibold rounded-lg transition ${
                !isRegister
                  ? 'bg-emerald-600 text-white shadow'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              Sign In
            </button>
            <button
              type="button"
              onClick={() => switchMode('register')}
              className={`py-2 text-xs font-semibold rounded-lg transition ${
                isRegister
                  ? 'bg-emerald-600 text-white shadow'
                  : 'text-zinc-400 hover:text-zinc-200'
              }`}
            >
              Create Account
            </button>
          </div>

          <div className="mb-5">
            <h2 className="text-base font-bold text-zinc-100">
              {isRegister ? 'Create your workspace' : 'Sign in to your workspace'}
            </h2>
            <p className="text-xs text-zinc-400 mt-0.5">
              {isRegister
                ? 'Start with your own isolated SEO workspace in seconds.'
                : 'Enter your work email and password to continue.'}
            </p>
          </div>

          {/* Inline Error Alert */}
          {formError && (
            <div className="mb-4 p-3 rounded-xl bg-rose-950/40 border border-rose-500/40 text-rose-200 text-xs flex items-start gap-2.5">
              <AlertCircle className="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
              <span>{formError}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            {isRegister && (
              <>
                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Full Name <span className="text-rose-400">*</span>
                  </label>
                  <div className="relative">
                    <User className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                    <input
                      type="text"
                      required
                      autoComplete="name"
                      value={name}
                      onChange={(e) => {
                        setName(e.target.value);
                        if (formError) setFormError(null);
                      }}
                      placeholder="e.g. Sarah Johnson"
                      className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                    Workspace / Agency Name{' '}
                    <span className="text-zinc-500 font-normal">(Optional)</span>
                  </label>
                  <div className="relative">
                    <Building2 className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                    <input
                      type="text"
                      autoComplete="organization"
                      value={orgName}
                      onChange={(e) => setOrgName(e.target.value)}
                      placeholder="e.g. Acme Digital or My Brand"
                      className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                </div>
              </>
            )}

            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                Email Address <span className="text-rose-400">*</span>
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type="email"
                  required
                  autoComplete="email"
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value);
                    if (formError) setFormError(null);
                  }}
                  placeholder="you@company.com"
                  className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                Password <span className="text-rose-400">*</span>
              </label>
              <div className="relative">
                <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  autoComplete={isRegister ? 'new-password' : 'current-password'}
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value);
                    if (formError) setFormError(null);
                  }}
                  placeholder={isRegister ? 'Min. 8 chars with letters & numbers' : 'Enter your password'}
                  className="w-full pl-10 pr-10 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((prev) => !prev)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300 transition"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>

              {/* Live Password Rules Checklist for Registration */}
              {isRegister && password.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                  <span className={`flex items-center gap-1 ${hasMinLength ? 'text-emerald-400' : 'text-zinc-500'}`}>
                    <CheckCircle2 className="w-3 h-3" /> 8+ chars
                  </span>
                  <span className={`flex items-center gap-1 ${hasLetter ? 'text-emerald-400' : 'text-zinc-500'}`}>
                    <CheckCircle2 className="w-3 h-3" /> Letter
                  </span>
                  <span className={`flex items-center gap-1 ${hasNumber ? 'text-emerald-400' : 'text-zinc-500'}`}>
                    <CheckCircle2 className="w-3 h-3" /> Number
                  </span>
                </div>
              )}
            </div>

            {isRegister && (
              <div>
                <label className="block text-xs font-medium text-zinc-300 mb-1.5">
                  Confirm Password <span className="text-rose-400">*</span>
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    required
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(e) => {
                      setConfirmPassword(e.target.value);
                      if (formError) setFormError(null);
                    }}
                    placeholder="Re-enter your password"
                    className={`w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none ${
                      confirmPassword.length > 0 && !passwordsMatch
                        ? 'border-rose-500/60 focus:border-rose-500'
                        : 'border-zinc-800 focus:border-emerald-500'
                    }`}
                  />
                </div>
                {confirmPassword.length > 0 && !passwordsMatch && (
                  <p className="text-[11px] text-rose-400 mt-1">Passwords do not match.</p>
                )}
              </div>
            )}

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full mt-2 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow-md transition flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <span>
                {isSubmitting
                  ? isRegister
                    ? 'Creating Workspace...'
                    : 'Signing In...'
                  : isRegister
                    ? 'Create Account & Workspace'
                    : 'Sign In'}
              </span>
              <ArrowRight className="w-4 h-4" />
            </button>
          </form>

          {/* Bottom helper switch */}
          <div className="mt-5 pt-4 border-t border-zinc-800/80 text-center text-xs text-zinc-400">
            {isRegister ? (
              <>
                Already have an account?{' '}
                <button
                  type="button"
                  onClick={() => switchMode('login')}
                  className="text-emerald-400 hover:text-emerald-300 font-semibold transition"
                >
                  Sign In
                </button>
              </>
            ) : (
              <>
                New to Web SEO AI?{' '}
                <button
                  type="button"
                  onClick={() => switchMode('register')}
                  className="text-emerald-400 hover:text-emerald-300 font-semibold transition"
                >
                  Create a free workspace
                </button>
              </>
            )}
          </div>
        </div>

        {/* Security & Legal Footer */}
        <div className="text-center mt-6 text-xs text-zinc-500 space-y-2">
          <div className="inline-flex items-center gap-1.5 text-[11px] text-zinc-400">
            <ShieldCheck className="w-3.5 h-3.5 text-emerald-500/80" />
            <span>Isolated Multi-Tenant Workspaces &amp; AES-256 Encrypted OAuth Tokens</span>
          </div>
          <div className="flex items-center justify-center gap-4 text-[11px]">
            <Link href="/privacy" className="text-zinc-400 hover:text-emerald-400 transition">
              Privacy Policy
            </Link>
            <span className="text-zinc-700">•</span>
            <Link href="/terms" className="text-zinc-400 hover:text-emerald-400 transition">
              Terms of Service
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
