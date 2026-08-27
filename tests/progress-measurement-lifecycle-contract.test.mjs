import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("measurement corrections and voids preserve tenant-owned history", async () => {
  const [migration, model, controller, service, routes] = await Promise.all([
    read("backend/database/migrations/2026_08_24_000027_add_progress_measurement_revision_lifecycle.php"),
    read("backend/app/Models/MemberProgressMeasurement.php"),
    read("backend/app/Http/Controllers/Api/V1/ProgressMeasurementController.php"),
    read("backend/app/Services/ProgressService.php"),
    read("backend/routes/api.php"),
  ]);

  assert.match(migration, /foreign\(\['gym_id', 'replaces_measurement_id'\]/);
  assert.match(migration, /\['gym_id', 'member_id', 'status', 'measured_at', 'id'\]/);
  assert.match(migration, /progress_measurements_one_replacement_unique/);
  assert.match(model, /use BelongsToGym, HasUuids/);
  assert.match(controller, /\$request->boolean\('include_history'\)/);
  assert.match(controller, /ProgressMeasurementStatus::Active/);
  assert.match(service, /DB::transaction/);
  assert.match(service, /lockForUpdate\(\)->findOrFail/);
  assert.match(service, /ProgressMeasurementStatus::Corrected/);
  assert.match(service, /ProgressMeasurementStatus::Voided/);
  assert.match(service, /progress_measurement\.corrected/);
  assert.match(service, /progress_measurement\.voided/);
  assert.doesNotMatch(service, /->delete\(/);
  assert.match(routes, /Route::patch\('\/progress-measurements\/\{measurement\}'/);
  assert.match(routes, /Route::delete\('\/progress-measurements\/\{measurement\}'/);
  assert.ok((routes.match(/role:super_admin,gym_owner,gym_manager/g) ?? []).length >= 2);
});

test("measurement management UI uses the Laravel API and keeps form labels separated", async () => {
  const [client, app, coaching, styles] = await Promise.all([
    read("app/lib/ironcore-api.ts"),
    read("app/ironcore-app.tsx"),
    read("app/coaching-management.tsx"),
    read("app/globals.css"),
  ]);

  assert.match(client, /method: "DELETE", body: JSON\.stringify\(\{ reason \}\)/);
  assert.match(client, /updateProgress\(gymId: string, measurementId: string/);
  assert.match(app, /api\.progressMeasurements\(selectedGym\.id, \["super_admin", "gym_owner", "gym_manager"\]/);
  assert.match(app, /onUpdateProgress: updateProgress/);
  assert.match(app, /onDeleteProgress: deleteProgress/);
  assert.match(coaching, /Create a corrected revision/);
  assert.match(coaching, /Reason for edit/);
  assert.match(coaching, /Reason for deletion/);
  assert.match(coaching, /The original measurement remains unchanged in history/);
  assert.match(coaching, /row\.status === "active"/);
  assert.match(styles, /\.progress-form label\{display:grid;gap:6px/);
  assert.match(styles, /@media\(max-width:480px\).*\.progress-form \.field-pair/s);
  assert.doesNotMatch(coaching, /localStorage|sessionStorage/);
});
