import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { decodeMimeTextParts } from "../scripts/ci/mime-message.mjs";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

const workflow = await read(".github/workflows/quality.yml");
const sandbox = await read("scripts/ci/notification-provider-sandbox.mjs");
const runtimeTest = await read(
  "backend/tests/Feature/MilestoneTwentyNotificationProviderRuntimeTest.php",
);
const exception = await read(
  "backend/app/Services/Notifications/NotificationProviderException.php",
);
const email = await read(
  "backend/app/Services/Notifications/EmailNotificationAdapter.php",
);
const sms = await read(
  "backend/app/Services/Notifications/SmsNotificationAdapter.php",
);
const push = await read(
  "backend/app/Services/Notifications/PushNotificationAdapter.php",
);

test("hosted backend runs a credential-free SMTP and HTTPS notification boundary", () => {
  assert.match(workflow, /IRONCORE_NOTIFICATION_RUNTIME_GATE: "true"/);
  assert.match(workflow, /MAIL_MAILER: smtp/);
  assert.match(workflow, /MAIL_HOST: 127\.0\.0\.1/);
  assert.match(workflow, /NOTIFICATION_SMS_ENDPOINT: https:\/\/127\.0\.0\.1:8443\/sms/);
  assert.match(workflow, /NOTIFICATION_PUSH_ENDPOINT: https:\/\/127\.0\.0\.1:8443\/push/);
  assert.match(workflow, /openssl req -x509 -newkey rsa:2048/);
  assert.match(workflow, /subjectAltName=IP:127\.0\.0\.1,DNS:localhost/);
  assert.match(workflow, /node scripts\/ci\/notification-provider-sandbox\.mjs/);
  assert.match(workflow, /Stop disposable notification provider boundary/);
  assert.match(workflow, /rm -f --[\s\S]*IRONCORE_NOTIFICATION_RUNTIME_CERTIFICATE[\s\S]*IRONCORE_NOTIFICATION_RUNTIME_PRIVATE_KEY/);
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);
});

test("disposable provider binds loopback and requires SMTP plus bearer authentication", () => {
  assert.match(sandbox, /const host = "127\.0\.0\.1"/);
  assert.match(sandbox, /AUTH PLAIN LOGIN/);
  assert.match(sandbox, /Authentication required/);
  assert.match(sandbox, /https\.createServer/);
  assert.match(sandbox, /equalSecret\(bearer\(request\), expectedToken\)/);
  assert.match(sandbox, /equalSecret\(bearer\(request\), evidenceToken\)/);
  assert.match(sandbox, /cache-control": "no-store"/);
  assert.match(sandbox, /text_parts: decodeMimeTextParts\(raw\)/);
  assert.doesNotMatch(sandbox, /0\.0\.0\.0/);
});

test("SMTP evidence decodes quoted-printable and base64 MIME text parts", () => {
  const html = '<a href="http://localhost/#reset_email=runtime%40example.test&amp;reset_token=xyz">Reset</a>';
  const message = [
    "MIME-Version: 1.0",
    "Content-Type: multipart/alternative;",
    ' boundary="ironcore-runtime-boundary"',
    "",
    "This is a multipart message.",
    "--ironcore-runtime-boundary",
    "Content-Type: text/plain; charset=utf-8",
    "Content-Transfer-Encoding: quoted-printable",
    "",
    "Reset: http://localhost/#reset_email=3Druntime%40example.test&reset_=",
    "token=3Dabc",
    "--ironcore-runtime-boundary",
    "Content-Type: text/html; charset=utf-8",
    "Content-Transfer-Encoding: base64",
    "",
    Buffer.from(html, "utf8").toString("base64"),
    "--ironcore-runtime-boundary--",
    "",
  ].join("\r\n");

  assert.deepEqual(decodeMimeTextParts(message), [
    {
      media_type: "text/plain",
      content: "Reset: http://localhost/#reset_email=runtime%40example.test&reset_token=abc",
    },
    { media_type: "text/html", content: html },
  ]);
});

test("runtime coverage crosses Redis and denies a mismatched tenant payload", () => {
  assert.match(runtimeTest, /auth\/forgot-password/);
  assert.match(runtimeTest, /drainQueue\('default'\)/);
  assert.match(runtimeTest, /drainQueue\('notifications'\)/);
  assert.match(runtimeTest, /'connection' => 'redis'/);
  for (const channel of ["Email", "Sms", "Push"]) {
    assert.match(runtimeTest, new RegExp(`NotificationChannel::${channel}`));
  }
  assert.match(runtimeTest, /new SendNotificationDelivery\(\$selectedGym->id, \$otherDelivery->id\)/);
  assert.match(runtimeTest, /catch \(ModelNotFoundException\)/);
  assert.match(runtimeTest, /NotificationDeliveryStatus::Queued/);
  assert.match(runtimeTest, /test_disabled_channel_is_suppressed_before_any_provider_request/);
  assert.match(runtimeTest, /NotificationDeliveryStatus::Suppressed/);
  assert.match(runtimeTest, /decodedSmtpText/);
  assert.doesNotMatch(runtimeTest, /quoted_printable_decode/);
});

test("adapter failures discard provider exception chains before queue evidence", () => {
  assert.match(exception, /failed-job exception chains/);
  assert.match(exception, /return new self\('The notification provider rejected the delivery\.'\)/);
  assert.doesNotMatch(exception, /previous:/);
  for (const adapter of [email, sms, push]) {
    assert.match(adapter, /catch \(Throwable\)/);
    assert.match(adapter, /NotificationProviderException::rejected\(\)/);
  }
  for (const adapter of [sms, push]) {
    assert.match(adapter, /withOptions\(\['verify' => \$caBundle\]\)/);
    assert.doesNotMatch(adapter, /'verify' => false/);
  }
  assert.match(runtimeTest, /assertNull\(\$exception->getPrevious\(\)\)/);
  assert.match(runtimeTest, /runtime_gate\.rejection_marker/);
});
