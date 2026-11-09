import { Injectable, computed, signal } from '@angular/core';
import { AuthUser, Tenant } from './models';

const TOKEN_KEY = 'syndic.token';
const USER_KEY = 'syndic.user';
const TENANT_KEY = 'syndic.tenant';

/**
 * Token + profile persistence. Deliberately free of HttpClient so the auth
 * interceptor can depend on it without creating a dependency cycle.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly tokenState = signal<string | null>(null);
  private readonly userState = signal<AuthUser | null>(null);
  private readonly tenantState = signal<Tenant | null>(null);

  readonly token = this.tokenState.asReadonly();
  readonly user = this.userState.asReadonly();
  readonly tenant = this.tenantState.asReadonly();
  readonly isAuthenticated = computed(() => this.tokenState() !== null);

  constructor() {
    this.restore();
  }

  save(token: string, user: AuthUser, tenant: Tenant): void {
    this.tokenState.set(token);
    this.userState.set(user);
    this.tenantState.set(tenant);

    try {
      localStorage.setItem(TOKEN_KEY, token);
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      localStorage.setItem(TENANT_KEY, JSON.stringify(tenant));
    } catch {
      // Private mode / storage disabled: the in-memory session still works.
    }
  }

  updateProfile(user: AuthUser, tenant: Tenant): void {
    this.userState.set(user);
    this.tenantState.set(tenant);

    try {
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      localStorage.setItem(TENANT_KEY, JSON.stringify(tenant));
    } catch {
      // ignore
    }
  }

  clear(): void {
    this.tokenState.set(null);
    this.userState.set(null);
    this.tenantState.set(null);

    try {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
      localStorage.removeItem(TENANT_KEY);
    } catch {
      // ignore
    }
  }

  private restore(): void {
    let token: string | null = null;

    try {
      token = localStorage.getItem(TOKEN_KEY);
      const user = readJson<AuthUser>(USER_KEY);
      const tenant = readJson<Tenant>(TENANT_KEY);

      if (token && user && tenant) {
        this.tokenState.set(token);
        this.userState.set(user);
        this.tenantState.set(tenant);
        return;
      }
    } catch {
      // Corrupted or unavailable storage: fall through and start signed out.
    }

    if (token) {
      this.clear();
    }
  }
}

function readJson<T>(key: string): T | null {
  const raw = localStorage.getItem(key);

  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}
