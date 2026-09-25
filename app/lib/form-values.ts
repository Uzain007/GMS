const ISO_DATE_INPUT = /^\d{4}-\d{2}-\d{2}$/;

export function isoDateInputValue(form: FormData, field: string): string | undefined {
  const value = form.get(field);
  if (typeof value !== "string") return undefined;

  const normalized = value.trim();
  return ISO_DATE_INPUT.test(normalized) ? normalized : undefined;
}
