<?php

declare(strict_types=1);

/**
 * Example of the local secrets file used by the PHP API.
 *
 * Copy to `config/secrets.php` on the server (never commit the real file) and
 * replace every placeholder. `_bootstrap.php` loads it when present, before the
 * CORS/DB setup, so `getenv()` returns these values just like real environment
 * variables. Values here override the server environment.
 *
 * AUTH_SECRET must be at least 32 characters and must stay identical between
 * deployments, otherwise every issued token becomes invalid.
 */

putenv('AUTH_SECRET=change-me-to-at-least-32-random-characters');
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=u000000000_syndic');
putenv('DB_USER=u000000000_syndic');
putenv('DB_PASS=change-me');

// Same-origin deployments (SPA and API on one domain) need no entry here.
// Add an origin only if the SPA is served from another domain, comma separated.
putenv('CORS_ALLOWED_ORIGINS=');
