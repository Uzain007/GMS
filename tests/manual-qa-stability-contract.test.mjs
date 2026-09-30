import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("staff onboarding has public expiring activation, queued delivery evidence and non-destructive offboarding", async () => {
  const routes = await read("backend/routes/api.php");
  const service = await read("backend/app/Services/StaffInvitationService.php");
  const job = await read("backend/app/Jobs/SendAccountInvitation.php");
  const ui = await read("app/staff-management.tsx");
  const activation = await read("app/staff-account-activation.tsx");

  assert.match(routes, /staff-invitations\/preview/);
  assert.match(routes, /staff-invitations\/accept/);
  assert.match(service, /->onQueue\('notifications'\)/);
  assert.match(service, /The invitation email could not be queued/);
  assert.match(job, /implements ShouldBeEncrypted, ShouldQueue/);
  assert.match(job, /delivery_status/);
  assert.match(ui, /Remove access/);
  assert.match(ui, /historical records remain available for audit/);
  assert.match(activation, /Create password/);
  assert.match(activation, /password_confirmation/);
  assert.doesNotMatch(service, /StaffProfile::.*->delete\(/s);
});

test("manual QA action tables remain usable at tablet widths", async () => {
  const css = await read("app/globals.css");
  const members = await read("app/ironcore-dashboard.tsx");
  const staff = await read("app/staff-management.tsx");
  const finance = await read("app/financial-management.tsx");
  const billing = await read("app/saas-billing-management.tsx");
  const platform = await read("app/platform-insights.tsx");

  assert.match(css, /@media\(max-width:980px\)/);
  assert.match(css, /\.action-column-table th:last-child,\.action-column-table td:last-child\{position:sticky;right:0/);
  for (const source of [members, staff, finance, billing, platform]) {
    assert.match(source, /data-table action-column-table/);
  }
});

test("class cards tolerate incomplete API records and keep readable controls", async () => {
  const engagement = await read("app/engagement-management.tsx");
  const css = await read("app/globals.css");

  assert.match(engagement, /safeSessionDateTime/);
  assert.match(engagement, /Capacity unavailable/);
  assert.match(engagement, /Untitled class/);
  assert.match(engagement, /Array\.isArray\(data\.sessions\)/);
  assert.match(engagement, /className="class-card-date"/);
  assert.match(engagement, /className="class-card-booking"/);
  assert.match(engagement, /className="class-card-trainer"/);
  assert.match(css, /\.class-card h3\{font-size:22px/);
  assert.match(css, /\.class-card>p:not\(\.eyebrow\)\{[^}]*font-size:15px/);
  assert.match(css, /\.class-card dl div\{[^}]*font-size:14px/);
  assert.match(css, /\.class-card-actions\{display:grid/);
  assert.match(css, /@media\(max-width:760px\)[\s\S]*\.class-card-actions\{grid-template-columns:1fr\}/);
});

test("class scheduling modal opens from the class workspace", async () => {
  const engagement = await read("app/engagement-management.tsx");

  assert.match(engagement, /onClick=\{\(\) => setClassModal\(true\)\}/);
  assert.match(engagement, /classModal && <Modal title="Schedule a class"/);
  assert.match(engagement, /className="schedule-class-modal"/);
});

test("class scheduling submits valid normalized session data", async () => {
  const engagement = await read("app/engagement-management.tsx");

  assert.match(engagement, /className="schedule-class-form" onSubmit=\{createClass\}/);
  assert.match(engagement, /await data\.onCreateSession\(\{/);
  assert.match(engagement, /zonedLocalDateTimeToIso\(String\(form\.get\("starts_at"\)\), data\.timezone\)/);
  assert.match(engagement, /zonedLocalDateTimeToIso\(String\(form\.get\("ends_at"\)\), data\.timezone\)/);
});

test("class scheduling keeps required browser validation", async () => {
  const engagement = await read("app/engagement-management.tsx");

  for (const field of ["title", "branch_id", "starts_at", "ends_at", "capacity"]) {
    assert.match(engagement, new RegExp(`name="${field}"[^>]*required`));
  }
  assert.match(engagement, /<option value="" disabled>Select a branch<\/option>/);
});

test("class scheduling modal contains wide controls and stacks on mobile", async () => {
  const css = await read("app/globals.css");

  assert.match(css, /\.schedule-class-modal-layer\{[^}]*overflow-x:hidden/);
  assert.match(css, /\.schedule-class-modal\{[^}]*width:min\(100%,720px\)[^}]*overflow-x:hidden/);
  assert.match(css, /\.schedule-class-modal \*\{[^}]*min-width:0/);
  assert.match(css, /\.schedule-class-grid\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
  assert.match(css, /input\[type="datetime-local"\]\{[^}]*min-width:0[^}]*inline-size:100%/);
  assert.match(css, /@media\(max-width:820px\)\{\.schedule-class-grid\{grid-template-columns:minmax\(0,1fr\)\}/);
  assert.match(css, /\.schedule-class-modal \.schedule-class-actions\{position:sticky;bottom:0[^}]*flex-wrap:wrap/);
  assert.match(css, /@media\(max-width:620px\)[^\n]*\.schedule-class-actions\{[^}]*flex-direction:column-reverse/);
});

test("class management modal contains wide controls and stacks on mobile", async () => {
  const engagement = await read("app/engagement-management.tsx");
  const css = await read("app/globals.css");

  assert.match(engagement, /className="manage-class-modal"/);
  assert.match(engagement, /className="manage-class-form" onSubmit=\{updateClass\}/);
  assert.match(engagement, /className="modal-actions manage-class-actions"/);
  assert.match(css, /\.manage-class-modal-layer\{[^}]*overflow-x:hidden/);
  assert.match(css, /\.manage-class-modal\{[^}]*width:min\(100%,720px\)[^}]*overflow-x:hidden/);
  assert.match(css, /\.manage-class-modal \*\{[^}]*min-width:0/);
  assert.match(css, /\.manage-class-grid\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
  assert.match(css, /\.manage-class-grid input\[type="datetime-local"\]\{[^}]*min-width:0[^}]*inline-size:100%/);
  assert.match(css, /@media\(max-width:820px\)\{\.manage-class-grid\{grid-template-columns:minmax\(0,1fr\)\}/);
  assert.match(css, /\.manage-class-modal \.manage-class-actions\{position:sticky;bottom:0[^}]*flex-wrap:wrap/);
});

test("class roster presents readable member and booking status rows", async () => {
  const engagement = await read("app/engagement-management.tsx");
  const css = await read("app/globals.css");

  assert.match(engagement, /className="class-roster-modal"/);
  assert.match(engagement, /className="class-roster-row"/);
  assert.match(engagement, /booking\.status \|\| "booked"/);
  assert.match(engagement, /No bookings yet/);
  assert.match(css, /\.class-roster-row\{[^}]*grid-template-columns:minmax\(0,1fr\) auto/);
  assert.match(css, /\.class-roster-row strong\{[^}]*font-size:15px/);
  assert.match(css, /\.class-roster-row small\{[^}]*font-size:12px/);
});
