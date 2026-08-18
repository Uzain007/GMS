import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { validateProductionWebEnvironment } from "../scripts/preflight/production-web.mjs";

const phpPreflight = await readFile(
  "backend/app/Support/ProductionConfigurationPreflight.php",
  "utf8",
);
const phpCommand = await readFile(
  "backend/app/Console/Commands/ProductionPreflightCommand.php",
  "utf8",
);
const phpTests = await readFile(
  "backend/tests/Feature/ProductionConfigurationPreflightTest.php",
  "utf8",
);
const bootstrap = await readFile("backend/bootstrap/app.php", "utf8");
const trustedProxyConfig = await readFile(
  "backend/config/trustedproxy.php",
  "utf8",
);
const packageJson = JSON.parse(await readFile("package.json", "utf8"));

const validWebEnvironment = {
  NODE_ENV: "production",
  NEXT_PUBLIC_IRONCORE_DEMO_MODE: "false",
  NEXT_PUBLIC_IRONCORE_API_URL: "https://api.ironcore.co.uk",
  VERCEL_GIT_COMMIT_SHA: "a".repeat(40),
};

test("production web preflight accepts an explicit live API and immutable release", () => {
  assert.deepEqual(validateProductionWebEnvironment(validWebEnvironment), []);
  assert.deepEqual(
    validateProductionWebEnvironment({
      ...validWebEnvironment,
      VERCEL_GIT_COMMIT_SHA: undefined,
      IRONCORE_RELEASE_SHA: "b".repeat(40),
    }),
    [],
  );
});

test("production web preflight fails closed on demo, unsafe origins and missing provenance", () => {
  const failures = validateProductionWebEnvironment({
    NODE_ENV: "development",
    NEXT_PUBLIC_IRONCORE_DEMO_MODE: "true",
    NEXT_PUBLIC_IRONCORE_API_URL:
      "https://embedded-marker@127.0.0.1/api?token=embedded-marker",
  });

  assert.equal(failures.length, 4);
  assert.match(failures.join("\n"), /NODE_ENV/);
  assert.match(failures.join("\n"), /NEXT_PUBLIC_IRONCORE_DEMO_MODE/);
  assert.match(failures.join("\n"), /NEXT_PUBLIC_IRONCORE_API_URL/);
  assert.match(failures.join("\n"), /full release SHA/);
  assert.doesNotMatch(failures.join("\n"), /embedded-marker/);

  assert.match(
    validateProductionWebEnvironment({
      ...validWebEnvironment,
      NEXT_PUBLIC_IRONCORE_API_URL: "https://preview.example.com",
    }).join("\n"),
    /NEXT_PUBLIC_IRONCORE_API_URL/,
  );
});

test("Laravel production preflight covers launch-critical resolved config without printing values", () => {
  for (const requirement of [
    "APP_ENV",
    "APP_DEBUG",
    "APP_KEY",
    "TRUSTED_PROXIES",
    "CORS_ALLOWED_ORIGINS",
    "SANCTUM_STATEFUL_DOMAINS",
    "SESSION_DOMAIN",
    "ironcore_app",
    "DB_SSLMODE",
    "REDIS_URL",
    "FILESYSTEM_DISK",
    "STRIPE_WEBHOOK_SECRET",
    "STRIPE_BILLING_WEBHOOK_SECRET",
    "STRIPE_CA_BUNDLE",
    "MAIL_MAILER",
    "LOG_CHANNEL",
  ]) {
    assert.match(phpPreflight, new RegExp(requirement));
  }

  assert.match(phpCommand, /ironcore:production-preflight/);
  assert.match(phpCommand, /return self::FAILURE/);
  assert.doesNotMatch(phpCommand, /config\(|env\(/);
  assert.match(phpTests, /assertStringNotContainsString/);
  assert.match(phpTests, /test_cookie_and_browser_origin_mismatch_fails_closed/);
  assert.equal(
    packageJson.scripts["preflight:production-web"],
    "node scripts/preflight/production-web.mjs",
  );
});

test("Laravel bootstrap defers trusted proxy resolution until request middleware", () => {
  assert.doesNotMatch(bootstrap, /\bconfig\s*\(/);
  assert.doesNotMatch(bootstrap, /trustProxies\s*\(/);
  assert.match(trustedProxyConfig, /TRUSTED_PROXIES/);
  assert.match(
    trustedProxyConfig,
    /\$proxies === \['\*'\] \? '\*' : \$proxies/,
  );
  assert.match(phpPreflight, /config\('trustedproxy\.proxies'\)/);
  assert.match(phpTests, /test_explicit_provider_wildcard_is_accepted/);
  assert.match(phpTests, /test_provider_wildcard_cannot_be_mixed/);
});
