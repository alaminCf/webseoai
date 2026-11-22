import type { Metadata } from 'next';
import './globals.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'Web SEO AI — Enterprise AI-Powered SEO Automation Platform',
  description: 'Multi-tenant SEO platform for Techboloy agency and client operations. Automated auditing, AI content generation, and approval workflows.',
};

const themeInitScript = `
(function() {
  try {
    var saved = localStorage.getItem('web_seo_ai_theme');
    var pref = (saved === 'light' || saved === 'dark' || saved === 'system') ? saved : 'dark';
    var resolved = pref === 'system'
      ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : pref;
    document.documentElement.classList.remove('light', 'dark');
    document.documentElement.classList.add(resolved);
    document.documentElement.dataset.theme = resolved;
    document.documentElement.style.colorScheme = resolved;
  } catch (e) {}
})();
`;

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="dark h-full antialiased bg-black text-zinc-100" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
      </head>
      <body className="min-h-full flex flex-col font-sans selection:bg-emerald-500/20 selection:text-emerald-300">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
