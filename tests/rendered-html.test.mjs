import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const developmentPreviewMeta =
  /<meta(?=[^>]*\bname=["']codex-preview["'])(?=[^>]*\bcontent=["']development["'])[^>]*>/i;
const developmentReleaseMeta =
  /<meta(?=[^>]*\bname=["']ironcore-release["'])(?=[^>]*\bcontent=["']development["'])[^>]*>/i;

test("renders development preview metadata", async () => {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}`);
  const { default: worker } = await import(workerUrl.href);

  const response = await worker.fetch(
    new Request("http://localhost/", {
      headers: { accept: "text/html" },
    }),
    {
      ASSETS: {
        fetch: async () => new Response("Not found", { status: 404 }),
      },
    },
    {
      waitUntil() {},
      passThroughOnException() {},
    },
  );

  assert.equal(response.status, 200);
  assert.match(
    response.headers.get("content-type") ?? "",
    /^text\/html\b/i,
  );
  const html = await response.text();
  assert.match(html, developmentPreviewMeta);
  assert.match(html, developmentReleaseMeta);
  assert.match(html, /IRONCORE/i);
  assert.match(html, /Good morning/i);
  assert.match(html, /Total members/i);
});

test("uses deterministic compact currency labels for server hydration", async () => {
  const dashboard = await readFile(new URL("../app/ironcore-dashboard.tsx", import.meta.url), "utf8");

  // Intl compact notation differs across ICU builds; fixed suffixes remain identical.
  assert.doesNotMatch(dashboard, /notation:\s*compact/);
  for (const suffix of ['"K"', '"M"', '"B"']) {
    assert.match(dashboard, new RegExp(suffix));
  }
  assert.match(dashboard, /SSR-safe suffixes/);
});

test("cleans environment-sensitive build caches before compiling", async () => {
  const buildScript = await readFile(new URL("../scripts/build-verified.sh", import.meta.url), "utf8");
  const installScript = await readFile(new URL("../scripts/install-ci.sh", import.meta.url), "utf8");
  const artifactScript = await readFile(new URL("../scripts/validate-artifact.sh", import.meta.url), "utf8");
  assert.match(buildScript, /rm -rf -- "\$\{SITES_PROJECT_ROOT\}\/dist" "\$\{SITES_PROJECT_ROOT\}\/\.vinext"/);
  assert.match(buildScript, /NEXT_PUBLIC values/);
  for (const script of [buildScript, installScript, artifactScript]) {
    assert.match(script, /exec bash "\$\{script_dir\}\/sites-env\.sh"/);
  }
  assert.match(buildScript, /bash "\$\{script_dir\}\/validate-artifact\.sh"/);
});
