import React from 'react';
import Link from 'next/link';
import { FileText, ArrowLeft } from 'lucide-react';

export const metadata = {
  title: 'Terms of Service | Web SEO AI',
  description: 'Terms of Service for Web SEO AI platform.',
};

export default function TermsOfServicePage() {
  return (
    <div className="min-h-screen bg-black text-zinc-200 px-4 py-12 sm:px-8">
      <div className="max-w-3xl mx-auto space-y-8">
        <div className="flex items-center justify-between border-b border-zinc-800 pb-6">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-400 flex items-center justify-center">
              <FileText className="w-5 h-5" />
            </div>
            <div>
              <h1 className="text-2xl font-bold text-zinc-100">Terms of Service</h1>
              <p className="text-xs text-zinc-400">Web SEO AI — Technical SEO &amp; Search Console Analytics Platform</p>
            </div>
          </div>
          <Link
            href="/"
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-zinc-300 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 rounded-lg transition"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            Back to App
          </Link>
        </div>

        <div className="space-y-6 text-sm leading-relaxed text-zinc-300">
          <p className="text-xs text-zinc-400">Effective Date: November 2026</p>

          <section className="space-y-2">
            <h2 className="text-base font-bold text-zinc-100">1. Acceptance of Terms</h2>
            <p>
              By accessing or using Web SEO AI (<span className="font-mono text-emerald-400">https://webseoai.vercel.app</span>),
              you agree to be bound by these Terms of Service and our{' '}
              <Link href="/privacy" className="text-emerald-400 underline hover:text-emerald-300">
                Privacy Policy
              </Link>
              .
            </p>
          </section>

          <section className="space-y-2">
            <h2 className="text-base font-bold text-zinc-100">2. Authorized Website Auditing &amp; Integrations</h2>
            <p>
              You agree to crawl, audit, and connect Google Search Console properties only for websites that you own or
              have explicit authorization to manage. Read-only Google Search Console access is used solely to display
              search performance analytics and indexing diagnostics inside your workspace.
            </p>
          </section>

          <section className="space-y-2">
            <h2 className="text-base font-bold text-zinc-100">3. No Guarantee of Search Rankings</h2>
            <p>
              Technical SEO audits, keyword insights, and URL inspection outputs are informational diagnostic tools.
              Web SEO AI does not guarantee specific search engine rankings, traffic levels, or indexing outcomes.
            </p>
          </section>

          <section className="space-y-2">
            <h2 className="text-base font-bold text-zinc-100">4. Contact</h2>
            <p>
              Questions regarding these terms may be sent to{' '}
              <a href="mailto:hellotechboloy@gmail.com" className="text-emerald-400 hover:underline">
                hellotechboloy@gmail.com
              </a>
              .
            </p>
          </section>
        </div>

        <footer className="pt-4 border-t border-zinc-800 flex items-center justify-between text-xs text-zinc-500">
          <span>Web SEO AI by Techboloy</span>
          <div className="flex items-center gap-4">
            <Link href="/privacy" className="hover:text-zinc-300">
              Privacy Policy
            </Link>
            <Link href="/" className="hover:text-zinc-300">
              Dashboard
            </Link>
          </div>
        </footer>
      </div>
    </div>
  );
}
