<?php

declare(strict_types=1);

/**
 * Shared bootstrap for every SyndicApp API endpoint.
 *
 * Responsibilities:
 *  - global error handling (always answers JSON, never HTML)
 *  - response envelope helpers ({ success, data|error, message })
 *  - input validation helpers
 *  - money helpers (integer centimes, no float drift)
 *  - stateless Bearer token authentication (HMAC-SHA256 signed, tenant aware)
 *
 * Include as the first statement of an endpoint:
 *   require_once __DIR__ . '/_bootstrap.php';
 */

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_fifo.php';

/** Token lifetime: 12 hours. */
const TOKEN_TTL_SECONDS = 43200;

/** Receipt numbers look like REC-2026-00042. */
const RECEIPT_PREFIX = 'REC';

// Shared hosting has no UI for environment variables: this optional file calls
// putenv() so getenv() sees AUTH_SECRET / DB_* without editing tracked code.
$secretsFile = __DIR__ . '/../config/secrets.php';
if (is_file($secretsFile)) {
    require_once $secretsFile;
}

// CORS headers must be present on error responses too, so register them first.
require_once __DIR__ . '/../config/cors.php';

// Never leak PHP errors as HTML into a JSON API.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Deprecations and notices stay quiet; warnings are bugs we want surfaced.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    if ($severity === E_WARNING || $severity === E_USER_WARNING || $severity === E_RECOVERABLE_ERROR) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    return false;
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[api] Uncaught %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    var_dump($e);

    echo json_encode(
        ['success' => false, 'error' => 'Erreur interne du serveur.'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

// The connection is opened here, after the handlers: a failure becomes a clean 500.
require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

/**
 * Emit a JSON response and stop the request.
 */
function json_response(int $status, array $payload): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * `{ "success": true, "data": {...}, "message": "..." }` with HTTP 200.
 */
function json_ok(array $data = [], string $message = 'Opération réussie.'): never
{
    json_response(200, [
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);
}

/**
 * Same envelope with HTTP 201 for freshly created resources.
 */
function json_created(array $data = [], string $message = 'Ressource créée.'): never
{
    json_response(201, [
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);
}

/**
 * `{ "success": false, "error": "..." }` with the given HTTP status.
 */
function json_error(int $status, string $error): never
{
    json_response($status, [
        'success' => false,
        'error' => $error,
    ]);
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

function api_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Allow only the listed verbs; answers 405 JSON otherwise.
 */
function require_method(string ...$allowed): string
{
    $method = api_method();

    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error(405, 'Méthode HTTP non autorisée pour cette ressource.');
    }

    return $method;
}

/**
 * Decoded JSON request body. An empty body yields an empty array.
 *
 * @return array<string, mixed>
 */
function json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_error(400, 'Corps de requête JSON invalide.');
    }

    return $decoded;
}

/**
 * `action` from the JSON body (or the query string), lower-cased.
 */
function request_action(array $body): string
{
    $action = $body['action'] ?? $_GET['action'] ?? null;

    if (!is_string($action) || trim($action) === '') {
        json_error(400, 'Paramètre « action » requis.');
    }

    return strtolower(trim($action));
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

/**
 * Read a trimmed string field.
 *
 * @param array<string, mixed> $body
 */
function field_string(array $body, string $field, int $maxLength = 255, bool $required = true): ?string
{
    $value = $body[$field] ?? null;

    if ($value === null || (is_string($value) && trim($value) === '')) {
        if ($required) {
            json_error(400, sprintf('Le champ « %s » est requis.', $field));
        }

        return null;
    }

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        json_error(400, sprintf('Le champ « %s » doit être une chaîne de caractères.', $field));
    }

    $value = trim((string) $value);

    if (str_length($value) > $maxLength) {
        json_error(400, sprintf('Le champ « %s » ne peut pas dépasser %d caractères.', $field, $maxLength));
    }

    return $value;
}

/**
 * Read an integer field within bounds.
 *
 * @param array<string, mixed> $body
 */
function field_int(array $body, string $field, int $min, int $max, bool $required = true): ?int
{
    $value = $body[$field] ?? null;

    if ($value === null || $value === '') {
        if ($required) {
            json_error(400, sprintf('Le champ « %s » est requis.', $field));
        }

        return null;
    }

    if (is_bool($value) || !is_numeric($value)) {
        json_error(400, sprintf('Le champ « %s » doit être un nombre entier.', $field));
    }

    $asFloat = (float) $value;
    if ($asFloat !== floor($asFloat)) {
        json_error(400, sprintf('Le champ « %s » doit être un nombre entier.', $field));
    }

    $int = (int) $asFloat;
    if ($int < $min || $int > $max) {
        json_error(400, sprintf('Le champ « %s » doit être compris entre %d et %d.', $field, $min, $max));
    }

    return $int;
}

/**
 * Read a boolean-ish flag.
 *
 * @param array<string, mixed> $body
 */
function field_bool(array $body, string $field, bool $default): bool
{
    if (!array_key_exists($field, $body) || $body[$field] === null) {
        return $default;
    }

    $value = $body[$field];

    if (is_bool($value)) {
        return $value;
    }

    if (in_array($value, [1, '1', 'true', 'TRUE', 'yes'], true)) {
        return true;
    }

    if (in_array($value, [0, '0', 'false', 'FALSE', 'no'], true)) {
        return false;
    }

    json_error(400, sprintf('Le champ « %s » doit être un booléen.', $field));
}

/**
 * Validate a Y-m-d date.
 */
function parse_date(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

/**
 * Read a required or optional Y-m-d date field.
 *
 * @param array<string, mixed> $body
 */
function field_date(array $body, string $field, bool $required = true, ?string $default = null): ?string
{
    $value = $body[$field] ?? null;

    if ($value === null || $value === '') {
        if ($required) {
            json_error(400, sprintf('Le champ « %s » est requis (format AAAA-MM-JJ).', $field));
        }

        return $default;
    }

    $parsed = parse_date($value);

    if ($parsed === null) {
        json_error(400, sprintf('Le champ « %s » doit être une date valide au format AAAA-MM-JJ.', $field));
    }

    return $parsed;
}

/**
 * Read an optional e-mail address.
 *
 * @param array<string, mixed> $body
 */
function field_email(array $body, string $field, bool $required = false): ?string
{
    $value = field_string($body, $field, 100, $required);

    if ($value === null) {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        json_error(400, sprintf('Le champ « %s » doit être une adresse e-mail valide.', $field));
    }

    return $value;
}

// ---------------------------------------------------------------------------
// Money helpers (integer centimes)
// ---------------------------------------------------------------------------

/**
 * Read a positive monetary amount (in centimes) from the request body.
 *
 * @param array<string, mixed> $body
 */
function field_amount_cents(array $body, string $field, int $minimumCents = 1): int
{
    $value = $body[$field] ?? null;

    if ($value === null || $value === '') {
        json_error(400, sprintf('Le champ « %s » est requis.', $field));
    }

    $cents = amount_to_cents($value);

    if ($cents === null) {
        json_error(400, sprintf('Le champ « %s » doit être un montant valide (ex. 450.00).', $field));
    }

    if ($cents < $minimumCents) {
        json_error(400, sprintf('Le champ « %s » doit être supérieur ou égal à %s.', $field, cents_to_decimal($minimumCents)));
    }

    return $cents;
}

/**
 * Read a single scalar value and express it in centimes (0 when empty).
 *
 * @param array<string, mixed> $params
 */
function scalar_cents(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();

    return $value === false ? 0 : (amount_to_cents($value) ?? 0);
}

/**
 * Current date in the project timezone.
 */
function today_in_casablanca(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Casablanca')))->format('Y-m-d');
}

/**
 * Current year in the project timezone.
 */
function current_year(): int
{
    return (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Casablanca')))->format('Y');
}

// ---------------------------------------------------------------------------
// Authentication (stateless signed token, no extra table needed)
// ---------------------------------------------------------------------------

/**
 * Shared HMAC secret. Required in every environment; never hard-coded.
 */
function auth_secret(): string
{
    $secret = getenv('AUTH_SECRET');

    if ($secret === false || strlen($secret) < 32) {
        error_log('[auth] AUTH_SECRET is missing or shorter than 32 characters.');
        json_error(500, 'Configuration serveur incomplète : AUTH_SECRET est requis.');
    }

    return $secret;
}

/**
 * Read the Bearer token from the request headers.
 */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

/**
 * Issue a signed token for a user row.
 *
 * @param array{user_id:string, tenant_id:string, role:string} $user
 * @return array{token:string, token_type:string, expires_at:string, expires_in:int}
 */
function issue_token(array $user, int $ttlSeconds = TOKEN_TTL_SECONDS): array
{
    $issuedAt = time();
    $expiresAt = $issuedAt + $ttlSeconds;

    $payload = [
        'sub' => (string) $user['user_id'],
        'tid' => (string) $user['tenant_id'],
        'role' => (string) $user['role'],
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $encodedPayload = base64url_encode(
        (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $signature = hash_hmac('sha256', $encodedPayload, auth_secret(), true);

    return [
        'token' => $encodedPayload . '.' . base64url_encode($signature),
        'token_type' => 'Bearer',
        'expires_at' => gmdate('c', $expiresAt),
        'expires_in' => $ttlSeconds,
    ];
}

/**
 * Validate a token and return its claims, or answer 401.
 *
 * @return array{sub:string, tid:string, role:string, iat:int, exp:int}
 */
function verify_token(string $token): array
{
    $parts = explode('.', $token);

    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        json_error(401, 'Jeton d’authentification invalide.');
    }

    [$encodedPayload, $encodedSignature] = $parts;

    $expectedSignature = base64url_encode(hash_hmac('sha256', $encodedPayload, auth_secret(), true));

    if (!hash_equals($expectedSignature, $encodedSignature)) {
        json_error(401, 'Jeton d’authentification invalide.');
    }

    $claims = json_decode(base64url_decode($encodedPayload), true);

    if (!is_array($claims) || !isset($claims['sub'], $claims['tid'], $claims['exp'])) {
        json_error(401, 'Jeton d’authentification invalide.');
    }

    if ((int) $claims['exp'] <= time()) {
        json_error(401, 'Session expirée, veuillez vous reconnecter.');
    }

    return $claims;
}

/**
 * Authenticate the request and load the user + tenant it belongs to.
 *
 * The tenant always comes from the token, never from client input.
 *
 * @return array{
 *     tenant_id:string,
 *     user:array{user_id:string, username:string, full_name:string, role:string, created_at:?string},
 *     tenant:array{tenant_id:string, name:string, subdomain:?string, status:string},
 *     token_expires_at:string
 * }
 */
function require_auth(PDO $pdo): array
{
    $token = bearer_token();

    if ($token === null) {
        json_error(401, 'Authentification requise : en-tête « Authorization: Bearer » manquant.');
    }

    $claims = verify_token($token);

    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.tenant_id, u.username, u.full_name, u.role, u.created_at,
                t.name AS tenant_name, t.subdomain, t.status AS tenant_status
           FROM users u
     INNER JOIN tenants t ON t.tenant_id = u.tenant_id
          WHERE u.user_id = :user_id
            AND u.tenant_id = :tenant_id
          LIMIT 1'
    );

    $stmt->execute([
        ':user_id' => (string) $claims['sub'],
        ':tenant_id' => (string) $claims['tid'],
    ]);

    $row = $stmt->fetch();

    if ($row === false) {
        json_error(401, 'Session invalide : utilisateur introuvable.');
    }

    if ($row['tenant_status'] !== 'active') {
        json_error(403, 'Ce syndic est suspendu.');
    }

    return [
        'tenant_id' => (string) $row['tenant_id'],
        'user' => [
            'user_id' => (string) $row['user_id'],
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'role' => (string) $row['role'],
            'created_at' => $row['created_at'],
        ],
        'tenant' => [
            'tenant_id' => (string) $row['tenant_id'],
            'name' => (string) $row['tenant_name'],
            'subdomain' => $row['subdomain'],
            'status' => (string) $row['tenant_status'],
        ],
        'token_expires_at' => gmdate('c', (int) $claims['exp']),
    ];
}
