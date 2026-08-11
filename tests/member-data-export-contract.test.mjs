import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("member export storage is tenant-owned and protected by forced RLS", async () => {
  const [schema, rls, model] = await Promise.all([
    read("backend/database/migrations/2026_08_11_000022_create_member_data_exports_table.php"),
    read("backend/database/migrations/2026_08_11_000023_enable_member_data_export_rls.php"),
    read("backend/app/Models/MemberDataExport.php"),
  ]);

  assert.match(schema, /Schema::create\('member_data_exports'/);
  assert.match(schema, /foreign\(\['gym_id', 'member_id'\]\)/);
  assert.match(schema, /index\(\['gym_id', 'member_id', 'created_at'\]\)/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /WITH CHECK \(gym_id = current_setting\('app\.current_gym_id'/);
  assert.match(model, /use BelongsToGym, HasUuids/);
  assert.match(model, /protected \$hidden = \['storage_disk', 'storage_path', 'failure_reason'\]/);
});

test("export jobs bind tenant context, use private tenant paths and purge bytes", async () => {
  const [generate, purge] = await Promise.all([
    read("backend/app/Jobs/GenerateMemberDataExport.php"),
    read("backend/app/Jobs/PurgeMemberDataExport.php"),
  ]);

  assert.match(generate, /public readonly string \$gymId/);
  assert.match(generate, /\$context->run\(\$gym/);
  assert.match(generate, /gyms\/\{\$this->gymId\}\/exports\/members/);
  assert.match(generate, /\['visibility' => 'private'\]/);
  assert.match(generate, /hash_init\('sha256'\)/);
  assert.match(generate, /->cursor\(\)/);
  assert.match(generate, /addDays\(7\)/);
  assert.match(generate, /PurgeMemberDataExport::dispatch/);
  assert.match(purge, /Storage::disk\(\$export->storage_disk\)->delete/);
  assert.match(purge, /MemberExportStatus::Expired/);
});

test("staff and member-self routes require tenant identity and explicit roles", async () => {
  const [routes, controller, resource] = await Promise.all([
    read("backend/routes/api.php"),
    read("backend/app/Http/Controllers/Api/V1/MemberDataExportController.php"),
    read("backend/app/Http/Resources/MemberDataExportResource.php"),
  ]);

  assert.match(routes, /members\/\{member\}\/data-exports/);
  assert.match(routes, /role:super_admin,gym_owner,gym_manager/);
  assert.match(routes, /member\/data-exports/);
  assert.match(routes, /selfStore.*role:member/);
  assert.match(controller, /where\('user_id', \$request->user\(\)->getKey\(\)\)/);
  assert.match(controller, /Cache-Control' => 'private, no-store'/);
  assert.doesNotMatch(resource, /storage_path/);
  assert.doesNotMatch(resource, /failure_reason/);
});
