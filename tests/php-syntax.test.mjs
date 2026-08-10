import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import PhpParser from "php-parser";

const backend = path.resolve("backend");
const parser = new PhpParser.Engine({
  parser: { extractDoc: true, suppressErrors: false },
  ast: { withPositions: true },
});

async function phpFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    if (["vendor", "storage", "bootstrap/cache"].includes(entry.name)) continue;
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...await phpFiles(target));
    else if (entry.name.endsWith(".php")) files.push(target);
  }
  return files;
}

test("every Laravel source and migration file has valid PHP syntax", async () => {
  const files = await phpFiles(backend);
  assert.ok(files.length >= 20, "Expected the Laravel backend source tree");

  for (const file of files) {
    const source = await readFile(file, "utf8");
    assert.doesNotThrow(() => parser.parseCode(source, file), `Invalid PHP syntax in ${file}`);
  }
});
