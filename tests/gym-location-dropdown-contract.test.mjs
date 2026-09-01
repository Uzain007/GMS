import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

const root = process.cwd();
const read = (path) => readFile(join(root, path), "utf8");

test("all live gym and branch settings use canonical searchable location fields", async () => {
  const [portal, dashboard, operations, api, select] = await Promise.all([
    read("app/platform-portal.tsx"),
    read("app/ironcore-dashboard.tsx"),
    read("app/tenant-operations.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("app/searchable-select.tsx"),
  ]);

  assert.equal((portal.match(/<SearchableSelect label="Country & calling code"/g) ?? []).length, 2);
  assert.equal((portal.match(/<SearchableSelect label="Timezone"/g) ?? []).length, 2);
  assert.equal((dashboard.match(/<SearchableSelect label="Country & calling code"/g) ?? []).length, 1);
  assert.equal((dashboard.match(/<SearchableSelect label="Timezone"/g) ?? []).length, 1);
  assert.equal((operations.match(/<SearchableSelect label="Timezone"/g) ?? []).length, 2);
  assert.doesNotMatch(`${portal}\n${dashboard}`, /(?:Country|Country code)<input/);
  assert.doesNotMatch(`${portal}\n${dashboard}\n${operations}`, /Timezone<input/);
  assert.doesNotMatch(portal, /Timezone<input/);
  assert.match(portal, /<label>Currency<select name="base_currency"/);
  assert.match(dashboard, /<label>Current currency<select name="base_currency"/);
  assert.match(operations, /gymTimezone: string/);
  assert.match(api, /timezone\?: string/);
  assert.match(select, /<input type="hidden" name=\{name\} value=\{value\}/);
  assert.match(select, /role="combobox"/);
  assert.match(select, /role="listbox"/);
});

test("country and timezone directories include required markets and reject legacy free text", async () => {
  const locations = await read("app/gym-location-options.ts");

  assert.match(locations, /\["PK", "Pakistan", "\+92"\]/);
  assert.match(locations, /\["GB", "United Kingdom", "\+44"\]/);
  assert.match(locations, /\["AE", "United Arab Emirates", "\+971"\]/);
  assert.match(locations, /supportedValuesOf\?\.\("timeZone"\)/);
  assert.match(locations, /AE: "Asia\/Dubai", GB: "Europe\/London", PK: "Asia\/Karachi"/);
  assert.match(locations, /new Intl\.DateTimeFormat\("en", \{ timeZone: candidate \}\)/);
  assert.match(locations, /Legacy free text such as "GMT \+5" falls back/);
});
