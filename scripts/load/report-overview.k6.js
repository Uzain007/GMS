import execution from "k6/execution";
import http from "k6/http";
import { check, fail } from "k6";

export const options = {
  scenarios: {
    cached_report_reads: {
      executor: "constant-arrival-rate",
      rate: Number(__ENV.IRONCORE_RATE || 8),
      timeUnit: "1s",
      duration: __ENV.IRONCORE_DURATION || "30s",
      preAllocatedVUs: 8,
      maxVUs: 32,
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.01"],
    "http_req_duration{endpoint:tenant-report-overview}": ["p(95)<500", "p(99)<1000"],
    checks: ["rate>0.99"],
  },
};

const baseUrl = (__ENV.IRONCORE_API_URL || "").replace(/\/+$/, "");
const gymId = __ENV.IRONCORE_GYM_ID || "";
const blockedGymId = __ENV.IRONCORE_BLOCKED_GYM_ID || "";
let tokens = [];
try {
  tokens = JSON.parse(__ENV.IRONCORE_ACCESS_TOKENS || "[]");
} catch {
  fail("IRONCORE_ACCESS_TOKENS must contain a JSON array.");
}
const from = __ENV.IRONCORE_REPORT_FROM || "2026-07-01";
const to = __ENV.IRONCORE_REPORT_TO || "2026-07-30";
const currency = __ENV.IRONCORE_CURRENCY || "GBP";

export function setup() {
  if (!baseUrl || !gymId || !blockedGymId || tokens.length < 8) {
    fail("Set the synthetic API URL, tenant IDs and at least eight expiring CI access tokens.");
  }

  http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }, 403));
  const headers = requestHeaders(tokens[0], gymId);
  const warm = http.get(reportUrl(gymId), { headers, tags: { endpoint: "cache-warmup" } });
  check(warm, {
    "cache warmup returns 200": (result) => result.status === 200,
    "cache warmup contains 500 active members": (result) => result.json("data.summary.active_members") === 500,
  });

  const denied = http.get(reportUrl(blockedGymId), {
    headers: requestHeaders(tokens[0], blockedGymId),
    tags: { endpoint: "tenant-isolation-probe" },
  });
  check(denied, {
    "cross-tenant report remains forbidden": (result) => result.status === 403,
  });

  return { generatedAt: warm.json("data.meta.generated_at") };
}

function reportUrl(selectedGymId) {
  return `${baseUrl}/api/v1/gyms/${encodeURIComponent(selectedGymId)}/reports/overview?from=${from}&to=${to}&currency=${currency}`;
}

function requestHeaders(token, selectedGymId) {
  return {
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
    "X-Gym-ID": selectedGymId,
  };
}

export default function readTenantReport(setupData) {
  // Route and header contain the same synthetic gym ID. Laravel still verifies
  // token membership, role, Eloquent scope and PostgreSQL RLS independently.
  const tokenIndex = execution.scenario.iterationInTest % tokens.length;
  const response = http.get(reportUrl(gymId), {
    headers: requestHeaders(tokens[tokenIndex], gymId),
    tags: { endpoint: "tenant-report-overview" },
  });

  check(response, {
    "report returns 200": (result) => result.status === 200,
    "report contains selected currency": (result) => result.json("data.period.currency") === currency,
    "report remains bounded": (result) => Number(result.json("data.period.days")) <= 366,
    "report is served from the warmed tenant cache": (result) => result.json("data.meta.generated_at") === setupData.generatedAt,
  });
}
