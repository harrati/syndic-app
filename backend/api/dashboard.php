<?php

declare(strict_types=1);

/**
 * Dashboard metrics endpoint (tenant scoped).
 *
 * GET ?year=2026
 *
 * Returns for the requested year:
 *   total_payments     — valid payments received during the year
 *   total_expenses     — valid expenses recorded during the year
 *   net_cash_balance   — total_payments - total_expenses
 *   unallocated_credits— valid payments not yet allocated to a charge (advance credit)
 *   collection_rate    — % of the year's charges settled by valid allocations
 */

require_once __DIR__ . '/_bootstrap.php';

require_method('GET');

$pdo = db();
$context = require_auth($pdo);
$tenantId = $context['tenant_id'];

$year = field_int($_GET, 'year', 2000, 2100, false) ?? current_year();

$paymentsCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(amount_received), 0)
       FROM payments
      WHERE tenant_id = :tenant_id
        AND status = \'VALID\'
        AND YEAR(payment_date) = :year',
    [':tenant_id' => $tenantId, ':year' => $year]
);

$expensesCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(amount), 0)
       FROM expenses
      WHERE tenant_id = :tenant_id
        AND status = \'VALID\'
        AND YEAR(expense_date) = :year',
    [':tenant_id' => $tenantId, ':year' => $year]
);

// Advance credit is cumulative: an overpayment made last year is still available.
$receivedAllTimeCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(amount_received), 0)
       FROM payments
      WHERE tenant_id = :tenant_id
        AND status = \'VALID\'',
    [':tenant_id' => $tenantId]
);

$allocatedAllTimeCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(a.amount_allocated), 0)
       FROM payment_allocations a
 INNER JOIN payments p ON p.payment_id = a.payment_id
      WHERE a.tenant_id = :tenant_id
        AND p.status = \'VALID\'',
    [':tenant_id' => $tenantId]
);

$creditCents = max(0, $receivedAllTimeCents - $allocatedAllTimeCents);

// Charges issued for the requested year and how much of them was settled.
$chargesDueCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(amount_due), 0)
       FROM charges
      WHERE tenant_id = :tenant_id
        AND period_year = :year',
    [':tenant_id' => $tenantId, ':year' => $year]
);

$chargesCollectedCents = scalar_cents(
    $pdo,
    'SELECT COALESCE(SUM(CASE WHEN p.status = \'VALID\' THEN a.amount_allocated ELSE 0 END), 0)
       FROM payment_allocations a
 INNER JOIN charges c ON c.charge_id = a.charge_id
  LEFT JOIN payments p ON p.payment_id = a.payment_id
      WHERE c.tenant_id = :tenant_id
        AND c.period_year = :year',
    [':tenant_id' => $tenantId, ':year' => $year]
);

$chargesOutstandingCents = max(0, $chargesDueCents - $chargesCollectedCents);

$collectionRate = $chargesDueCents > 0
    ? round(($chargesCollectedCents / $chargesDueCents) * 100, 1)
    : 0.0;

json_ok([
    'year' => $year,
    'total_payments' => cents_to_decimal($paymentsCents),
    'total_expenses' => cents_to_decimal($expensesCents),
    'net_cash_balance' => cents_to_decimal($paymentsCents - $expensesCents),
    'unallocated_credits' => cents_to_decimal($creditCents),
    'collection_rate' => $collectionRate,
    'charges' => [
        'total_due' => cents_to_decimal($chargesDueCents),
        'total_collected' => cents_to_decimal($chargesCollectedCents),
        'total_outstanding' => cents_to_decimal($chargesOutstandingCents),
    ],
    'currency' => 'MAD',
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Casablanca')))->format(DateTimeInterface::ATOM),
], 'Tableau de bord calculé.');
