'use client';

import React from 'react';
import { Sun, Moon, Monitor } from 'lucide-react';
import { useTheme, ThemePreference } from '@/context/ThemeContext';

interface ThemeToggleProps {
  variant?: 'segmented' | 'compact';
  className?: string;
}

const OPTIONS: Array<{ id: ThemePreference; label: string; icon: React.ComponentType<{ className?: string }> }> = [
  { id: 'light', label: 'Light', icon: Sun },
  { id: 'system', label: 'System', icon: Monitor },
  { id: 'dark', label: 'Dark', icon: Moon },
];

export function ThemeToggle({ variant = 'segmented', className = '' }: ThemeToggleProps) {
  const { theme, setTheme } = useTheme();

  if (variant === 'compact') {
    return (
      <div
        className={`inline-flex items-center p-0.5 rounded-xl bg-zinc-900 border border-zinc-800 ${className}`}
        role="group"
        aria-label="Theme mode"
      >
        {OPTIONS.map((opt) => {
          const Icon = opt.icon;
          const active = theme === opt.id;
          return (
            <button
              key={opt.id}
              type="button"
              onClick={() => setTheme(opt.id)}
              title={`${opt.label} Mode`}
              aria-label={`Switch to ${opt.label} theme`}
              aria-pressed={active}
              className={`p-1.5 rounded-lg transition ${
                active
                  ? 'bg-emerald-600 text-white shadow-sm'
                  : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60'
              }`}
            >
              <Icon className="w-3.5 h-3.5" />
            </button>
          );
        })}
      </div>
    );
  }

  return (
    <div
      className={`grid grid-cols-3 gap-1 p-1 rounded-xl bg-zinc-900 border border-zinc-800 ${className}`}
      role="group"
      aria-label="Theme mode"
    >
      {OPTIONS.map((opt) => {
        const Icon = opt.icon;
        const active = theme === opt.id;
        return (
          <button
            key={opt.id}
            type="button"
            onClick={() => setTheme(opt.id)}
            aria-pressed={active}
            className={`flex items-center justify-center gap-1.5 py-1.5 px-2 rounded-lg text-[11px] font-medium transition ${
              active
                ? 'bg-emerald-600 text-white shadow-sm'
                : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/70'
            }`}
          >
            <Icon className="w-3.5 h-3.5 shrink-0" />
            <span>{opt.label}</span>
          </button>
        );
      })}
    </div>
  );
}
