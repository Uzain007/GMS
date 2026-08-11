import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const workflow = await readFile(".github/workflows/quality.yml", "utf8");
const databaseBootstrap = await readFile("scripts/ci/prepare-postgres.php", "utf8");
const runtimeTest = await readFile(
  "backend/tests/Feature/ProductionRuntimeGateTest.php",
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
