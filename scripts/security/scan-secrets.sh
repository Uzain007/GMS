#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# These signatures catch common live credentials and private keys without
# flagging intentionally blank variable names in committed example files.
if rg --hidden --glob '!.git/**' --glob '!node_modules/**' --glob '!dist/**' \
  '(sk_live_[A-Za-z0-9]{16,}|rk_live_[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{16}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|xox[baprs]-[A-Za-z0-9-]{16,})' \
  "${project_root}"; then
  echo "Potential live secret found in the project." >&2
  exit 1
fi

echo "No known live-secret signatures found."
