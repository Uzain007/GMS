import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("web auth uses Sanctum CSRF and credentialed HttpOnly sessions", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const controller = await read("backend/app/Http/Controllers/Api/V1/AuthController.php");
  const cors = await read("backend/config/cors.php");

  assert.match(client, /\/sanctum\/csrf-cookie/);
  assert.match(client, /credentials:\s*"include"/);
  assert.match(client, /X-XSRF-TOKEN/);
  assert.match(client, /use_bearer_token:\s*false/);
  assert.doesNotMatch(client, /localStorage|sessionStorage/);
  assert.match(controller, /Auth::guard\('web'\)->login\(\$user\)/);
  assert.match(controller, /! \$usesBearerToken && ! \$request->hasSession\(\)/);
  assert.match(controller, /\$request->session\(\)->regenerate\(\)/);
  assert.match(controller, /\$request->session\(\)->invalidate\(\)/);
  assert.match(cors, /X-XSRF-TOKEN/);
});

test("tenant member calls bind the same gym to route and verified header", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");

  assert.match(client, /headers\.set\("X-Gym-ID", gymId\)/);
  assert.match(client, /gyms\/\$\{encodeURIComponent\(gymId\)\}\/members/);
  assert.match(app, /identity\.platform_role !== "super_admin" && access\.length === 1/);
  assert.match(app, /if \(!selectedGym\) return <TenantPicker/);
});

test("authenticated mode exposes only integrated tenant modules", async () => {
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const app = await read("app/ironcore-app.tsx");
  const client = await read("app/lib/ironcore-api.ts");
  const operations = await read("app/tenant-operations.tsx");

  assert.match(dashboard, /tenantViews\.includes\(item\.id\)/);
  assert.match(dashboard, /Live tenant data/);
  for (const resource of ["branches", "membership-plans", "memberships"]) assert.match(client, new RegExp(`tenantCollection<.*>\\(gymId, "${resource}"\\)`));
  assert.match(operations, /Number\(match\[1\]\) \* 100/);
  assert.match(app, /NEXT_PUBLIC_IRONCORE_DEMO_MODE/);
  assert.match(app, /liveSaasBilling=\{demoSaasBilling\}/);
});

test("preview navigation renders operations and attendance keeps readable columns", async () => {
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const operations = await read("app/tenant-operations.tsx");
  const engagement = await read("app/engagement-management.tsx");
  const styles = await read("app/globals.css");

  assert.match(app, /const demoOperations: OperationData/);
  assert.match(app, /sharedPreview = \{ liveOperations: demoOperations/);
  for (const record of ["Manchester Central", "Unlimited", "demo-membership-1"]) {
    assert.match(app, new RegExp(record));
  }
  for (const view of ["branches", "plans", "memberships"]) {
    assert.match(dashboard, new RegExp(`view === "${view}" && liveOperations`));
  }
  assert.match(operations, /Representative preview/);
  assert.match(operations, /Sample records stay isolated from authenticated gym data/);
  assert.match(operations, /title === "Branches" \? "branch"/);
  assert.match(operations, /function billingLabel/);
  assert.match(operations, /one_time: "One time"/);
  assert.match(engagement, /className="data-table engagement-data-table"/);
  assert.match(styles, /\.engagement-table \.table-scroll\{overflow-x:auto\}/);
  assert.match(styles, /\.engagement-data-table\{min-width:720px\}/);
});

test("shared section labels reserve vertical ink space inside scrollable panels", async () => {
  const styles = await read("app/globals.css");

  assert.match(styles, /\.eyebrow \{[^}]*padding-block:1px[^}]*overflow:visible[^}]*line-height:1\.4/);
  assert.match(styles, /\.table-scroll>\.panel-heading \{ padding:18px 18px 14px; \}/);
});

test("gym-client portal has a distinct tenant dashboard without expanding browser authority", async () => {
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const gymOverview = await read("app/gym-client-overview.tsx");
  const styles = await read("app/globals.css");

  assert.match(dashboard, /"gym-dashboard"/);
  assert.match(dashboard, /item\.id !== "gym-dashboard"/);
  assert.match(dashboard, /resolvedPortalMode !== "gym" \|\| !activeGym/);
  assert.match(dashboard, /already loaded for[\s\S]*selected gym/);
  assert.match(dashboard, /PostgreSQL RLS remain authoritative/);
  assert.match(app, /\["gym-dashboard", "members", "branches"/);
  assert.match(app, /portalMode="gym"/);
  assert.match(app, /Preview gym portal/);
  assert.match(app, /Back to Super Admin/);
  assert.match(gymOverview, /Selected-gym data only/);
  assert.match(gymOverview, /availableViews\.includes\(view\)/);
  assert.doesNotMatch(gymOverview, /fetch\(|localStorage|sessionStorage|gym_id/);
  assert.match(styles, /Milestone 6B keeps the gym client's landing view separate/);
  assert.match(styles, /@media\(max-width:620px\)\{\.gym-welcome/);
});

test("staff access uses tenant endpoints, hierarchy guards and one-time fragment links", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const staffUi = await read("app/staff-management.tsx");
  const guard = await read("backend/app/Services/StaffInvitationService.php");
  const controller = await read("backend/app/Http/Controllers/Api/V1/StaffProfileController.php");
  const resource = await read("backend/app/Http/Resources/StaffInvitationResource.php");

  assert.match(client, /tenantCollection<StaffRecord>\(gymId, "staff"\)/);
  assert.match(client, /tenantCollection<StaffInvitationRecord>\(gymId, "staff-invitations"\)/);
  assert.match(client, /acceptStaffInvitation/);
  assert.match(app, /#invite_gym=/);
  assert.match(app, /window\.history\.replaceState/);
  assert.match(staffUi, /data\.actorRole === "gym_manager" \? operationalRoles : allRoles/);
  assert.match(staffUi, /Audit reason/);
  assert.match(guard, /ensureProfileCanBeManaged/);
  assert.match(controller, /ensureProfileCanBeManaged/);
  assert.doesNotMatch(resource, /'token_hash'\s*=>/);
});

test("membership plan form matches Laravel billing interval enum", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const operations = await read("app/tenant-operations.tsx");
  const backendEnum = await read("backend/app/Enums/BillingInterval.php");

  for (const interval of ["one_time", "weekly", "monthly", "quarterly", "yearly"]) {
    assert.match(client, new RegExp(`"${interval}"`));
    assert.match(operations, new RegExp(`value="${interval}"`));
    assert.match(backendEnum, new RegExp(`'${interval}'`));
  }
  assert.doesNotMatch(operations, /value="month"|value="week"|value="year"/);
});

test("tenant finance UI integrates invoices, idempotent payments, refunds and Stripe onboarding", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const finance = await read("app/financial-management.tsx");

  for (const resource of ["invoices", "payments"]) {
    assert.match(client, new RegExp(`tenantCollection<.*>\\(gymId, "${resource}"\\)`));
  }
  assert.match(client, /payments\/summary/);
  assert.match(client, /payments\/\$\{encodeURIComponent\(paymentId\)\}\/refunds/);
  assert.match(client, /payment-gateways\/stripe\/onboard/);
  assert.match(app, /Promise\.all\(\[\s*api\.payments/);
  assert.match(app, /setFinance\(\{ payments: \[\], invoices: \[\]/);
  assert.match(dashboard, /FinancialManagement data=\{liveFinance\}/);
  assert.match(finance, /paymentRequestKey\(\)/);
  assert.match(finance, /crypto\?\.getRandomValues/);
  assert.match(finance, /Never enter a card number in IronCore/);
  assert.match(finance, /Stripe-hosted payment/);
  assert.doesNotMatch(finance, /name="card_number"|name="cvc"|name="card_expiry"/);
});

test("SaaS billing UI uses tenant-safe subscription, invoice, Checkout and portal APIs", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const billing = await read("app/saas-billing-management.tsx");

  for (const segment of ["saas-plans", "saas-subscription", "saas-billing-invoices"]) {
    assert.match(client, new RegExp(segment));
  }
  assert.match(client, /saas-subscription\/checkout/);
  assert.match(client, /saas-subscription\/portal/);
  assert.match(app, /Promise\.all\(\[\s*api\.saasPlans/);
  assert.match(app, /setSaas\(\{ plans: \[\], subscription: null, invoices: \[\]/);
  assert.match(dashboard, /SaasBillingManagement data=\{liveSaasBilling\}/);
  assert.match(billing, /Money flows are isolated/);
  assert.match(billing, /requestKey\(\)/);
  assert.match(billing, /crypto\.getRandomValues/);
  assert.match(billing, /Stripe-hosted page/);
  assert.doesNotMatch(billing, /name="card_number"|name="cvc"|name="card_expiry"/);
});

test("engagement UI integrates tenant-safe QR attendance, classes and FIFO booking actions", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const engagement = await read("app/engagement-management.tsx");

  for (const segment of ["attendance/check-ins", "class-sessions", "class-bookings", "access-credential"]) {
    assert.match(client, new RegExp(segment));
  }
  assert.match(app, /Promise\.all\(\[attendanceRequest, api\.classSessions/);
  assert.match(app, /setEngagement\(\{ attendance: \[\], sessions: \[\], bookings: \[\]/);
  assert.match(dashboard, /EngagementManagement data=\{liveEngagement\}/);
  assert.match(engagement, /Scan QR or enter Member Code/);
  assert.match(engagement, /QRCode\.toCanvas/);
  assert.match(engagement, /FIFO waitlist/);
  assert.match(engagement, /data\.actorRole === "member" \? "classes"/);
  assert.doesNotMatch(engagement, /localStorage|sessionStorage/);
});

test("coaching UI integrates assignment-scoped plans, exact progress and private notifications", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const coaching = await read("app/coaching-management.tsx");

  for (const segment of ["trainer-assignments", "workout-plans", "workout-sessions", "progress-measurements", "notification-preferences", "notification-deliveries"]) {
    assert.match(client, new RegExp(segment));
  }
  assert.match(app, /Promise\.all\(\[\s*api\.trainerAssignments/);
  assert.match(app, /sequence !== coachingSequence\.current/);
  assert.match(app, /setCoaching\(\{ assignments: \[\], plans: \[\], sessions: \[\], measurements: \[\]/);
  assert.match(app, /liveCoaching=\{canUseCoaching \? liveCoaching : undefined\}/);
  assert.match(app, /endTrainerAssignment/);
  assert.match(client, /trainer-assignments\/\$\{encodeURIComponent\(assignmentId\)\}\/end/);
  assert.match(dashboard, /CoachingManagement data=\{liveCoaching\}/);
  assert.match(dashboard, /Coaching & progress/);
  assert.match(coaching, /Math\.round\(loadKg \* 1000\)/);
  assert.match(coaching, /Math\.round\(value \* 1000\)/);
  assert.match(coaching, /Assignment-bound coaching workspace/);
  assert.match(coaching, /End trainer access/);
  assert.match(coaching, /This assignment cannot be reactivated/);
  assert.match(coaching, /Preference changes are member-controlled/);
  assert.match(coaching, /Gym teams see delivery state without seeing encrypted destinations/);
  assert.doesNotMatch(coaching, /localStorage|sessionStorage|destination\}/);
});

test("reporting UI uses one guarded tenant aggregate and keeps currencies separate", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const reports = await read("app/report-management.tsx");
  const styles = await read("app/globals.css");

  assert.match(client, /reports\/overview\?\$\{params\}/);
  assert.match(client, /reportOverview\(gymId/);
  assert.match(app, /sequence !== reportSequence\.current/);
  assert.match(app, /setReports\(\(current\) => \(\{ \.\.\.current, report: null, currency:/);
  assert.match(app, /canReadReports = \["super_admin", "gym_owner", "gym_manager"\]/);
  assert.match(dashboard, /ReportManagement data=\{liveReports\}/);
  assert.match(reports, /Tenant-safe live report/);
  assert.match(reports, /currency only|currency\} only/);
  assert.match(reports, /type="date"/);
  assert.match(reports, /From date must be before or the same as the To date/);
  for (const currency of ["GBP", "USD", "PKR", "AED", "SAR"]) {
    assert.match(reports, new RegExp(`"${currency}"`));
  }
  assert.doesNotMatch(reports, /localStorage|sessionStorage/);
  assert.match(styles, /@media\(max-width:620px\)\{\.report-filters/);
  assert.match(styles, /\.report-metrics,\.report-insight-grid\{grid-template-columns:1fr\}/);
});

test("class scheduling and member timetables use the selected gym timezone", async () => {
  const app = await read("app/ironcore-app.tsx");
  const engagement = await read("app/engagement-management.tsx");
  const member = await read("app/member-portal.tsx");
  const coaching = await read("app/coaching-management.tsx");
  const overview = await read("app/gym-client-overview.tsx");
  const gymTime = await read("app/lib/gym-time.ts");

  assert.match(app, /timezone: selectedGym\.timezone/);
  assert.match(engagement, /zonedLocalDateTimeToIso\(String\(form\.get\("starts_at"\)\), data\.timezone\)/);
  assert.match(engagement, /formatGymDateTime\(session\.starts_at, data\.timezone\)/);
  assert.match(member, /formatGymDateTime\(value, timeZone\)/);
  assert.match(member, /if \(saved\) formElement\.reset\(\)/);
  assert.doesNotMatch(member, /event\.currentTarget\.reset\(\)/);
  assert.match(overview, /formatGymDateTime\(session\.startsAt, data\.timezone\)/);
  assert.match(coaching, /zonedLocalDateTimeToIso\(String\(form\.get\("performed_at"\)\), data\.timezone\)/);
  assert.match(gymTime, /formatToParts/);
  assert.match(gymTime, /daylight-saving offsets settle correctly/);
  assert.match(engagement, /canBookOthers && <button className="primary-button class-book-button"/);
  assert.doesNotMatch(engagement, /new Date\(String\(form\.get\("starts_at"\)\)\)\.toISOString\(\)/);
  assert.doesNotMatch(coaching, /new Date\(String\(form\.get\("performed_at"\)\)\)\.toISOString\(\)/);
});

test("tenant operation and member lifecycle edits use audited backend updates", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");
  const dashboard = await read("app/ironcore-dashboard.tsx");
  const operations = await read("app/tenant-operations.tsx");

  for (const method of ["updateMember", "updateBranch", "updateMembershipPlan", "updateMembership"]) {
    assert.match(client, new RegExp(`${method}\\(`));
    assert.match(app, new RegExp(`api\\.${method}\\(`));
  }
  assert.match(dashboard, /EditMemberModal/);
  assert.match(dashboard, /Audit reason/);
  assert.match(operations, /EditBranchModal/);
  assert.match(operations, /EditPlanModal/);
  assert.match(operations, /EditMembershipModal/);
  assert.match(operations, /Existing memberships keep their accepted price snapshot/);
  assert.match(operations, /The selected gym, actor and reason are written to the audit trail/);
});
