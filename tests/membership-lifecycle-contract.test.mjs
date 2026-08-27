import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("member profiles expose one tenant-scoped current membership summary", async () => {
  const [controller, resource, client, dashboard, app] = await Promise.all([
    read("backend/app/Http/Controllers/Api/V1/MemberController.php"),
    read("backend/app/Http/Resources/MemberResource.php"),
    read("app/lib/ironcore-api.ts"),
    read("app/ironcore-dashboard.tsx"),
    read("app/ironcore-app.tsx"),
  ]);

  assert.match(controller, /Membership::query\(\)->with\(\['plan', 'branch'\]\)/);
  assert.match(controller, /where\('member_id', \$model->getKey\(\)\)/);
  assert.match(resource, /'current_membership'/);
  assert.match(client, /async member\(gymId: string, memberId: string\)/);
  assert.match(app, /dashboardMemberProfile/);
  assert.match(dashboard, /Live member profile/);
  assert.match(dashboard, /No current membership/);
  assert.match(dashboard, /Access ready/);
  assert.match(dashboard, /minorMoney\(membership\.priceMinor, membership\.currency\)/);
});

test("plan and membership forms carry real duration and expiry values", async () => {
  const [operations, api] = await Promise.all([
    read("app/tenant-operations.tsx"),
    read("app/lib/ironcore-api.ts"),
  ]);

  assert.match(api, /duration_days: number \| null/);
  assert.match(api, /is_in_date\?: boolean/);
  assert.match(operations, /Fixed duration \(days\)/);
  assert.match(operations, /End date override/);
  assert.match(operations, /<th>Expires<\/th>/);
  assert.match(operations, /No fixed expiry/);
});

test("QR pass creation and display require an active in-date membership", async () => {
  const [attendance, membershipResource, portal] = await Promise.all([
    read("backend/app/Services/AttendanceService.php"),
    read("backend/app/Http/Resources/MembershipResource.php"),
    read("app/member-portal.tsx"),
  ]);

  assert.match(attendance, /activeMembershipForCredential\(\$member\)/);
  assert.match(attendance, /An active, in-date membership is required to create a gym pass/);
  assert.match(membershipResource, /'is_in_date'/);
  assert.match(portal, /membershipAccessReady/);
  assert.match(portal, /Membership required/);
  assert.match(portal, /Access unavailable/);
});
