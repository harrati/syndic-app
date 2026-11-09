<?php

declare(strict_types=1);

/**
 * Payments endpoint.
 *
 * POST { "apartment_id": "...", "amount_received": 450.00, "payment_date": "2026-11-04",
 *        "payment_method": "Espèces", "reference_number": "..." }
 *
 * The payment is written inside a transaction, receives a unique receipt number
 * (REC-YYYY-XXXXX) and is allocated to the apartment's oldest unpaid charges (FIFO).
 * Any excess stays as unallocated advance credit.
 *
 * Concurrency notes:
 *  - the apartment row is locked FOR UPDATE, so two payments on the same apartment
 *    are serialised and cannot allocate the same centime twice;
 *  - a receipt-number collision aborts and *restarts* the whole transaction, because
 *    a REPEATABLE READ snapshot would never see the other transaction's row.
 */

require_once __DIR__ . '/_bootstrap.php';

/** How many times a receipt-number collision is retried before giving up. */
const RECEIPT_MAX_ATTEMPTS = 5;

$method = require_method('GET', 'POST');

$pdo = db();
$context = require_auth($pdo);
$tenantId = $context['tenant_id'];

if ($method === 'GET') {
    payments_index($pdo, $tenantId);
}

$body = json_body();

$apartmentId = field_string($body, 'apartment_id', 36);
$amountCents = field_amount_cents($body, 'amount_received', 1);
$paymentDate = field_date($body, 'payment_date', false, today_in_casablanca());
$paymentMethod = field_string($body, 'payment_method', 50);
$referenceNumber = field_string($body, 'reference_number', 100, false);

// Fail fast (before opening a transaction) when the apartment is not ours.
$apartmentStmt = $pdo->prepare(
    'SELECT apartment_id, apartment_number, active
       FROM apartments
      WHERE apartment_id = :apartment_id
        AND tenant_id = :tenant_id
      LIMIT 1'
);
$apartmentStmt->execute([':apartment_id' => $apartmentId, ':tenant_id' => $tenantId]);
$apartment = $apartmentStmt->fetch();

if ($apartment === false) {
    json_error(404, 'Appartement introuvable pour ce syndic.');
}

if ((int) $apartment['active'] !== 1) {
    json_error(400, 'Cet appartement est inactif : impossible d’enregistrer un paiement.');
}

$paymentId = uuid4();
$receiptNumber = '';
$plan = ['allocations' => [], 'allocated_cents' => 0, 'unallocated_cents' => 0];
$attempt = 0;

while (true) {
    $attempt++;

    try {
        $pdo->beginTransaction();

        // Serialize concurrent payments on the same apartment.
        $lockStmt = $pdo->prepare(
            'SELECT apartment_id
               FROM apartments
              WHERE apartment_id = :apartment_id
                AND tenant_id = :tenant_id
              FOR UPDATE'
        );
        $lockStmt->execute([':apartment_id' => $apartmentId, ':tenant_id' => $tenantId]);

        if ($lockStmt->fetch() === false) {
            throw new RuntimeException('Apartment disappeared during payment.');
        }

        $receiptNumber = next_receipt_number($pdo, $tenantId);

        $insertPayment = $pdo->prepare(
            'INSERT INTO payments (payment_id, tenant_id, receipt_number, apartment_id, payment_date,
                                   amount_received, payment_method, reference_number, status)
             VALUES (:payment_id, :tenant_id, :receipt_number, :apartment_id, :payment_date,
                     :amount_received, :payment_method, :reference_number, :status)'
        );
        $insertPayment->execute([
            ':payment_id' => $paymentId,
            ':tenant_id' => $tenantId,
            ':receipt_number' => $receiptNumber,
            ':apartment_id' => $apartmentId,
            ':payment_date' => $paymentDate,
            ':amount_received' => cents_to_decimal($amountCents),
            ':payment_method' => $paymentMethod,
            ':reference_number' => $referenceNumber,
            ':status' => 'VALID',
        ]);

        $charges = fetch_unpaid_charges($pdo, $tenantId, $apartmentId);
        $plan = fifo_allocate($charges, $amountCents);

        $insertAllocation = $pdo->prepare(
            'INSERT INTO payment_allocations (allocation_id, tenant_id, payment_id, charge_id, amount_allocated)
             VALUES (:allocation_id, :tenant_id, :payment_id, :charge_id, :amount_allocated)'
        );

        foreach ($plan['allocations'] as $allocation) {
            $insertAllocation->execute([
                ':allocation_id' => uuid4(),
                ':tenant_id' => $tenantId,
                ':payment_id' => $paymentId,
                ':charge_id' => $allocation['charge_id'],
                ':amount_allocated' => cents_to_decimal($allocation['amount_cents']),
            ]);
        }

        $pdo->commit();
        break;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // A concurrent encaissement took this receipt number: start over, which also
        // takes a fresh snapshot so the next MAX() read sees the committed row.
        if ($e instanceof PDOException && is_receipt_collision($e) && $attempt < RECEIPT_MAX_ATTEMPTS) {
            usleep(50000 * $attempt);
            continue;
        }

        error_log('[payments] failed to record payment: ' . $e->getMessage());

        json_error(500, 'Erreur lors de l’enregistrement du paiement. Aucune écriture n’a été effectuée.');
    }
}

$allocations = array_map(
    static fn (array $allocation): array => [
        'charge_id' => $allocation['charge_id'],
        'period' => sprintf('%04d-%02d', $allocation['period_year'], $allocation['period_month']),
        'period_year' => $allocation['period_year'],
        'period_month' => $allocation['period_month'],
        'due_date' => $allocation['due_date'],
        'amount_allocated' => cents_to_decimal($allocation['amount_cents']),
    ],
    $plan['allocations']
);

$unallocated = cents_to_decimal($plan['unallocated_cents']);
$message = sprintf('Paiement enregistré. Reçu %s.', $receiptNumber);

if ($plan['unallocated_cents'] > 0) {
    $message .= sprintf(' %s MAD conservés en avance.', $unallocated);
}

json_created([
    'payment' => [
        'payment_id' => $paymentId,
        'receipt_number' => $receiptNumber,
        'apartment_id' => $apartmentId,
        'apartment_number' => (string) $apartment['apartment_number'],
        'payment_date' => $paymentDate,
        'amount_received' => cents_to_decimal($amountCents),
        'payment_method' => $paymentMethod,
        'reference_number' => $referenceNumber,
        'status' => 'VALID',
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Casablanca')))->format('Y-m-d H:i:s'),
    ],
    'allocations' => $allocations,
    'allocated_total' => cents_to_decimal($plan['allocated_cents']),
    'unallocated_credit' => $unallocated,
    'apartment_credit' => cents_to_decimal(apartment_credit_cents($pdo, $tenantId, $apartmentId)),
    'currency' => 'MAD',
], $message);

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/**
 * GET ?year=2026&apartment_id=...&limit=20 — recent valid payments with the
 * apartment number, plus the filtered totals (limit only trims the list).
 */
function payments_index(PDO $pdo, string $tenantId): never
{
    $year = field_int($_GET, 'year', 2000, 2100, false);
    $apartmentId = field_string($_GET, 'apartment_id', 36, false);
    $limit = field_int($_GET, 'limit', 1, 100, false) ?? 20;

    $conditions = ['p.tenant_id = :tenant_id', "p.status = 'VALID'"];
    $params = [':tenant_id' => $tenantId];

    if ($year !== null) {
        $conditions[] = 'YEAR(p.payment_date) = :year';
        $params[':year'] = $year;
    }

    if ($apartmentId !== null) {
        $conditions[] = 'p.apartment_id = :apartment_id';
        $params[':apartment_id'] = $apartmentId;
    }

    $where = implode(' AND ', $conditions);

    // Totals ignore the row limit but honour the filters.
    $totalsStmt = $pdo->prepare(
        'SELECT COUNT(*) AS entries, COALESCE(SUM(p.amount_received), 0) AS total_amount
           FROM payments p
          WHERE ' . $where
    );
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch();

    $listStmt = $pdo->prepare(
        'SELECT p.payment_id, p.receipt_number, p.apartment_id, a.apartment_number,
                p.payment_date, p.amount_received, p.payment_method, p.reference_number,
                p.status, p.created_at
           FROM payments p
     INNER JOIN apartments a ON a.apartment_id = p.apartment_id
                           AND a.tenant_id = p.tenant_id
          WHERE ' . $where . '
       ORDER BY p.payment_date DESC, p.created_at DESC, p.payment_id DESC
          LIMIT :limit'
    );

    foreach ($params as $name => $value) {
        $listStmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $listStmt->execute();

    $payments = array_map(
        static fn (array $row): array => [
            'payment_id' => (string) $row['payment_id'],
            'receipt_number' => (string) $row['receipt_number'],
            'apartment_id' => (string) $row['apartment_id'],
            'apartment_number' => (string) $row['apartment_number'],
            'payment_date' => (string) $row['payment_date'],
            'amount_received' => cents_to_decimal(amount_to_cents($row['amount_received']) ?? 0),
            'payment_method' => (string) $row['payment_method'],
            'reference_number' => $row['reference_number'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ],
        $listStmt->fetchAll()
    );

    json_ok([
        'payments' => $payments,
        'summary' => [
            'entries' => (int) $totals['entries'],
            'total_amount' => cents_to_decimal(amount_to_cents($totals['total_amount']) ?? 0),
            'year' => $year,
            'apartment_id' => $apartmentId,
        ],
        'currency' => 'MAD',
    ], 'Paiements récupérés.');
}

/**
 * Is this exception the payments.receipt_number unique-key collision?
 */
function is_receipt_collision(PDOException $exception): bool
{
    return (string) $exception->getCode() === '23000'
        && str_contains($exception->getMessage(), 'unique_receipt');
}

/**
 * Next receipt number for the current year: REC-YYYY-XXXXX.
 */
function next_receipt_number(PDO $pdo, string $tenantId): string
{
    $year = current_year();

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_number, 10) AS UNSIGNED)), 0) AS last_sequence
           FROM payments
          WHERE tenant_id = :tenant_id
            AND receipt_number LIKE :pattern'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':pattern' => RECEIPT_PREFIX . '-' . $year . '-%',
    ]);

    $lastSequence = (int) $stmt->fetchColumn();

    return sprintf('%s-%d-%05d', RECEIPT_PREFIX, $year, $lastSequence + 1);
}

/**
 * Unpaid (or partially paid) charges of one apartment, oldest period first.
 *
 * @return array<int, array{charge_id:string, period_year:int, period_month:int, due_date:?string, amount_cents:int}>
 */
function fetch_unpaid_charges(PDO $pdo, string $tenantId, string $apartmentId): array
{
    $sql = 'SELECT c.charge_id, c.period_year, c.period_month, c.due_date, c.amount_due,
                   COALESCE(SUM(CASE WHEN p.status = \'VALID\' THEN a.amount_allocated ELSE 0 END), 0) AS amount_allocated
              FROM charges c
              LEFT JOIN payment_allocations a ON a.charge_id = c.charge_id
              LEFT JOIN payments p ON p.payment_id = a.payment_id
             WHERE c.tenant_id = :tenant_id
               AND c.apartment_id = :apartment_id
          GROUP BY c.charge_id, c.period_year, c.period_month, c.due_date, c.amount_due, c.created_at
            HAVING c.amount_due - COALESCE(SUM(CASE WHEN p.status = \'VALID\' THEN a.amount_allocated ELSE 0 END), 0) > 0
          ORDER BY c.period_year ASC, c.period_month ASC, c.due_date ASC, c.created_at ASC, c.charge_id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tenant_id' => $tenantId, ':apartment_id' => $apartmentId]);

    $charges = [];

    foreach ($stmt->fetchAll() as $row) {
        $dueCents = amount_to_cents($row['amount_due']) ?? 0;
        $allocatedCents = amount_to_cents($row['amount_allocated']) ?? 0;
        $remainingCents = $dueCents - $allocatedCents;

        if ($remainingCents <= 0) {
            continue;
        }

        $charges[] = [
            'charge_id' => (string) $row['charge_id'],
            'period_year' => (int) $row['period_year'],
            'period_month' => (int) $row['period_month'],
            'due_date' => $row['due_date'],
            'amount_cents' => $remainingCents,
        ];
    }

    return $charges;
}

/**
 * Total unallocated credit of an apartment (valid payments minus allocations).
 */
function apartment_credit_cents(PDO $pdo, string $tenantId, string $apartmentId): int
{
    $receivedStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount_received), 0)
           FROM payments
          WHERE tenant_id = :tenant_id
            AND apartment_id = :apartment_id
            AND status = \'VALID\''
    );
    $receivedStmt->execute([':tenant_id' => $tenantId, ':apartment_id' => $apartmentId]);

    $allocatedStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(a.amount_allocated), 0)
           FROM payment_allocations a
     INNER JOIN payments p ON p.payment_id = a.payment_id
          WHERE a.tenant_id = :tenant_id
            AND p.apartment_id = :apartment_id
            AND p.status = \'VALID\''
    );
    $allocatedStmt->execute([':tenant_id' => $tenantId, ':apartment_id' => $apartmentId]);

    $received = amount_to_cents($receivedStmt->fetchColumn()) ?? 0;
    $allocated = amount_to_cents($allocatedStmt->fetchColumn()) ?? 0;

    return max(0, $received - $allocated);
}
