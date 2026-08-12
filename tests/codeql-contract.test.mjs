import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const workflow = await readFile(
  new URL("../.github/workflows/codeql.yml", import.meta.url),
  "utf8",
);

test("CodeQL scans application and workflow source without repository write credentials", () => {
  assert.match(workflow, /name: IronCore application security/);
  assert.match(workflow, /pull_request:\s*\n\s*branches:\s*\n\s*- main/);
  assert.doesNotMatch(workflow, /pull_request_target:/);
  assert.match(workflow, /schedule:\s*\n\s*- cron:/);
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  assert.match(workflow, /security-events: write/);
  assert.match(workflow, /persist-credentials: false/);
  assert.match(workflow, /- actions\s*\n\s*- javascript-typescript/);
  assert.match(workflow, /queries: security-extended/);
});

test("CodeQL actions are pinned to reviewed immutable revisions", () => {
  const actionUses = [...workflow.matchAll(/^\s*uses:\s*([^\s#]+)/gm)].map((match) => match[1]);

  assert.equal(actionUses.length, 3);
  for (const action of actionUses) {
    assert.match(action, /^[\w.-]+\/[\w.-]+(?:\/[\w.-]+)?@[0-9a-f]{40}$/);
  }
});
