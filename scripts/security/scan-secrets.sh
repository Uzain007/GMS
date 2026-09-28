#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# A missing scanner must fail the security gate instead of being mistaken for
# a clean repository.
if command -v rg >/dev/null 2>&1; then
  scanner="rg"
elif command -v rg.exe >/dev/null 2>&1; then
  scanner="rg.exe"
else
  echo "ripgrep (rg) is required for the live-secret scan." >&2
  exit 1
fi

# These signatures catch common live credentials and private keys without
# flagging intentionally blank variable names in committed example files.
cd "${project_root}"
set +e
"${scanner}" --hidden --glob '!.git/**' --glob '!node_modules/**' --glob '!dist/**' \
  '(sk_live_[A-Za-z0-9]{16,}|rk_live_[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|xox[baprs]-[A-Za-z0-9-]{16,})' \
  .
scan_status=$?
set -e

if [[ "${scan_status}" -eq 0 ]]; then
  echo "Potential live secret found in the project." >&2
  exit 1
fi

if [[ "${scan_status}" -ne 1 ]]; then
  echo "The live-secret scan could not be completed." >&2
  exit 1
fi

echo "No known live-secret signatures found."
