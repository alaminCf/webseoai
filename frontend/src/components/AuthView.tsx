'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { Sparkles, Lock, Mail, ArrowRight, ShieldCheck, User } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

export function AuthView() {
  const [isRegister, setIsRegister] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('admin@techboloy.com');
  const [password, setPassword] = useState('Admin1234!');
  const [orgName, setOrgName] = useState('Techboloy Agency');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { login, register } = useAuth();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    try {
      if (isRegister) {
        await register(name, email, password, orgName);
      } else {
        await login(email, password);
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleQuickDemoFill = (roleEmail: string, rolePass: string) => {
    setEmail(roleEmail);
    setPassword(rolePass);
    setIsRegister(false);
  };

  return (
    <div className="min-h-screen bg-black text-zinc-100 flex flex-col justify-center items-center p-4 sm:p-6">
      <div className="w-full max-w-md">
        {/* Brand Banner */}
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-400 mb-3 shadow-lg shadow-emerald-950/40">
            <Sparkles className="w-6 h-6" />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-zinc-100">
            Web SEO AI
          </h1>
          <p className="text-xs text-zinc-400 mt-1">
            Enterprise SEO Automation & Agency Hub by Techboloy
          </p>
        </div>

        {/* Auth Card */}
        <div className="bg-zinc-950 border border-zinc-800/80 rounded-2xl shadow-2xl p-7">
          <div className="flex items-center justify-between pb-5 mb-5 border-b border-zinc-800">
            <h2 className="text-sm font-semibold text-zinc-200">
              {isRegister ? 'Create Internal Account' : 'Sign In to Workspace'}
            </h2>
            <button
              onClick={() => setIsRegister(!isRegister)}
              className="text-xs text-emerald-400 hover:text-emerald-300 font-medium transition"
            >
              {isRegister ? 'Already have an account?' : 'Need an account?'}
            </button>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            {isRegister && (
              <>
                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">Full Name</label>
                  <div className="relative">
                    <User className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                    <input
                      type="text"
                      required
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Alamin Architect"
                      className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-zinc-300 mb-1.5">Agency / Org Name</label>
                  <input
                    type="text"
                    value={orgName}
                    onChange={(e) => setOrgName(e.target.value)}
                    placeholder="Techboloy Agency"
                    className="w-full px-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                  />
                </div>
              </>
            )}

            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">Work Email Address</label>
              <div className="relative">
                <Mail className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="admin@techboloy.com"
                  className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-zinc-300 mb-1.5">Password</label>
              <div className="relative">
                <Lock className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500" />
                <input
                  type="password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••••••"
                  className="w-full pl-10 pr-3.5 py-2.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-600 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full mt-2 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold shadow-md transition flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <span>{isSubmitting ? 'Authenticating...' : isRegister ? 'Create Account' : 'Sign In'}</span>
              <ArrowRight className="w-4 h-4" />
            </button>
          </form>

          {/* Quick seeded test accounts */}
          <div className="mt-6 pt-5 border-t border-zinc-800/80">
            <div className="text-[11px] font-semibold text-zinc-400 mb-2">
              Seeded Test Accounts (Click to Fill):
            </div>
            <div className="grid grid-cols-2 gap-2 text-[11px]">
              <button
                type="button"
                onClick={() => handleQuickDemoFill('admin@techboloy.com', 'Admin1234!')}
                className="p-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-left transition"
              >
                <div className="font-semibold text-zinc-200">Agency Owner</div>
                <div className="text-zinc-500 text-[10px]">admin@techboloy.com</div>
              </button>
              <button
                type="button"
                onClick={() => handleQuickDemoFill('specialist@techboloy.com', 'Specialist1234!')}
                className="p-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-left transition"
              >
                <div className="font-semibold text-zinc-200">SEO Specialist</div>
                <div className="text-zinc-500 text-[10px]">specialist@techboloy.com</div>
              </button>
            </div>
          </div>
        </div>

        <div className="text-center mt-6 text-xs text-zinc-500 space-y-2">
          <div>Protected by Multi-Tenant RBAC &amp; AES-256 Encrypted OAuth Tokens</div>
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
