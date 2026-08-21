const localDateTimePattern = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;

function zonedParts(value: Date, timeZone: string): Record<string, number> {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23",
  }).formatToParts(value);

  return Object.fromEntries(parts
    .filter((part) => part.type !== "literal")
    .map((part) => [part.type, Number(part.value)]));
}

export function zonedLocalDateTimeToIso(value: string, timeZone: string): string {
  const match = localDateTimePattern.exec(value);
  if (!match) throw new Error("Choose a valid class date and time.");

  const [, year, month, day, hour, minute] = match.map(Number);
  const intendedUtc = Date.UTC(year, month - 1, day, hour, minute, 0);
  let candidate = intendedUtc;

  // datetime-local has no zone. Resolve its wall-clock fields against the
  // selected gym zone twice so daylight-saving offsets settle correctly.
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const shown = zonedParts(new Date(candidate), timeZone);
    const shownAsUtc = Date.UTC(shown.year, shown.month - 1, shown.day, shown.hour, shown.minute, shown.second);
    candidate -= shownAsUtc - intendedUtc;
  }

  const resolved = zonedParts(new Date(candidate), timeZone);
  if (resolved.year !== year || resolved.month !== month || resolved.day !== day || resolved.hour !== hour || resolved.minute !== minute) {
    throw new Error("That local time does not exist in the gym timezone. Choose another time.");
  }

  return new Date(candidate).toISOString();
}

export function formatGymDateTime(value: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone,
    weekday: "short",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

export function formatGymTime(value: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone,
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

export function formatGymDay(value: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-GB", { timeZone, day: "numeric" }).format(new Date(value));
}

export function formatGymMonth(value: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-GB", { timeZone, month: "short" }).format(new Date(value));
}
