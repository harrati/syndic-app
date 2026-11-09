<?php

declare(strict_types=1);

/**
 * Authentication endpoint.
 *
 * POST { "action": "login", "username": "admin", "password": "..." }
 *      optional: "tenant" = tenant subdomain (only needed when the same username
 *      exists in several tenants)
 *
 * POST { "action": "check_session" }  with header Authorization: Bearer <token>
 */

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

$pdo = db();
$body = json_body();

switch (request_action($body)) {
    case 'login':
        auth_login($pdo, $body);
        break;

    case 'check_session':
    case 'check-session':
        auth_check_session($pdo);
        break;

    default:
        json_error(400, 'Action inconnue : utilisez « login » ou « check_session ».');
}

/**
 * Verify credentials and issue a Bearer token.
 *
 * @param array<string, mixed> $body
 */
function auth_login(PDO $pdo, array $body): never
{
    $username = field_string($body, 'username', 50);
    $tenantSlug = field_string($body, 'tenant', 50, false);

    // Passwords are compared verbatim: never trim what the user typed.
    $password = $body['password'] ?? null;
    if (!is_string($password) || $password === '') {
        json_error(400, 'Le champ « password » est requis.');
    }

    $sql = 'SELECT u.user_id, u.tenant_id, u.username, u.password_hash, u.full_name,
                   u.role, u.created_at,
                   t.name AS tenant_name, t.subdomain, t.status AS tenant_status
              FROM users u
        INNER JOIN tenants t ON t.tenant_id = u.tenant_id
             WHERE u.username = :username';

    $params = [':username' => $username];

    if ($tenantSlug !== null) {
        $sql .= ' AND (t.subdomain = :tenant_slug OR t.tenant_id = :tenant_id)';
        $params[':tenant_slug'] = $tenantSlug;
        $params[':tenant_id'] = $tenantSlug;
    }

    $sql .= ' ORDER BY t.created_at ASC, u.created_at ASC LIMIT 2';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (count($rows) === 0) {
        json_error(401, 'Identifiants invalides.');
    }

    if (count($rows) > 1) {
        json_error(400, 'Plusieurs comptes utilisent cet identifiant : précisez le sous-domaine du syndic.');
    }

    $user = $rows[0];

    if (!password_verify($password, (string) $user['password_hash'])) {
        json_error(401, 'Identifiants invalides.');
    }

    if ($user['tenant_status'] !== 'active') {
        json_error(403, 'Ce syndic est suspendu. Contactez l’administrateur.');
    }

    // Transparently upgrade the stored hash when PHP's default algorithm changes.
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if (is_string($newHash)) {
            $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :user_id AND tenant_id = :tenant_id');
            $update->execute([
                ':hash' => $newHash,
                ':user_id' => $user['user_id'],
                ':tenant_id' => $user['tenant_id'],
            ]);
        }
    }

    $issued = issue_token($user);

    json_ok([
        'user' => [
            'user_id' => (string) $user['user_id'],
            'username' => (string) $user['username'],
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'created_at' => $user['created_at'],
        ],
        'tenant' => [
            'tenant_id' => (string) $user['tenant_id'],
            'name' => (string) $user['tenant_name'],
            'subdomain' => $user['subdomain'],
        ],
        'token' => $issued['token'],
        'token_type' => $issued['token_type'],
        'expires_at' => $issued['expires_at'],
        'expires_in' => $issued['expires_in'],
    ], 'Connexion réussie.');
}

/**
 * Validate the Bearer token and return the current user + tenant.
 */
function auth_check_session(PDO $pdo): never
{
    $context = require_auth($pdo);

    json_ok([
        'user' => $context['user'],
        'tenant' => $context['tenant'],
        'expires_at' => $context['token_expires_at'],
    ], 'Session valide.');
}
