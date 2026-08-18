import { timingSafeEqual } from "node:crypto";
import { readFileSync } from "node:fs";
import https from "node:https";

const host = "127.0.0.1";
const port = Number.parseInt(process.env.IRONCORE_STRIPE_RUNTIME_HTTPS_PORT ?? "8444", 10);
const certificate = readFileSync(required("IRONCORE_STRIPE_RUNTIME_CERTIFICATE"));
const privateKey = readFileSync(required("IRONCORE_STRIPE_RUNTIME_PRIVATE_KEY"));
const providerToken = required("STRIPE_SECRET_KEY");
const evidenceToken = required("IRONCORE_STRIPE_RUNTIME_EVIDENCE_TOKEN");
const rejectionMarker = required("IRONCORE_STRIPE_RUNTIME_REJECTION_MARKER");

let evidence;
reset();

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error(`${name} is required`);
  return value;
}

function reset() {
  evidence = {
    requests: [],
    rejections: 0,
    counters: { account: 0, paymentCheckout: 0, saasCheckout: 0, refund: 0, product: 0, price: 0, customer: 0, portal: 0 },
    lastSaasCheckout: null,
  };
}

function equalSecret(actual, expected) {
  const left = Buffer.from(actual);
  const right = Buffer.from(expected);
  return left.length === right.length && timingSafeEqual(left, right);
}

function bearer(request) {
  const authorization = request.headers.authorization ?? "";
  return authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
}

function respondJson(response, status, payload) {
  response.writeHead(status, {
    "content-type": "application/json",
    "cache-control": "no-store",
  });
  response.end(JSON.stringify(payload));
}

async function formBody(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > 128 * 1024) throw new Error("Request body is too large");
    chunks.push(chunk);
  }
  return Object.fromEntries(new URLSearchParams(Buffer.concat(chunks).toString("utf8")));
}

function record(request, url, body = {}) {
  evidence.requests.push({
    method: request.method,
    path: url.pathname,
    stripe_account: request.headers["stripe-account"] ?? null,
    idempotency_key: request.headers["idempotency-key"] ?? null,
    body,
  });
}

function activeAccount(id) {
  return {
    id,
    charges_enabled: true,
    payouts_enabled: true,
    details_submitted: true,
    requirements: { currently_due: [], eventually_due: [], disabled_reason: null },
  };
}

const server = https.createServer({ cert: certificate, key: privateKey }, async (request, response) => {
  const url = new URL(request.url ?? "/", `https://${host}:${port}`);

  if (request.method === "GET" && url.pathname === "/health") {
    respondJson(response, 200, { ready: true });
    return;
  }

  if (url.pathname === "/_evidence" || url.pathname === "/_reset") {
    if (!equalSecret(bearer(request), evidenceToken)) {
      respondJson(response, 401, { error: "unauthorized" });
      return;
    }
    if (request.method === "GET" && url.pathname === "/_evidence") {
      respondJson(response, 200, evidence);
      return;
    }
    if (request.method === "POST" && url.pathname === "/_reset") {
      reset();
      respondJson(response, 200, { reset: true });
      return;
    }
  }

  if (!equalSecret(bearer(request), providerToken)) {
    respondJson(response, 401, { error: "provider_authentication_required" });
    return;
  }

  try {
    const body = request.method === "POST" ? await formBody(request) : Object.fromEntries(url.searchParams);
    record(request, url, body);

    if (request.method === "POST" && url.pathname === "/v1/accounts") {
      evidence.counters.account += 1;
      respondJson(response, 200, activeAccount(`acct_ci_${evidence.counters.account}`));
      return;
    }
    if (request.method === "GET" && url.pathname.startsWith("/v1/accounts/")) {
      respondJson(response, 200, activeAccount(url.pathname.split("/").at(-1)));
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/account_links") {
      respondJson(response, 200, { url: "https://connect.stripe.test/ironcore-onboarding" });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/checkout/sessions") {
      if (request.headers["stripe-account"]) {
        evidence.counters.paymentCheckout += 1;
        const id = `cs_ci_payment_${evidence.counters.paymentCheckout}`;
        respondJson(response, 200, { id, url: `https://checkout.stripe.test/${id}` });
      } else {
        evidence.counters.saasCheckout += 1;
        const id = `cs_ci_saas_${evidence.counters.saasCheckout}`;
        evidence.lastSaasCheckout = { id, body };
        respondJson(response, 200, { id, url: `https://checkout.stripe.test/${id}`, expires_at: Math.floor(Date.now() / 1000) + 1800 });
      }
      return;
    }
    if (request.method === "GET" && url.pathname.startsWith("/v1/checkout/sessions/")) {
      const id = url.pathname.split("/").at(-1);
      respondJson(response, 200, { id, url: `https://checkout.stripe.test/${id}`, status: "open" });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/refunds") {
      if (body.amount === "9999") {
        evidence.rejections += 1;
        respondJson(response, 402, { error: { message: rejectionMarker } });
        return;
      }
      evidence.counters.refund += 1;
      respondJson(response, 200, { id: `re_ci_${evidence.counters.refund}` });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/products") {
      evidence.counters.product += 1;
      respondJson(response, 200, { id: `prod_ci_${evidence.counters.product}` });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/prices") {
      evidence.counters.price += 1;
      respondJson(response, 200, { id: `price_ci_${evidence.counters.price}` });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/customers") {
      evidence.counters.customer += 1;
      respondJson(response, 200, { id: `cus_ci_${evidence.counters.customer}` });
      return;
    }
    if (request.method === "POST" && url.pathname === "/v1/billing_portal/sessions") {
      evidence.counters.portal += 1;
      respondJson(response, 200, { url: `https://billing.stripe.test/bps_ci_${evidence.counters.portal}` });
      return;
    }
    if (request.method === "GET" && url.pathname.startsWith("/v1/subscriptions/")) {
      const checkout = evidence.lastSaasCheckout?.body ?? {};
      respondJson(response, 200, {
        id: url.pathname.split("/").at(-1),
        customer: checkout.customer,
        status: "trialing",
        metadata: { gym_id: checkout.client_reference_id },
        items: { data: [{ price: checkout["line_items[0][price]"] }] },
        current_period_start: Math.floor(Date.now() / 1000),
        current_period_end: Math.floor(Date.now() / 1000) + 2592000,
        trial_end: Math.floor(Date.now() / 1000) + 1209600,
        cancel_at_period_end: false,
      });
      return;
    }

    respondJson(response, 404, { error: "unsupported_runtime_operation" });
  } catch {
    respondJson(response, 400, { error: "invalid_runtime_request" });
  }
});

server.listen(port, host, () => {
  process.stdout.write(`IronCore disposable Stripe boundary listening on https://${host}:${port}\n`);
});

function shutdown() {
  server.close(() => process.exit(0));
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
