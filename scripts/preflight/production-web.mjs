import { isIP } from "node:net";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

function isPublicHost(hostname) {
  const host = hostname.toLowerCase();
  if (
    host === "localhost" ||
    host.endsWith(".localhost") ||
    /\.(local|test|example|invalid)$/.test(host) ||
    /(^|\.)example\.(com|net|org)$/.test(host)
  ) {
    return false;
  }

  const ipVersion = isIP(host);
  if (ipVersion === 4) {
    const octets = host.split(".").map(Number);
    return !(
      octets[0] === 10 ||
      octets[0] === 127 ||
      octets[0] === 0 ||
      (octets[0] === 169 && octets[1] === 254) ||
      (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) ||
      (octets[0] === 192 && octets[1] === 168) ||
      (octets[0] === 100 && octets[1] >= 64 && octets[1] <= 127) ||
      (octets[0] === 192 && octets[1] === 0) ||
      (octets[0] === 198 && (octets[1] === 18 || octets[1] === 19)) ||
      (octets[0] === 198 && octets[1] === 51 && octets[2] === 100) ||
      (octets[0] === 203 && octets[1] === 0 && octets[2] === 113) ||
      octets[0] >= 224
    );
  }

  if (ipVersion === 6) {
    return !(
      host === "::" ||
      host === "::1" ||
      host.startsWith("fc") ||
      host.startsWith("fd") ||
      host.startsWith("fe8") ||
      host.startsWith("fe9") ||
      host.startsWith("fea") ||
      host.startsWith("feb") ||
      host.startsWith("2001:db8") ||
      host.startsWith("::ffff:")
    );
  }

  return host.includes(".") && /^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/.test(host);
}

function isPublicHttpsOrigin(value) {
  if (typeof value !== "string" || value.trim() === "") return false;

  try {
    const url = new URL(value.trim());
    return (
      url.protocol === "https:" &&
      url.username === "" &&
      url.password === "" &&
      url.search === "" &&
      url.hash === "" &&
      url.pathname === "/" &&
      isPublicHost(url.hostname)
    );
  } catch {
    return false;
  }
}

/**
 * Validate only public web-build settings. Stable messages intentionally omit
 * values so deployment logs cannot disclose an accidentally embedded secret.
 */
export function validateProductionWebEnvironment(environment) {
  const failures = [];

  if (environment.NODE_ENV !== "production") {
    failures.push("NODE_ENV must equal production.");
  }

  if (environment.NEXT_PUBLIC_IRONCORE_DEMO_MODE !== "false") {
    failures.push("NEXT_PUBLIC_IRONCORE_DEMO_MODE must explicitly equal false.");
  }

  if (!isPublicHttpsOrigin(environment.NEXT_PUBLIC_IRONCORE_API_URL)) {
    failures.push(
      "NEXT_PUBLIC_IRONCORE_API_URL must be a public HTTPS origin without credentials, query or fragment.",
    );
  }

  const release =
    environment.VERCEL_GIT_COMMIT_SHA ??
    environment.GITHUB_SHA ??
    environment.IRONCORE_RELEASE_SHA ??
    "";
  if (!/^[0-9a-f]{40}$/i.test(release)) {
    failures.push(
      "VERCEL_GIT_COMMIT_SHA, GITHUB_SHA or IRONCORE_RELEASE_SHA must provide the immutable full release SHA.",
    );
  }

  return failures;
}

const isDirectExecution =
  process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isDirectExecution) {
  const failures = validateProductionWebEnvironment(process.env);
  if (failures.length === 0) {
    console.log("IronCore production web preflight passed.");
  } else {
    console.error("IronCore production web preflight failed:");
    for (const failure of failures) console.error(` - ${failure}`);
    process.exitCode = 1;
  }
}
