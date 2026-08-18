import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import {
  activationArguments,
  githubTokenEnvironmentKey,
  installComposerDependencies,
  maximumAttempts,
  prefetchArguments,
  retryDelaysSeconds,
} from "../scripts/ci/install-composer-dependencies.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const workflow = await readFile(path.join(root, ".github/workflows/quality.yml"), "utf8");
const installerPath = path.join(root, "scripts/ci/install-composer-dependencies.mjs");
const installer = await readFile(installerPath, "utf8");

test("backend dependency cache and workflow credential remain narrowly scoped", () => {
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
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);

  const installStep = workflow.match(
    /      - name: Install backend dependencies[\s\S]*?(?=\n      - name:)/,
  )?.[0];
  assert.ok(installStep, "Composer install step must exist");
  assert.match(
    installStep,
    /IRONCORE_COMPOSER_GITHUB_TOKEN: \$\{\{ github\.token \}\}/,
  );
  assert.doesNotMatch(
    workflow.replace(installStep, ""),
    /IRONCORE_COMPOSER_GITHUB_TOKEN|github\.token|COMPOSER_AUTH/,
  );
});

test("Composer prefetch is inert and activation remains credential-free", () => {
  assert.match(workflow, /COMPOSER_MAX_PARALLEL_HTTP: "4"/);
  assert.match(workflow, /node \.\.\/scripts\/ci\/install-composer-dependencies\.mjs/);
  assert.match(workflow, /Set up locked Node runtime for CI helpers/);
  assert.match(workflow, /node-version: 22\.13\.0/);
  assert.equal(maximumAttempts, 4);
  assert.deepEqual(retryDelaysSeconds, [15, 30, 60]);
  assert.equal(githubTokenEnvironmentKey, "IRONCORE_COMPOSER_GITHUB_TOKEN");
  assert.deepEqual(prefetchArguments, [
    "install",
    "--prefer-dist",
    "--no-interaction",
    "--no-progress",
    "--no-ansi",
    "--no-plugins",
    "--no-scripts",
  ]);
  assert.deepEqual(activationArguments, [
    "install",
    "--prefer-dist",
    "--no-interaction",
    "--no-progress",
    "--no-ansi",
  ]);
  assert.doesNotMatch(installer, /composer update|clear-cache|prefer-source/);
});

test("Composer retries authenticated inert prefetches then activates without credentials", async () => {
  const fixture = await mkdtemp(path.join(tmpdir(), "ironcore-composer-retry-"));

  try {
    await Promise.all([
      writeFile(path.join(fixture, "composer.json"), "{}\n"),
      writeFile(path.join(fixture, "composer.lock"), "{}\n"),
    ]);

    const calls = [];
    const delays = [];
    const logs = [];
    const results = [1, 1, 0, 0];
    await installComposerDependencies({
      directory: fixture,
      environment: {
        [githubTokenEnvironmentKey]: "ephemeral-test-token",
        COMPOSER_AUTH: "ambient-composer-auth",
        GITHUB_TOKEN: "ambient-github-token",
        GH_TOKEN: "ambient-gh-token",
        SAFE_MARKER: "retained",
      },
      runComposer: (command, args, options) => {
        calls.push({ command, args, options });
        return { status: results.shift(), error: null };
      },
      wait: async (seconds) => delays.push(seconds),
      logger: {
        log(message) {
          logs.push(message);
        },
        error(message) {
          logs.push(message);
        },
      },
    });

    assert.equal(calls.length, 4);
    assert.deepEqual(delays, [15, 30]);
    for (const call of calls.slice(0, 3)) {
      assert.equal(call.command, "composer");
      assert.deepEqual(call.args, prefetchArguments);
      assert.equal(call.options.cwd, fixture);
      assert.equal(call.options.env.COMPOSER_MAX_PARALLEL_HTTP, "4");
      assert.equal(call.options.env.SAFE_MARKER, "retained");
      assert.equal(call.options.env[githubTokenEnvironmentKey], undefined);
      assert.equal(call.options.env.GITHUB_TOKEN, undefined);
      assert.equal(call.options.env.GH_TOKEN, undefined);
      assert.deepEqual(JSON.parse(call.options.env.COMPOSER_AUTH), {
        "github-oauth": { "github.com": "ephemeral-test-token" },
      });
    }

    const activationCall = calls.at(-1);
    assert.equal(activationCall.command, "composer");
    assert.deepEqual(activationCall.args, activationArguments);
    assert.equal(activationCall.options.cwd, fixture);
    assert.equal(activationCall.options.env.SAFE_MARKER, "retained");
    assert.equal(activationCall.options.env[githubTokenEnvironmentKey], undefined);
    assert.equal(activationCall.options.env.COMPOSER_AUTH, undefined);
    assert.equal(activationCall.options.env.GITHUB_TOKEN, undefined);
    assert.equal(activationCall.options.env.GH_TOKEN, undefined);
    assert.doesNotMatch(logs.join("\n"), /ephemeral-test-token|ambient-/);
    assert.equal(await readFile(path.join(fixture, "composer.lock"), "utf8"), "{}\n");
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
});

test("Composer prefetch and activation fail closed at their separate boundaries", async () => {
  const fixture = await mkdtemp(path.join(tmpdir(), "ironcore-composer-failure-"));

  try {
    await Promise.all([
      writeFile(path.join(fixture, "composer.json"), "{}\n"),
      writeFile(path.join(fixture, "composer.lock"), "{}\n"),
    ]);

    let failureCalls = 0;
    const failureDelays = [];
    await assert.rejects(
      installComposerDependencies({
        directory: fixture,
        environment: { [githubTokenEnvironmentKey]: "ephemeral-test-token" },
        runComposer: () => {
          failureCalls += 1;
          return { status: 1, error: null };
        },
        wait: async (seconds) => failureDelays.push(seconds),
        logger: { log() {}, error() {} },
      }),
      /prefetch exhausted 4 bounded attempts/,
    );
    assert.equal(failureCalls, 4);
    assert.deepEqual(failureDelays, [15, 30, 60]);

    let activationCalls = 0;
    await assert.rejects(
      installComposerDependencies({
        directory: fixture,
        environment: { [githubTokenEnvironmentKey]: "ephemeral-test-token" },
        runComposer: () => {
          activationCalls += 1;
          return { status: activationCalls === 1 ? 0 : 1, error: null };
        },
        wait: async () => {},
        logger: { log() {}, error() {} },
      }),
      /dependency activation failed/,
    );
    assert.equal(activationCalls, 2);

    await assert.rejects(
      installComposerDependencies({
        directory: fixture,
        environment: {},
        logger: { log() {}, error() {} },
      }),
      /prefetch credential is missing/,
    );
    assert.equal(await readFile(path.join(fixture, "composer.lock"), "utf8"), "{}\n");
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
});
