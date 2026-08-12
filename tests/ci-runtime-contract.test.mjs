import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const workflow = await readFile(".github/workflows/quality.yml", "utf8");
const databaseBootstrap = await readFile("scripts/ci/prepare-postgres.php", "utf8");
const runtimeTest = await readFile(
  "backend/tests/Feature/ProductionRuntimeGateTest.php",
  "utf8",
);
const applicationBootstrap = await readFile("backend/bootstrap/app.php", "utf8");
const tenantIsolationTest = await readFile(
  "backend/tests/Feature/PhaseThreeTenantIsolationTest.php",
  "utf8",
);
const userModel = await readFile("backend/app/Models/User.php", "utf8");
const accountSecurityController = await readFile(
  "backend/app/Http/Controllers/Api/V1/AccountSecurityController.php",
  "utf8",
);
const mfaController = await readFile(
  "backend/app/Http/Controllers/Api/V1/MfaController.php",
  "utf8",
);

test("CI is read-only and exercises locked web checks plus live PostgreSQL and Redis", () => {
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  assert.doesNotMatch(workflow, /pull_request_target/);
  assert.match(workflow, /persist-credentials: false/g);
  assert.doesNotMatch(workflow, /uses: [^\n]+@(v|main|master)\b/);
  assert.match(workflow, /actions\/checkout@[0-9a-f]{40} # v7\.0\.1/g);
  assert.match(workflow, /actions\/setup-node@[0-9a-f]{40} # v7\.0\.0/);
  assert.match(workflow, /shivammathur\/setup-php@[0-9a-f]{40} # 2\.37\.2/);
  assert.match(workflow, /node-version: 22\.13\.0/);
  assert.match(workflow, /npm ci/);
  assert.match(workflow, /npm audit --omit=dev --audit-level=high/);
  assert.match(workflow, /image: postgres:17-alpine/);
  assert.match(workflow, /image: redis:8-alpine/);
  assert.match(workflow, /DB_USERNAME: ironcore_app/);
  assert.match(workflow, /php artisan test --fail-on-skipped --fail-on-risky/);
  assert.match(workflow, /composer audit --no-interaction/);
  const buildPosition = workflow.indexOf("npm run build");
  const contractsPosition = workflow.indexOf("node --test tests/*.test.mjs");
  assert.ok(buildPosition !== -1 && buildPosition < contractsPosition);
  assert.doesNotMatch(workflow, /fail-fast:/);
});

test("cookie-authenticated Sanctum sessions never treat TransientToken as a stored token", () => {
  assert.match(userModel, /currentPersonalAccessTokenId\(\): \?int/);
  assert.match(userModel, /\$token instanceof Model/);
  assert.match(userModel, /deleteCurrentPersonalAccessToken\(\): bool/);
  assert.doesNotMatch(accountSecurityController, /currentAccessToken\(\)\?->getKey/);
  assert.doesNotMatch(mfaController, /currentAccessToken\(\)\?->getKey/);
});

test("tenant authorization precedes implicit route binding in the PostgreSQL runtime", () => {
  const bindings = applicationBootstrap.indexOf(
    "prependToPriorityList(SubstituteBindings::class",
  );
  assert.ok(bindings !== -1);
  for (const middleware of [
    "EnsureAuthenticationVersion::class",
    "BindDatabaseIdentity::class",
    "ResolveTenant::class",
    "RequireRole::class",
  ]) {
    assert.match(applicationBootstrap, new RegExp(
      `prependToPriorityList\\(SubstituteBindings::class, ${middleware.replaceAll("\\", "\\\\")}\\)`,
    ));
  }
  assert.match(runtimeTest, /test_tenant_security_middleware_precedes_implicit_route_binding/);
  assert.match(tenantIsolationTest, /postJson\("\/api\/v1\/gyms\/\{\$gym->id\}\/memberships"[\s\S]*?->assertCreated\(\)/);
});

test("the hosted runtime gate fails closed on privileged PostgreSQL or missing FORCE RLS", () => {
  assert.match(databaseBootstrap, /NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS/);
  assert.match(databaseBootstrap, /Refusing to prepare PostgreSQL outside/);
  assert.doesNotMatch(
    databaseBootstrap,
    /fwrite\([^;]*(?:superuserPassword|applicationPassword)/,
  );
  assert.match(runtimeTest, /IRONCORE_RUNTIME_GATE/);
  assert.match(runtimeTest, /rolbypassrls/);
  assert.match(runtimeTest, /relforcerowsecurity/);
  assert.match(runtimeTest, /Cache::store\('redis'\)/);
});
