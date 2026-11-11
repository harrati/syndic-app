<?php

declare(strict_types=1);

/**
 * Buildings & apartments endpoint (tenant scoped).
 *
 * GET  — every building of the tenant, each with its active apartments.
 * POST — action based:
 *   { "action": "create_building", "building_code": "A", "building_name": "Bâtiment A" }
 *   { "action": "update_building", "building_id": "...", "building_name": "..." }
 *   { "action": "create_apartment", "building_id": "...", "apartment_number": "12",
 *     "floor": 3, "owner_name": "...", "monthly_fee": 150, ... }
 *   { "action": "update_apartment", "apartment_id": "...", ... }
 */

require_once __DIR__ . '/_bootstrap.php';

$method = require_method('GET', 'POST');
$pdo = db();
$context = require_auth($pdo);
$tenantId = $context['tenant_id'];

if ($method === 'GET') {
    apartments_index($pdo, $tenantId);
}

$body = json_body();

switch (request_action($body)) {
    case 'create_building':
        building_create($pdo, $tenantId, $body);
        break;

    case 'update_building':
        building_update($pdo, $tenantId, $body);
        break;

    case 'create_apartment':
        apartment_create($pdo, $tenantId, $body);
        break;

    case 'update_building':
        building_update($pdo, $tenantId, $body);
        break;
    case 'delete_building':
        building_delete($pdo, $tenantId, $body);
        break;
    case 'update_apartment':
        apartment_update($pdo, $tenantId, $body);
        break;
    case 'delete_apartment':
        apartment_delete($pdo, $tenantId, $body);
        break;
    default:
        json_error(400, 'Action inconnue : create_building, update_building, create_apartment, delete_building ou delete_apartment.');
}

function building_delete(PDO $pdo, string $tenantId, array $body): never
{
    $buildingId = field_string($body, 'building_id', 36);
    $existing = fetch_building($pdo, $tenantId, $buildingId);

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM buildings
              WHERE building_id = :building_id
                AND tenant_id = :tenant_id'
        );
        $stmt->execute([':building_id' => $buildingId, ':tenant_id' => $tenantId]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, 'Impossible de supprimer cet immeuble car il contient des appartements actifs.');
        }

        throw $e;
    }

    json_ok([], 'Immeuble supprimé.');
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function apartments_index(PDO $pdo, string $tenantId): never
{
    $buildingsStmt = $pdo->prepare(
        'SELECT building_id, building_code, building_name, address_or_description, active, created_at
           FROM buildings
          WHERE tenant_id = :tenant_id
       ORDER BY building_code ASC, building_name ASC'
    );
    $buildingsStmt->execute([':tenant_id' => $tenantId]);

    $apartmentsStmt = $pdo->prepare(
        'SELECT apartment_id, building_id, apartment_number, floor, owner_name, resident_name,
                phone, email, monthly_fee, active, created_at
           FROM apartments
          WHERE tenant_id = :tenant_id
            AND active = 1
       ORDER BY apartment_number ASC'
    );
    $apartmentsStmt->execute([':tenant_id' => $tenantId]);

    $apartmentsByBuilding = [];
    foreach ($apartmentsStmt->fetchAll() as $row) {
        $apartmentsByBuilding[$row['building_id']][] = map_apartment($row);
    }

    $buildings = [];
    $apartmentCount = 0;

    foreach ($buildingsStmt->fetchAll() as $row) {
        $apartments = $apartmentsByBuilding[$row['building_id']] ?? [];
        $apartmentCount += count($apartments);

        $buildings[] = map_building($row) + [
            'apartment_count' => count($apartments),
            'apartments' => $apartments,
        ];
    }

    json_ok([
        'buildings' => $buildings,
        'totals' => [
            'buildings' => count($buildings),
            'apartments' => $apartmentCount,
        ],
        'currency' => 'MAD',
    ], 'Immeubles et appartements récupérés.');
}

// ---------------------------------------------------------------------------
// Buildings
// ---------------------------------------------------------------------------

function building_create(PDO $pdo, string $tenantId, array $body): never
{
    $code = field_string($body, 'building_code', 50);
    $name = field_string($body, 'building_name', 100);
    $address = field_string($body, 'address_or_description', 2000, false);
    $active = field_bool($body, 'active', true);

    if (building_code_taken($pdo, $tenantId, $code)) {
        json_error(409, sprintf('Le code « %s » est déjà utilisé par un autre immeuble.', $code));
    }

    $buildingId = uuid4();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO buildings (building_id, tenant_id, building_code, building_name, address_or_description, active)
             VALUES (:building_id, :tenant_id, :building_code, :building_name, :address_or_description, :active)'
        );
        $stmt->execute([
            ':building_id' => $buildingId,
            ':tenant_id' => $tenantId,
            ':building_code' => $code,
            ':building_name' => $name,
            ':address_or_description' => $address,
            ':active' => $active ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, sprintf('Le code « %s » est déjà utilisé par un autre immeuble.', $code));
        }

        throw $e;
    }

    json_created(['building' => map_building(fetch_building($pdo, $tenantId, $buildingId))], 'Immeuble créé.');
}

function building_update(PDO $pdo, string $tenantId, array $body): never
{
    $buildingId = field_string($body, 'building_id', 36);
    $existing = fetch_building($pdo, $tenantId, $buildingId);

    $updates = [];
    $params = [':building_id' => $buildingId, ':tenant_id' => $tenantId];

    if (array_key_exists('building_code', $body)) {
        $code = field_string($body, 'building_code', 50);

        if ($code !== $existing['building_code'] && building_code_taken($pdo, $tenantId, $code, $buildingId)) {
            json_error(409, sprintf('Le code « %s » est déjà utilisé par un autre immeuble.', $code));
        }

        $updates[] = 'building_code = :building_code';
        $params[':building_code'] = $code;
    }

    if (array_key_exists('building_name', $body)) {
        $updates[] = 'building_name = :building_name';
        $params[':building_name'] = field_string($body, 'building_name', 100);
    }

    if (array_key_exists('address_or_description', $body)) {
        $updates[] = 'address_or_description = :address_or_description';
        $params[':address_or_description'] = field_string($body, 'address_or_description', 2000, false);
    }

    if (array_key_exists('active', $body)) {
        $updates[] = 'active = :active';
        $params[':active'] = field_bool($body, 'active', true) ? 1 : 0;
    }

    if ($updates === []) {
        json_error(400, 'Aucune modification fournie.');
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE buildings SET ' . implode(', ', $updates) . '
              WHERE building_id = :building_id
                AND tenant_id = :tenant_id'
        );
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, 'Ce code d’immeuble est déjà utilisé.');
        }

        throw $e;
    }

    json_ok(['building' => map_building(fetch_building($pdo, $tenantId, $buildingId))], 'Immeuble mis à jour.');
}

// ---------------------------------------------------------------------------
// Apartments
// ---------------------------------------------------------------------------

function apartment_create(PDO $pdo, string $tenantId, array $body): never
{
    $buildingId = field_string($body, 'building_id', 36);
    fetch_building($pdo, $tenantId, $buildingId);

    $number = field_string($body, 'apartment_number', 20);
    $floor = field_int($body, 'floor', -10, 200);
    $ownerName = field_string($body, 'owner_name', 100);
    $residentName = field_string($body, 'resident_name', 100, false);
    $phone = field_string($body, 'phone', 30, false);
    $email = field_email($body, 'email');

    $monthlyFeeCents = isset($body['monthly_fee']) && $body['monthly_fee'] !== ''
        ? field_amount_cents($body, 'monthly_fee', 0)
        : 15000; // schema default: 150.00 MAD

    $active = field_bool($body, 'active', true);

    if (apartment_number_taken($pdo, $tenantId, $buildingId, $number)) {
        json_error(409, sprintf('L’appartement « %s » existe déjà dans cet immeuble.', $number));
    }

    $apartmentId = uuid4();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO apartments (apartment_id, tenant_id, building_id, apartment_number, floor,
                                     owner_name, resident_name, phone, email, monthly_fee, active)
             VALUES (:apartment_id, :tenant_id, :building_id, :apartment_number, :floor,
                     :owner_name, :resident_name, :phone, :email, :monthly_fee, :active)'
        );
        $stmt->execute([
            ':apartment_id' => $apartmentId,
            ':tenant_id' => $tenantId,
            ':building_id' => $buildingId,
            ':apartment_number' => $number,
            ':floor' => $floor,
            ':owner_name' => $ownerName,
            ':resident_name' => $residentName,
            ':phone' => $phone,
            ':email' => $email,
            ':monthly_fee' => cents_to_decimal($monthlyFeeCents),
            ':active' => $active ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, sprintf('L’appartement « %s » existe déjà dans cet immeuble.', $number));
        }

        throw $e;
    }

    json_created(['apartment' => map_apartment(fetch_apartment($pdo, $tenantId, $apartmentId))], 'Appartement créé.');
}

function apartment_update(PDO $pdo, string $tenantId, array $body): never
{
    $apartmentId = field_string($body, 'apartment_id', 36);
    $existing = fetch_apartment($pdo, $tenantId, $apartmentId);

    $updates = [];
    $params = [':apartment_id' => $apartmentId, ':tenant_id' => $tenantId];

    if (array_key_exists('building_id', $body)) {
        $buildingId = field_string($body, 'building_id', 36);
        fetch_building($pdo, $tenantId, $buildingId);

        $updates[] = 'building_id = :building_id';
        $params[':building_id'] = $buildingId;
    } else {
        $buildingId = (string) $existing['building_id'];
    }

    if (array_key_exists('apartment_number', $body)) {
        $number = field_string($body, 'apartment_number', 20);

        if (apartment_number_taken($pdo, $tenantId, $buildingId, $number, $apartmentId)) {
            json_error(409, sprintf('L’appartement « %s » existe déjà dans cet immeuble.', $number));
        }

        $updates[] = 'apartment_number = :apartment_number';
        $params[':apartment_number'] = $number;
    }

    if (array_key_exists('floor', $body)) {
        $updates[] = 'floor = :floor';
        $params[':floor'] = field_int($body, 'floor', -10, 200);
    }

    if (array_key_exists('owner_name', $body)) {
        $updates[] = 'owner_name = :owner_name';
        $params[':owner_name'] = field_string($body, 'owner_name', 100);
    }

    if (array_key_exists('resident_name', $body)) {
        $updates[] = 'resident_name = :resident_name';
        $params[':resident_name'] = field_string($body, 'resident_name', 100, false);
    }

    if (array_key_exists('phone', $body)) {
        $updates[] = 'phone = :phone';
        $params[':phone'] = field_string($body, 'phone', 30, false);
    }

    if (array_key_exists('email', $body)) {
        $updates[] = 'email = :email';
        $params[':email'] = field_email($body, 'email');
    }

    if (array_key_exists('monthly_fee', $body)) {
        $updates[] = 'monthly_fee = :monthly_fee';
        $params[':monthly_fee'] = cents_to_decimal(field_amount_cents($body, 'monthly_fee', 0));
    }

    if (array_key_exists('active', $body)) {
        $updates[] = 'active = :active';
        $params[':active'] = field_bool($body, 'active', true) ? 1 : 0;
    }

    if ($updates === []) {
        json_error(400, 'Aucune modification fournie.');
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE apartments SET ' . implode(', ', $updates) . '
              WHERE apartment_id = :apartment_id
                AND tenant_id = :tenant_id'
        );
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, 'Cet appartement existe déjà dans l’immeuble sélectionné.');
        }

        throw $e;
    }

    json_ok(['apartment' => map_apartment(fetch_apartment($pdo, $tenantId, $apartmentId))], 'Appartement mis à jour.');
}

function apartment_delete(PDO $pdo, string $tenantId, array $body): never
{
    $apartmentId = field_string($body, 'apartment_id', 36);
    $existing = fetch_apartment($pdo, $tenantId, $apartmentId);

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM apartments
              WHERE apartment_id = :apartment_id
                AND tenant_id = :tenant_id'
        );
        $stmt->execute([':apartment_id' => $apartmentId, ':tenant_id' => $tenantId]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_error(409, 'Impossible de supprimer cet appartement.');
        }

        throw $e;
    }

    json_ok([], 'Appartement supprimé.');
}

// ---------------------------------------------------------------------------
// Lookups & serialization
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function fetch_building(PDO $pdo, string $tenantId, string $buildingId): array
{
    $stmt = $pdo->prepare(
        'SELECT building_id, tenant_id, building_code, building_name, address_or_description, active, created_at
           FROM buildings
          WHERE building_id = :building_id
            AND tenant_id = :tenant_id
          LIMIT 1'
    );
    $stmt->execute([':building_id' => $buildingId, ':tenant_id' => $tenantId]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_error(404, 'Immeuble introuvable pour ce syndic.');
    }

    return $row;
}

/**
 * @return array<string, mixed>
 */
function fetch_apartment(PDO $pdo, string $tenantId, string $apartmentId): array
{
    $stmt = $pdo->prepare(
        'SELECT apartment_id, tenant_id, building_id, apartment_number, floor, owner_name,
                resident_name, phone, email, monthly_fee, active, created_at
           FROM apartments
          WHERE apartment_id = :apartment_id
            AND tenant_id = :tenant_id
          LIMIT 1'
    );
    $stmt->execute([':apartment_id' => $apartmentId, ':tenant_id' => $tenantId]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_error(404, 'Appartement introuvable pour ce syndic.');
    }

    return $row;
}

function building_code_taken(PDO $pdo, string $tenantId, string $code, ?string $exceptBuildingId = null): bool
{
    $sql = 'SELECT 1 FROM buildings WHERE tenant_id = :tenant_id AND building_code = :building_code';
    $params = [':tenant_id' => $tenantId, ':building_code' => $code];

    if ($exceptBuildingId !== null) {
        $sql .= ' AND building_id <> :building_id';
        $params[':building_id'] = $exceptBuildingId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}

function apartment_number_taken(
    PDO $pdo,
    string $tenantId,
    string $buildingId,
    string $number,
    ?string $exceptApartmentId = null
): bool {
    $sql = 'SELECT 1 FROM apartments
             WHERE tenant_id = :tenant_id
               AND building_id = :building_id
               AND apartment_number = :apartment_number';
    $params = [
        ':tenant_id' => $tenantId,
        ':building_id' => $buildingId,
        ':apartment_number' => $number,
    ];

    if ($exceptApartmentId !== null) {
        $sql .= ' AND apartment_id <> :apartment_id';
        $params[':apartment_id'] = $exceptApartmentId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function map_building(array $row): array
{
    return [
        'building_id' => (string) $row['building_id'],
        'building_code' => (string) $row['building_code'],
        'building_name' => (string) $row['building_name'],
        'address_or_description' => $row['address_or_description'],
        'active' => (bool) $row['active'],
        'created_at' => $row['created_at'],
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function map_apartment(array $row): array
{
    return [
        'apartment_id' => (string) $row['apartment_id'],
        'building_id' => (string) $row['building_id'],
        'apartment_number' => (string) $row['apartment_number'],
        'floor' => (int) $row['floor'],
        'owner_name' => (string) $row['owner_name'],
        'resident_name' => $row['resident_name'],
        'phone' => $row['phone'],
        'email' => $row['email'],
        'monthly_fee' => cents_to_decimal((int) amount_to_cents($row['monthly_fee'])),
        'active' => (bool) $row['active'],
        'created_at' => $row['created_at'],
    ];
}
