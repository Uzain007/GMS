import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import PhpParser from "php-parser";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");
const workflow = await read(".github/workflows/quality.yml");
const fixture = await read("scripts/ci/prepare-load-fixture.php");
const loadProbe = await read("scripts/load/report-overview.k6.js");

test("hosted backend gate runs a pinned local k6 release against disposable services", () => {
  assert.match(workflow, /IRONCORE_LOAD_GATE: "true"/);
  assert.match(workflow, /grafana\/setup-k6-action@[0-9a-f]{40} # v1\.2\.1/);
  assert.match(workflow, /k6-version: "1\.7\.1"/);
  assert.match(workflow, /PHP_CLI_SERVER_WORKERS=16/);
  assert.match(workflow, /k6 run scripts\/load\/report-overview\.k6\.js/);
  assert.match(workflow, /if: always\(\)/);
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);
});

test("load fixture is guarded, synthetic, expiring and PHP-parseable", () => {
  assert.match(fixture, /Refusing to create load fixtures outside/);
  assert.match(fixture, /@example\.test/);
  assert.match(fixture, /addMinutes\(10\)/);
  assert.match(fixture, /index <= 16/);
  assert.match(fixture, /index <= 500/);
  assert.match(fixture, /IRONCORE_ACCESS_TOKENS=/);
  assert.doesNotMatch(fixture, /(?:amazonaws\.com|supabase\.co|neon\.tech|vercel\.com)/);

  const parser = new PhpParser.Engine({ parser: { suppressErrors: false } });
  assert.doesNotThrow(() => parser.parseCode(fixture));
});

test("k6 proves cached latency, payload integrity and cross-tenant denial", () => {
  assert.match(loadProbe, /constant-arrival-rate/);
  assert.match(loadProbe, /p\(95\)<500/);
  assert.match(loadProbe, /p\(99\)<1000/);
  assert.match(loadProbe, /active_members"\) === 500/);
  assert.match(loadProbe, /result\.status === 403/);
  assert.match(loadProbe, /generated_at"\) === setupData\.generatedAt/);
  assert.match(loadProbe, /iterationInTest % tokens\.length/);
  assert.doesNotMatch(loadProbe, /Bearer [A-Za-z0-9]/);
});
