import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../backend/", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("backend targets the agreed Laravel and PostgreSQL stack", async () => {
  const composer = JSON.parse(await read("composer.json"));
  const database = await read("config/database.php");

  assert.equal(composer.require.php, "^8.3");
  assert.equal(composer.require["laravel/framework"], "^13.0");
  assert.ok(composer.require["laravel/sanctum"]);
  assert.match(database, /DB_CONNECTION', 'pgsql/);
  assert.match(database, /REDIS_CLIENT/);
});

test("tenant middleware denies cross-gym access on the server", async () => {
  const middleware = await read("app/Http/Middleware/ResolveTenant.php");
  const context = await read("app/Tenancy/TenantContext.php");

  assert.match(middleware, /X-Gym-ID/);
  assert.match(middleware, /hash_equals/);
  assert.match(middleware, /gym context does not match the request route/);
  assert.match(middleware, /wherePivot\('status', 'active'\)/);
  assert.match(middleware, /\$this->context->set\(\$gym\)/);
  assert.match(middleware, /JsonResponse\(\['message' => 'You do not have access to this gym\.'\], 403\)/);
  assert.match(context, /No gym tenant has been resolved/);
  assert.match(context, /ironcore\.current_gym_id/);
});

test("all tenant records are indexed from the gym boundary", async () => {
  const gyms = await read("database/migrations/2026_08_06_000002_create_gyms_and_memberships_tables.php");
  const audits = await read("database/migrations/2026_08_06_000004_create_audit_logs_table.php");

  assert.match(gyms, /primary\(\['gym_id', 'user_id'\]\)/);
  assert.match(gyms, /index\(\['gym_id', 'role', 'status'\]\)/);
  assert.match(audits, /index\(\['gym_id', 'event', 'created_at'\]\)/);
  assert.match(audits, /index\(\['auditable_type', 'auditable_id'\]\)/);
});

test("phase-three tables use gym foreign keys, composite integrity and PostgreSQL RLS", async () => {
  const operations = await read("database/migrations/2026_08_06_000005_create_gym_operations_tables.php");
  const rls = await read("database/migrations/2026_08_06_000006_enable_postgresql_tenant_rls.php");

  for (const table of [
    "gym_branches", "members", "staff_profiles", "staff_profile_branch",
    "staff_invitations", "membership_plans", "memberships",
  ]) {
    assert.match(operations, new RegExp(`Schema::create\\('${table}'`));
  }
  assert.ok((operations.match(/uuid\('gym_id'\)/g) ?? []).length >= 7);
  assert.match(operations, /foreign\(\['gym_id', 'member_id'\]\)/);
  assert.match(operations, /foreign\(\['gym_id', 'plan_id'\]\)/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /WITH CHECK/);
  assert.match(rls, /memberships_one_current_per_member/);
});

test("tenant models fail closed and reject cross-gym writes", async () => {
  const concern = await read("app/Models/Concerns/BelongsToGym.php");

  assert.match(concern, /whereRaw\('1 = 0'\)/);
  assert.match(concern, /A tenant context is required to save tenant-owned data/);
  assert.match(concern, /Tenant-owned data cannot be saved for another gym/);
  assert.match(concern, /cannot be moved between gyms/);
});

test("phase-three endpoints remain behind tenant and role middleware", async () => {
  const routes = await read("routes/api.php");

  for (const segment of ["branches", "members", "member-imports", "staff", "staff-invitations", "membership-plans", "memberships"]) {
    assert.match(routes, new RegExp(`/${segment}`));
  }
  assert.match(routes, /Route::middleware\('tenant'\)/);
  assert.match(routes, /role:super_admin,gym_owner,gym_manager,receptionist/);
});

test("member CSV imports stream in tenant-scoped queue batches", async () => {
  const migration = await read("database/migrations/2026_08_06_000007_create_queue_and_member_import_tables.php");
  const job = await read("app/Jobs/ProcessMemberImport.php");
  const controller = await read("app/Http/Controllers/Api/V1/MemberImportController.php");

  assert.match(migration, /Schema::create\('member_imports'/);
  assert.match(migration, /index\(\['gym_id', 'status', 'created_at'\]\)/);
  assert.match(job, /implements ShouldQueue/);
  assert.match(job, /count\(\$batch\) >= 500/);
  assert.match(job, /'gym_id' => app\(TenantContext::class\)->id\(\)/);
  assert.match(job, /insertOrIgnore\(\$batch\)/);
  assert.match(controller, /gyms\/\{\$context->id\(\)\}\/imports\/members/);
  assert.match(controller, /afterCommit\(\)/);
});

test("sensitive changes keep actor, reason and before-after evidence", async () => {
  const audit = await read("app/Services/AuditService.php");
  const update = await read("app/Http/Controllers/Api/V1/GymController.php");

  for (const field of ["actor_id", "before_values", "after_values", "reason", "ip_address", "user_agent"]) {
    assert.match(audit, new RegExp(`'${field}'`));
  }
  assert.match(update, /gym\.updated/);
  assert.match(update, /\$request->string\('reason'\)/);
});

test("currency and role enums preserve the agreed product contract", async () => {
  const currencies = await read("app/Enums/Currency.php");
  const roles = await read("app/Enums/UserRole.php");

  for (const currency of ["GBP", "USD", "PKR", "AED", "SAR"]) {
    assert.match(currencies, new RegExp(`case ${currency} = '${currency}'`));
  }
  for (const role of ["super_admin", "gym_owner", "gym_manager", "receptionist", "trainer", "member"]) {
    assert.match(roles, new RegExp(`'${role}'`));
  }
});

test("role permission arrays separate platform, tenant staff and self-service access", async () => {
  const permissions = await read("config/permissions.php");

  for (const permission of [
    "platform.gyms.manage", "staff.manage", "members.create",
    "members.assigned.read", "membership.self.read",
  ]) {
    assert.match(permissions, new RegExp(permission.replaceAll(".", "\\.")));
  }
  assert.match(permissions, /UserRole::SuperAdmin/);
  assert.match(permissions, /UserRole::Member/);
});

test("payment tables preserve tenant boundaries, composite integrity and scale indexes", async () => {
  const schema = await read("database/migrations/2026_08_07_000009_create_payment_tables.php");
  const rls = await read("database/migrations/2026_08_07_000010_enable_payment_rls.php");

  for (const table of ["payment_gateway_accounts", "invoices", "invoice_items", "payments", "payment_refunds", "payment_webhook_events"]) {
    assert.match(schema, new RegExp(`Schema::create\\('${table}'`));
    assert.match(rls, new RegExp(`'${table}'`));
  }
  assert.ok((schema.match(/uuid\('gym_id'\)/g) ?? []).length >= 6);
  assert.match(schema, /foreign\(\['gym_id', 'invoice_id'\]\)/);
  assert.match(schema, /foreign\(\['gym_id', 'payment_id'\]\)/);
  assert.match(schema, /unique\(\['gym_id', 'idempotency_key'\]\)/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /ironcore_gateway_webhook_lookup/);
  assert.match(rls, /current_provider_account_id/);
});

test("finance services calculate money server-side and keep the ledger append-oriented", async () => {
  const invoices = await read("app/Services/InvoiceService.php");
  const payments = await read("app/Services/PaymentService.php");
  const refundRequest = await read("app/Http/Requests/StoreRefundRequest.php");
  const resource = await read("app/Http/Resources/PaymentResource.php");

  assert.match(invoices, /\$lineSubtotal = \(int\) \$item\['quantity'\] \* \(int\) \$item\['unit_amount_minor'\]/);
  assert.match(payments, /lockForUpdate\(\)/);
  assert.match(payments, /idempotency_key/);
  assert.match(payments, /The payment exceeds the invoice balance/);
  assert.match(refundRequest, /'reason' => \['required'/);
  assert.doesNotMatch(resource, /card_number|\bcvc\b|secret_key/);
});

test("Stripe webhooks verify signatures before narrow tenant resolution and deduplicate events", async () => {
  const gateway = await read("app/Services/StripeGatewayService.php");
  const webhooks = await read("app/Services/StripeWebhookService.php");
  const controller = await read("app/Http/Controllers/Api/V1/StripeWebhookController.php");
  const routes = await read("routes/api.php");

  assert.match(gateway, /hash_hmac\('sha256'/);
  assert.match(gateway, /Stripe-Account/);
  assert.match(gateway, /Idempotency-Key/);
  assert.match(controller, /verifyWebhook\(\$payload/);
  assert.match(webhooks, /resolveGymId\(\$accountId\)/);
  assert.match(webhooks, /firstOrCreate/);
  assert.match(webhooks, /payload_hash/);
  assert.doesNotMatch(webhooks, /'payload'\s*=>\s*\$rawPayload/);
  assert.match(routes, /payments\/\{payment\}\/refunds/);
  assert.match(routes, /role:super_admin,gym_owner/);
});

test("SaaS billing schema separates platform catalogue from tenant subscription ledgers", async () => {
  const schema = await read("database/migrations/2026_08_07_000011_create_saas_billing_tables.php");
  const rls = await read("database/migrations/2026_08_07_000012_enable_saas_billing_rls.php");

  for (const table of ["saas_plans", "saas_plan_prices", "platform_billing_customers", "gym_subscriptions", "subscription_checkout_sessions", "saas_billing_invoices", "saas_billing_webhook_events"]) {
    assert.match(schema, new RegExp(`Schema::create\\('${table}'`));
  }
  assert.ok((schema.match(/uuid\('gym_id'\)/g) ?? []).length >= 5);
  assert.match(schema, /foreign\(\['gym_id', 'billing_customer_id'\]\)/);
  assert.match(schema, /gym_subscriptions_one_current_unique/);
  assert.match(schema, /subscription_checkout_sessions_one_open_unique/);
  assert.match(schema, /saas_plan_prices_active_catalog_unique/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /ironcore_billing_webhook_customer_lookup/);
  assert.match(rls, /current_billing_customer_id/);
});

test("platform subscriptions never use connected-account payment routing", async () => {
  const billing = await read("app/Services/StripePlatformBillingService.php");
  const tenantPayments = await read("app/Services/StripeGatewayService.php");

  assert.match(billing, /mode' => 'subscription'/);
  assert.match(billing, /billing_portal\/sessions/);
  assert.match(billing, /saas-checkout:/);
  assert.doesNotMatch(billing, /Stripe-Account/);
  assert.match(tenantPayments, /Stripe-Account/);
  assert.match(billing, /billing_webhook_secret/);
});

test("SaaS Billing webhooks verify, resolve one customer, hash payloads and snapshot access", async () => {
  const verifier = await read("app/Services/StripePlatformBillingService.php");
  const webhook = await read("app/Services/StripeBillingWebhookService.php");
  const routes = await read("routes/api.php");

  assert.match(verifier, /hash_hmac\('sha256'/);
  assert.match(webhook, /resolveGymId\(\$customerId\)/);
  assert.match(webhook, /firstOrCreate/);
  assert.match(webhook, /feature_limits_snapshot/);
  assert.match(webhook, /payload_hash/);
  assert.doesNotMatch(webhook, /'payload'\s*=>\s*\$rawPayload/);
  assert.match(routes, /webhooks\/stripe\/billing/);
  assert.match(routes, /saas-subscription\/checkout/);
  assert.match(routes, /role:super_admin,gym_owner/);
});

test("attendance and class tables preserve tenant integrity, scale indexes and forced RLS", async () => {
  const schema = await read("database/migrations/2026_08_07_000013_create_attendance_and_class_tables.php");
  const rls = await read("database/migrations/2026_08_07_000014_enable_attendance_and_class_rls.php");

  for (const table of ["member_access_credentials", "attendance_records", "class_sessions", "class_bookings"]) {
    assert.match(schema, new RegExp(`Schema::create\\('${table}'`));
    assert.match(rls, new RegExp(`'${table}'`));
  }
  assert.ok((schema.match(/uuid\('gym_id'\)/g) ?? []).length >= 4);
  assert.match(schema, /foreign\(\['gym_id', 'member_id'\]\)/);
  assert.match(schema, /foreign\(\['gym_id', 'class_session_id'\]\)/);
  assert.match(schema, /attendance_records_one_open_unique/);
  assert.match(schema, /class_bookings_one_active_unique/);
  assert.match(schema, /member_access_credentials_one_active_unique/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /WITH CHECK/);
});

test("check-ins hash QR secrets and class bookings lock capacity with FIFO promotion", async () => {
  const attendance = await read("app/Services/AttendanceService.php");
  const bookings = await read("app/Services/ClassBookingService.php");
  const credential = await read("app/Models/MemberAccessCredential.php");
  const routes = await read("routes/api.php");

  assert.match(attendance, /hash\('sha256', \$plaintext\)/);
  assert.match(attendance, /hash\('sha256', \$data\['credential'\]\)/);
  assert.match(attendance, /activeMembershipFor/);
  assert.match(attendance, /whereDate\('starts_at', '<=', today\(\)\)/);
  assert.match(attendance, /AttendanceStatus::CheckedIn/);
  assert.doesNotMatch(credential, /plaintext|credential_token/);
  assert.match(bookings, /ClassSession::query\(\)->lockForUpdate\(\)/);
  assert.match(bookings, /next_waitlist_sequence\+\+/);
  assert.match(bookings, /orderBy\('waitlist_sequence'\)->lockForUpdate\(\)/);
  assert.match(bookings, /Members may book only for themselves/);
  assert.match(bookings, /Trainers may access only their assigned class roster/);
  assert.match(routes, /attendance\/check-ins/);
  assert.match(routes, /class-bookings\/\{booking\}\/attend/);
});

test("PostgreSQL sessions are pinned to UTC for timezone-aware records", async () => {
  const database = await read("config/database.php");
  assert.match(database, /'timezone' => env\('DB_TIMEZONE', '\+00:00'\)/);
});

test("training, progress and notification tables preserve tenant integrity and exact scale fields", async () => {
  const schema = await read("database/migrations/2026_08_07_000015_create_training_progress_notification_tables.php");
  const rls = await read("database/migrations/2026_08_07_000016_enable_training_progress_notification_rls.php");

  for (const table of [
    "trainer_member_assignments", "workout_plans", "workout_plan_exercises",
    "workout_sessions", "workout_set_logs", "member_progress_measurements",
    "notification_preferences", "notification_deliveries",
  ]) {
    assert.match(schema, new RegExp(`Schema::create\\('${table}'`));
    assert.match(rls, new RegExp(`'${table}'`));
  }
  assert.ok((schema.match(/uuid\('gym_id'\)/g) ?? []).length >= 8);
  for (const parent of ["trainer_staff_profile_id", "member_id", "workout_plan_id", "workout_session_id", "workout_plan_exercise_id"]) {
    assert.match(schema, new RegExp(`foreign\\(\\['gym_id', '${parent}'\\]\\)`));
  }
  assert.match(schema, /target_load_grams/);
  assert.match(schema, /value_milli/);
  assert.match(schema, /trainer_member_assignments_one_active_unique/);
  assert.match(schema, /workout_plans_one_active_member_unique/);
  assert.match(schema, /index\(\['gym_id', 'member_id', 'metric', 'measured_at', 'id'\]/);
  assert.match(rls, /FORCE ROW LEVEL SECURITY/);
  assert.match(rls, /WITH CHECK/);
});

test("trainer access, append-only history and notification jobs fail closed", async () => {
  const access = await read("app/Services/TrainingAccessService.php");
  const training = await read("app/Services/TrainingService.php");
  const progress = await read("app/Services/ProgressService.php");
  const notification = await read("app/Services/NotificationService.php");
  const delivery = await read("app/Models/NotificationDelivery.php");
  const job = await read("app/Jobs/SendNotificationDelivery.php");
  const assignmentController = await read("app/Http/Controllers/Api/V1/TrainerAssignmentController.php");
  const planController = await read("app/Http/Controllers/Api/V1/WorkoutPlanController.php");
  const sms = await read("app/Services/Notifications/SmsNotificationAdapter.php");
  const push = await read("app/Services/Notifications/PushNotificationAdapter.php");
  const routes = await read("routes/api.php");

  assert.match(access, /where\('user_id', \$actor->getKey\(\)\)/);
  assert.match(access, /Trainer access requires an active member assignment/);
  assert.match(access, /hasCurrentAssignment/);
  assert.match(access, /Training access requires an active trainer profile and tenant role/);
  assert.match(access, /StaffStatus::Active/);
  assert.match(training, /Create an active trainer assignment before prescribing a plan/);
  assert.match(training, /trainer_assignment\.ended/);
  assert.match(training, /TrainerAssignmentStatus::Inactive/);
  assert.match(training, /Every set must reference an exercise from this plan/);
  assert.match(training, /WorkoutSession::query\(\)->create/);
  assert.match(progress, /MemberProgressMeasurement::query\(\)->create/);
  assert.match(notification, /idempotency_key/);
  assert.match(notification, /nextAllowedAt/);
  assert.match(notification, /onQueue\('notifications'\)/);
  assert.doesNotMatch(notification, /failure_message' => Str::limit\(\$exception->getMessage/);
  assert.match(delivery, /'destination' => 'encrypted'/);
  assert.match(delivery, /protected \$hidden = \['destination'\]/);
  assert.match(job, /implements ShouldQueue/);
  assert.match(job, /\$context->run\(\$gym/);
  for (const controller of [assignmentController, planController]) {
    assert.match(controller, /whereDate\('starts_on', '<=', today\(\)\)/);
    assert.match(controller, /orWhereDate\('ends_on', '>=', today\(\)\)/);
  }
  for (const adapter of [sms, push]) {
    assert.match(adapter, /FILTER_VALIDATE_URL/);
    assert.match(adapter, /PHP_URL_SCHEME\) !== 'https'/);
  }
  for (const segment of ["trainer-assignments", "workout-plans", "workout-sessions", "progress-measurements", "notification-preferences", "notification-deliveries"]) {
    assert.match(routes, new RegExp(segment));
  }
  assert.match(routes, /trainer-assignments\/\{assignment\}\/end/);
  assert.match(assignmentController, /EndTrainerAssignmentRequest/);
});

test("tenant reports are bounded, currency-specific, cached and role protected", async () => {
  const request = await read("app/Http/Requests/ReportOverviewRequest.php");
  const service = await read("app/Services/ReportService.php");
  const routes = await read("routes/api.php");
  const limiter = await read("app/Providers/AppServiceProvider.php");
  const indexes = await read("database/migrations/2026_08_07_000017_add_reporting_indexes.php");

  assert.match(request, /cannot exceed 366 days/);
  assert.match(request, /Rule::enum\(Currency::class\)/);
  assert.match(service, /ironcore:gym:\{\$gym->id\}:reports:overview/);
  assert.match(service, /CACHE_SECONDS = 60/);
  assert.match(service, /where\('gym_id', \$gymId\)/);
  assert.match(service, /where\('currency', \$currency->value\)/);
  assert.match(service, /AT TIME ZONE/);
  assert.match(service, /changeBasisPoints/);
  assert.match(routes, /reports\/overview/);
  assert.match(routes, /role:super_admin,gym_owner,gym_manager/);
  assert.match(routes, /throttle:reports/);
  assert.match(limiter, /RateLimiter::for\('reports'/);
  assert.match(limiter, /Limit::perMinute\(30\)/);
  for (const index of [
    "members_report_created_idx", "memberships_report_cancel_idx",
    "payments_report_currency_idx", "refunds_report_currency_idx",
    "invoices_report_balance_idx", "attendance_report_checkin_idx",
  ]) {
    assert.match(indexes, new RegExp(index));
  }
});

test("operational readiness and launch assets do not expose infrastructure details or credentials", async () => {
  const health = await read("app/Http/Controllers/Api/V1/HealthController.php");
  const routes = await read("routes/api.php");
  const limiter = await read("app/Providers/AppServiceProvider.php");
  const load = await readFile(new URL("../scripts/load/report-overview.k6.js", import.meta.url), "utf8");
  const runbook = await readFile(new URL("../docs/DEPLOYMENT_RUNBOOK.md", import.meta.url), "utf8");

  assert.match(health, /DB::selectOne\('SELECT 1'\)/);
  assert.match(health, /Redis::connection\(\)->command\('ping'\)/);
  assert.match(health, /'status' => 'unavailable'/);
  assert.doesNotMatch(health, /getMessage|trace|connectionName/);
  assert.match(routes, /health\/readiness/);
  assert.match(limiter, /Limit::perMinute\(60\)/);
  assert.match(load, /IRONCORE_ACCESS_TOKEN/);
  assert.doesNotMatch(load, /sk_live_|Bearer [A-Za-z0-9]/);
  assert.match(runbook, /point-in-time recovery/i);
  assert.match(runbook, /queue workers/i);
});
