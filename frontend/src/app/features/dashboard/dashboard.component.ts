import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, computed, effect, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { DashboardResult, Expense, Payment } from '../../core/models';
import { UiService } from '../../core/ui.service';
import { MadCurrencyPipe } from '../../shared/mad-currency.pipe';

interface Movement {
  id: string;
  label: string;
  detail: string;
  date: string;
  amount: string;
  direction: 'in' | 'out';
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [MadCurrencyPipe, DecimalPipe, DatePipe],
  templateUrl: './dashboard.component.html',
})
export class DashboardComponent {
  private readonly api = inject(ApiService);
  private readonly ui = inject(UiService);

  protected readonly year = new Date().getFullYear();
  protected readonly loading = signal(true);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly dashboard = signal<DashboardResult | null>(null);
  protected readonly movements = signal<Movement[]>([]);

  protected readonly collectionRate = computed(() =>
    Math.max(0, Math.min(100, this.dashboard()?.collection_rate ?? 0)),
  );

  constructor() {
    // Refetch whenever a payment or expense was recorded anywhere in the app.
    effect(() => {
      this.ui.ledgerVersion();
      this.load();
    });
  }

  protected openPayment(): void {
    this.ui.openPayment();
  }

  protected openExpense(): void {
    this.ui.openExpense();
  }

  protected reload(): void {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    forkJoin({
      dashboard: this.api.getDashboard(this.year),
      payments: this.api.getPayments({ year: this.year, limit: 5 }),
      expenses: this.api.getExpenses({ year: this.year, status: 'VALID', limit: 5 }),
    }).subscribe({
      next: ({ dashboard, payments, expenses }) => {
        this.dashboard.set(dashboard);
        this.movements.set(buildMovements(payments.payments, expenses.expenses));
        this.loading.set(false);
      },
      error: (error: Error) => {
        this.loading.set(false);
        this.errorMessage.set(error.message);
      },
    });
  }
}

function buildMovements(payments: Payment[], expenses: Expense[]): Movement[] {
  const inflows = payments.map<Movement>((payment) => ({
    id: payment.payment_id,
    label: `Encaissement · Appt ${payment.apartment_number ?? '—'}`,
    detail: `Reçu ${payment.receipt_number} · ${payment.payment_method}`,
    date: payment.payment_date,
    amount: payment.amount_received,
    direction: 'in',
  }));

  const outflows = expenses.map<Movement>((expense) => ({
    id: expense.expense_id,
    label: expense.category_name,
    detail: `${expense.supplier_or_payee} · ${expense.payment_method}`,
    date: expense.expense_date,
    amount: expense.amount,
    direction: 'out',
  }));

  return [...inflows, ...outflows]
    .sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0))
    .slice(0, 6);
}
