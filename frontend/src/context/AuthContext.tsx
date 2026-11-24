'use client';

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { User, Organization } from '@/types';
import { api } from '@/lib/api';
import { useToast } from './ToastContext';

interface AuthActionResult {
  success: boolean;
  error?: string;
}

interface AuthContextType {
  user: User | null;
  organizations: Organization[];
  currentOrganization: Organization | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<AuthActionResult>;
  register: (
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
    organizationName?: string
  ) => Promise<AuthActionResult>;
  logout: () => Promise<void>;
  switchOrganization: (orgId: number) => void;
  refreshOrganizations: () => Promise<void>;
}

function extractApiError(
  error?: { message?: string; details?: Record<string, string[] | string> | unknown } | null,
  fallback = 'Request failed. Please try again.'
): string {
  if (!error) return fallback;
  if (error.details && typeof error.details === 'object' && !Array.isArray(error.details)) {
    const entries = Object.values(error.details as Record<string, unknown>);
    for (const val of entries) {
      if (Array.isArray(val) && typeof val[0] === 'string' && val[0].trim()) {
        return val[0];
      }
      if (typeof val === 'string' && val.trim()) {
        return val;
      }
    }
  }
  return error.message || fallback;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [currentOrganization, setCurrentOrganization] = useState<Organization | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const toast = useToast();

  const fetchUserProfile = useCallback(async () => {
    const token = api.getToken();
    if (!token) {
      setIsLoading(false);
      return;
    }

    try {
      const res = await api.get<{
        user: User;
        organizations: Organization[];
      }>('/auth/me');

      if (res.success && res.data) {
        setUser(res.data.user);
        const orgs = res.data.organizations || [];
        setOrganizations(orgs);

        const savedOrgId = api.getOrganizationId();
        const active = orgs.find((o) => o.id === savedOrgId) || orgs[0] || null;

        if (active) {
          setCurrentOrganization(active);
          api.setOrganizationId(active.id);
        }
      } else {
        // Token expired or invalid
        api.setToken(null);
        setUser(null);
        setOrganizations([]);
        setCurrentOrganization(null);
      }
    } catch {
      api.setToken(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchUserProfile();
  }, [fetchUserProfile]);

  const login = async (email: string, password: string): Promise<AuthActionResult> => {
    setIsLoading(true);
    try {
      const res = await api.post<{
        user: User;
        token: string;
        organizations: Organization[];
        current_organization: Organization;
      }>('/auth/login', { email: email.trim().toLowerCase(), password });

      if (res.success && res.data) {
        api.setToken(res.data.token);
        setUser(res.data.user);
        setOrganizations(res.data.organizations || []);

        const activeOrg = res.data.current_organization || res.data.organizations[0] || null;
        setCurrentOrganization(activeOrg);
        if (activeOrg) {
          api.setOrganizationId(activeOrg.id);
        }

        toast.success(`Welcome back, ${res.data.user.name}!`);
        return { success: true };
      } else {
        const msg = extractApiError(res.error, 'Login failed. Please check your credentials.');
        toast.error(msg);
        return { success: false, error: msg };
      }
    } finally {
      setIsLoading(false);
    }
  };

  const register = async (
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
    organizationName?: string
  ): Promise<AuthActionResult> => {
    setIsLoading(true);
    try {
      const res = await api.post<{
        user: User;
        token: string;
        organizations?: Organization[];
        current_organization: Organization;
      }>('/auth/register', {
        name: name.trim(),
        email: email.trim().toLowerCase(),
        password,
        password_confirmation: passwordConfirmation,
        organization_name: organizationName?.trim() || undefined,
      });

      if (res.success && res.data) {
        api.setToken(res.data.token);
        setUser(res.data.user);
        const org = res.data.current_organization;
        const orgs = res.data.organizations && res.data.organizations.length > 0 ? res.data.organizations : [org];
        setOrganizations(orgs);
        setCurrentOrganization(org);
        api.setOrganizationId(org.id);

        toast.success(`Welcome to Web SEO AI, ${res.data.user.name}! Your workspace "${org.name}" is ready.`);
        return { success: true };
      } else {
        const msg = extractApiError(res.error, 'Registration failed. Please review your details.');
        toast.error(msg);
        return { success: false, error: msg };
      }
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Ignore network errors on logout
    } finally {
      api.setToken(null);
      api.setOrganizationId(null);
      setUser(null);
      setOrganizations([]);
      setCurrentOrganization(null);
      toast.info('Logged out.');
    }
  };

  const switchOrganization = (orgId: number) => {
    const found = organizations.find((o) => o.id === orgId);
    if (found) {
      setCurrentOrganization(found);
      api.setOrganizationId(found.id);
      toast.info(`Switched to workspace: ${found.name}`);
    }
  };

  const refreshOrganizations = async () => {
    const res = await api.get<Organization[]>('/organizations');
    if (res.success && res.data) {
      setOrganizations(res.data);
      if (currentOrganization) {
        const updated = res.data.find((o) => o.id === currentOrganization.id);
        if (updated) setCurrentOrganization(updated);
      }
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        organizations,
        currentOrganization,
        isLoading,
        login,
        register,
        logout,
        switchOrganization,
        refreshOrganizations,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
