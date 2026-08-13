import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("hosted backend gate includes disposable S3-compatible storage", async () => {
  const workflow = await read(".github/workflows/quality.yml");

  assert.match(workflow, /IRONCORE_S3_RUNTIME_GATE: "true"/);
  assert.match(workflow, /FILESYSTEM_DISK: s3/);
  assert.match(workflow, /AWS_ENDPOINT: http:\/\/127\.0\.0\.1:4566/);
  assert.match(workflow, /image: localstack\/localstack:4\.14\.0/);
  assert.match(workflow, /SERVICES: s3/);
  assert.match(workflow, /_localstack\/health/);
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);
});

test("S3 runtime coverage executes export generation and expiry", async () => {
  const runtime = await read(
    "backend/tests/Feature/PhaseTwelveMemberDataExportS3RuntimeTest.php",
  );

  assert.match(runtime, /new S3Client/);
  assert.match(runtime, /createBucket/);
  assert.match(runtime, /new GenerateMemberDataExport/);
  assert.match(runtime, /gyms\/\{\$gym->id\}\/exports\/members/);
  assert.match(runtime, /assertSame\('private'/);
  assert.match(runtime, /hash\('sha256', \$bytes\)/);
  assert.match(runtime, /new PurgeMemberDataExport/);
  assert.match(runtime, /assertFalse\(Storage::disk\('s3'\)->exists/);
});
