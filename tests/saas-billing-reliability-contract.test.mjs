import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("SaaS billing isolates tenant failures and reports a monitored batch failure", async () => {
  const [service, command, regression] = await Promise.all([
    read("backend/app/Services/AutomatedSaasBillingService.php"),
    read("backend/app/Console/Commands/RunSaasBillingLifecycle.php"),
    read("backend/tests/Feature/SaasBillingReliabilityTest.php"),
  ]);

  assert.match(service, /catch \(Throwable\)/);
  assert.match(service, /tenant_processing_failed/);
  assert.match(service, /\$totals\['failed'\]\+\+/);
  assert.doesNotMatch(service, /Log::error\([^;]*getMessage/s);
  assert.match(command, /\$result\['failed'\] === 0 \? self::SUCCESS : self::FAILURE/);
  assert.match(regression, /Scheduler tenant A/);
  assert.match(regression, /Scheduler tenant B/);
  assert.match(regression, /Scheduler tenant C/);
  assert.match(regression, /assertFalse\(\$context->hasTenant\(\)\)/);
});

test("SaaS reminder failures retain retries but expose sanitized evidence only", async () => {
  const [job, exception, regression] = await Promise.all([
    read("backend/app/Jobs/SendSaasBillingReminder.php"),
    read("backend/app/Exceptions/SaasBillingReminderDeliveryException.php"),
    read("backend/tests/Feature/SaasBillingReliabilityTest.php"),
  ]);

  assert.match(job, /public int \$tries = 3/);
  assert.match(job, /public array \$backoff = \[30, 120, 600\]/);
  assert.match(job, /mail_delivery_failed/);
  assert.match(job, /throw SaasBillingReminderDeliveryException::rejected\(\)/);
  assert.doesNotMatch(job, /throw \$exception/);
  assert.doesNotMatch(job, /Log::warning\([^;]*getMessage/s);
  assert.match(exception, /SaaS billing reminder delivery failed\./);
  assert.match(regression, /assertNull\(\$exception->getPrevious\(\)\)/);
  assert.match(regression, /assertSame\(3, \$fresh->attempts\)/);
});
