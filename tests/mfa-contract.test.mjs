import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("MFA secrets and recovery codes are platform-owned protected credentials", async () => {
  const migration = await read("backend/database/migrations/2026_08_11_000021_add_multi_factor_authentication.php");
  const user = await read("backend/app/Models/User.php");
  const mfa = await read("backend/app/Services/MfaService.php");

  assert.match(migration, /mfa_secret/);
  assert.match(migration, /user_mfa_recovery_codes/);
  assert.doesNotMatch(migration.split("Schema::create\('user_mfa_recovery_codes'")[1].split("if \(DB::connection")[0], /gym_id/);
  assert.match(user, /'mfa_secret' => 'encrypted'/);
  assert.match(user, /mfa_last_used_step/);
  assert.match(mfa, /hash_hmac\('sha256'/);
  assert.match(mfa, /whereNull\('used_at'\).*lockForUpdate/s);
});

test("short-lived MFA login challenges are hashed, locked, bounded and generation-bound", async () => {
  const challenge = await read("backend/app/Services/MfaChallengeService.php");
  const provider = await read("backend/app/Providers/AppServiceProvider.php");

  assert.match(challenge, /TTL_SECONDS = 300/);
  assert.match(challenge, /MAX_ATTEMPTS = 5/);
  assert.match(challenge, /ironcore:auth:mfa:challenge.*hash\('sha256'/s);
  assert.match(challenge, /Cache::lock/);
  assert.match(challenge, /auth_version/);
  assert.match(challenge, /Cache::forget\(\$cacheKey\).*return \[/s);
  assert.match(provider, /mfa-challenge/);
});

test("every full authentication entry path preserves an enabled second factor", async () => {
  const auth = await read("backend/app/Http/Controllers/Api/V1/AuthController.php");
  const recovery = await read("backend/app/Http/Controllers/Api/V1/AccountSecurityController.php");
  const activation = await read("backend/app/Http/Controllers/Api/V1/MemberAccountInvitationController.php");
  const routes = await read("backend/routes/api.php");

  for (const controller of [auth, recovery, activation]) {
    assert.match(controller, /mfaEnabled\(\)/);
    assert.match(controller, /mfaChallenges->create/);
  }
  for (const endpoint of ["mfa/challenge", "mfa/setup", "mfa/confirm", "mfa/recovery-codes"]) {
    assert.match(routes, new RegExp(endpoint.replace("/", "\\/")));
  }
  assert.match(routes, /Route::delete\('\/mfa'/);
});

test("the web keeps MFA challenges, secrets and recovery codes in volatile component state", async () => {
  const app = await read("app/ironcore-app.tsx");
  const security = await read("app/account-security.tsx");
  const api = await read("app/lib/ironcore-api.ts");

  assert.match(app, /useState<MfaChallenge \| null>/);
  assert.match(app, /setMfaChallenge\(null\)/);
  assert.match(security, /QRCode\.toCanvas/);
  assert.match(security, /useState<string\[\]>/);
  assert.match(api, /verifyMfaChallenge/);
  assert.doesNotMatch(`${app}\n${security}\n${api}`, /localStorage|sessionStorage/);
});
