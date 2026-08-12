import { isIP } from "node:net";
import { pathToFileURL } from "node:url";

const DEFAULT_REQUEST_TIMEOUT_MS = 15_000;
const MAX_HTML_BYTES = 2 * 1024 * 1024;
const MAX_MANIFEST_BYTES = 64 * 1024;

function integerAtLeast(value, fallback, name, minimum = 0) {
  if (value === undefined || value === "") {
    return fallback;
  }

  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed < minimum) {
    throw new Error(`${name} must be an integer of at least ${minimum}.`);
  }

  return parsed;
}

function blockedIp(hostname) {
  const address = hostname.replace(/^\[|\]$/g, "").toLowerCase();
  const family = isIP(address);

  if (family === 4) {
    const octets = address.split(".").map(Number);
    return (
      octets[0] === 0 ||
      octets[0] === 10 ||
      octets[0] === 127 ||
      (octets[0] === 169 && octets[1] === 254) ||
      (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) ||
      (octets[0] === 192 && octets[1] === 168) ||
      (octets[0] === 100 && octets[1] >= 64 && octets[1] <= 127) ||
      octets[0] >= 224
    );
  }

  if (family === 6) {
    return (
      address === "::" ||
      address === "::1" ||
      address.startsWith("fc") ||
      address.startsWith("fd") ||
      address.startsWith("fe8") ||
      address.startsWith("fe9") ||
      address.startsWith("fea") ||
      address.startsWith("feb")
    );
  }

  return false;
}

export function validateTargetUrl(rawTarget, rawAllowedHosts) {
  if (!rawTarget) {
    throw new Error("IRONCORE_SMOKE_WEB_URL is required.");
  }

  const target = new URL(rawTarget);
  const allowedHosts = new Set(
    (rawAllowedHosts ?? "")
      .split(",")
      .map((host) => host.trim().toLowerCase())
      .filter(Boolean),
  );

  if (target.protocol !== "https:") {
    throw new Error("The deployment smoke target must use HTTPS.");
  }
  if (target.username || target.password || target.search || target.hash) {
    throw new Error("The deployment smoke target cannot contain credentials, a query or a fragment.");
  }
  if (target.port && target.port !== "443") {
    throw new Error("The deployment smoke target must use the default HTTPS port.");
  }

  const hostname = target.hostname.toLowerCase();
  if (
    hostname === "localhost" ||
    hostname.endsWith(".localhost") ||
    hostname.endsWith(".local") ||
    hostname.endsWith(".internal") ||
    blockedIp(hostname)
  ) {
    throw new Error("The deployment smoke target cannot resolve to a local or private host literal.");
  }
  if (allowedHosts.size === 0 || !allowedHosts.has(hostname)) {
    throw new Error("The deployment smoke target is not in IRONCORE_SMOKE_ALLOWED_HOSTS.");
  }

  target.pathname = target.pathname.replace(/\/+$/, "") || "/";
  return target;
}

export function validateExpectedCommit(rawCommit) {
  if (!rawCommit) {
    return null;
  }
  if (!/^[0-9a-f]{40}$/i.test(rawCommit)) {
    throw new Error("IRONCORE_EXPECTED_COMMIT must be a full Git commit SHA.");
  }

  return rawCommit.toLowerCase();
}

function attributeValue(tag, attribute) {
  const pattern = new RegExp(`${attribute}\\s*=\\s*["']([^"']+)["']`, "i");
  return tag.match(pattern)?.[1] ?? null;
}

export function extractMetaContent(html, name) {
  for (const match of html.matchAll(/<meta\b[^>]*>/gi)) {
    if (attributeValue(match[0], "name") === name) {
      return attributeValue(match[0], "content");
    }
  }

  return null;
}

function assertSameOrigin(response, origin, label) {
  if (new URL(response.url).origin !== origin) {
    throw new Error(`${label} redirected outside the reviewed deployment origin.`);
  }
}

async function boundedText(response, maximumBytes, label) {
  const declaredLength = Number(response.headers.get("content-length") ?? 0);
  if (declaredLength > maximumBytes) {
    throw new Error(`${label} exceeded the ${maximumBytes}-byte limit.`);
  }

  if (!response.body) {
    return "";
  }

  const reader = response.body.getReader();
  const chunks = [];
  let receivedBytes = 0;

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }

      receivedBytes += value.byteLength;
      if (receivedBytes > maximumBytes) {
        await reader.cancel();
        throw new Error(`${label} exceeded the ${maximumBytes}-byte limit.`);
      }
      chunks.push(Buffer.from(value));
    }
  } finally {
    reader.releaseLock();
  }

  return Buffer.concat(chunks).toString("utf8");
}

async function request(url, options = {}) {
  const timeout = integerAtLeast(
    process.env.IRONCORE_SMOKE_REQUEST_TIMEOUT_MS,
    DEFAULT_REQUEST_TIMEOUT_MS,
    "IRONCORE_SMOKE_REQUEST_TIMEOUT_MS",
    1,
  );

  return fetch(url, {
    cache: "no-store",
    redirect: "follow",
    signal: AbortSignal.timeout(timeout),
    ...options,
    headers: {
      "cache-control": "no-cache",
      "user-agent": "IronCore-Deployment-Smoke/1.0",
      ...options.headers,
    },
  });
}

async function verifyAsset(target, assetPath, expectedType) {
  const assetUrl = new URL(assetPath, target);
  if (assetUrl.origin !== target.origin) {
    throw new Error("A rendered asset reference escaped the deployment origin.");
  }

  const response = await request(assetUrl, { headers: { range: "bytes=0-0" } });
  assertSameOrigin(response, target.origin, "Static asset");
  if (![200, 206].includes(response.status)) {
    throw new Error(`Static asset ${assetUrl.pathname} returned HTTP ${response.status}.`);
  }

  const contentType = response.headers.get("content-type") ?? "";
  if (!contentType.includes(expectedType)) {
    throw new Error(`Static asset ${assetUrl.pathname} returned ${contentType || "no content type"}.`);
  }
  await response.body?.cancel();
}

async function verifyManifest(target, html) {
  const manifestTag = html.match(/<link\b[^>]*rel=["']manifest["'][^>]*>/i)?.[0];
  const manifestPath = manifestTag ? attributeValue(manifestTag, "href") : null;
  if (!manifestPath) {
    throw new Error("The deployed page does not advertise its install manifest.");
  }

  const manifestUrl = new URL(manifestPath, target);
  if (manifestUrl.origin !== target.origin) {
    throw new Error("The install manifest escaped the deployment origin.");
  }

  const response = await request(manifestUrl);
  assertSameOrigin(response, target.origin, "Install manifest");
  if (response.status !== 200) {
    throw new Error(`Install manifest returned HTTP ${response.status}.`);
  }

  const manifest = JSON.parse(await boundedText(response, MAX_MANIFEST_BYTES, "Install manifest"));
  if (manifest.name !== "IronCore Gym" || manifest.display !== "standalone" || manifest.start_url !== "/") {
    throw new Error("The install manifest does not match the reviewed IronCore contract.");
  }
}

async function verifyDeployment(target, expectedCommit) {
  // The cache-busting value contains no tenant or credential data. It prevents
  // an edge cache from satisfying a release-identity check with stale HTML.
  const requestUrl = new URL(target);
  requestUrl.searchParams.set("ironcore-smoke", String(Date.now()));

  const response = await request(requestUrl);
  assertSameOrigin(response, target.origin, "Homepage");
  if (response.status !== 200) {
    throw new Error(`Homepage returned HTTP ${response.status}.`);
  }
  if (!(response.headers.get("content-type") ?? "").includes("text/html")) {
    throw new Error("Homepage did not return HTML.");
  }

  const hsts = response.headers.get("strict-transport-security") ?? "";
  if (!/max-age=(?:[3-9]\d{7}|[1-9]\d{8,})/i.test(hsts) || !/includeSubDomains/i.test(hsts)) {
    throw new Error("Homepage is missing the reviewed HSTS policy.");
  }

  const html = await boundedText(response, MAX_HTML_BYTES, "Homepage");
  if (!/<html\b[^>]*lang=["']en["']/i.test(html)) {
    throw new Error("Homepage is missing its English document language.");
  }
  if (!/<title>IronCore \| Gym management, built to scale<\/title>/i.test(html)) {
    throw new Error("Homepage title does not match the reviewed IronCore release.");
  }
  if (!html.includes("IRONCORE") || !html.includes("Preview gym portal")) {
    throw new Error("Homepage is missing the platform-shell smoke markers.");
  }

  const releaseCommit = extractMetaContent(html, "ironcore-release")?.toLowerCase();
  if (!releaseCommit) {
    throw new Error("Homepage is missing the ironcore-release identity marker.");
  }
  if (expectedCommit && releaseCommit !== expectedCommit) {
    throw new Error(`Deployment is serving ${releaseCommit}, expected ${expectedCommit}.`);
  }

  const assetPaths = [...html.matchAll(/(?:src|href)=["']([^"']+)["']/gi)]
    .map((match) => match[1])
    .filter((path) => path.startsWith("/_next/static/"));
  const stylesheet = assetPaths.find((path) => path.endsWith(".css"));
  const script = assetPaths.find((path) => path.endsWith(".js"));
  if (!stylesheet || !script) {
    throw new Error("Homepage is missing its deployable CSS or JavaScript asset reference.");
  }

  await Promise.all([
    verifyAsset(target, stylesheet, "text/css"),
    verifyAsset(target, script, "javascript"),
    verifyManifest(target, html),
  ]);

  return {
    target: target.origin,
    release_commit: releaseCommit,
    homepage_status: response.status,
    hsts: true,
    stylesheet: "reachable",
    script: "reachable",
    manifest: "valid",
  };
}

async function main() {
  const target = validateTargetUrl(
    process.env.IRONCORE_SMOKE_WEB_URL,
    process.env.IRONCORE_SMOKE_ALLOWED_HOSTS,
  );
  const expectedCommit = validateExpectedCommit(process.env.IRONCORE_EXPECTED_COMMIT);
  const waitMs = integerAtLeast(
    process.env.IRONCORE_SMOKE_DEPLOY_WAIT_MS,
    0,
    "IRONCORE_SMOKE_DEPLOY_WAIT_MS",
  );
  const retryMs = integerAtLeast(
    process.env.IRONCORE_SMOKE_RETRY_MS,
    15_000,
    "IRONCORE_SMOKE_RETRY_MS",
    1,
  );
  const deadline = Date.now() + waitMs;
  let attempt = 0;
  let lastError;

  do {
    attempt += 1;
    try {
      const result = await verifyDeployment(target, expectedCommit);
      console.log(JSON.stringify({ ...result, attempts: attempt }, null, 2));
      return;
    } catch (error) {
      lastError = error;
      if (Date.now() >= deadline) {
        break;
      }

      console.log(`Smoke attempt ${attempt} is not ready: ${error.message}`);
      await new Promise((resolve) => setTimeout(resolve, Math.min(retryMs, deadline - Date.now())));
    }
  } while (Date.now() <= deadline);

  throw lastError;
}

const invokedPath = process.argv[1] ? pathToFileURL(process.argv[1]).href : null;
if (invokedPath === import.meta.url) {
  main().catch((error) => {
    console.error(`Deployment smoke failed: ${error.message}`);
    process.exitCode = 1;
  });
}
