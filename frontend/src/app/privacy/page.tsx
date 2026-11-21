import React from 'react';
import Link from 'next/link';
import { ShieldCheck, ArrowLeft, Lock, Database, Trash2, Mail } from 'lucide-react';

export const metadata = {
  title: 'Privacy Policy | Web SEO AI',
  description: 'Privacy Policy and Google API Services User Data Policy disclosure for Web SEO AI.',
};

export default function PrivacyPolicyPage() {
  return (
    <div className="min-h-screen bg-black text-zinc-200 px-4 py-12 sm:px-8">
      <div className="max-w-3xl mx-auto space-y-8">
        <div className="flex items-center justify-between border-b border-zinc-800 pb-6">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-400 flex items-center justify-center">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <h1 className="text-2xl font-bold text-zinc-100">Privacy Policy</h1>
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

        <section className="space-y-3 text-sm leading-relaxed text-zinc-300">
          <p className="text-xs text-zinc-400">Effective Date: November 2026</p>
          <p>
            Web SEO AI (&quot;we&quot;, &quot;our&quot;, or &quot;the Platform&quot;), operated at{' '}
            <span className="font-mono text-emerald-400">https://webseoai.vercel.app</span>, respects your privacy and
            is committed to protecting your workspace, website, and Google Search Console data.
          </p>
        </section>

        <section className="p-5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
          <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
            <Database className="w-4 h-4 text-emerald-400" />
            1. Google Search Console Data We Access
          </h2>
          <p className="text-sm text-zinc-300 leading-relaxed">
            When you connect your Google account to Web SEO AI, we request only the read-only scope{' '}
            <code className="px-1.5 py-0.5 rounded bg-zinc-900 text-emerald-400 text-xs">
              https://www.googleapis.com/auth/webmasters.readonly
            </code>
            . This allows the platform strictly to:
          </p>
          <ul className="list-disc pl-5 space-y-1.5 text-sm text-zinc-300">
            <li>List the verified Search Console properties associated with your Google account.</li>
            <li>Retrieve search performance analytics (clicks, impressions, CTR, average position, queries, pages, devices, and countries) for the property you select.</li>
            <li>Run read-only Google URL Inspection checks on pages you explicitly request.</li>
          </ul>
          <p className="text-xs text-zinc-400">
            Web SEO AI never modifies your Search Console properties, sitemaps, or Google account settings.
          </p>
        </section>

        <section className="p-5 rounded-2xl bg-zinc-950 border border-emerald-900/50 space-y-3">
          <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
            <ShieldCheck className="w-4 h-4 text-emerald-400" />
            2. Google API Services User Data Policy (Limited Use Disclosure)
          </h2>
          <p className="text-sm text-zinc-300 leading-relaxed">
            Web SEO AI&apos;s use and transfer to any other app of information received from Google APIs will adhere to
            the{' '}
            <a
              href="https://developers.google.com/terms/api-services-user-data-policy"
              target="_blank"
              rel="noopener noreferrer"
              className="text-emerald-400 underline hover:text-emerald-300"
            >
              Google API Services User Data Policy
            </a>
            , including the Limited Use requirements.
          </p>
          <ul className="list-disc pl-5 space-y-1.5 text-sm text-zinc-300">
            <li>We do not sell, rent, or transfer Google user data to third parties or data brokers.</li>
            <li>We do not use Google Search Console data for serving advertisements.</li>
            <li>We do not use Google user data to train generalized AI or machine learning models.</li>
            <li>Access to stored analytics is strictly scoped to your authenticated organization workspace.</li>
          </ul>
        </section>

        <section className="p-5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
          <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
            <Lock className="w-4 h-4 text-emerald-400" />
            3. Data Storage &amp; Security
          </h2>
          <p className="text-sm text-zinc-300 leading-relaxed">
            OAuth access tokens and refresh tokens are encrypted at rest using AES-256-CBC encryption before storage in
            our managed PostgreSQL database. All communications between your browser, Web SEO AI, and Google APIs occur
            exclusively over HTTPS/TLS.
          </p>
        </section>

        <section className="p-5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
          <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
            <Trash2 className="w-4 h-4 text-emerald-400" />
            4. Revoking Access &amp; Data Deletion
          </h2>
          <p className="text-sm text-zinc-300 leading-relaxed">
            You may disconnect Google Search Console at any time from the <strong>Performance</strong> tab in Web SEO AI
            by clicking <strong>Disconnect</strong>, which revokes your OAuth token with Google and removes stored
            credentials immediately. You can also revoke access directly in your{' '}
            <a
              href="https://myaccount.google.com/permissions"
              target="_blank"
              rel="noopener noreferrer"
              className="text-emerald-400 underline hover:text-emerald-300"
            >
              Google Account Third-Party Connections
            </a>
            . Deleting a website or organization permanently deletes all associated synced search performance records.
          </p>
        </section>

        <section className="p-5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
          <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
            <Mail className="w-4 h-4 text-emerald-400" />
            5. Contact Us
          </h2>
          <p className="text-sm text-zinc-300">
            For privacy questions or data deletion requests, contact us at{' '}
            <a href="mailto:hellotechboloy@gmail.com" className="text-emerald-400 hover:underline">
              hellotechboloy@gmail.com
            </a>
            .
          </p>
        </section>

        <footer className="pt-4 border-t border-zinc-800 flex items-center justify-between text-xs text-zinc-500">
          <span>Web SEO AI by Techboloy</span>
          <div className="flex items-center gap-4">
            <Link href="/terms" className="hover:text-zinc-300">
              Terms of Service
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
