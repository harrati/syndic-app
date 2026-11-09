import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ApiService } from './api.service';
import { LoginResult, SessionResult } from './models';
import { SessionStore } from './session-store.service';

/**
 * Authentication facade: issues the login request, persists the Bearer token
 * (via SessionStore/localStorage) and exposes the signed-in profile.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);
  private readonly session = inject(SessionStore);

  readonly user = this.session.user;
  readonly tenant = this.session.tenant;
  readonly token = this.session.token;
  readonly isAuthenticated = this.session.isAuthenticated;

  login(username: string, password: string): Observable<LoginResult> {
    return this.api.login(username, password).pipe(
      tap((result) => this.session.save(result.token, result.user, result.tenant)),
    );
  }

  /**
   * Revalidate a restored token and refresh the cached profile.
   *
   * Only a 401 signs the user out (the auth interceptor does that); a network or
   * 5xx failure must leave the stored session intact so the app can retry.
   */
  refreshSession(): Observable<SessionResult> {
    return this.api
      .checkSession()
      .pipe(tap((result) => this.session.updateProfile(result.user, result.tenant)));
  }

  logout(): void {
    this.session.clear();
  }
}
