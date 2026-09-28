import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("conditional forms omit absent optional values instead of serializing null strings", async () => {
  const [helper, finance, member, platform] = await Promise.all([
    read("app/lib/form-values.ts"),
    read("app/financial-management.tsx"),
    read("app/member-portal.tsx"),
    read("app/platform-portal.tsx"),
  ]);

  assert.match(helper, /typeof value !== "string"/);
  assert.match(helper, /return normalized \|\| undefined/);
  assert.match(finance, /bank_reference: method === "bank_transfer" \? optionalStringInputValue\(value, "bank_reference"\) : undefined/);
  assert.match(member, /bank_reference: method === "bank_transfer" \? optionalStringInputValue\(form, "bank_reference"\) : undefined/);
  assert.doesNotMatch(finance, /bank_reference: String\(value\.get\("bank_reference"\)\)/);
  assert.doesNotMatch(member, /bank_reference: String\(form\.get\("bank_reference"\)\)/);
  assert.match(platform, /const temporaryPassword = createOwner && setupMethod === "temporary_password"/);
  assert.match(platform, /temporary_password_confirmation: createOwner && setupMethod === "temporary_password"/);
  assert.match(platform, /require_password_change: createOwner && setupMethod === "temporary_password"/);
});

test("calendar-only business inputs use the selected gym timezone", async () => {
  const [gymTime, finance, member, operations, saas, platform, requests] = await Promise.all([
    read("app/lib/gym-time.ts"),
    read("app/financial-management.tsx"),
    read("app/member-portal.tsx"),
    read("app/tenant-operations.tsx"),
    read("app/saas-billing-management.tsx"),
    read("app/platform-insights.tsx"),
    Promise.all([
      read("backend/app/Http/Requests/StorePaymentRequest.php"),
      read("backend/app/Http/Requests/StoreMemberPaymentRequest.php"),
      read("backend/app/Http/Requests/StoreSaasSubscriptionPaymentRequest.php"),
      read("backend/app/Http/Requests/CorrectSaasSubscriptionPaymentRequest.php"),
      read("backend/app/Http/Requests/StoreSaasBillingInvoiceRequest.php"),
    ]).then((values) => values.join("\n")),
  ]);

  assert.match(gymTime, /export function dateInputValueInTimeZone/);
  assert.match(gymTime, /export function formatCalendarDate/);
  assert.match(finance, /dateInputValueInTimeZone\(data\.timezone\)/);
  assert.match(member, /dateInputValueInTimeZone\(data\.gym\.timezone\)/);
  assert.match(operations, /dateInputValueInTimeZone\(data\.gymTimezone\)/);
  assert.match(operations, /nextDayClassDefaults\(branch\.timezone \?\? data\.gymTimezone\)/);
  assert.match(member, /membershipDate\(data\.membership\?\.starts_at\)/);
  assert.match(saas, /dateInputValueInTimeZone\(timezone\)/);
  assert.match(platform, /dateInputValueInTimeZone\(invoiceGym\?\.timezone \?\? "UTC"\)/);
  assert.match(requests, /date_format:Y-m-d/);
  assert.match(requests, /this->tenantToday\(\)/);
  assert.doesNotMatch(requests, /before_or_equal:today|after_or_equal:today/);
});
