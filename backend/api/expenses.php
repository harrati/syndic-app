<?php

declare(strict_types=1);

/**
 * Expenses endpoint (tenant scoped).
 *
 * GET  — ?year=2026&month=11&category=Plomberie&status=VALID|VOID|ALL&limit=100
 *        returns the expense log, the known categories and the filtered totals.
 *
 * POST — action based:
 *   { "action": "create", "expense_date": "2026-11-04", "category_name": "Plomberie",
 *     "description": "...", "supplier_or_payee": "...", "amount": 1200,
 *     "payment_method": "Virement", "reference_number": "..." }
 *   { "action": "void", "expense_id": "..." }
 *
 * The schema stores the category on each expense (no separate table): a category
 * exists as soon as an expense uses it.
 */

require_once __DIR__ . '/_bootstrap.php';

$method = require_method('GET', 'POST');
$pdo = db();
$context = require_auth($pdo);
$tenantId = $context['tenant_id'];

if ($method === 'GET') {
    expenses_index($pdo, $tenantId);
}

$body = json_body();

switch (request_action($body)) {
    case 'create':
    case 'create_expense':
        expense_create($pdo, $tenantId, $body);
        break;

    case 'void':
    case 'void_expense':
        expense_void($pdo, $tenantId, $body);
        break;

    default:
        json_error(400, 'Action inconnue : utilisez « create » ou « void ».');
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function expenses_index(PDO $pdo, string $tenantId): never
{
    $year = field_int($_GET, 'year', 2000, 2100, false);
    $month = field_int($_GET, 'month', 1, 12, false);
    $category = field_string($_GET, 'category', 100, false);
    $limit = field_int($_GET, 'limit', 1, 500, false) ?? 100;

    $status = strtoupper((string) ($_GET['status'] ?? 'ALL'));
    if (!in_array($status, ['ALL', 'VALID', 'VOID'], true)) {
        json_error(400, 'Le paramètre « status » doit valoir VALID, VOID ou ALL.');
    }

    $conditions = ['tenant_id = :tenant_id'];
    $params = [':tenant_id' => $tenantId];

    if ($year !== null) {
        $conditions[] = 'YEAR(expense_date) = :year';
        $params[':year'] = $year;
    }

    if ($month !== null) {
        $conditions[] = 'MONTH(expense_date) = :month';
        $params[':month'] = $month;
    }

    if ($category !== null) {
        $conditions[] = 'category_name = :category_name';
        $params[':category_name'] = $category;
    }

    if ($status !== 'ALL') {
        $conditions[] = 'status = :status';
        $params[':status'] = $status;
    }

    $where = implode(' AND ', $conditions);

    // Totals ignore the row limit but honour the filters.
    $totalsStmt = $pdo->prepare(
        'SELECT COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total_amount
           FROM expenses
          WHERE ' . $where
    );
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch();

    $listStmt = $pdo->prepare(
        'SELECT expense_id, expense_date, category_name, description, supplier_or_payee,
                amount, payment_method, reference_number, status, created_at
           FROM expenses
          WHERE ' . $where . '
       ORDER BY expense_date DESC, created_at DESC, expense_id DESC
          LIMIT :limit'
    );

    foreach ($params as $name => $value) {
        $listStmt->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $listStmt->execute();

    // Category picker: every category ever used by this tenant (valid entries only).
    $categoriesStmt = $pdo->prepare(
        'SELECT category_name, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total_amount
           FROM expenses
          WHERE tenant_id = :tenant_id
            AND status = \'VALID\'
       GROUP BY category_name
       ORDER BY category_name ASC'
    );
    $categoriesStmt->execute([':tenant_id' => $tenantId]);

    $categories = array_map(
        static fn (array $row): array => [
            'category_name' => (string) $row['category_name'],
            'entries' => (int) $row['entries'],
            'total_amount' => cents_to_decimal(amount_to_cents($row['total_amount']) ?? 0),
        ],
        $categoriesStmt->fetchAll()
    );

    $expenses = array_map('map_expense', $listStmt->fetchAll());

    json_ok([
        'expenses' => $expenses,
        'categories' => $categories,
        'summary' => [
            'entries' => (int) $totals['entries'],
            'total_amount' => cents_to_decimal(amount_to_cents($totals['total_amount']) ?? 0),
            'status' => $status,
            'year' => $year,
            'month' => $month,
            'category' => $category,
        ],
        'currency' => 'MAD',
    ], 'Dépenses récupérées.');
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function expense_create(PDO $pdo, string $tenantId, array $body): never
{
    $expenseDate = field_date($body, 'expense_date', false, today_in_casablanca());
    $categoryName = field_string($body, 'category_name', 100);
    $description = field_string($body, 'description', 5000);
    $supplier = field_string($body, 'supplier_or_payee', 100);
    $amountCents = field_amount_cents($body, 'amount', 1);
    $paymentMethod = field_string($body, 'payment_method', 50);
    $referenceNumber = field_string($body, 'reference_number', 100, false);

    $expenseId = uuid4();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (expense_id, tenant_id, expense_date, category_name, description,
                                   supplier_or_payee, amount, payment_method, reference_number, status)
             VALUES (:expense_id, :tenant_id, :expense_date, :category_name, :description,
                     :supplier_or_payee, :amount, :payment_method, :reference_number, :status)'
        );

        $stmt->execute([
            ':expense_id' => $expenseId,
            ':tenant_id' => $tenantId,
            ':expense_date' => $expenseDate,
            ':category_name' => $categoryName,
            ':description' => $description,
            ':supplier_or_payee' => $supplier,
            ':amount' => cents_to_decimal($amountCents),
            ':payment_method' => $paymentMethod,
            ':reference_number' => $referenceNumber,
            ':status' => 'VALID',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[expenses] failed to create expense: ' . $e->getMessage());

        json_error(500, 'Erreur lors de l’enregistrement de la dépense. Aucune écriture n’a été effectuée.');
    }

    json_created([
        'expense' => map_expense(fetch_expense($pdo, $tenantId, $expenseId)),
        'currency' => 'MAD',
    ], 'Dépense enregistrée.');
}

function expense_void(PDO $pdo, string $tenantId, array $body): never
{
    $expenseId = field_string($body, 'expense_id', 36);
    $existing = fetch_expense($pdo, $tenantId, $expenseId);

    if ($existing['status'] === 'VOID') {
        json_error(409, 'Cette dépense est déjà annulée.');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'UPDATE expenses
                SET status = \'VOID\'
              WHERE expense_id = :expense_id
                AND tenant_id = :tenant_id
                AND status = \'VALID\''
        );
        $stmt->execute([':expense_id' => $expenseId, ':tenant_id' => $tenantId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_error(409, 'Cette dépense a déjà été modifiée par une autre opération.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[expenses] failed to void expense: ' . $e->getMessage());

        json_error(500, 'Erreur lors de l’annulation de la dépense.');
    }

    json_ok([
        'expense' => map_expense(fetch_expense($pdo, $tenantId, $expenseId)),
        'currency' => 'MAD',
    ], 'Dépense annulée.');
}

// ---------------------------------------------------------------------------
// Lookup & serialization
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function fetch_expense(PDO $pdo, string $tenantId, string $expenseId): array
{
    $stmt = $pdo->prepare(
        'SELECT expense_id, tenant_id, expense_date, category_name, description, supplier_or_payee,
                amount, payment_method, reference_number, status, created_at
           FROM expenses
          WHERE expense_id = :expense_id
            AND tenant_id = :tenant_id
          LIMIT 1'
    );
    $stmt->execute([':expense_id' => $expenseId, ':tenant_id' => $tenantId]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_error(404, 'Dépense introuvable pour ce syndic.');
    }

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function map_expense(array $row): array
{
    return [
        'expense_id' => (string) $row['expense_id'],
        'expense_date' => $row['expense_date'],
        'category_name' => (string) $row['category_name'],
        'description' => (string) $row['description'],
        'supplier_or_payee' => (string) $row['supplier_or_payee'],
        'amount' => cents_to_decimal(amount_to_cents($row['amount']) ?? 0),
        'payment_method' => (string) $row['payment_method'],
        'reference_number' => $row['reference_number'],
        'status' => (string) $row['status'],
        'created_at' => $row['created_at'],
    ];
}
