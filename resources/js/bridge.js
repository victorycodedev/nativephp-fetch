const baseUrl = "/_native/api/call";

function csrfToken() {
  return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content || "";
}

export async function bridgeCall(method, params = {}) {
  const response = await globalThis.fetch(baseUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken() },
    body: JSON.stringify({ method, params }),
  });

  if (!response.ok) throw new Error(`Native bridge returned HTTP ${response.status}`);

  const result = await response.json();
  if (result.status === "error") {
    const error = new Error(result.message || "Native call failed");
    error.code = result.code || null;
    throw error;
  }

  const nativeResponse = result.data ?? result;
  if (nativeResponse?.status === "error") {
    const error = new Error(nativeResponse.message || "Native call failed");
    error.code = nativeResponse.code || null;
    throw error;
  }

  return nativeResponse?.data ?? nativeResponse;
}

export const start = (parameters = {}) => bridgeCall("Fetch.Start", parameters);
export const downloadNative = (parameters = {}) => bridgeCall("Fetch.Download", parameters);
export async function cancel(id) {
  const response = await bridgeCall("Fetch.Cancel", { request_id: id });
  return Boolean(response?.cancelled);
}
