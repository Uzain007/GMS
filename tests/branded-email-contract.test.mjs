import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("transactional emails share one responsive email-safe IronCore layout", async () => {
  const [layout, header, button, infoCard, badge] = await Promise.all([
    read("backend/resources/views/emails/layouts/ironcore.blade.php"),
    read("backend/resources/views/emails/partials/header.blade.php"),
    read("backend/resources/views/emails/partials/button.blade.php"),
    read("backend/resources/views/emails/partials/info-card.blade.php"),
    read("backend/resources/views/emails/partials/status-badge.blade.php"),
  ]);

  assert.match(layout, /max-width:640px/);
  assert.match(layout, /@media only screen and \(max-width: 480px\)/);
  assert.doesNotMatch(layout, /width="640"/);
  assert.match(layout, /width="100%"/);
  assert.match(layout, /box-sizing: border-box/);
  assert.match(layout, /table-layout:fixed/);
  assert.match(layout, /overflow-wrap: anywhere/);
  assert.doesNotMatch(layout, /<script|rel=["']stylesheet/i);
  assert.match(header, /IRONCORE/);
  assert.match(header, /#6d42e5/i);
  assert.doesNotMatch([layout, header, button, infoCard, badge].join("\n"), /#176b52/i);
  assert.match(button, /role="presentation"/);
  assert.match(button, /max-width:100%/);
  assert.match(infoCard, /role="presentation"/);
  assert.match(infoCard, /overflow-wrap:anywhere/);
  assert.match(badge, /warning/);
});

test("long dynamic email values have wrapping contracts at every width-sensitive boundary", async () => {
  const [layout, header, footer, invitation, accountAccess] = await Promise.all([
    read("backend/resources/views/emails/layouts/ironcore.blade.php"),
    read("backend/resources/views/emails/partials/header.blade.php"),
    read("backend/resources/views/emails/partials/footer.blade.php"),
    read("backend/resources/views/emails/invitations/account.blade.php"),
    read("backend/resources/views/emails/security/account-access.blade.php"),
  ]);

  assert.match(layout, /email-header-brand, \.email-header-label/);
  assert.match(header, /email-header-label/);
  assert.match(footer, /email-footer-copy/);
  assert.match(invitation, /fallback-link/);
  assert.match(accountAccess, /fallback-link/);
  assert.match(invitation, /word-break:break-all/);
  assert.match(accountAccess, /word-break:break-all/);
});

test("existing queued email flows use branded views without replacing their security or ledger contracts", async () => {
  const [reset, invitation, saas, generic, memberService, staffService] = await Promise.all([
    read("backend/app/Jobs/SendPasswordResetLink.php"),
    read("backend/app/Jobs/SendAccountInvitation.php"),
    read("backend/app/Jobs/SendSaasBillingReminder.php"),
    read("backend/app/Services/Notifications/EmailNotificationAdapter.php"),
    read("backend/app/Services/MemberAccountInvitationService.php"),
    read("backend/app/Services/StaffInvitationService.php"),
  ]);

  assert.match(reset, /Password::sendResetLink/);
  assert.match(reset, /\/#reset_email=.*&reset_token=/);
  assert.match(reset, /emails\.security\.account-access/);
  assert.match(invitation, /implements ShouldBeEncrypted, ShouldQueue/);
  assert.match(invitation, /#activate_gym=.*&activate_token=/s);
  assert.match(invitation, /#invite_gym=.*&invite_token=/s);
  assert.match(invitation, /recordStaffDelivery/);
  assert.match(memberService, /SendAccountInvitation::dispatch/);
  assert.match(staffService, /SendAccountInvitation::dispatch/);
  assert.match(saas, /emails\.saas\.trial-ending/);
  assert.match(saas, /emails\.saas\.trial-expired/);
  assert.match(saas, /emails\.saas\.invoice-status/);
  assert.match(saas, /email_destination=saas_billing/);
  assert.match(saas, /mail_delivery_failed/);
  assert.match(generic, /membership_payment_due.*emails\.membership\.payment-due/s);
  assert.match(generic, /workout_plan_assigned.*emails\.training\.workout-plan-assigned/s);
  assert.match(generic, /membership_payment_due' => 'member_account'/);
  assert.match(generic, /workout_plan_assigned' => 'member_training'/);
  assert.match(generic, /NotificationProviderException::rejected/);
});

test("email CTAs open the intended existing portal views without carrying tenant data", async () => {
  const [memberPortal, gymDashboard] = await Promise.all([
    read("app/member-portal.tsx"),
    read("app/ironcore-dashboard.tsx"),
  ]);

  assert.match(memberPortal, /email_destination/);
  assert.match(memberPortal, /member_training.*setView\("training"\)/s);
  assert.match(memberPortal, /member_account.*setView\("account"\)/s);
  assert.match(gymDashboard, /saas_billing.*tenantViews\?\.includes\("billing"\).*setView\("billing"\)/s);
});

test("every supported transactional view extends the shared IronCore layout", async () => {
  const views = [
    "security/account-access",
    "invitations/account",
    "saas/trial-ending",
    "saas/trial-expired",
    "saas/invoice-status",
    "membership/payment-due",
    "training/workout-plan-assigned",
    "notifications/transactional",
  ];

  for (const view of views) {
    const source = await read(`backend/resources/views/emails/${view}.blade.php`);
    assert.match(source, /@extends\('emails\.layouts\.ironcore'/);
    assert.match(source, /trim\(\(string\) \(\$recipientName \?\? ''\)\)/);
  }
});

test("customer-facing email copy contains no preview-only wrapping instructions", async () => {
  const views = [
    "membership/payment-due",
    "training/workout-plan-assigned",
    "saas/invoice-status",
  ];
  const source = (await Promise.all(views.map((view) => read(`backend/resources/views/emails/${view}.blade.php`)))).join("\n");

  assert.doesNotMatch(source, /deliberately long example|intentionally longer preview sentence/i);
});
