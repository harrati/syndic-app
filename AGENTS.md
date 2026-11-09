# AGENTS.md - Freebuff Project Context \& Directives

## Project Overview

Mobile-First Syndic Management Application tailored for Moroccan residential complexes (100–150 apartments across 5–6 buildings). Built as a Single Page Application (SPA) with an Angular frontend, Tailwind CSS, Native PHP 8.x REST API backend, and MySQL database. Prepared for multi-tenancy SaaS evolution.

* **Primary Currency**: Moroccan Dirham (`MAD` / `DH`)
* **Primary Timezone**: `Africa/Casablanca`
* **Default Language**: French (`FR`)

\---

## Tech Stack \& Architecture Rules

### 1\. Frontend (Angular + Tailwind CSS)

* **Framework**: Angular (latest stable, standalone components, Reactive Forms).
* **Styling**: Utility-first Tailwind CSS. Touch-first UI (`min-h-\\\[44px]` touch targets).
* **Communication**: Angular `HttpClient` pointing to PHP REST API endpoints.
* **State \& Data**: RxJS observables, centralized Services (`ApiService`, `AuthService`).
* **PWA Ready**: Mobile bottom navigation bar, touch responsive, local storage token caching.

### 2\. Backend (Native PHP 8.x + MySQL PDO)

* **Architecture**: Stateless RESTful API returning strict JSON (`Content-Type: application/json`).
* **Database Access**: Native PHP `PDO` with prepared statements (Strictly NO raw string concatenations in SQL).
* **Multi-Tenancy Preparedness**: Every primary table MUST contain a `tenant\\\_id VARCHAR(36)` column.
* **Transactions**: Financial writes MUST use MySQL transactions (`$pdo->beginTransaction()`, `$pdo->commit()`, `$pdo->rollBack()`).
* **Accounting Engine**: Strict **FIFO (First In, First Out)** allocation algorithm for monthly charges.
* **Security**: CORS headers enabled, `password\\\_hash()` (Bcrypt/Argon2id) for credentials, Bearer token authentication header check on protected routes.

\---

## Database Schema Specification (MySQL)

Ensure all tables enforce utf8mb4 encoding and foreign key constraints:

```sql
CREATE DATABASE IF NOT EXISTS syndic\\\_db CHARACTER SET utf8mb4 COLLATE utf8mb4\\\_unicode\\\_ci;
USE syndic\\\_db;

-- Tenants (Multi-tenancy ready)
CREATE TABLE tenants (
    tenant\\\_id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    subdomain VARCHAR(50) UNIQUE,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP
);

-- Users
CREATE TABLE users (
    user\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    username VARCHAR(50) NOT NULL,
    password\\\_hash VARCHAR(255) NOT NULL,
    full\\\_name VARCHAR(100) NOT NULL,
    role ENUM('ADMIN', 'GESTIONNAIRE') DEFAULT 'ADMIN',
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id)
);

-- Buildings
CREATE TABLE buildings (
    building\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    building\\\_code VARCHAR(50) NOT NULL,
    building\\\_name VARCHAR(100) NOT NULL,
    address\\\_or\\\_description TEXT,
    active TINYINT(1) DEFAULT 1,
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id),
    UNIQUE KEY unique\\\_bld\\\_tenant (tenant\\\_id, building\\\_code)
);

-- Apartments
CREATE TABLE apartments (
    apartment\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    building\\\_id VARCHAR(36) NOT NULL,
    apartment\\\_number VARCHAR(20) NOT NULL,
    floor INT NOT NULL,
    owner\\\_name VARCHAR(100) NOT NULL,
    resident\\\_name VARCHAR(100),
    phone VARCHAR(30),
    email VARCHAR(100),
    monthly\\\_fee DECIMAL(10,2) NOT NULL DEFAULT 150.00,
    active TINYINT(1) DEFAULT 1,
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id),
    FOREIGN KEY (building\\\_id) REFERENCES buildings(building\\\_id),
    UNIQUE KEY unique\\\_apt\\\_bld (building\\\_id, apartment\\\_number)
);

-- Charges
CREATE TABLE charges (
    charge\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    apartment\\\_id VARCHAR(36) NOT NULL,
    period\\\_year INT NOT NULL,
    period\\\_month INT NOT NULL,
    due\\\_date DATE NOT NULL,
    amount\\\_due DECIMAL(10,2) NOT NULL,
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id),
    FOREIGN KEY (apartment\\\_id) REFERENCES apartments(apartment\\\_id),
    UNIQUE KEY unique\\\_charge (apartment\\\_id, period\\\_year, period\\\_month)
);

-- Payments
CREATE TABLE payments (
    payment\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    receipt\\\_number VARCHAR(50) NOT NULL,
    apartment\\\_id VARCHAR(36) NOT NULL,
    payment\\\_date DATE NOT NULL,
    amount\\\_received DECIMAL(10,2) NOT NULL,
    payment\\\_method VARCHAR(50) NOT NULL,
    reference\\\_number VARCHAR(100),
    status ENUM('VALID', 'VOID') DEFAULT 'VALID',
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id),
    FOREIGN KEY (apartment\\\_id) REFERENCES apartments(apartment\\\_id),
    UNIQUE KEY unique\\\_receipt (tenant\\\_id, receipt\\\_number)
);

-- Payment Allocations (FIFO)
CREATE TABLE payment\\\_allocations (
    allocation\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    payment\\\_id VARCHAR(36) NOT NULL,
    charge\\\_id VARCHAR(36) NOT NULL,
    amount\\\_allocated DECIMAL(10,2) NOT NULL,
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id),
    FOREIGN KEY (payment\\\_id) REFERENCES payments(payment\\\_id),
    FOREIGN KEY (charge\\\_id) REFERENCES charges(charge\\\_id)
);

-- Expenses
CREATE TABLE expenses (
    expense\\\_id VARCHAR(36) PRIMARY KEY,
    tenant\\\_id VARCHAR(36) NOT NULL DEFAULT 'default-tenant',
    expense\\\_date DATE NOT NULL,
    category\\\_name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    supplier\\\_or\\\_payee VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment\\\_method VARCHAR(50) NOT NULL,
    reference\\\_number VARCHAR(100),
    status ENUM('VALID', 'VOID') DEFAULT 'VALID',
    created\\\_at DATETIME DEFAULT CURRENT\\\_TIMESTAMP,
    FOREIGN KEY (tenant\\\_id) REFERENCES tenants(tenant\\\_id)
);
```

\---

## Code Guidelines \& Conventions

### PHP Rules

1. Wrap API responses in standard JSON format:

```json
   { "success": true, "data": { ... }, "message": "Success notification" }
   ```

2. Exception Handling: Return HTTP `400` for validation errors, `401` for unauthorized, `500` for database failures with JSON error message: `{ "success": false, "error": "Reason" }`.
3. Generate UUIDs for all primary keys (`RAMSEY/UUID` or native `bin2hex(random\\\_bytes(16))`).

### Angular Rules

1. Use Standalone Components (`standalone: true`).
2. Use Reactive Forms (`FormBuilder`, `Validators`).
3. Handle API loaders cleanly with signals or RxJS observables (`BehaviorSubject`).
4. Apply Tailwind CSS utility classes directly.

\---

## Command Reference

### Angular Commands

* `ng serve`: Run local frontend development server at `http://localhost:4200`.
* `ng g c features/<feature-name> --standalone`: Generate standalone component.
* `ng build`: Build production artifacts in `dist/`.

### PHP Server Command

* `php -S localhost:8000 -t backend/`: Run built-in PHP development server pointing to backend directory.



