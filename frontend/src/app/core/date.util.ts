/**
 * Today's date as `YYYY-MM-DD` in the user's local timezone (not UTC, which can
 * be a day behind in Casablanca late in the evening).
 */
export function todayIso(): string {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);

  return local.toISOString().slice(0, 10);
}
