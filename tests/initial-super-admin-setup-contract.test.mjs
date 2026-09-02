import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const app = fs.readFileSync(new URL("../app/ironcore-app.tsx", import.meta.url), "utf8");
const api = fs.readFileSync(new URL("../app/lib/ironcore-api.ts", import.meta.url), "utf8");
const routes = fs.readFileSync(new URL("../backend/routes/api.php", import.meta.url), "utf8");
const provider = fs.readFileSync(new URL("../backend/app/Providers/AppServiceProvider.php", import.meta.url), "utf8");
const controller = fs.readFileSync(new URL("../backend/app/Http/Controllers/Api/V1/InitialSuperAdminController.php", import.meta.url), "utf8");
const seeder = fs.readFileSync(new URL("../backend/database/seeders/DatabaseSeeder.php", import.meta.url), "utf8");

test("fresh installations enter the real one-time Super Admin setup flow", () => {
  assert.match(api, /initialSetupStatus\(\)/);
  assert.match(api, /createInitialSuperAdmin\(input/);
  assert.match(app, /setup\.setup_required/);
  assert.match(app, /phase === "setup"/);
  assert.match(app, /Create the first Super Admin/);
  assert.match(app, /password_confirmation/);
  assert.doesNotMatch(app, /admin@ironcore|ChangeMe123|LocalDemo2026/);
  assert.match(app, /className="auth-card" onSubmit=\{submit\} autoComplete="off"/);
});

test("the setup authority stays server-side and closes after the first Super Admin", () => {
  assert.match(routes, /\/setup\/super-admin.*status/);
  assert.match(routes, /\/setup\/super-admin.*store/);
  assert.match(routes, /throttle:initial-setup-status/);
  assert.match(provider, /RateLimiter::for\('initial-setup'/);
  assert.match(controller, /hash_equals\(\$expectedHash, hash\('sha256'/);
  assert.match(controller, /Cache::lock\('ironcore:platform:initial-super-admin'/);
  assert.match(controller, /superAdminExists\(\)/);
  assert.match(controller, /where\('platform_role', UserRole::SuperAdmin->value\)/);
  assert.match(controller, /UserRole::SuperAdmin/);
  assert.doesNotMatch(seeder, /ChangeMe|admin@ironcore/);
});
