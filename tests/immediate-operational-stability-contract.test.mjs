import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("approved immediate stability workflows stay wired through existing architecture", () => {
  const routes = read("backend/routes/api.php");
  const migration = read("backend/database/migrations/2026_10_09_000046_add_operational_stabilization_lifecycles.php");
  const billing = read("app/saas-billing-management.tsx");
  const client = read("app/lib/ironcore-api.ts");
  const memberUi = read("app/ironcore-dashboard.tsx");
  const platform = read("app/platform-portal.tsx");
  const insights = read("app/platform-insights.tsx");
  const audit = read("backend/app/Support/AuditPresentation.php");
  const css = read("app/globals.css");
  assert.match(routes, /saas-plan-change-requests/);
  assert.match(routes, /platform\/saas-payment-reminders/);
  assert.match(routes, /staff-attendance/);
  assert.match(migration, /FORCE ROW LEVEL SECURITY/);
  assert.match(migration, /membership_renewals/);
  assert.match(billing, /Request plan change/);
  assert.match(billing, /Send all due reminders/);
  assert.match(billing, /You are about to send a payment reminder to \$\{eligible\} gym/);
  assert.match(client, /previewSaasPaymentReminders/);
  assert.match(client, /deleteMember/);
  assert.match(memberUi, /Remove member/);
  assert.match(platform, /Global employees/);
  assert.match(insights, /membership_history_count/);
  assert.match(insights, /attendance_history_count/);
  assert.match(billing, /This permanently deletes this SaaS plan|Plan change requests/);
  assert.match(audit, /Membership automatically renewed/);
  assert.match(audit, /Member automatically checked out/);
  assert.match(css, /overflow-wrap: anywhere/);
});

test("historical lifecycles remain non-destructive", () => {
  const members = read("backend/app/Http/Controllers/Api/V1/MemberController.php");
  const staff = read("backend/app/Services/TrainerLifecycleService.php");
  assert.match(members, /MemberStatus::Deleted/);
  assert.doesNotMatch(members, /->delete\(\)/);
  assert.match(staff, /StaffStatus::Deleted/);
  assert.doesNotMatch(staff, /locked->delete\(\)/);
});
