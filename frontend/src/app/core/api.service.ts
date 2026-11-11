import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, throwError } from 'rxjs';
import { API_BASE_URL } from './api.config';
import {
  ApartmentResult,
  ApiEnvelope,
  BuildingResult,
  BuildingsResult,
  DashboardResult,
  ExpenseResult,
  ExpensesResult,
  LoginResult,
  PaymentResult,
  PaymentsResult,
  SessionResult,
} from './models';

export interface PaymentFilters {
  year?: number;
  apartment_id?: string;
  limit?: number;
}

export interface ExpenseFilters {
  year?: number;
  month?: number;
  category?: string;
  status?: 'VALID' | 'VOID' | 'ALL';
  limit?: number;
}

export interface CreateBuildingPayload {
  building_code: string;
  building_name: string;
  address_or_description?: string | null;
}

export interface CreateApartmentPayload {
  building_id: string;
  apartment_number: string;
  floor: number;
  owner_name: string;
  resident_name?: string | null;
  phone?: string | null;
  email?: string | null;
  monthly_fee?: string;
}

export interface UpdateBuildingPayload {
  building_id: string;
  building_code?: string;
  building_name?: string;
  address_or_description?: string | null;
}

export interface DeleteBuildingPayload {
  building_id: string;
}

export interface UpdateApartmentPayload {
  apartment_id: string;
  building_id?: string;
  apartment_number?: string;
  floor?: number;
  owner_name?: string;
  resident_name?: string | null;
  phone?: string | null;
  email?: string | null;
  monthly_fee?: string;
}

export interface DeleteApartmentPayload {
  apartment_id: string;
}

export interface CreatePaymentPayload {
  apartment_id: string;
  amount_received: string;
  payment_date: string;
  payment_method: string;
  reference_number?: string;
}

export interface CreateExpensePayload {
  expense_date: string;
  category_name: string;
  description: string;
  supplier_or_payee: string;
  amount: string;
  payment_method: string;
  reference_number?: string;
}

/**
 * Thin typed wrapper around the PHP REST API. Every method resolves with the
 * `data` part of the `{ success, data, message }` envelope and rejects with an
 * Error carrying a French message ready for the UI.
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL).replace(/\/+$/, '');

  login(username: string, password: string): Observable<LoginResult> {
    return this.post<LoginResult>('/auth.php', { action: 'login', username, password });
  }

  checkSession(): Observable<SessionResult> {
    return this.post<SessionResult>('/auth.php', { action: 'check_session' });
  }

  getBuildings(): Observable<BuildingsResult> {
    return this.get<BuildingsResult>('/apartments.php');
  }

  createBuilding(payload: CreateBuildingPayload): Observable<BuildingResult> {
    return this.post<BuildingResult>('/apartments.php', { action: 'create_building', ...payload });
  }

  updateBuilding(payload: UpdateBuildingPayload): Observable<BuildingResult> {
    return this.post<BuildingResult>('/apartments.php', { action: 'update_building', ...payload });
  }

  deleteBuilding(buildingId: string): Observable<BuildingResult> {
    return this.post<BuildingResult>('/apartments.php', { action: 'delete_building', building_id: buildingId });
  }

  createApartment(payload: CreateApartmentPayload): Observable<ApartmentResult> {
    return this.post<ApartmentResult>('/apartments.php', { action: 'create_apartment', ...payload });
  }

  updateApartment(payload: UpdateApartmentPayload): Observable<ApartmentResult> {
    return this.post<ApartmentResult>('/apartments.php', { action: 'update_apartment', ...payload });
  }

  deleteApartment(apartmentId: string): Observable<ApartmentResult> {
    return this.post<ApartmentResult>('/apartments.php', { action: 'delete_apartment', apartment_id: apartmentId });
  }

  createPayment(payload: CreatePaymentPayload): Observable<PaymentResult> {
    return this.post<PaymentResult>('/payments.php', payload);
  }

  getPayments(filters: PaymentFilters = {}): Observable<PaymentsResult> {
    return this.get<PaymentsResult>('/payments.php', this.toParams({ year: filters.year, apartment_id: filters.apartment_id, limit: filters.limit }));
  }

  getExpenses(filters: ExpenseFilters = {}): Observable<ExpensesResult> {
    return this.get<ExpensesResult>('/expenses.php', this.toParams({
      year: filters.year,
      month: filters.month,
      category: filters.category,
      status: filters.status,
      limit: filters.limit,
    }));
  }

  createExpense(payload: CreateExpensePayload): Observable<ExpenseResult> {
    return this.post<ExpenseResult>('/expenses.php', { action: 'create', ...payload });
  }

  getDashboard(year?: number): Observable<DashboardResult> {
    return this.get<DashboardResult>('/dashboard.php', this.toParams({ year }));
  }

  private get<T>(path: string, params?: HttpParams): Observable<T> {
    return this.http.get<ApiEnvelope<T>>(this.url(path), { params }).pipe(
      map((response) => response.data),
      catchError(forwardApiError),
    );
  }

  private post<T>(path: string, body: unknown): Observable<T> {
    return this.http.post<ApiEnvelope<T>>(this.url(path), body).pipe(
      map((response) => response.data),
      catchError(forwardApiError),
    );
  }

  private url(path: string): string {
    return `${this.baseUrl}${path}`;
  }

  private toParams(values: Record<string, string | number | null | undefined>): HttpParams {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(values)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return params;
  }
}

function forwardApiError(error: unknown): Observable<never> {
  return throwError(() => new Error(apiErrorMessage(error)));
}

/**
 * Turn an HttpErrorResponse into the message the API sent, with sensible
 * fallbacks for network and auth failures.
 */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const body: unknown = error.error;

    if (body && typeof body === 'object' && 'error' in body) {
      const message = (body as { error?: unknown }).error;
      if (typeof message === 'string' && message.trim() !== '') {
        return message;
      }
    }

    if (error.status === 0) {
      return 'Serveur injoignable. Vérifiez que l’API PHP est démarrée.';
    }

    if (error.status === 401) {
      return 'Session expirée. Veuillez vous reconnecter.';
    }

    return `Erreur serveur (${error.status}).`;
  }

  if (error instanceof Error && error.message) {
    return error.message;
  }

  return 'Une erreur inattendue est survenue.';
}