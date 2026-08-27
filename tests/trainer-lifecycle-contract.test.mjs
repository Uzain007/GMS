import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("Gym Admin trainer lifecycle is API-backed and role-fixed", async () => {
  const [routes, request, controller, service, client, staff, app] = await Promise.all([
    read("backend/routes/api.php"),
    read("backend/app/Http/Requests/StoreTrainerRequest.php"),
    read("backend/app/Http/Controllers/Api/V1/StaffProfileController.php"),
    read("backend/app/Services/TrainerLifecycleService.php"),
    read("app/lib/ironcore-api.ts"),
    read("app/staff-management.tsx"),
    read("app/ironcore-app.tsx"),
  ]);

  assert.match(routes, /Route::post\('\/staff', \[StaffProfileController::class, 'store'\]\)/);
  assert.match(routes, /Route::delete\('\/staff\/\{staff\}', \[StaffProfileController::class, 'destroy'\]\)/);
  assert.match(request, /home_branch_id'[\s\S]*tenantExists\('gym_branches'\)/);
  assert.doesNotMatch(request, /'role'/);
  assert.match(service, /UserRole::Trainer->value/);
  assert.match(service, /where\('gym_id', \$this->tenant->id\(\)\)/);
  assert.match(controller, /account_setup_token/);
  assert.match(client, /async createTrainer\(/);
  assert.match(client, /body: form/);
  assert.match(staff, /Create trainer/);
  for (const field of ["Name", "Email", "Phone", "Profile image", "Branch", "Status"]) assert.match(staff, new RegExp(field));
  assert.match(app, /setStaffRefresh/);
});

test("active trainer records feed classes, coaching and workout plans", async () => {
  const [app, classUi, coachingUi, classService, trainingService] = await Promise.all([
    read("app/ironcore-app.tsx"),
    read("app/engagement-management.tsx"),
    read("app/coaching-management.tsx"),
    read("backend/app/Services/ClassBookingService.php"),
    read("backend/app/Services/TrainingService.php"),
  ]);

  assert.match(app, /row\.role === "trainer" && row\.status === "active"/);
  assert.match(classUi, /data\.trainers\.map/);
  assert.match(coachingUi, /data\.trainers\.map/);
  assert.match(classService, /assertTrainerBranchAccess/);
  assert.match(trainingService, /assertTrainerMemberBranchAccess/);
});

test("trainer images and destructive actions stay behind tenant API routes", async () => {
  const [migration, model, routes, service, staff] = await Promise.all([
    read("backend/database/migrations/2026_08_21_000026_add_trainer_lifecycle_fields_to_staff_profiles.php"),
    read("backend/app/Models/StaffProfile.php"),
    read("backend/routes/api.php"),
    read("backend/app/Services/TrainerLifecycleService.php"),
    read("app/staff-management.tsx"),
  ]);

  for (const column of ["display_name", "contact_email", "phone", "profile_image_path"]) assert.match(migration, new RegExp(column));
  assert.match(model, /BelongsToGym/);
  assert.match(routes, /\/staff\/\{staff\}\/profile-image/);
  assert.match(service, /has class or coaching history/);
  assert.match(service, /where\('gym_id', \$this->tenant->id\(\)\)/);
  assert.match(staff, /Delete trainer/);
  assert.match(staff, /Inactive trainers immediately disappear/);
});
