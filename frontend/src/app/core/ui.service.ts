import { Injectable, signal } from '@angular/core';

/**
 * Cross-component UI state: the two global forms (payment / expense) and a
 * ledger version that pages watch to refetch after a financial write.
 */
@Injectable({ providedIn: 'root' })
export class UiService {
  private readonly paymentOpenState = signal(false);
  private readonly expenseOpenState = signal(false);
  private readonly ledgerVersionState = signal(0);

  readonly paymentOpen = this.paymentOpenState.asReadonly();
  readonly expenseOpen = this.expenseOpenState.asReadonly();
  readonly ledgerVersion = this.ledgerVersionState.asReadonly();

  openPayment(): void {
    this.paymentOpenState.set(true);
  }

  closePayment(): void {
    this.paymentOpenState.set(false);
  }

  openExpense(): void {
    this.expenseOpenState.set(true);
  }

  closeExpense(): void {
    this.expenseOpenState.set(false);
  }

  /** Announce that a payment or expense was written so views refresh. */
  notifyLedgerChanged(): void {
    this.ledgerVersionState.update((version) => version + 1);
  }
}
