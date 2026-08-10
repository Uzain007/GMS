import http from "k6/http";
import { check, fail } from "k6";

export const options = {
  scenarios: {
    cached_report_reads: {
      executor: "constant-arrival-rate",
      rate: Number(__ENV.IRONCORE_RATE || 20),
      timeUnit: "1s",
      duration: __ENV.IRONCORE_DURATION || "2m",
      preAllocatedVUs: 10,
      maxVUs: 100,
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.01"],
    http_req_duration: ["p(95)<500", "p(99)<1000"],
    checks: ["rate>0.99"],
  },
};

const baseUrl = (__ENV.IRONCORE_API_URL || "").replace(/\/+$/, "");
const gymId = __ENV.IRONCORE_GYM_ID || "";
const token = __ENV.IRONCORE_ACCESS_TOKEN || "";
const from = __ENV.IRONCORE_REPORT_FROM || "2026-07-01";
const to = __ENV.IRONCORE_REPORT_TO || "2026-07-30";
const currency = __ENV.IRONCORE_CURRENCY || "GBP";

export function setup() {
  if (!baseUrl || !gymId || !token) {
    fail("Set IRONCORE_API_URL, IRONCORE_GYM_ID and IRONCORE_ACCESS_TOKEN with synthetic test-tenant values.");
  }
}

export default function readTenantReport() {
  // Route and header contain the same synthetic gym ID. Laravel still verifies
  // token membership, role, Eloquent scope and PostgreSQL RLS independently.
  const url = `${baseUrl}/api/v1/gyms/${encodeURIComponent(gymId)}/reports/overview?from=${from}&to=${to}&currency=${currency}`;
  const response = http.get(url, {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      "X-Gym-ID": gymId,
    },
    tags: { endpoint: "tenant-report-overview" },
  });

  check(response, {
    "report returns 200": (result) => result.status === 200,
    "report contains selected currency": (result) => result.json("data.period.currency") === currency,
    "report remains bounded": (result) => Number(result.json("data.period.days")) <= 366,
  });
}
