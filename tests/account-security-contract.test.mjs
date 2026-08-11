import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("authentication versions revoke stale sessions independent of the session driver", async () => {
  const migration = await read("backend/database/migrations/2026_08_11_000020_add_auth_version_to_users_table.php");
  const model = await read("backend/app/Models/User.php");
  const middleware = await read("backend/app/Http/Middleware/EnsureAuthenticationVersion.php");
  const bootstrap = await read("backend/bootstrap/app.php");
  const routes = await read("backend/routes/api.php");

  assert.match(migration, /unsignedInteger\('auth_version'\)->default\(1\)/);
  assert.match(model, /SESSION_AUTH_VERSION_KEY = 'ironcore_auth_version'/);
  assert.match(middleware, /sessionVersion !== \$user->auth_version/);
  assert.match(middleware, /session\(\)->invalidate\(\)/);
  assert.match(bootstrap, /'auth.version' => EnsureAuthenticationVersion::class/);
  assert.match(routes, /\['auth:sanctum', 'auth.version', 'database.identity'\]/);
});

test("public recovery is queued, non-enumerating, throttled and fragment-only", async () => {
  const controller = await read("backend/app/Http/Controllers/Api/V1/AccountSecurityController.php");
  const job = await read("backend/app/Jobs/SendPasswordResetLink.php");
  const provider = await read("backend/app/Providers/AppServiceProvider.php");
  const routes = await read("backend/routes/api.php");

  assert.match(controller, /SendPasswordResetLink::dispatch/);
  assert.match(controller, /If an account matches that email/);
  assert.doesNotMatch(controller.split("public function resetPassword")[0], /User::query|where\('email'/);
  assert.match(job, /implements ShouldQueue/);
  assert.match(job, /Password::sendResetLink/);
  assert.match(provider, /RateLimiter::for\('recovery'/);
  assert.match(provider, /\/#reset_email=/);
  assert.match(routes, /forgot-password.*throttle:recovery/);
  assert.match(routes, /reset-password.*throttle:recovery/);
});

test("password reset and change rotate generations and revoke Sanctum credentials", async () => {
  const controller = await read("backend/app/Http/Controllers/Api/V1/AccountSecurityController.php");
  const resetRequest = await read("backend/app/Http/Requests/ResetPasswordRequest.php");
  const changeRequest = await read("backend/app/Http/Requests/ChangePasswordRequest.php");
  const auth = await read("backend/app/Http/Controllers/Api/V1/AuthController.php");
  const activation = await read("backend/app/Http/Controllers/Api/V1/MemberAccountInvitationController.php");

  assert.match(controller, /'auth_version' => \$lockedUser->auth_version \+ 1/);
  assert.match(controller, /\$lockedUser->tokens\(\)->delete\(\)/);
  assert.match(controller, /\$lockedUser->tokens\(\)->where\('id', '!=', \$currentTokenId\)->delete\(\)/);
  assert.match(controller, /lockForUpdate\(\)->findOrFail/);
  assert.match(controller, /Password::PASSWORD_RESET/);
  assert.match(controller, /password_reset_tokens/);
  assert.match(controller, /\$resetToken === null/);
  for (const request of [resetRequest, changeRequest]) {
    assert.match(request, /Password::min\(12\)->letters\(\)->mixedCase\(\)->numbers\(\)->symbols\(\)/);
  }
  assert.match(auth, /SESSION_AUTH_VERSION_KEY/);
  assert.match(activation, /SESSION_AUTH_VERSION_KEY/);
});

test("web recovery clears fragment secrets and exposes account security to every shell", async () => {
  const app = await read("app/ironcore-app.tsx");
  const api = await read("app/lib/ironcore-api.ts");
  const dialog = await read("app/account-security.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const member = await read("app/member-portal.tsx");

  assert.match(app, /params\.get\("reset_email"\)/);
  assert.match(app, /setPasswordReset\(reset\)/);
  assert.match(app, /window\.history\.replaceState/);
  for (const method of ["requestPasswordReset", "resetPassword", "changePassword"]) assert.match(api, new RegExp(`async ${method}\\(`));
  assert.match(dialog, /autoComplete="current-password"/);
  assert.match(dialog, /autoComplete="new-password"/);
  assert.match(dashboard, /AccountSecurityDialog/);
  assert.match(member, /AccountSecurityDialog/);
  assert.doesNotMatch(`${app}\n${api}\n${dialog}`, /localStorage|sessionStorage|indexedDB/);
});
