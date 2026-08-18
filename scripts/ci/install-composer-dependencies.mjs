#!/usr/bin/env node

import { existsSync } from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { setTimeout as waitFor } from "node:timers/promises";
import { pathToFileURL } from "node:url";

export const maximumAttempts = 4;
export const retryDelaysSeconds = Object.freeze([15, 30, 60]);
export const githubTokenEnvironmentKey = "IRONCORE_COMPOSER_GITHUB_TOKEN";
export const prefetchArguments = Object.freeze([
  "install",
  "--prefer-dist",
  "--no-interaction",
  "--no-progress",
  "--no-ansi",
  "--no-plugins",
  "--no-scripts",
]);
export const activationArguments = Object.freeze([
  "install",
  "--prefer-dist",
  "--no-interaction",
  "--no-progress",
  "--no-ansi",
]);

const defaultRunner = (command, args, options) =>
  spawnSync(command, args, { ...options, stdio: "inherit" });

const defaultWait = (seconds) => waitFor(seconds * 1_000);

function credentialFreeEnvironment(environment) {
  const childEnvironment = {
    ...environment,
    COMPOSER_MAX_PARALLEL_HTTP: environment.COMPOSER_MAX_PARALLEL_HTTP || "4",
  };

  // Third-party Composer scripts must never inherit the workflow credential.
  delete childEnvironment[githubTokenEnvironmentKey];
  delete childEnvironment.COMPOSER_AUTH;
  delete childEnvironment.GITHUB_TOKEN;
  delete childEnvironment.GH_TOKEN;

  return childEnvironment;
}

function authenticatedPrefetchEnvironment(environment) {
  const githubToken = environment[githubTokenEnvironmentKey];

  if (typeof githubToken !== "string" || githubToken.trim() === "") {
    throw new Error("The Composer prefetch credential is missing.");
  }

  return {
    ...credentialFreeEnvironment(environment),
    // Authentication exists only in the no-plugin/no-script Composer child.
    COMPOSER_AUTH: JSON.stringify({
      "github-oauth": { "github.com": githubToken },
    }),
  };
}

export async function installComposerDependencies({
  directory = process.cwd(),
  environment = process.env,
  runComposer = defaultRunner,
  wait = defaultWait,
  logger = console,
} = {}) {
  if (
    !existsSync(path.join(directory, "composer.json")) ||
    !existsSync(path.join(directory, "composer.lock"))
  ) {
    throw new Error("Composer metadata is missing from the backend working directory.");
  }

  const prefetchEnvironment = authenticatedPrefetchEnvironment(environment);
  const activationEnvironment = credentialFreeEnvironment(environment);

  for (let attempt = 1; attempt <= maximumAttempts; attempt += 1) {
    logger.log(`Composer package prefetch attempt ${attempt}/${maximumAttempts}.`);
    const result = runComposer("composer", [...prefetchArguments], {
      cwd: directory,
      env: prefetchEnvironment,
    });

    if (!result.error && result.status === 0) {
      break;
    }

    if (attempt === maximumAttempts) {
      throw new Error(
        `Composer package prefetch exhausted ${maximumAttempts} bounded attempts.`,
      );
    }

    const delay = retryDelaysSeconds[attempt - 1];
    logger.error(`Composer package prefetch failed; retrying after ${delay} seconds.`);
    await wait(delay);
  }

  logger.log("Activating locked Composer dependencies without workflow credentials.");
  const activationResult = runComposer("composer", [...activationArguments], {
    cwd: directory,
    env: activationEnvironment,
  });

  if (activationResult.error || activationResult.status !== 0) {
    throw new Error("Composer dependency activation failed.");
  }
}

const invokedPath = process.argv[1]
  ? pathToFileURL(path.resolve(process.argv[1])).href
  : null;

if (invokedPath === import.meta.url) {
  installComposerDependencies().catch((error) => {
    console.error(error instanceof Error ? error.message : "Composer install failed.");
    process.exitCode = 1;
  });
}
