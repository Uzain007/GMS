import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("branch workspace exposes real tenant-backed management actions", async () => {
  const ui = await read("app/tenant-operations.tsx");
  const app = await read("app/ironcore-app.tsx");
  const api = await read("app/lib/ironcore-api.ts");

  for (const label of ["View", "Edit", "Manage team", "Manage members", "Schedule class", "Deactivate", "Delete unused"]) {
    assert.match(ui, new RegExp(label));
  }
  for (const callback of ["onAssignBranchMember", "onAssignBranchStaff", "onCreateClassSession", "onDeleteBranch"]) {
    assert.match(ui, new RegExp(callback));
    assert.match(app, new RegExp(callback));
  }
  assert.match(api, /async deleteBranch\(gymId: string, branchId: string, reason: string\)/);
  assert.match(api, /method: "DELETE", body: JSON\.stringify\(\{ reason \}\)/);
  assert.doesNotMatch(ui, /localStorage|sessionStorage|indexedDB/);
});

test("branch deletion and inactive operations stay server-enforced", async () => {
  const routes = await read("backend/routes/api.php");
  const controller = await read("backend/app/Http/Controllers/Api/V1/BranchController.php");
  const attendance = await read("backend/app/Services/AttendanceService.php");
  const classes = await read("backend/app/Services/ClassBookingService.php");

  assert.match(routes, /Route::delete\('\/branches\/\{branch\}'/);
  assert.match(routes, /role:super_admin,gym_owner,gym_manager/);
  assert.match(controller, /branch\.deleted/);
  assert.match(controller, /This branch has linked records/);
  assert.match(controller, /lockForUpdate\(\)->findOrFail\(\$branch\)/);
  assert.match(attendance, /Check-in is unavailable while this branch is inactive/);
  assert.match(classes, /Classes cannot be scheduled at an inactive branch/);
  assert.match(classes, /Booking is unavailable while this branch is inactive/);
});
