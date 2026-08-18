import { timingSafeEqual } from "node:crypto";
import { readFile } from "node:fs/promises";
import https from "node:https";
import net from "node:net";

import { decodeMimeTextParts } from "./mime-message.mjs";

const host = "127.0.0.1";

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error(`${name} is required.`);
  return value;
}

function port(name) {
  const value = Number.parseInt(required(name), 10);
  if (!Number.isInteger(value) || value < 1024 || value > 65535) {
    throw new Error(`${name} must be a non-privileged TCP port.`);
  }
  return value;
}

function equalSecret(actual, expected) {
  const left = Buffer.from(actual);
  const right = Buffer.from(expected);
  return left.length === right.length && timingSafeEqual(left, right);
}

const smtpPort = port("IRONCORE_NOTIFICATION_RUNTIME_SMTP_PORT");
const httpsPort = port("IRONCORE_NOTIFICATION_RUNTIME_HTTPS_PORT");
const smtpUsername = required("MAIL_USERNAME");
const smtpPassword = required("MAIL_PASSWORD");
const smsToken = required("NOTIFICATION_SMS_TOKEN");
const pushToken = required("NOTIFICATION_PUSH_TOKEN");
const evidenceToken = required("IRONCORE_NOTIFICATION_RUNTIME_EVIDENCE_TOKEN");
const rejectionMarker = required("IRONCORE_NOTIFICATION_RUNTIME_REJECTION_MARKER");
const certificate = await readFile(required("IRONCORE_NOTIFICATION_RUNTIME_CERTIFICATE"));
const privateKey = await readFile(required("IRONCORE_NOTIFICATION_RUNTIME_PRIVATE_KEY"));

const evidence = {
  smtp: [],
  sms: [],
  push: [],
  rejections: 0,
};

function decodeBase64(value) {
  try {
    return Buffer.from(value, "base64").toString("utf8");
  } catch {
    return "";
  }
}

function validPlainCredentials(value) {
  const [authorizationId = "", username = "", password = ""] =
    decodeBase64(value).split("\0");
  return authorizationId === ""
    && equalSecret(username, smtpUsername)
    && equalSecret(password, smtpPassword);
}

const smtpServer = net.createServer((socket) => {
  socket.setEncoding("utf8");
  socket.setTimeout(15_000);

  let buffer = "";
  let authenticated = false;
  let authenticationStage = null;
  let pendingUsername = "";
  let mailFrom = "";
  let recipients = [];
  let dataLines = null;

  const reply = (line) => socket.write(`${line}\r\n`);
  const resetEnvelope = () => {
    mailFrom = "";
    recipients = [];
    dataLines = null;
  };

  const authenticationFailed = () => {
    authenticationStage = null;
    pendingUsername = "";
    reply("535 5.7.8 Authentication credentials invalid");
  };

  const handleAuthentication = (line) => {
    if (authenticationStage === "plain") {
      if (validPlainCredentials(line)) {
        authenticated = true;
        authenticationStage = null;
        reply("235 2.7.0 Authentication successful");
      } else {
        authenticationFailed();
      }
      return true;
    }

    if (authenticationStage === "login-username") {
      pendingUsername = decodeBase64(line);
      authenticationStage = "login-password";
      reply("334 UGFzc3dvcmQ6");
      return true;
    }

    if (authenticationStage === "login-password") {
      const password = decodeBase64(line);
      if (equalSecret(pendingUsername, smtpUsername)
        && equalSecret(password, smtpPassword)) {
        authenticated = true;
        authenticationStage = null;
        pendingUsername = "";
        reply("235 2.7.0 Authentication successful");
      } else {
        authenticationFailed();
      }
      return true;
    }

    return false;
  };

  const handleLine = (line) => {
    if (dataLines) {
      if (line === ".") {
        const raw = dataLines.join("\r\n");
        evidence.smtp.push({
          mail_from: mailFrom,
          recipients: [...recipients],
          raw,
          // Decoded bodies remain inside the authenticated, in-memory CI
          // evidence boundary and are never written to provider logs.
          text_parts: decodeMimeTextParts(raw),
        });
        resetEnvelope();
        reply("250 2.0.0 Message accepted for delivery");
      } else {
        dataLines.push(line.startsWith("..") ? line.slice(1) : line);
      }
      return;
    }

    if (handleAuthentication(line)) return;

    const [verb = ""] = line.trim().split(/\s+/, 1);
    switch (verb.toUpperCase()) {
      case "EHLO":
      case "HELO":
        reply("250-ironcore-runtime.local");
        reply("250-AUTH PLAIN LOGIN");
        reply("250 8BITMIME");
        break;
      case "AUTH": {
        const [, mechanism = "", initial = ""] = line.split(/\s+/, 3);
        if (mechanism.toUpperCase() === "PLAIN") {
          if (initial) {
            if (validPlainCredentials(initial)) {
              authenticated = true;
              reply("235 2.7.0 Authentication successful");
            } else {
              authenticationFailed();
            }
          } else {
            authenticationStage = "plain";
            reply("334");
          }
        } else if (mechanism.toUpperCase() === "LOGIN") {
          authenticationStage = "login-username";
          reply("334 VXNlcm5hbWU6");
        } else {
          reply("504 5.5.4 Unsupported authentication mechanism");
        }
        break;
      }
      case "MAIL": {
        if (!authenticated) {
          reply("530 5.7.0 Authentication required");
          break;
        }
        const match = line.match(/^MAIL FROM:\s*<([^>]*)>/i);
        if (!match) {
          reply("501 5.5.4 Invalid sender");
          break;
        }
        mailFrom = match[1];
        recipients = [];
        reply("250 2.1.0 Sender accepted");
        break;
      }
      case "RCPT": {
        const match = line.match(/^RCPT TO:\s*<([^>]+)>/i);
        if (!mailFrom || !match) {
          reply("503 5.5.1 Sender required before recipient");
          break;
        }
        recipients.push(match[1]);
        reply("250 2.1.5 Recipient accepted");
        break;
      }
      case "DATA":
        if (!mailFrom || recipients.length === 0) {
          reply("503 5.5.1 Sender and recipient required");
        } else {
          dataLines = [];
          reply("354 End data with <CR><LF>.<CR><LF>");
        }
        break;
      case "RSET":
        resetEnvelope();
        reply("250 2.0.0 Reset");
        break;
      case "NOOP":
        reply("250 2.0.0 OK");
        break;
      case "QUIT":
        socket.end("221 2.0.0 Closing connection\r\n");
        break;
      default:
        reply("502 5.5.2 Command not implemented");
    }
  };

  socket.on("data", (chunk) => {
    buffer += chunk;
    while (buffer.includes("\n")) {
      const newline = buffer.indexOf("\n");
      const line = buffer.slice(0, newline).replace(/\r$/, "");
      buffer = buffer.slice(newline + 1);
      handleLine(line);
    }
  });
  socket.on("timeout", () => socket.destroy());
  socket.on("error", () => {});
  reply("220 ironcore-runtime.local ESMTP ready");
});

function respondJson(response, status, value) {
  const body = JSON.stringify(value);
  response.writeHead(status, {
    "content-type": "application/json",
    "content-length": Buffer.byteLength(body),
    "cache-control": "no-store",
  });
  response.end(body);
}

function bearer(request) {
  const authorization = request.headers.authorization ?? "";
  return authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
}

async function jsonBody(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > 64 * 1024) throw new Error("Request body is too large.");
    chunks.push(chunk);
  }
  return JSON.parse(Buffer.concat(chunks).toString("utf8"));
}

const httpsServer = https.createServer({ cert: certificate, key: privateKey }, async (request, response) => {
  const url = new URL(request.url ?? "/", `https://${host}:${httpsPort}`);

  if (request.method === "GET" && url.pathname === "/health") {
    respondJson(response, 200, { status: "ready" });
    return;
  }

  if (url.pathname === "/_evidence" || url.pathname === "/_reset") {
    if (!equalSecret(bearer(request), evidenceToken)) {
      respondJson(response, 401, { error: "unauthorized" });
      return;
    }
    if (request.method === "GET" && url.pathname === "/_evidence") {
      respondJson(response, 200, evidence);
      return;
    }
    if (request.method === "POST" && url.pathname === "/_reset") {
      evidence.smtp.length = 0;
      evidence.sms.length = 0;
      evidence.push.length = 0;
      evidence.rejections = 0;
      respondJson(response, 200, { status: "reset" });
      return;
    }
    respondJson(response, 405, { error: "method_not_allowed" });
    return;
  }

  const channel = url.pathname.startsWith("/sms") ? "sms"
    : url.pathname.startsWith("/push") ? "push"
      : null;
  const expectedToken = channel === "sms" ? smsToken : pushToken;
  if (!channel || request.method !== "POST") {
    respondJson(response, 404, { error: "not_found" });
    return;
  }
  if (!equalSecret(bearer(request), expectedToken)) {
    respondJson(response, 401, { error: "unauthorized" });
    return;
  }

  try {
    const body = await jsonBody(request);
    if (url.pathname.endsWith("/reject")) {
      evidence.rejections += 1;
      respondJson(response, 503, { error: rejectionMarker });
      return;
    }
    evidence[channel].push(body);
    respondJson(response, 202, { id: `ci-${channel}-${evidence[channel].length}` });
  } catch {
    respondJson(response, 400, { error: "invalid_request" });
  }
});

httpsServer.on("tlsClientError", () => {});

await Promise.all([
  new Promise((resolve, reject) => {
    smtpServer.once("error", reject);
    smtpServer.listen(smtpPort, host, resolve);
  }),
  new Promise((resolve, reject) => {
    httpsServer.once("error", reject);
    httpsServer.listen(httpsPort, host, resolve);
  }),
]);

console.log("IronCore disposable notification provider boundary is ready.");

function shutdown() {
  smtpServer.close();
  httpsServer.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 1_000).unref();
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
