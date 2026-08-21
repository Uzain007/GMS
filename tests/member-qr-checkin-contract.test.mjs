import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("member codes are six-digit tenant-local lookup values, not security identifiers", async () => {
  const [migration, model, service, request, attendance] = await Promise.all([
    read("backend/database/migrations/2026_08_21_000025_add_member_codes_to_members.php"),
    read("backend/app/Models/Member.php"),
    read("backend/app/Services/MemberCodeService.php"),
    read("backend/app/Http/Requests/StoreAttendanceCheckInRequest.php"),
    read("backend/app/Services/AttendanceService.php"),
  ]);

  assert.match(migration, /char\('member_code', 6\)/);
  assert.match(migration, /unique\(\['gym_id', 'member_code'\]\)/);
  assert.match(model, /'member_code'/);
  assert.match(service, /random_int\(0, self::MAX_CODE\)/);
  assert.match(request, /member_code.*regex:\/\^\\\\d\{4,6\}\$\//s);
  assert.match(attendance, /where\('member_code', \$data\['member_code'\]\)/);
});

test("camera scanning prefers rear cameras, supports device switching, and has denied/manual fallbacks", async () => {
  const [scanner, engagement, styles] = await Promise.all([
    read("app/qr-camera-scanner.tsx"),
    read("app/engagement-management.tsx"),
    read("app/globals.css"),
  ]);

  assert.match(engagement, /Scan QR with Camera/);
  assert.match(engagement, /inputMode="numeric"/);
  assert.match(scanner, /navigator\.mediaDevices\.getUserMedia/);
  assert.match(scanner, /facingMode: \{ ideal: "environment" \}/);
  assert.match(scanner, /enumerateDevices\(\)/);
  assert.match(scanner, /deviceId: \{ exact: preferredDeviceId \}/);
  assert.match(scanner, /NotAllowedError/);
  assert.match(scanner, /Use Member Code instead/);
  assert.match(scanner, /getTracks\(\)\.forEach\(\(track\) => track\.stop\(\)\)/);
  assert.match(styles, /\.qr-scanner-card/);
  assert.match(styles, /@media\(max-width:620px\).*\.qr-video-shell\{aspect-ratio:3\/4\}/s);
});

test("member digital pass places the visible code beneath the secure QR", async () => {
  const [portal, api, resource] = await Promise.all([
    read("app/member-portal.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("backend/app/Http/Resources/MemberSelfResource.php"),
  ]);

  assert.match(portal, /member-code-display/);
  assert.match(portal, /Member Code/);
  assert.match(portal, /data\.profile\?\.member_code/);
  assert.match(api, /member_code: string/);
  assert.match(resource, /'member_code' => \$this->member_code/);
});
