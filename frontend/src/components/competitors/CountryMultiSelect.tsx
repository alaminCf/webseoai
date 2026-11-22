'use client';

import React, { useState, useRef, useEffect, useMemo } from 'react';
import { Globe, ChevronDown, Search, Check, X } from 'lucide-react';
import { ALL_COUNTRIES, POPULAR_COUNTRY_CODES, countryFlag, getCountryName } from '@/lib/countries';

interface CountryMultiSelectProps {
  selected: string[];
  onChange: (countries: string[]) => void;
  maxSelection?: number;
  className?: string;
}

export function CountryMultiSelect({
  selected,
  onChange,
  maxSelection = 5,
  className = '',
}: CountryMultiSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleOutside);
    return () => document.removeEventListener('mousedown', handleOutside);
  }, []);

  const filteredCountries = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) {
      // Selected first, then popular countries, then full alphabetical list
      const selectedSet = new Set(selected);
      const popularSet = new Set(POPULAR_COUNTRY_CODES);
      const picked = ALL_COUNTRIES.filter((c) => selectedSet.has(c.code));
      const popular = ALL_COUNTRIES.filter((c) => !selectedSet.has(c.code) && popularSet.has(c.code));
      const rest = ALL_COUNTRIES.filter((c) => !selectedSet.has(c.code) && !popularSet.has(c.code));
      return [...picked, ...popular, ...rest];
    }
    return ALL_COUNTRIES.filter(
      (c) => c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q)
    );
  }, [query, selected]);

  const toggleCountry = (code: string) => {
    if (selected.includes(code)) {
      if (selected.length === 1) return; // Always keep at least 1 country selected
      onChange(selected.filter((c) => c !== code));
    } else {
      if (selected.length >= maxSelection) return;
      onChange([...selected, code]);
    }
  };

  const selectOnly = (code: string) => {
    onChange([code]);
    setOpen(false);
    setQuery('');
  };

  const summaryLabel =
    selected.length === 1
      ? `${countryFlag(selected[0])} ${selected[0]} (${getCountryName(selected[0])})`
      : `${selected.map((c) => `${countryFlag(c)} ${c}`).slice(0, 3).join(', ')}${selected.length > 3 ? ` +${selected.length - 3}` : ''}`;

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="inline-flex items-center justify-between gap-2 min-w-[200px] max-w-[280px] bg-zinc-900 border border-zinc-800 hover:border-zinc-700 rounded-xl px-3 py-2 text-xs text-zinc-200 transition"
        aria-label="Select Target Countries"
      >
        <span className="flex items-center gap-1.5 truncate">
          <Globe className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
          <span className="truncate font-medium">{summaryLabel}</span>
        </span>
        <ChevronDown className={`w-3.5 h-3.5 text-zinc-400 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-80 bg-zinc-950 border border-zinc-800 rounded-2xl shadow-2xl z-50 overflow-hidden">
          {/* Header + Selected Pills */}
          <div className="p-3 border-b border-zinc-800 space-y-2">
            <div className="flex items-center justify-between text-[11px]">
              <span className="font-semibold text-zinc-200">
                Target Countries ({selected.length}/{maxSelection})
              </span>
              <span className="text-zinc-500">All 195 countries • Multi-select</span>
            </div>

            <div className="flex flex-wrap gap-1.5">
              {selected.map((code) => (
                <span
                  key={code}
                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 text-[11px] font-medium"
                >
                  <span>{countryFlag(code)}</span>
                  <span>{code}</span>
                  {selected.length > 1 && (
                    <button
                      type="button"
                      onClick={() => toggleCountry(code)}
                      className="hover:text-white ml-0.5"
                      title={`Remove ${getCountryName(code)}`}
                    >
                      <X className="w-3 h-3" />
                    </button>
                  )}
                </span>
              ))}
            </div>

            <div className="relative">
              <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-2.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search country (e.g. Bangladesh, UAE, UK)..."
                className="w-full pl-8 pr-3 py-1.5 bg-zinc-900 border border-zinc-800 rounded-xl text-xs text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          {/* Country List */}
          <div className="max-h-64 overflow-y-auto divide-y divide-zinc-900/80">
            {filteredCountries.length === 0 ? (
              <div className="p-4 text-center text-xs text-zinc-500">No country matched &ldquo;{query}&rdquo;</div>
            ) : (
              filteredCountries.map((c) => {
                const isPicked = selected.includes(c.code);
                const disabled = !isPicked && selected.length >= maxSelection;
                return (
                  <div
                    key={c.code}
                    className={`flex items-center justify-between px-3 py-2 text-xs hover:bg-zinc-900/70 transition ${
                      isPicked ? 'bg-emerald-950/20' : ''
                    }`}
                  >
                    <button
                      type="button"
                      disabled={disabled}
                      onClick={() => toggleCountry(c.code)}
                      className="flex items-center gap-2 flex-1 text-left disabled:opacity-40"
                    >
                      <span
                        className={`w-4 h-4 rounded flex items-center justify-center border shrink-0 ${
                          isPicked
                            ? 'bg-emerald-600 border-emerald-500 text-white'
                            : 'bg-zinc-900 border-zinc-700'
                        }`}
                      >
                        {isPicked && <Check className="w-3 h-3" />}
                      </span>
                      <span className="text-sm leading-none">{c.flag}</span>
                      <span className="text-zinc-200 truncate">{c.name}</span>
                      <span className="text-[10px] font-mono text-zinc-500">{c.code}</span>
                    </button>

                    <button
                      type="button"
                      onClick={() => selectOnly(c.code)}
                      className="text-[10px] text-sky-400 hover:text-sky-300 px-1.5 py-0.5 rounded hover:bg-sky-500/10 shrink-0"
                      title={`Select only ${c.name}`}
                    >
                      Only
                    </button>
                  </div>
                );
              })
            )}
          </div>

          <div className="p-2 border-t border-zinc-800 bg-zinc-900/50 flex items-center justify-between text-[10px] text-zinc-400">
            <span>Check multiple boxes or click &ldquo;Only&rdquo;</span>
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg"
            >
              Done
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
