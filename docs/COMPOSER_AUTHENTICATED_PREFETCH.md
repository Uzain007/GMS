# Credential-isolated Composer prefetch gate

Milestone 23 addresses the GitHub package-download limit that remained after Milestone 22's cache, lower concurrency and four retries. It changes no Composer package, lockfile entry, PHP source or IronCore behavior.

The backend job supplies GitHub Actions' ephemeral read-only `github.token` only to the reviewed Node install runner. That runner converts it to in-memory `COMPOSER_AUTH` for Composer prefetch children that always include both `--no-plugins` and `--no-scripts`. Those children can download and install the exact locked package files, but no root script, dependency script or Composer plugin can execute with the credential.

After a successful prefetch, the runner starts a separate normal locked install to generate the final autoloader and run Laravel package discovery. Its explicit child environment removes `COMPOSER_AUTH`, `GITHUB_TOKEN`, `GH_TOKEN` and the workflow handoff variable first. Activation is attempted once and fails closed so application/script defects are not hidden as transport retries.

The existing lockfile-keyed download cache, four-attempt 15/30/60-second backoff, `--prefer-dist`, reduced parallelism and no-`vendor` cache contract remain active. Repository secrets and production/provider credentials are not accepted. Portable tests verify the exact phase arguments, token isolation, safe-variable retention, secret-free logs, bounded recovery/exhaustion, activation failure and unchanged lockfile content.

The approved `48561a8` run confirmed the transport repair: authenticated prefetch downloaded and installed all 116 locked packages, then the credential-free activation found nothing further to download. Laravel package discovery subsequently exposed a separate early-bootstrap config-service defect, which Milestone 24 addresses in [LARAVEL_BOOTSTRAP_SAFETY.md](LARAVEL_BOOTSTRAP_SAFETY.md). No Laravel assertion ran in that backend job.
