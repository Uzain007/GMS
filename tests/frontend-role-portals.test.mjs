import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("signed-out users receive real session login before any representative preview", async () => {
  const app = await read("app/ironcore-app.tsx");
  const api = await read("app/lib/ironcore-api.ts");

  assert.match(app, /const \[phase, setPhase\].*demoMode \? "anonymous" : "booting"/);
  assert.match(app, /phase === "anonymous" \|\| !user.*<LoginScreen/);
  assert.match(app, /Sign in securely/);
  for (const role of ["Super Admin", "Gym Admin", "Member"]) assert.match(app, new RegExp(`>${role}<`));
  assert.match(api, /async login\(email: string, password: string\)/);
  assert.match(api, /await this\.csrf\(\)/);
  assert.match(api, /credentials:\s*"include"/);
  assert.doesNotMatch(app, /localStorage|sessionStorage/);
});

test("Super Admin portal uses platform APIs and explicit tenant selection", async () => {
  const app = await read("app/ironcore-app.tsx");
  const portal = await read("app/platform-portal.tsx");
  const api = await read("app/lib/ironcore-api.ts");
  const routes = await read("backend/routes/api.php");

  assert.match(app, /user\.platform_role === "super_admin" && !selectedGym.*<PlatformPortal/);
  assert.match(api, /async createGym\(input: NewGym\)/);
  assert.match(api, /async platformSaasPlans\(\)/);
  assert.match(api, /async createSaasPlan\(input: NewSaasPlan\)/);
  assert.match(portal, /onCreateGym: \(input: NewGym\) => Promise<void>/);
  assert.match(portal, /onCreatePlan: \(input: NewSaasPlan\) => Promise<void>/);
  assert.match(portal, /onOpenGym: \(gym: GymSummary\) => void/);
  assert.match(routes, /Route::post\('\/gyms'/);
  assert.match(routes, /Route::post\('\/platform\/saas-plans'/);
});

test("Gym Admin and Member portals keep visible writes connected to tenant APIs", async () => {
  const app = await read("app/ironcore-app.tsx");
  const api = await read("app/lib/ironcore-api.ts");
  const member = await read("app/member-portal.tsx");

  for (const gymAction of [
    "createMember", "createBranch", "createMembershipPlan", "createMembership",
    "createInvoice", "createPayment", "createStaffInvitation", "createClassSession",
    "createTrainerAssignment", "createWorkoutPlan", "recordProgress",
  ]) assert.match(api, new RegExp(`${gymAction}\\(`));

  assert.match(app, /selectedGym\.role === "member"/);
  for (const memberAction of [
    "updateMemberSelfProfile", "rotateMemberSelfCredential", "bookClass",
    "cancelClassBooking", "logWorkoutSession", "updateNotificationPreference",
  ]) assert.match(api, new RegExp(`${memberAction}\\(`));
  assert.match(member, /actions\.onUpdateProfile/);
  assert.match(member, /actions\.onRotateCredential/);
  assert.match(member, /actions\.onBookClass/);
  assert.match(member, /actions\.onRecordProgress/);
});

test("representative portals are explicit and read-only", async () => {
  const app = await read("app/ironcore-app.tsx");
  const staff = await read("app/staff-management.tsx");
  const finance = await read("app/financial-management.tsx");
  const engagement = await read("app/engagement-management.tsx");
  const coaching = await read("app/coaching-management.tsx");
  const member = await read("app/member-portal.tsx");

  for (const fixture of ["demoStaff", "demoFinance", "demoSaasBilling", "demoEngagement", "demoCoaching"]) {
    assert.match(app, new RegExp(`const ${fixture}:[\\s\\S]*?readOnly: true,`));
  }
  assert.match(app, /<MemberPortal data=\{demoMemberPortal\} actions=\{\{\s*readOnly: true/);
  for (const surface of [staff, finance, engagement, coaching, member]) assert.match(surface, /readOnly\?: boolean/);
  assert.match(app, /canManageSetup: false/);
  assert.match(app, /canManageMemberships: false/);
  assert.doesNotMatch(app, /liveMembers=\{\{[^}]*onInvitePortal/);
  assert.match(await read("app/ironcore-dashboard.tsx"), /m\.email && live\.onInvitePortal[\s\S]*Preview only/);
});
