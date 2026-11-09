import { DatePipe } from '@angular/common';
import { Component, HostListener, inject, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ApiService } from '../../core/api.service';
import { todayIso } from '../../core/date.util';
import { Expense } from '../../core/models';
import { UiService } from '../../core/ui.service';
import { MadCurrencyPipe } from '../../shared/mad-currency.pipe';

const MONEY_PATTERN = /^\d{1,8}([.,]\d{1,2})?$/;

const CATEGORY_SUGGESTIONS = [
  'Nettoyage',
  'Gardiennage',
  'Électricité',
  'Eau',
  'Ascenseur',
  'Entretien',
  'Travaux',
  'Assurance',
  'Honoraires syndic',
  'Fournitures',
] as const;

const PAYMENT_METHODS = ['Espèces', 'Virement bancaire', 'Chèque', 'Carte bancaire', 'Prélèvement'] as const;

@Component({
  selector: 'app-expense-modal',
  standalone: true,
  imports: [ReactiveFormsModule, MadCurrencyPipe, DatePipe],
  templateUrl: './expense-modal.component.html',
})
export class ExpenseModalComponent {
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);
  private readonly ui = inject(UiService);

  readonly closed = output<void>();

  protected readonly categories = CATEGORY_SUGGESTIONS;
  protected readonly methods = PAYMENT_METHODS;
  protected readonly today = todayIso();

  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly submitted = signal(false);
  protected readonly saved = signal<Expense | null>(null);

  protected readonly form = this.fb.nonNullable.group({
    expense_date: [this.today, [Validators.required]],
    category_name: ['', [Validators.required, Validators.maxLength(100)]],
    description: ['', [Validators.required]],
    supplier_or_payee: ['', [Validators.required, Validators.maxLength(100)]],
    amount: ['', [Validators.required, Validators.pattern(MONEY_PATTERN)]],
    payment_method: ['Espèces', [Validators.required]],
    reference_number: [''],
  });

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (!this.saving()) {
      this.closed.emit();
    }
  }

  protected close(): void {
    if (!this.saving()) {
      this.closed.emit();
    }
  }

  protected submit(): void {
    this.submitted.set(true);
    this.form.markAllAsTouched();

    if (this.form.invalid) {
      return;
    }

    const raw = this.form.getRawValue();
    this.saving.set(true);
    this.formError.set(null);

    this.api
      .createExpense({
        expense_date: raw.expense_date,
        category_name: raw.category_name.trim(),
        description: raw.description.trim(),
        supplier_or_payee: raw.supplier_or_payee.trim(),
        amount: raw.amount.replace(',', '.').trim(),
        payment_method: raw.payment_method,
        reference_number: raw.reference_number.trim() || undefined,
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.saved.set(result.expense);
          this.ui.notifyLedgerChanged();
        },
        error: (error: Error) => {
          this.saving.set(false);
          this.formError.set(error.message);
        },
      });
  }

  protected startAnother(): void {
    this.saved.set(null);
    this.submitted.set(false);
    this.formError.set(null);
    this.form.reset({
      expense_date: this.today,
      category_name: '',
      description: '',
      supplier_or_payee: '',
      amount: '',
      payment_method: 'Espèces',
      reference_number: '',
    });
  }
}
