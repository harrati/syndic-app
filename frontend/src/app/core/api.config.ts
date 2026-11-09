import { InjectionToken } from '@angular/core';

/**
 * API root. Defaults to `/api`, which the dev server proxies to the PHP backend
 * (`frontend/proxy.conf.json`) and which also works when PHP serves the built SPA.
 * Override in the app config to point at another origin.
 */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => '/api',
});
