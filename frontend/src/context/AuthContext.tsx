'use client';

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { User, Organization } from '@/types';
import { api } from '@/lib/api';
import { useToast } from './ToastContext';

interface AuthContextType {
  user: User | null;
  organizations: Organization[];
  currentOrganization: Organization | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<boolean>;
  register: (name: string, email: string, password: string, organizationName?: string) => Promise<boolean>;
  logout: () => Promise<void>;
  switchOrganization: (orgId: number) => void;
  refreshOrganizations: () => Promise<void>;
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

  const login = async (email: string, password: string): Promise<boolean> => {
    setIsLoading(true);
    try {
      const res = await api.post<{
        user: User;
        token: string;
        organizations: Organization[];
        current_organization: Organization;
      }>('/auth/login', { email, password });

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
        return true;
      } else {
        toast.error(res.error?.message || 'Login failed. Please check your credentials.');
        return false;
      }
    } finally {
      setIsLoading(false);
    }
  };

  const register = async (
    name: string,
    email: string,
    password: string,
    organizationName?: string
  ): Promise<boolean> => {
    setIsLoading(true);
    try {
      const res = await api.post<{
        user: User;
        token: string;
        current_organization: Organization;
      }>('/auth/register', {
        name,
        email,
        password,
        password_confirmation: password,
        organization_name: organizationName,
      });

      if (res.success && res.data) {
        api.setToken(res.data.token);
        setUser(res.data.user);
        const org = res.data.current_organization;
        setOrganizations([org]);
        setCurrentOrganization(org);
        api.setOrganizationId(org.id);

        toast.success('Account successfully registered!');
        return true;
      } else {
        toast.error(res.error?.message || 'Registration failed.');
        return false;
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
