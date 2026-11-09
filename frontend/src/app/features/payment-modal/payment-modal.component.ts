import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, DestroyRef, HostListener, inject, output, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ApiService } from '../../core/api.service';
import { todayIso } from '../../core/date.util';
import { Apartment, PaymentResult } from '../../core/models';
import { UiService } from '../../core/ui.service';
import { MadCurrencyPipe } from '../../shared/mad-currency.pipe';

const MONEY_PATTERN = /^\d{1,8}([.,]\d{1,2})?$/;

interface ApartmentOption {
  apartment: Apartment;
  buildingLabel: string;
}

const PAYMENT_METHODS = ['Espèces', 'Virement bancaire', 'Chèque', 'Carte bancaire', 'Prélèvement'] as const;

@Component({
  selector: 'app-payment-modal',
  standalone: true,
  imports: [ReactiveFormsModule, MadCurrencyPipe, DatePipe, DecimalPipe],
  templateUrl: './payment-modal.component.html',
})
export class PaymentModalComponent {
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);
  private readonly ui = inject(UiService);
  private readonly destroyRef = inject(DestroyRef);

  readonly closed = output<void>();

  protected readonly methods = PAYMENT_METHODS;
  protected readonly options = signal<ApartmentOption[]>([]);
  protected readonly loadingApartments = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly submitted = signal(false);
  protected readonly receipt = signal<PaymentResult | null>(null);

  protected readonly today = todayIso();

  protected readonly form = this.fb.nonNullable.group({
    apartment_id: ['', [Validators.required]],
    amount_received: ['', [Validators.required, Validators.pattern(MONEY_PATTERN)]],
    payment_date: [this.today, [Validators.required]],
    payment_method: ['Espèces', [Validators.required]],
    reference_number: [''],
  });

  constructor() {
    this.loadApartments();

    this.form.controls.apartment_id.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((apartmentId) => {
        const apartment = this.options().find((item) => item.apartment.apartment_id === apartmentId);
        const amount = this.form.controls.amount_received;

        // Prefill with the monthly fee, without overwriting what was typed.
        if (apartment && !amount.dirty) {
          amount.setValue(apartment.apartment.monthly_fee);
        }
      });
  }

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

  protected apartmentLabel(apartmentId: string): string {
    const option = this.options().find((item) => item.apartment.apartment_id === apartmentId);

    return option ? `Appt ${option.apartment.apartment_number} — ${option.buildingLabel}` : '—';
  }

  protected optionLabel(option: ApartmentOption): string {
    const { apartment, buildingLabel } = option;
    return `Appt ${apartment.apartment_number} — ${buildingLabel} · ${apartment.owner_name}`;
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
      .createPayment({
        apartment_id: raw.apartment_id,
        amount_received: raw.amount_received.replace(',', '.').trim(),
        payment_date: raw.payment_date,
        payment_method: raw.payment_method,
        reference_number: raw.reference_number.trim() || undefined,
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.receipt.set(result);
          this.ui.notifyLedgerChanged();
        },
        error: (error: Error) => {
          this.saving.set(false);
          this.formError.set(error.message);
        },
      });
  }

  /** Keep the modal open but clear it for another entry. */
  protected startAnother(): void {
    this.receipt.set(null);
    this.submitted.set(false);
    this.formError.set(null);
    this.form.reset({
      apartment_id: '',
      amount_received: '',
      payment_date: this.today,
      payment_method: 'Espèces',
      reference_number: '',
    });
    this.form.controls.amount_received.markAsPristine();
  }

  private loadApartments(): void {
    this.loadingApartments.set(true);
    this.loadError.set(null);

    this.api.getBuildings().subscribe({
      next: (result) => {
        const options: ApartmentOption[] = [];

        for (const building of result.buildings) {
          for (const apartment of building.apartments) {
            options.push({ apartment, buildingLabel: `${building.building_code} · ${building.building_name}` });
          }
        }

        this.options.set(options);
        this.loadingApartments.set(false);

        if (options.length > 0) {
          this.form.controls.apartment_id.setValue(options[0].apartment.apartment_id);
        }
      },
      error: (error: Error) => {
        this.loadingApartments.set(false);
        this.loadError.set(error.message);
      },
    });
  }
}
