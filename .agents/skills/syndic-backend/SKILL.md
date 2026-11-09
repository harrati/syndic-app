---
name: syndic-backend
description: Conventions for the SyndicApp native PHP 8.x REST API (PDO/MySQL, multi-tenant, FIFO accounting). Load before writing or modifying anything under /backend.
---

# Syndic Backend Skill

Backend for a Moroccan syndic (condo) management app: 100–150 apartments, 5–6 buildings,
multi-tenant ready, MAD currency, Africa/Casablanca timezone, French UI copy.

## Stack rules

- Native PHP 8.x, no framework. `declare(strict_types=1);` at the top of every PHP file.
- MySQL via `PDO` only. `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`,
  `PDO::ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`, `PDO::ATTR_EMULATE_PREPARES => false`.
- Connection is created once in `config/database.php` and exposed as `$pdo`.
- Charset `utf8mb4` / `utf8mb4_unicode_ci` on every connection and table.

## Files

| File                    | Purpose                                                        |
| ----------------------- | -------------------------------------------------------------- |
| `config/database.php`   | Singleton PDO connection, utf8mb4, env-driven credentials.      |
| `config/cors.php`       | CORS + preflight handling, JSON content type, security headers. |
| `schema.sql`            | Full MySQL schema (tenants → expenses) with FKs + unique keys.  |
| `seed.sql`              | Default tenant + admin user needed before any login.           |
| `api/_bootstrap.php`    | JSON envelope, validation, auth, money helpers, error handling. |
| `api/_lib.php`          | Pure helpers (money, UUID, base64url) — no DB, testable.        |
| `api/_fifo.php`         | Pure FIFO allocation engine over integer centimes.              |
| `api/*.php`             | Endpoints: auth, apartments, payments, expenses, dashboard.     |

## Non-negotiable conventions

1. Every response is strict JSON, `Content-Type: application/json; charset=utf-8`:
   - success: `{ "success": true, "data": {...}, "message": "..." }`
   - failure: `{ "success": false, "error": "Reason" }`
2. HTTP codes: `400` validation, `401` unauthorized, `403` forbidden/tenant mismatch,
   `404` not found, `500` database/server failure. Never leak PDO messages to clients;
   log the real error server-side.
3. SQL: prepared statements with bound parameters only. No string concatenation of
   user input into SQL. Never interpolate table/column names from request data.
4. Multi-tenancy: every primary table carries `tenant_id VARCHAR(36)`. Every query on
   tenant data must filter `AND tenant_id = :tenant_id`, using the tenant from the
   authenticated token — never from the request body.
5. Financial writes (payments, allocations, expenses) MUST run inside a transaction:
   `$pdo->beginTransaction()` … `$pdo->commit()`, `$pdo->rollBack()` on any exception.
6. FIFO allocation: a payment settles the apartment's unpaid charges ordered by
   `period_year, period_month, due_date`; allocate the smallest of remaining payment and
   remaining charge into `payment_allocations`. Overpayment stays unallocated (credit).
   `amount_allocated` rows are append-only; void by marking the payment `VOID` and
   reversing, never by editing history.
7. Authentication: `Authorization: Bearer <token>` on protected routes, checked before
   any business logic. Passwords via `password_hash()` / `password_verify()`.
8. IDs: UUID v4 strings for every primary key (`bin2hex(random_bytes(16))` formatted, or ramsey/uuid).
9. Money: `DECIMAL(10,2)` in MySQL, strings/float-safe math in PHP (bcmath or integer
   centimes). Format as `MAD` for humans; store no currency symbol.

## Schema facts worth remembering

- `users.role` is `ENUM('ADMIN','GESTIONNAIRE')`; default tenant `'default-tenant'`.
- `charges` uniqueness: one charge per apartment per year/month.
- `payments.receipt_number` unique per tenant; status `VALID` / `VOID`.
- `apartments.monthly_fee` defaults to `150.00`.
- Expenses are tenant-level (not per apartment) and carry `status VALID|VOID`.

## API surface

Every endpoint requires `Authorization: Bearer <token>` except `POST auth.php` with
`action: login`. Unknown actions and unknown fields are rejected with `400`.

| Endpoint          | Verbs     | Actions / behaviour                                                        |
| ----------------- | --------- | -------------------------------------------------------------------------- |
| `auth.php`        | POST      | `login` (username, password, optional tenant subdomain), `check_session`     |
| `apartments.php`  | GET, POST | GET lists buildings + active apartments; POST `create_building`, `update_building`, `create_apartment`, `update_apartment` |
| `payments.php`    | POST      | Records a payment, receipt `REC-YYYY-XXXXX`, FIFO allocation, advance credit |
| `expenses.php`    | GET, POST | GET log + categories + totals; POST `create`, `void`                        |
| `dashboard.php`   | GET       | `?year=` totals, net cash, unallocated credits, collection rate             |

- Duplicate unique keys answer `409`; wrong verb answers `405` with `Allow`.
- Tokens are stateless HMAC-SHA256 (`base64url(payload).base64url(sig)`), 12 h TTL,
  and require `AUTH_SECRET` (32+ chars) in the environment.
- Money is handled as integer centimes inside PHP and as `DECIMAL(10,2)` strings in
  JSON (`"450.00"`); only the UI formats `MAD`.
- A receipt-number collision restarts the whole payment transaction: inside
  REPEATABLE READ a retried `MAX()` would not see the competing commit.

## Local run

```bash
AUTH_SECRET="...at least 32 random characters..." php -S localhost:8000 -t backend/
```

`pdo_mysql` must be enabled in the PHP build (it is not by default in every install:
`php -d extension=pdo_mysql -S ...` otherwise). Load `backend/seed.sql` once for the
first login.

CORS must allow the Angular dev origin (`http://localhost:4200`) with
`Authorization` and `Content-Type` request headers, and handle `OPTIONS` preflight
with `204 No Content` and no body.
