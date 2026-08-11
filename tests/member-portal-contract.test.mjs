import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("member self-service resolves the authenticated link and returns least-privilege resources", async () => {
  const routes = await read("backend/routes/api.php");
  const controller = await read("backend/app/Http/Controllers/Api/V1/MemberSelfServiceController.php");
  const profileResource = await read("backend/app/Http/Resources/MemberSelfResource.php");
  const credentialResource = await read("backend/app/Http/Resources/MemberSelfCredentialResource.php");
  const request = await read("backend/app/Http/Requests/UpdateMemberSelfRequest.php");

  for (const path of ["/member/me", "/member/membership", "/member/invoices", "/member/payments", "/member/attendance", "/member/access-credential"]) {
    assert.match(routes, new RegExp(path.replaceAll("/", "\\/")));
  }
  assert.ok((routes.match(/middleware\('role:member'\)/g) ?? []).length >= 8);
  assert.match(controller, /where\('user_id', \$request->user\(\)->getKey\(\)\)/);
  assert.match(controller, /where\('member_id', \$member->getKey\(\)\)/);
  assert.match(controller, /diffInDays\(\$to\) > 90/);
  assert.doesNotMatch(request, /member_id|gym_id|status|metadata/);
  for (const resource of [profileResource, credentialResource]) {
    assert.doesNotMatch(resource, /'gym_id'\s*=>|'member_id'\s*=>|'user_id'\s*=>|'metadata'\s*=>/);
  }
  assert.doesNotMatch(profileResource, /'id'\s*=>/);
  assert.doesNotMatch(credentialResource, /'id'\s*=>|'credential_hash'\s*=>/);
});

test("member portal is role-routed, mobile installable and keeps one-time QR plaintext in memory", async () => {
  const api = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const portal = await read("app/member-portal.tsx");
  const manifest = await read("app/manifest.ts");

  for (const method of ["memberSelfProfile", "memberSelfMembership", "memberSelfInvoices", "memberSelfPayments", "memberSelfAttendance", "memberSelfCredential", "rotateMemberSelfCredential"]) {
    assert.match(api, new RegExp(`${method}\\(gymId: string`));
  }
  assert.match(app, /selectedGym\.role === "member"/);
  assert.match(app, /return <MemberPortal data=/);
  assert.match(portal, /const \[qrPlaintext, setQrPlaintext\] = useState<string \| null>\(null\)/);
  assert.match(portal, /QRCode\.toCanvas/);
  assert.match(portal, /if \(nextView !== "pass"\) setQrPlaintext\(null\)/);
  assert.doesNotMatch(portal, /localStorage|sessionStorage|indexedDB/);
  assert.doesNotMatch(app, /randomUUID/);
  assert.match(manifest, /display: "standalone"/);
  assert.match(manifest, /start_url: "\/"/);
});
