import { Pipe, PipeTransform } from '@angular/core';

/**
 * Formats an API money string ("450.00") as `450,00 DH`.
 * Values stay decimal strings end to end; only this pipe turns them into text.
 */
@Pipe({ name: 'mad', standalone: true })
export class MadCurrencyPipe implements PipeTransform {
  private static readonly formatter = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  transform(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
      return '—';
    }

    const amount = typeof value === 'number' ? value : Number(value);

    if (!Number.isFinite(amount)) {
      return `${value} DH`;
    }

    return `${MadCurrencyPipe.formatter.format(amount)} DH`;
  }
}
