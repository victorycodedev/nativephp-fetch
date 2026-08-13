export function requestId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();

  return `fetch-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
