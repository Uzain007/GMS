# Laravel bootstrap-safe trusted proxy gate

Milestone 24 addresses the application-startup failure exposed after Milestone 23 successfully cleared the Composer download boundary. The approved hosted run installed the complete locked dependency graph, removed every Composer/GitHub credential and then failed in `artisan package:discover` because `bootstrap/app.php` called `config()` before Laravel had registered the config service.

## Runtime contract

- `bootstrap/app.php` registers middleware and tenant-security ordering without resolving configuration.
- `config/trustedproxy.php` parses `TRUSTED_PROXIES` into IP/CIDR entries, or into Laravel's scalar `*` provider wildcard.
- Laravel's default global trusted-proxy middleware resolves that value while handling HTTP requests, after configuration is available.
- The production preflight reads the same resolved value and rejects an empty list, hostnames, malformed IP/CIDR values and unsupported wildcard shapes.

This preserves HTTPS and client-IP forwarding protection at the production edge while allowing Composer package discovery, configuration caching and ordinary Artisan commands to create the application. No tenant schema, route, role, policy, dependency, workflow or product behavior changes.

## Evidence boundary

Portable tests prevent configuration access from returning to early bootstrap and validate the config/preflight contract. Laravel feature tests cover an allowed CIDR, the explicit provider wildcard and invalid hostname rejection. Only an approved commit/push can re-run the complete hosted PostgreSQL/Redis/S3/provider/restore/load authority and confirm package discovery in the Linux PHP runtime.
