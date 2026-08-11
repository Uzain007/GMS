import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("member account invitations are tenant-owned, digest-only and forced through RLS", async () => {
  const schema = await read("backend/database/migrations/2026_08_11_000018_create_member_account_invitations_table.php");
  const rls = await read("backend/database/migrations/2026_08_11_000019_enable_member_account_invitation_rls.php");
  const model = await read("backend/app/Models/MemberAccountInvitation.php");
  const service = await read("backend/app/Services/MemberAccountInvitationService.php");
  const resource = await read("backend/app/Http/Resources/MemberAccountInvitationResource.php");

  assert.match(schema, /Schema::create\('member_account_invitations'/);
  assert.match(schema, /foreign\(\['gym_id', 'member_id'\]\)/);
  assert.match(schema, /index\(\['gym_id', 'member_id', 'status', 'created_at'\]\)/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /member_account_invitations_one_pending_member/);
  assert.match(model, /protected \$hidden = \['token_hash'\]/);
  assert.match(service, /hash\('sha256', mb_strtolower\(\$gymId\)\.'\|'\.\$plainToken\)/);
  assert.match(service, /lockForUpdate\(\)/);
  assert.doesNotMatch(resource, /token_hash|activation_token/);
});

test("activation routes are bounded, tenant-bound and preserve account roles", async () => {
  const routes = await read("backend/routes/api.php");
  const service = await read("backend/app/Services/MemberAccountInvitationService.php");
  const controller = await read("backend/app/Http/Controllers/Api/V1/MemberAccountInvitationController.php");
  const limiter = await read("backend/app/Providers/AppServiceProvider.php");

  for (const segment of ["members/{member}/account-invitations", "member-account-invitations/preview", "member-account-invitations/accept"]) {
    assert.match(routes, new RegExp(segment.replaceAll("/", "\\/")));
  }
  assert.match(routes, /throttle:member-activation/);
  assert.match(routes, /role:super_admin,gym_owner,gym_manager,receptionist/);
  assert.match(service, /\$this->context->run\(\$gym/);
  assert.match(service, /A staff account cannot be converted into a member account/);
  assert.match(service, /firstOrCreate/);
  assert.match(controller, /Auth::guard\('web'\)->login\(\$user\)/);
  assert.match(controller, /\$request->session\(\)->regenerate\(\)/);
  assert.match(limiter, /RateLimiter::for\('member-activation'/);
});

test("browser activation clears fragment secrets and keeps issuance in volatile UI state", async () => {
  const api = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const activation = await read("app/member-account-activation.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");

  for (const method of ["createMemberAccountInvitation", "previewMemberAccountActivation", "acceptMemberAccountActivation"]) {
    assert.match(api, new RegExp(`${method}\\(gymId: string`));
  }
  assert.match(app, /#activate_gym=/);
  assert.match(app, /window\.history\.replaceState/);
  assert.match(app, /setMemberActivation\(invitation\)/);
  assert.match(dashboard, /Invite portal/);
  assert.match(dashboard, /One-time activation link ready/);
  assert.match(activation, /autoComplete="new-password"/);
  assert.doesNotMatch(`${app}\n${activation}`, /localStorage|sessionStorage|indexedDB/);
});
