# IronCore API

Laravel 13 API foundation for IronCore's multi-tenant gym-management platform.

## Requirements

- PHP 8.3+
- Composer 2
- PostgreSQL
- Redis

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The API is available below `/api/v1`. Authentication uses Laravel Sanctum. Every tenant request must resolve a gym and pass server-side membership checks; sending a different `X-Gym-ID` is never sufficient to gain access.

## Current endpoints

- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `GET /api/v1/gyms`
- `POST /api/v1/gyms` — super admin only
- `GET /api/v1/gyms/{gym}`
- `PATCH /api/v1/gyms/{gym}` — authorised management roles

## Tests

```bash
composer test
```

The backend feature tests cover credential handling, token issuance, tenant isolation and super-admin access. Root-level contract tests also verify tenancy, audit, role, currency and database-index invariants without requiring a PHP runtime.
