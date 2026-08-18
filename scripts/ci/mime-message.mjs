function splitEntity(entity) {
  const normalized = entity.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  const separator = normalized.indexOf("\n\n");

  if (separator === -1) {
    return { headers: new Map(), body: normalized };
  }

  const headers = new Map();
  let currentName = null;
  for (const line of normalized.slice(0, separator).split("\n")) {
    if (/^[ \t]/.test(line) && currentName) {
      headers.set(currentName, `${headers.get(currentName)} ${line.trim()}`);
      continue;
    }

    const colon = line.indexOf(":");
    if (colon <= 0) continue;
    currentName = line.slice(0, colon).trim().toLowerCase();
    headers.set(currentName, line.slice(colon + 1).trim());
  }

  return { headers, body: normalized.slice(separator + 2) };
}

function boundaryFrom(contentType) {
  const match = contentType.match(
    /(?:^|;)\s*boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i,
  );
  return match ? (match[1] ?? match[2]) : null;
}

function multipartParts(body, boundary) {
  const delimiter = `--${boundary}`;
  const closingDelimiter = `${delimiter}--`;
  const parts = [];
  let current = null;

  for (const line of body.split("\n")) {
    const boundaryCandidate = line.replace(/[ \t]+$/, "");
    if (boundaryCandidate === delimiter) {
      if (current !== null) parts.push(current.join("\r\n"));
      current = [];
      continue;
    }
    if (boundaryCandidate === closingDelimiter) {
      if (current !== null) parts.push(current.join("\r\n"));
      current = null;
      break;
    }
    if (current !== null) current.push(line);
  }

  if (current !== null) parts.push(current.join("\r\n"));
  return parts.filter((part) => part.trim() !== "");
}

function decodeQuotedPrintable(body) {
  const unfolded = body.replace(/=\n/g, "");
  const bytes = [];

  for (let index = 0; index < unfolded.length;) {
    const encodedByte = unfolded.slice(index, index + 3);
    if (/^=[0-9a-f]{2}$/i.test(encodedByte)) {
      bytes.push(Number.parseInt(encodedByte.slice(1), 16));
      index += 3;
      continue;
    }

    const codePoint = unfolded.codePointAt(index);
    const character = String.fromCodePoint(codePoint);
    for (const byte of Buffer.from(character, "utf8")) bytes.push(byte);
    index += character.length;
  }

  return Buffer.from(bytes).toString("utf8");
}

function decodeBody(body, transferEncoding) {
  switch (transferEncoding.trim().toLowerCase()) {
    case "quoted-printable":
      return decodeQuotedPrintable(body);
    case "base64": {
      const compact = body.replace(/\s+/g, "");
      if (!/^(?:[a-z0-9+/]{4})*(?:[a-z0-9+/]{2}==|[a-z0-9+/]{3}=)?$/i.test(compact)) {
        return null;
      }
      return Buffer.from(compact, "base64").toString("utf8");
    }
    case "":
    case "7bit":
    case "8bit":
    case "binary":
      return body;
    default:
      return null;
  }
}

function collectTextParts(entity, decodedParts) {
  const { headers, body } = splitEntity(entity);
  const contentType = headers.get("content-type") ?? "text/plain";
  const mediaType = contentType.split(";", 1)[0].trim().toLowerCase();

  if (mediaType.startsWith("multipart/")) {
    const boundary = boundaryFrom(contentType);
    if (!boundary) return;
    for (const part of multipartParts(body, boundary)) {
      collectTextParts(part, decodedParts);
    }
    return;
  }

  if (!mediaType.startsWith("text/")) return;
  const content = decodeBody(
    body,
    headers.get("content-transfer-encoding") ?? "",
  );
  if (content === null) return;

  decodedParts.push({ media_type: mediaType, content });
}

export function decodeMimeTextParts(message) {
  const decodedParts = [];
  collectTextParts(message, decodedParts);
  return decodedParts;
}
