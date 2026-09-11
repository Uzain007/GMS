import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import jsQR from "jsqr";
import QRCode from "qrcode";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("the mobile fallback decodes the same opaque credential rendered on a member pass", () => {
  const credential = "icqr1_browser_fallback_acceptance_credential";
  const qr = QRCode.create(credential, { errorCorrectionLevel: "M" });
  const quietZone = 4;
  const scale = 8;
  const size = (qr.modules.size + quietZone * 2) * scale;
  const pixels = new Uint8ClampedArray(size * size * 4);
  pixels.fill(255);

  for (let row = 0; row < qr.modules.size; row += 1) {
    for (let column = 0; column < qr.modules.size; column += 1) {
      if (!qr.modules.get(row, column)) continue;
      for (let y = 0; y < scale; y += 1) {
        for (let x = 0; x < scale; x += 1) {
          const pixel = ((((row + quietZone) * scale + y) * size) + ((column + quietZone) * scale + x)) * 4;
          pixels[pixel] = 0;
          pixels[pixel + 1] = 0;
          pixels[pixel + 2] = 0;
          pixels[pixel + 3] = 255;
        }
      }
    }
  }

  assert.equal(jsQR(pixels, size, size, { inversionAttempts: "attemptBoth" })?.data, credential);
});

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

test("camera scanning prefers rear cameras, supports mobile decoding, device switching, and manual fallbacks", async () => {
  const [scanner, engagement, shell, api, styles, packageFile] = await Promise.all([
    read("app/qr-camera-scanner.tsx"),
    read("app/engagement-management.tsx"),
    read("app/ironcore-app.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("app/globals.css"),
    read("package.json"),
  ]);

  assert.match(engagement, /Scan QR with Camera/);
  assert.match(engagement, /inputMode="numeric"/);
  assert.match(scanner, /navigator\.mediaDevices\.getUserMedia/);
  assert.match(scanner, /window\.isSecureContext/);
  assert.match(scanner, /await import\("jsqr"\)/);
  assert.match(scanner, /inversionAttempts: "attemptBoth"/);
  assert.match(scanner, /facingMode: \{ ideal: "environment" \}/);
  assert.match(scanner, /enumerateDevices\(\)/);
  assert.match(scanner, /deviceId: \{ exact: preferredDeviceId \}/);
  assert.match(scanner, /NotAllowedError/);
  assert.match(scanner, /Camera permission is required to scan member QR codes\./);
  assert.match(scanner, /Your browser does not support QR scanning\. Please enter Member Code manually\./);
  assert.match(scanner, /Use Member Code instead/);
  assert.match(scanner, /getTracks\(\)\.forEach\(\(track\) => track\.stop\(\)\)/);
  assert.match(scanner, /muted playsInline autoPlay/);
  assert.match(packageFile, /"jsqr": "\^1\.4\.0"/);
  assert.doesNotMatch(engagement, /disabled=\{busy \|\| !selectedCheckInBranch\}/);
  assert.match(engagement, /branchId: selectedCheckInBranch \|\| undefined/);
  assert.match(engagement, /Primary location resolved securely/);
  assert.match(shell, /const branch = input\.branchId \? \{ branch_id: input\.branchId \} : \{\}/);
  assert.match(shell, /error: engagement\.error \?\? operations\.error/);
  assert.match(api, /AttendanceCheckIn = \{ branch_id\?: string/);
  assert.match(styles, /\.qr-scanner-card/);
  assert.match(styles, /@media\(max-width:620px\).*\.qr-video-shell\{aspect-ratio:3\/4\}/s);
});

test("member digital pass places the visible code beneath the secure QR", async () => {
  const [portal, dashboard, engagement, api, resource, credentialController] = await Promise.all([
    read("app/member-portal.tsx"),
    read("app/ironcore-dashboard.tsx"),
    read("app/engagement-management.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("backend/app/Http/Resources/MemberSelfResource.php"),
    read("backend/app/Http/Controllers/Api/V1/AttendanceController.php"),
  ]);

  assert.match(portal, /member-code-display/);
  assert.match(portal, /Member Code/);
  assert.match(portal, /data\.profile\?\.member_code/);
  assert.doesNotMatch(portal, /data\.profile\?\.member_number/);
  assert.match(dashboard, /Member Code/);
  assert.match(dashboard, /member\.memberCode/);
  assert.match(engagement, /credential-member-code/);
  assert.match(engagement, /issuedMember\?\.memberCode/);
  assert.match(engagement, /method: "member_code", accessValue/);
  assert.match(api, /member_code: string/);
  assert.match(resource, /'member_code' => \$this->member_code/);
  assert.match(credentialController, /\$data\['member_code'\] = \$member->member_code/);
});

test("member detail resources consistently include the tenant-scoped visible code", async () => {
  const resources = await Promise.all([
    "MemberResource.php",
    "AttendanceRecordResource.php",
    "ClassBookingResource.php",
    "TrainerMemberAssignmentResource.php",
    "WorkoutPlanResource.php",
    "WorkoutSessionResource.php",
    "MemberProgressMeasurementResource.php",
  ].map((file) => read(`backend/app/Http/Resources/${file}`)));

  for (const resource of resources) assert.match(resource, /'member_code'/);
});
