/**
 * Shapes returned by the PHP API. Money fields stay decimal strings ("450.00")
 * exactly as the backend sends them; only the template formats them.
 */

export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  message: string;
}

export interface AuthUser {
  user_id: string;
  username: string;
  full_name: string;
  role: string;
  created_at: string | null;
}

export interface Tenant {
  tenant_id: string;
  name: string;
  subdomain: string | null;
  status?: string;
}

export interface LoginResult {
  user: AuthUser;
  tenant: Tenant;
  token: string;
  token_type: string;
  expires_at: string;
  expires_in: number;
}

export interface SessionResult {
  user: AuthUser;
  tenant: Tenant;
  expires_at: string;
}

export interface Apartment {
  apartment_id: string;
  building_id: string;
  apartment_number: string;
  floor: number;
  owner_name: string;
  resident_name: string | null;
  phone: string | null;
  email: string | null;
  monthly_fee: string;
  active: boolean;
  created_at: string;
}

export interface Building {
  building_id: string;
  building_code: string;
  building_name: string;
  address_or_description: string | null;
  active: boolean;
  created_at: string;
  apartment_count: number;
  apartments: Apartment[];
}

export interface BuildingsResult {
  buildings: Building[];
  totals: { buildings: number; apartments: number };
  currency: string;
}

export interface ChargeAllocation {
  charge_id: string;
  period: string;
  period_year: number;
  period_month: number;
  due_date: string | null;
  amount_allocated: string;
}

export interface Payment {
  payment_id: string;
  receipt_number: string;
  apartment_id: string;
  apartment_number?: string;
  payment_date: string;
  amount_received: string;
  payment_method: string;
  reference_number?: string | null;
  status: string;
  created_at: string;
}

export interface PaymentResult {
  payment: Payment;
  allocations: ChargeAllocation[];
  allocated_total: string;
  unallocated_credit: string;
  apartment_credit: string;
  currency: string;
}

export interface PaymentsResult {
  payments: Payment[];
  summary: { entries: number; total_amount: string; year: number | null };
  currency: string;
}

export interface Expense {
  expense_id: string;
  expense_date: string;
  category_name: string;
  description: string;
  supplier_or_payee: string;
  amount: string;
  payment_method: string;
  reference_number: string | null;
  status: string;
  created_at: string;
}

export interface ExpenseCategory {
  category_name: string;
  entries: number;
  total_amount: string;
}

export interface ExpensesResult {
  expenses: Expense[];
  categories: ExpenseCategory[];
  summary: {
    entries: number;
    total_amount: string;
    status: string;
    year: number | null;
    month: number | null;
    category: string | null;
  };
  currency: string;
}

export interface DashboardResult {
  year: number;
  total_payments: string;
  total_expenses: string;
  net_cash_balance: string;
  unallocated_credits: string;
  collection_rate: number;
  charges: {
    total_due: string;
    total_collected: string;
    total_outstanding: string;
  };
  currency: string;
  generated_at: string;
}

export interface BuildingResult {
  building: Building;
}

export interface ApartmentResult {
  apartment: Apartment;
}

export interface ExpenseResult {
  expense: Expense;
  currency: string;
}
