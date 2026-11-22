'use client';

import React, { createContext, useContext, useEffect, useState, useCallback, useMemo } from 'react';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

interface ThemeContextValue {
  theme: ThemePreference;
  resolvedTheme: ResolvedTheme;
  setTheme: (theme: ThemePreference) => void;
  cycleTheme: () => void;
}

const STORAGE_KEY = 'web_seo_ai_theme';

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

function getSystemTheme(): ResolvedTheme {
  if (typeof window === 'undefined') return 'dark';
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyThemeToDocument(resolved: ResolvedTheme) {
  if (typeof document === 'undefined') return;
  const root = document.documentElement;
  root.classList.remove('light', 'dark');
  root.classList.add(resolved);
  root.dataset.theme = resolved;
  root.style.colorScheme = resolved;
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = useState<ThemePreference>('system');
  const [resolvedTheme, setResolvedTheme] = useState<ResolvedTheme>('dark');

  // Hydrate saved preference on mount
  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(STORAGE_KEY) as ThemePreference | null;
      const initial: ThemePreference =
        saved === 'light' || saved === 'dark' || saved === 'system' ? saved : 'dark';
      setThemeState(initial);
      const resolved = initial === 'system' ? getSystemTheme() : initial;
      setResolvedTheme(resolved);
      applyThemeToDocument(resolved);
    } catch {
      applyThemeToDocument('dark');
    }
  }, []);

  // Listen to OS system theme changes when preference is 'system'
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const sync = () => {
      const resolved = theme === 'system' ? (media.matches ? 'dark' : 'light') : theme;
      setResolvedTheme(resolved);
      applyThemeToDocument(resolved);
    };

    sync();

    if (theme === 'system') {
      media.addEventListener('change', sync);
      return () => media.removeEventListener('change', sync);
    }
  }, [theme]);

  const setTheme = useCallback((next: ThemePreference) => {
    setThemeState(next);
    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Ignore storage quota errors
    }
    const resolved = next === 'system' ? getSystemTheme() : next;
    setResolvedTheme(resolved);
    applyThemeToDocument(resolved);
  }, []);

  const cycleTheme = useCallback(() => {
    setTheme(theme === 'dark' ? 'light' : theme === 'light' ? 'system' : 'dark');
  }, [theme, setTheme]);

  const value = useMemo(
    () => ({ theme, resolvedTheme, setTheme, cycleTheme }),
    [theme, resolvedTheme, setTheme, cycleTheme]
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }
  return ctx;
}
