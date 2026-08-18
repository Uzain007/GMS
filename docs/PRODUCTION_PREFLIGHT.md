# IronCore production configuration preflight

Milestone 19 adds two credential-safe checks that must pass before a production release is eligible for traffic. They validate resolved configuration shape and print only setting names and requirements; configured values never enter command output.

## Backend gate

After the deployment platform has injected its secrets, build Laravel's configuration cache and run:

```bash
cd backend
php artisan config:cache
php artisan ironcore:production-preflight
```

A non-zero exit stops the release before migrations or traffic. The command checks production/debug mode, key strength, HTTPS browser/API origins, trusted proxies, CORS/Sanctum/session alignment, the `ironcore_app` PostgreSQL identity, encrypted PostgreSQL and Redis connections, Redis cache/session/queues, private S3-compatible storage, Stripe secrets and callback URLs, authenticated SMTP, a centrally collectable log stream, complete optional SMS/push adapter pairs and any configured readable Stripe or notification-provider CA bundle.

`TRUSTED_PROXIES` is parsed by `config/trustedproxy.php` as comma-separated IP addresses/CIDRs or the explicit `*` provider wildcard. Laravel's default HTTP proxy middleware resolves that setting only after configuration is bootstrapped; this keeps Composer package discovery and CLI commands safe without weakening forwarding-header protection. An empty or invalid production boundary still fails this preflight.

The preflight intentionally does not connect to PostgreSQL, Redis, S3, Stripe or notification providers. Connectivity and behaviour remain covered by readiness checks, hosted runtime tests and explicit provider/environment evidence.

## Web gate

Run this in the production web-build environment before building the deployable frontend:

```bash
npm run preflight:production-web
npm run build
```

It requires `NODE_ENV=production`, explicitly disables representative demo mode, accepts only a public HTTPS API origin and requires the full immutable release SHA injected as `VERCEL_GIT_COMMIT_SHA`, `GITHUB_SHA` or portable `IRONCORE_RELEASE_SHA`. It accepts no backend or provider secret.

## Evidence boundary

A passing result proves only that the release received a coherent security-sensitive configuration. It does not close Stripe/mail/SMS/push sandbox execution, provider backup/PITR/storage controls, monitoring and alert delivery, privacy approval, branch protection, hardware acceptance or production-topology capacity testing.
