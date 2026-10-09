import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("member billing hooks reuse the notification ledger for every authoritative transition", () => {
  const billing = read("backend/app/Services/BillingNotificationService.php");
  const invoices = read("backend/app/Services/InvoiceService.php");
  const automation = read("backend/app/Services/AutomatedMembershipBillingService.php");
  const payments = read("backend/app/Services/PaymentService.php");

  for (const key of [
    "membership_invoice_created",
    "membership_payment_due",
    "membership_invoice_overdue",
    "membership_payment_paid",
    "membership_access_restricted",
    "membership_access_restored",
  ]) assert.match(billing, new RegExp(key));

  assert.match(invoices, /memberInvoiceCreated/);
  assert.match(automation, /memberInvoiceDue/);
  assert.match(automation, /memberAccessRestricted/);
  assert.match(payments, /PaymentStatus::Paid[\s\S]*memberPaymentPaid/);
  assert.match(billing, /membership-payment:\{\$payment->id\}:paid:email/);
});

test("saas billing hooks stay separate and use webhook or payment identity for idempotency", () => {
  const billing = read("backend/app/Services/BillingNotificationService.php");
  const automation = read("backend/app/Services/AutomatedSaasBillingService.php");
  const manual = read("backend/app/Services/SaasBillingService.php");
  const stripe = read("backend/app/Services/StripeBillingWebhookService.php");

  for (const key of [
    "saas_invoice_created",
    "saas_invoice_due",
    "saas_invoice_overdue",
    "saas_invoice_paid",
    "saas_account_restricted",
    "saas_account_restored",
  ]) assert.match(billing, new RegExp(key));

  assert.match(automation, /saasInvoiceCreated/);
  assert.match(automation, /saasInvoiceDue/);
  assert.match(automation, /saasAccessRestricted/);
  assert.match(manual, /PaymentStatus::Paid[\s\S]*saasInvoicePaid/);
  assert.match(stripe, /invoice\.paid[\s\S]*saasInvoicePaid/);
  assert.match(billing, /SaasBillingNotification::query\(\)->firstOrCreate/);
});

test("billing mail uses only the existing branded mailable and responsive shared layout", () => {
  const memberAdapter = read("backend/app/Services/Notifications/EmailNotificationAdapter.php");
  const saasJob = read("backend/app/Jobs/SendSaasBillingReminder.php");
  const layout = read("backend/resources/views/emails/layouts/ironcore.blade.php");

  assert.match(memberAdapter, /new BrandedTransactionalMail/);
  assert.match(saasJob, /new BrandedTransactionalMail/);
  assert.match(layout, /max-width:640px/);
  assert.match(layout, /overflow-wrap: anywhere/);
  assert.doesNotMatch(memberAdapter + saasJob, /PREVIEW ONLY|intentionally longer|deliberately long example/i);
});
