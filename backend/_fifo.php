<?php

declare(strict_types=1);

/**
 * FIFO allocation engine.
 *
 * Pure functions: no database access, no output. All amounts are integer centimes
 * so that no rounding error can creep into the ledger.
 */

/**
 * Allocate a payment over unpaid charges, oldest period first.
 *
 * @param array<int, array{charge_id:string, period_year:int, period_month:int, due_date:?string, amount_cents:int}> $charges
 *        Unpaid charges; `amount_cents` is what is still owed on each one.
 * @param int $paymentCents Amount received, in centimes.
 *
 * @return array{
 *     allocations: array<int, array{charge_id:string, period_year:int, period_month:int, due_date:?string, amount_cents:int}>,
 *     allocated_cents: int,
 *     unallocated_cents: int
 * }
 */
function fifo_allocate(array $charges, int $paymentCents): array
{
    $allocations = [];
    $remaining = max(0, $paymentCents);

    if ($remaining === 0) {
        return ['allocations' => [], 'allocated_cents' => 0, 'unallocated_cents' => 0];
    }

    $payable = array_values(array_filter(
        $charges,
        static fn (array $charge): bool => (int) ($charge['amount_cents'] ?? 0) > 0
    ));

    usort($payable, static function (array $a, array $b): int {
        $byYear = ((int) $a['period_year']) <=> ((int) $b['period_year']);
        if ($byYear !== 0) {
            return $byYear;
        }

        $byMonth = ((int) $a['period_month']) <=> ((int) $b['period_month']);
        if ($byMonth !== 0) {
            return $byMonth;
        }

        $byDueDate = strcmp($a['due_date'] ?? '9999-12-31', $b['due_date'] ?? '9999-12-31');
        if ($byDueDate !== 0) {
            return $byDueDate;
        }

        // Deterministic tie-break so the same input always produces the same ledger.
        return strcmp((string) $a['charge_id'], (string) $b['charge_id']);
    });

    foreach ($payable as $charge) {
        if ($remaining === 0) {
            break;
        }

        $owed = (int) $charge['amount_cents'];
        $take = min($remaining, $owed);
        if ($take <= 0) {
            continue;
        }

        $allocations[] = [
            'charge_id' => (string) $charge['charge_id'],
            'period_year' => (int) $charge['period_year'],
            'period_month' => (int) $charge['period_month'],
            'due_date' => $charge['due_date'] ?? null,
            'amount_cents' => $take,
        ];

        $remaining -= $take;
    }

    return [
        'allocations' => $allocations,
        'allocated_cents' => $paymentCents - $remaining,
        'unallocated_cents' => $remaining,
    ];
}
