import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

const workflow = await read(".github/workflows/quality.yml");
const restoreDrill = await read("scripts/ci/verify-postgres-restore.sh");

test("hosted backend gate invokes the disposable PostgreSQL restore drill", () => {
  assert.match(workflow, /IRONCORE_RESTORE_DRILL_GATE: "true"/);
  assert.match(workflow, /POSTGRES_CONTAINER: \$\{\{ job\.services\.postgres\.id \}\}/);
  assert.match(workflow, /bash scripts\/ci\/verify-postgres-restore\.sh/);
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);
});

test("restore drill is synthetic, portable and cleanup-bound", () => {
  assert.match(restoreDrill, /Refusing to run the PostgreSQL restore drill outside/);
  assert.match(restoreDrill, /source_database.*ironcore_test/);
  assert.match(restoreDrill, /restore_database="ironcore_restore_test"/);
  assert.match(restoreDrill, /restore-drill@example\.test/);
  assert.match(restoreDrill, /trap cleanup EXIT/);
  assert.match(restoreDrill, /DROP DATABASE IF EXISTS.*WITH \(FORCE\)/);
  assert.match(restoreDrill, /rm -f.*dump_path/);
  assert.doesNotMatch(restoreDrill, /(?:amazonaws\.com|supabase\.co|neon\.tech|vercel\.com)/);
});

test("custom backup and restore preserve forced RLS and tenant isolation", () => {
  assert.match(restoreDrill, /pg_dump[\s\S]*--format=custom[\s\S]*--no-owner[\s\S]*--no-acl/);
  assert.match(restoreDrill, /pg_restore[\s\S]*--exit-on-error[\s\S]*--single-transaction[\s\S]*--no-owner[\s\S]*--no-acl/);
  assert.match(restoreDrill, /rolbypassrls/);
  assert.match(restoreDrill, /relrowsecurity/);
  assert.match(restoreDrill, /relforcerowsecurity/);
  assert.match(restoreDrill, /did not fail closed without context/);
  assert.match(restoreDrill, /RESTORE-A/);
  assert.match(restoreDrill, /RESTORE-B/);
  assert.match(restoreDrill, /visible to an unrelated tenant/);
});
