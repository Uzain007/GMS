# IronCore production CI runtime gate

Milestone 11 moves the Laravel runtime evidence out of the build-only workspace and into GitHub-hosted Linux jobs. The workflow runs for every pull request, every push to `main`, and an authorised manual dispatch. It uses only read access to repository contents, pins actions to reviewed full commit hashes and does not receive production secrets.

## Required checks

### Web build and contracts

- Installs exactly the committed `package-lock.json` with Node 22.13.
- Runs ESLint, TypeScript, the committed secret scan and every portable architecture/product contract.
- Audits production npm dependencies at high severity or above.
- Builds and validates the deployable web artifact.

### Laravel, PostgreSQL RLS and Redis

- Installs PHP 8.3 and the extensions used by the production API.
- Starts disposable PostgreSQL 17 and Redis 8 services.
- Creates the test database under `ironcore_app`, an explicit non-superuser login that cannot create databases/roles, inherit privileges or bypass RLS.
- Generates a new ephemeral Laravel `APP_KEY`; no committed or production key is used.
- Installs and audits Composer dependencies, runs the complete Laravel suite with skipped/risky tests treated as failures, and validates production config/route/view caches.
- Proves that every public table containing `gym_id` has both RLS and FORCE RLS enabled and that Redis-backed cache, session and queue configuration is active.

The bootstrap script refuses to run unless both `CI=true` and `IRONCORE_RUNTIME_GATE=true`. Its credentials are disposable workflow-only values and it never creates or modifies production infrastructure.

## GitHub handoff

After this milestone reaches GitHub:

1. Open the repository's Actions page and confirm both jobs finish successfully.
2. In repository branch rules for `main`, require a pull request and require both named checks above.
3. Keep direct pushes and force pushes disabled for `main` once the rule is verified.
4. Retain failed logs as defect evidence; do not weaken the role or remove fail-on-skip flags to make a run green.

Weekly Dependabot checks cover npm, Composer and GitHub Actions metadata. Dependency updates still pass through the same two runtime jobs before merge.

## Evidence boundary

A green hosted run closes the generic PHP/PostgreSQL/Redis execution gate and provides forced-RLS evidence for the current migrations and feature suite. It does not replace Stripe/mail/SMS/push sandbox tests, measured k6 load testing, backup restoration, infrastructure monitoring, privacy approval or production user-acceptance testing.

## First hosted-run repair

The first `main` run after Milestone 12 exposed two ordering/namespace defects that local build-first validation could not reveal: rendered-output contracts executed before `dist/server/index.js` existed, and the member-export policy read `app.current_gym_id` while the shared tenant context writes `ironcore.current_gym_id`. Milestone 13 makes the verified build precede the portable contracts, aligns the export policy with the established fail-closed setting, removes an unsupported setup action input, and adds regression assertions for both contracts.

The next hosted run passed the web job and then exposed a Laravel-only authentication boundary: Sanctum represents cookie-authenticated sessions with a non-persisted `TransientToken`. IronCore now treats only Eloquent-backed access tokens as bearer credentials when retaining or deleting a current token, while session credential rotation continues through `auth_version` and session regeneration.
