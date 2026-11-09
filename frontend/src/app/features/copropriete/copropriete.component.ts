import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ApiService } from '../../core/api.service';
import { Apartment, Building } from '../../core/models';
import { MadCurrencyPipe } from '../../shared/mad-currency.pipe';

/** Decimal amount as typed on a Moroccan keyboard ("450", "450.5", "450,50"). */
const MONEY_PATTERN = /^\d{1,8}([.,]\d{1,2})?$/;

type BuildingForm = 'building' | 'apartment';

@Component({
  selector: 'app-copropriete',
  standalone: true,
  imports: [ReactiveFormsModule, MadCurrencyPipe],
  templateUrl: './copropriete.component.html',
})
export class CoproprieteComponent {
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);

  protected readonly buildings = signal<Building[]>([]);
  protected readonly totals = signal({ buildings: 0, apartments: 0 });
  protected readonly loading = signal(true);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);

  protected readonly search = signal('');
  protected readonly modal = signal<BuildingForm | null>(null);
  protected readonly saving = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly submitted = signal(false);

  protected readonly buildingForm = this.fb.nonNullable.group({
    building_code: ['', [Validators.required, Validators.maxLength(50)]],
    building_name: ['', [Validators.required, Validators.maxLength(100)]],
    address_or_description: [''],
  });

  protected readonly apartmentForm = this.fb.nonNullable.group({
    building_id: ['', [Validators.required]],
    apartment_number: ['', [Validators.required, Validators.maxLength(20)]],
    floor: [0, [Validators.required, Validators.min(-5), Validators.max(200)]],
    owner_name: ['', [Validators.required, Validators.maxLength(100)]],
    resident_name: [''],
    phone: ['', [Validators.maxLength(30)]],
    email: ['', [Validators.email, Validators.maxLength(100)]],
    monthly_fee: ['150.00', [Validators.required, Validators.pattern(MONEY_PATTERN)]],
  });

  /** Buildings whose own fields or one of their apartments match the query. */
  protected readonly filteredBuildings = computed<Building[]>(() => {
    const query = this.search().trim().toLowerCase();
    const all = this.buildings();

    if (query === '') {
      return all;
    }

    const contains = (value: string | number | null | undefined): boolean =>
      String(value ?? '').toLowerCase().includes(query);

    return all
      .map((building) => {
        const buildingMatches =
          contains(building.building_code) ||
          contains(building.building_name) ||
          contains(building.address_or_description);

        const apartments = building.apartments.filter(
          (apartment) =>
            buildingMatches ||
            contains(apartment.apartment_number) ||
            contains(apartment.owner_name) ||
            contains(apartment.resident_name) ||
            contains(apartment.phone) ||
            contains(apartment.email),
        );

        return { ...building, apartments, apartment_count: apartments.length };
      })
      .filter((building) => building.apartments.length > 0);
  });

  constructor() {
    this.load();
  }

  protected onSearch(event: Event): void {
    this.search.set((event.target as HTMLInputElement).value);
  }

  protected reload(): void {
    this.load();
  }

  protected openBuildingModal(): void {
    this.formError.set(null);
    this.submitted.set(false);
    this.buildingForm.reset({ building_code: '', building_name: '', address_or_description: '' });
    this.modal.set('building');
  }

  protected openApartmentModal(): void {
    const buildings = this.buildings();

    if (buildings.length === 0) {
      this.notice.set('Créez d’abord un immeuble avant d’ajouter un appartement.');
      return;
    }

    this.formError.set(null);
    this.submitted.set(false);
    this.apartmentForm.reset({
      building_id: buildings[0].building_id,
      apartment_number: '',
      floor: 0,
      owner_name: '',
      resident_name: '',
      phone: '',
      email: '',
      monthly_fee: '150.00',
    });
    this.modal.set('apartment');
  }

  protected closeModal(): void {
    if (this.saving()) {
      return;
    }

    this.modal.set(null);
  }

  protected submitBuilding(): void {
    this.submitted.set(true);
    this.buildingForm.markAllAsTouched();

    if (this.buildingForm.invalid) {
      return;
    }

    const raw = this.buildingForm.getRawValue();
    this.saving.set(true);
    this.formError.set(null);

    this.api
      .createBuilding({
        building_code: raw.building_code.trim(),
        building_name: raw.building_name.trim(),
        address_or_description: raw.address_or_description.trim() || null,
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.modal.set(null);
          this.notice.set(`Immeuble « ${result.building.building_name} » créé.`);
          this.load();
        },
        error: (error: Error) => {
          this.saving.set(false);
          this.formError.set(error.message);
        },
      });
  }

  protected submitApartment(): void {
    this.submitted.set(true);
    this.apartmentForm.markAllAsTouched();

    if (this.apartmentForm.invalid) {
      return;
    }

    const raw = this.apartmentForm.getRawValue();
    this.saving.set(true);
    this.formError.set(null);

    this.api
      .createApartment({
        building_id: raw.building_id,
        apartment_number: raw.apartment_number.trim(),
        floor: Number(raw.floor),
        owner_name: raw.owner_name.trim(),
        resident_name: raw.resident_name.trim() || null,
        phone: raw.phone.trim() || null,
        email: raw.email.trim() || null,
        monthly_fee: raw.monthly_fee.replace(',', '.').trim(),
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.modal.set(null);
          this.notice.set(`Appartement ${result.apartment.apartment_number} ajouté.`);
          this.load();
        },
        error: (error: Error) => {
          this.saving.set(false);
          this.formError.set(error.message);
        },
      });
  }

  protected ownerLabel(apartment: Apartment): string {
    return apartment.owner_name;
  }

  private load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    this.api.getBuildings().subscribe({
      next: (result) => {
        this.buildings.set(result.buildings);
        this.totals.set(result.totals);
        this.loading.set(false);
      },
      error: (error: Error) => {
        this.loading.set(false);
        this.errorMessage.set(error.message);
      },
    });
  }
}
