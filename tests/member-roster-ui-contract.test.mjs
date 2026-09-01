import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

const root = process.cwd();
const read = (path) => readFile(join(root, path), "utf8");

test("member page exposes real import preview, templates and tenant export actions", async () => {
  const [dashboard, app, api, css] = await Promise.all([
    read("app/ironcore-dashboard.tsx"), read("app/ironcore-app.tsx"),
    read("app/lib/ironcore-api.ts"), read("app/globals.css"),
  ]);
  for (const label of ["Import members", "Export members", "Download template"]) assert.match(dashboard, new RegExp(label));
  assert.match(dashboard, /accept="\.csv,\.xls,\.xlsx/);
  for (const field of ["total_rows", "valid_rows", "invalid_rows", "duplicate_rows", "missing_required_fields", "invalid_email", "invalid_phone", "invalid_branch_references", "invalid_membership_references"]) assert.match(dashboard, new RegExp(`summary\\.${field}`));
  assert.match(dashboard, /summary\?\.invalid_rows[^\n]+> 0/);
  assert.match(api, /previewMemberImport/);
  assert.match(api, /confirmMemberImport/);
  assert.match(api, /memberImportTemplate/);
  assert.match(api, /memberRosterExport/);
  assert.match(app, /onPreviewImport: previewMemberImport/);
  assert.match(css, /@media\(max-width:460px\)\{\.member-bulk-actions/);
});

test("backend keeps preview confirmation and exports behind explicit tenant or platform roles", async () => {
  const [routes, request, preview, roster, composer] = await Promise.all([
    read("backend/routes/api.php"), read("backend/app/Http/Requests/StoreMemberImportRequest.php"),
    read("backend/app/Services/MemberImportPreviewService.php"),
    read("backend/app/Http/Controllers/Api/V1/MemberRosterController.php"),
    read("backend/composer.json"),
  ]);
  assert.match(request, /mimes:csv,txt,xls,xlsx/);
  assert.match(routes, /member-imports\/\{import\}\/confirm/);
  assert.match(routes, /members-import-template/);
  assert.match(routes, /members-export/);
  assert.match(routes, /platform\/members\/export[^]*role:super_admin/);
  assert.match(preview, /duplicate_rows/);
  assert.match(preview, /invalid_membership_references/);
  assert.match(roster, /context->run\(\$gym/);
  assert.match(roster, /chunkById\(500/);
  assert.match(composer, /shuchkin\/simplexls/);
  assert.match(composer, /shuchkin\/simplexlsx/);
});
