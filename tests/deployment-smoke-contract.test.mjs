import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import {
  extractMetaContent,
  validateExpectedCommit,
  validateTargetUrl,
} from "../scripts/smoke/deployed-web.mjs";

const workflow = await readFile(
  new URL("../.github/workflows/deployment-smoke.yml", import.meta.url),
  "utf8",
);
const layout = await readFile(new URL("../app/layout.tsx", import.meta.url), "utf8");
const packageJson = JSON.parse(
  await readFile(new URL("../package.json", import.meta.url), "utf8"),
);

test("deployed web smoke runs after main changes and on a bounded schedule", () => {
  assert.match(workflow, /name: IronCore deployed web smoke/);
  assert.match(workflow, /push:\s*\n\s*branches:\s*\n\s*- main/);
  assert.match(workflow, /schedule:\s*\n\s*- cron: "17 \*\/6 \* \* \*"/);
  assert.match(workflow, /workflow_dispatch:/);
  assert.doesNotMatch(workflow, /pull_request_target:|pull_request:/);
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  assert.match(workflow, /persist-credentials: false/);
  assert.match(workflow, /IRONCORE_EXPECTED_COMMIT: \$\{\{ github\.sha \}\}/);
  assert.match(workflow, /IRONCORE_SMOKE_WEB_URL: https:\/\/gms-beige-ten\.vercel\.app\//);
  assert.match(workflow, /IRONCORE_SMOKE_ALLOWED_HOSTS: gms-beige-ten\.vercel\.app/);
  assert.match(workflow, /timeout-minutes: 35/);
  assert.match(workflow, /IRONCORE_SMOKE_DEPLOY_WAIT_MS: "1800000"/);
});

test("deployed web smoke actions are immutable and need no package install", () => {
  const actionUses = [...workflow.matchAll(/^\s*uses:\s*([^\s#]+)/gm)].map((match) => match[1]);

  assert.equal(actionUses.length, 2);
  for (const action of actionUses) {
    assert.match(action, /^[\w.-]+\/[\w.-]+(?:\/[\w.-]+)?@[0-9a-f]{40}$/);
  }
  assert.doesNotMatch(workflow, /npm (?:ci|install)|secrets\./);
  assert.equal(packageJson.scripts["smoke:deployment"], "node scripts/smoke/deployed-web.mjs");
});

test("release identity uses Vercel's immutable Git commit before local fallbacks", () => {
  assert.match(layout, /process\.env\.VERCEL_GIT_COMMIT_SHA \?\?/);
  assert.match(layout, /process\.env\.GITHUB_SHA \?\?/);
  assert.match(layout, /process\.env\.IRONCORE_RELEASE_SHA \?\?/);
  assert.match(layout, /"ironcore-release": releaseCommit/);
});

test("smoke target validation rejects unreviewed and private destinations", () => {
  const target = validateTargetUrl(
    "https://gms-beige-ten.vercel.app/",
    "gms-beige-ten.vercel.app",
  );
  assert.equal(target.origin, "https://gms-beige-ten.vercel.app");

  assert.throws(
    () => validateTargetUrl("http://gms-beige-ten.vercel.app/", "gms-beige-ten.vercel.app"),
    /must use HTTPS/,
  );
  assert.throws(
    () => validateTargetUrl("https://127.0.0.1/", "127.0.0.1"),
    /local or private/,
  );
  assert.throws(
    () => validateTargetUrl("https://example.com/", "gms-beige-ten.vercel.app"),
    /not in IRONCORE_SMOKE_ALLOWED_HOSTS/,
  );
  assert.throws(
    () => validateTargetUrl("https://gms-beige-ten.vercel.app/?token=secret", "gms-beige-ten.vercel.app"),
    /cannot contain credentials, a query or a fragment/,
  );
});

test("release marker parsing and expected commit validation are deterministic", () => {
  const sha = "2ddc641898702ac169558acd3cdd416b5284f18a";
  assert.equal(
    extractMetaContent(`<meta content="${sha}" name="ironcore-release">`, "ironcore-release"),
    sha,
  );
  assert.equal(validateExpectedCommit(sha.toUpperCase()), sha);
  assert.equal(validateExpectedCommit(undefined), null);
  assert.throws(() => validateExpectedCommit("2ddc641"), /full Git commit SHA/);
});
