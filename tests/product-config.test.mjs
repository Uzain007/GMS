import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const configUrl = new URL("../config/ironcore.config.json", import.meta.url);
test("keeps the agreed scale and currency contract", async () => {
  const config = JSON.parse(await readFile(configUrl, "utf8"));
  assert.equal(config.product, "IronCore");
  assert.equal(config.architecture, "multi-tenant");
  assert.deepEqual(config.initialCapacity, { gyms: 100, members: 1_000_000 });
  assert.deepEqual(config.currencies, ["GBP", "USD", "PKR", "AED", "SAR"]);
});
test("audits sensitive financial changes", async () => {
  const config = JSON.parse(await readFile(configUrl, "utf8"));
  assert.ok(config.paymentMethods.includes("card"));
  assert.ok(config.paymentMethods.includes("cash"));
  assert.ok(config.auditRequiredFor.includes("manual_payment_create"));
  assert.ok(config.auditRequiredFor.includes("payment_status_change"));
  assert.ok(config.auditRequiredFor.includes("refund"));
});
test("defines platform and tenant roles", async () => {
  const config = JSON.parse(await readFile(configUrl, "utf8"));
  assert.equal(config.roles[0], "super_admin");
  assert.ok(config.roles.includes("gym_owner"));
  assert.ok(config.roles.includes("member"));
  assert.equal(new Set(config.roles).size, config.roles.length);
});
