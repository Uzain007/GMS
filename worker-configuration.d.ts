// Cloudflare's imported `env` is typed through this global binding contract.
// Values are injected by the hosting control plane and never hard-coded here.
declare namespace Cloudflare {
  interface Env {
    DB: D1Database;
  }
}
