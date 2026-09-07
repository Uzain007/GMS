import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("signed-out users receive only the real session login", async () => {
  const app = await read("app/ironcore-app.tsx");
  const api = await read("app/lib/ironcore-api.ts");

  assert.match(app, /const \[phase, setPhase\].*demoMode \? "anonymous" : "booting"/);
  assert.match(app, /phase === "anonymous" \|\| !user.*<LoginScreen/);
  assert.match(app, /Sign in securely/);
  assert.match(app, /name="email" type="email" autoComplete="off" required/);
  assert.match(app, /name="password" type="password" autoComplete="off" required/);
  assert.doesNotMatch(app, /auth-preview-launch|Explore read-only product previews|onPreview=\{demoMode/);
  assert.doesNotMatch(app, /admin@ironcore\.test|alice\.owner@ironcore\.test|maya\.member@ironcore\.test|LocalDemo|ChangeMe/i);
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
  assert.match(portal, /onCreateGym: \(input: NewGym\) => Promise<CreatedGym>/);
  assert.match(portal, /Gym Owner Account/);
  assert.match(api, /generateGymOwnerTemporaryPassword/);
  assert.match(routes, /owner-account\/temporary-password/);
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

test("demo fixtures cannot be reached through signed-out authentication", async () => {
  const app = await read("app/ironcore-app.tsx");

  assert.doesNotMatch(app, /previewActive|setPreviewActive|sharedPreview/);
  assert.doesNotMatch(app, /onPreview=\{demoMode|Preview gym portal|Preview member portal/);
  assert.match(app, /if \(!api\) throw new Error\("Live account activation needs the Laravel API deployment to be configured first\."\)/);
});
