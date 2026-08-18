import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import {
  installArguments,
  installComposerDependencies,
  maximumAttempts,
  retryDelaysSeconds,
} from "../scripts/ci/install-composer-dependencies.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const workflow = await readFile(path.join(root, ".github/workflows/quality.yml"), "utf8");
const installerPath = path.join(root, "scripts/ci/install-composer-dependencies.mjs");
const installer = await readFile(installerPath, "utf8");

test("backend dependency cache is immutable, lockfile-keyed and credential-free", () => {
  assert.match(
    workflow,
    /uses: actions\/cache@cdf6c1fa76f9f475f3d7449005a359c84ca0f306 # v5\.0\.3/,
  );
  assert.match(workflow, /composer config cache-files-dir/);
  assert.match(
    workflow,
    /name: Locate Composer download cache[\s\S]{0,160}working-directory: backend/,
  );
  assert.match(workflow, /path: \$\{\{ steps\.composer-cache\.outputs\.path \}\}/);
  assert.match(workflow, /hashFiles\('backend\/composer\.lock'\)/);
  assert.doesNotMatch(workflow, /restore-keys:/);
  assert.doesNotMatch(workflow, /path: (?:backend\/)?vendor/);
  assert.doesNotMatch(workflow, /COMPOSER_AUTH|github\.token|secrets\.[A-Za-z0-9_]+/);
});

test("Composer installation is bounded and never changes the lockfile", () => {
  assert.match(workflow, /COMPOSER_MAX_PARALLEL_HTTP: "4"/);
  assert.match(workflow, /node \.\.\/scripts\/ci\/install-composer-dependencies\.mjs/);
  assert.match(workflow, /Set up locked Node runtime for CI helpers/);
  assert.match(workflow, /node-version: 22\.13\.0/);
  assert.equal(maximumAttempts, 4);
  assert.deepEqual(retryDelaysSeconds, [15, 30, 60]);
  assert.deepEqual(installArguments, [
    "install",
    "--prefer-dist",
    "--no-interaction",
    "--no-progress",
    "--no-ansi",
  ]);
  assert.doesNotMatch(installer, /composer update|clear-cache|prefer-source/);
});

test("Composer retry runner succeeds after transient failures and fails after its ceiling", async () => {
  const fixture = await mkdtemp(path.join(tmpdir(), "ironcore-composer-retry-"));

  try {
    await Promise.all([
      writeFile(path.join(fixture, "composer.json"), "{}\n"),
      writeFile(path.join(fixture, "composer.lock"), "{}\n"),
    ]);

    const calls = [];
    const delays = [];
    const results = [1, 1, 0];
    await installComposerDependencies({
      directory: fixture,
      environment: {},
      runComposer: (command, args, options) => {
        calls.push({ command, args, options });
        return { status: results.shift(), error: null };
      },
      wait: async (seconds) => delays.push(seconds),
      logger: { log() {}, error() {} },
    });

    assert.equal(calls.length, 3);
    assert.deepEqual(delays, [15, 30]);
    for (const call of calls) {
      assert.equal(call.command, "composer");
      assert.deepEqual(call.args, installArguments);
      assert.equal(call.options.cwd, fixture);
      assert.equal(call.options.env.COMPOSER_MAX_PARALLEL_HTTP, "4");
    }

    let failureCalls = 0;
    const failureDelays = [];
    await assert.rejects(
      installComposerDependencies({
        directory: fixture,
        environment: {},
        runComposer: () => {
          failureCalls += 1;
          return { status: 1, error: null };
        },
        wait: async (seconds) => failureDelays.push(seconds),
        logger: { log() {}, error() {} },
      }),
      /exhausted 4 bounded attempts/,
    );
    assert.equal(failureCalls, 4);
    assert.deepEqual(failureDelays, [15, 30, 60]);
    assert.equal(await readFile(path.join(fixture, "composer.lock"), "utf8"), "{}\n");
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
});
