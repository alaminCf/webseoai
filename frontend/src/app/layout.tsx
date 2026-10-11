import type { Metadata } from 'next';
import './globals.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'Web SEO AI — Enterprise AI-Powered SEO Automation Platform',
  description: 'Multi-tenant SEO platform for Techboloy agency and client operations. Automated auditing, AI content generation, and approval workflows.',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="dark h-full antialiased bg-black text-zinc-100">
      <body className="min-h-full flex flex-col font-sans selection:bg-emerald-500/20 selection:text-emerald-300">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
