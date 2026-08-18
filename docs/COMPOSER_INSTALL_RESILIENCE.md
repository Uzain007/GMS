# Composer install resilience gate

Milestone 22 hardens the hosted backend quality job after three consecutive releases stopped during dependency installation before any Laravel assertion ran. It changes no package version and does not weaken the lockfile or runtime test authority.

The workflow discovers Composer's download-cache directory and restores only that directory through official `actions/cache` v5.0.3 pinned to full commit `cdf6c1fa76f9f475f3d7449005a359c84ca0f306`. The key includes the runner operating system, PHP 8.3 and the exact `backend/composer.lock` digest. Generated `backend/vendor` code is never cached.

The install runner sets parallel Composer HTTP requests to four and executes the exact non-interactive `--prefer-dist` install. Transient failures wait 15, 30 and 60 seconds before attempts two, three and four. A fourth failure exits non-zero. It never runs `composer update`, clears the reviewed download cache, substitutes a source install or receives `github.token`, repository secrets or production/provider credentials.

Portable tests execute the retry runner with synthetic outcomes and verify recovery, exhaustion, exact arguments, cache scope, immutable action pinning and credential absence. The first approved hosted run remains authoritative for Laravel, PostgreSQL forced RLS, Redis, S3, provider transports, restore and load execution.
