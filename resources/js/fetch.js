/**
 * Official JavaScript client for victorycodedev/nativephp-fetch.
 * Intended for NativePHP v4 Inertia/Vue/React and legacy web-view apps.
 */

const baseUrl = "/_native/api/call";

function requestId() {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }

  return (
    "fetch-" +
    Date.now().toString(36) +
    "-" +
    Math.random().toString(36).slice(2)
  );
}

function csrfToken() {
  return (
    globalThis.document?.querySelector('meta[name="csrf-token"]')?.content || ""
  );
}

/**
 * Call a registered NativePHP bridge function.
 *
 * @param {string} method
 * @param {Record<string, unknown>} params
 * @returns {Promise<Record<string, unknown>>}
 */
export async function bridgeCall(method, params = {}) {
  const response = await globalThis.fetch(baseUrl, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrfToken(),
    },
    body: JSON.stringify({ method, params }),
  });

  if (!response.ok) {
    throw new Error(`Native bridge returned HTTP ${response.status}`);
  }

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

/** Low-level Fetch.Start bridge export required by the plugin manifest. */
export function start(parameters = {}) {
  return bridgeCall("Fetch.Start", parameters);
}

/** Low-level Fetch.Download bridge export required by the plugin manifest. */
export function downloadNative(parameters = {}) {
  return bridgeCall("Fetch.Download", parameters);
}

/** Cancel a request, upload, or download by request ID. */
export async function cancel(id) {
  const response = await bridgeCall("Fetch.Cancel", { request_id: id });
  return Boolean(response?.cancelled);
}

export class PendingRequest {
  constructor(id = requestId()) {
    this.requestId = id;
    this.headers = {};
    this.attachments = [];
    this.timeoutSeconds = 30;
    this.sendJson = true;
  }

  id() {
    return this.requestId;
  }

  withHeaders(headers) {
    for (const [name, value] of Object.entries(headers)) {
      this.headers[String(name)] = String(value);
    }

    return this;
  }

  withHeader(name, value) {
    this.headers[String(name)] = String(value);
    return this;
  }

  withToken(token, type = "Bearer") {
    return this.withHeader("Authorization", `${String(type).trim()} ${token}`);
  }

  acceptJson() {
    return this.withHeader("Accept", "application/json");
  }

  asJson() {
    this.sendJson = true;

    if (this.attachments.length === 0) {
      this.withHeader("Content-Type", "application/json");
    }

    return this;
  }

  timeout(seconds) {
    if (!Number.isInteger(seconds) || seconds < 1) {
      throw new TypeError("Fetch timeout must be a positive integer.");
    }

    this.timeoutSeconds = seconds;
    return this;
  }

  attach(name, path, filename = null, mimeType = null) {
    if (typeof name !== "string" || name.trim() === "") {
      throw new TypeError("Fetch attachment name cannot be empty.");
    }

    if (typeof path !== "string" || path.trim() === "") {
      throw new TypeError("Fetch attachment path cannot be empty.");
    }

    this.attachments.push({
      field: name,
      path,
      filename: filename || path.split("/").pop(),
      mime_type: mimeType || "application/octet-stream",
    });

    for (const header of Object.keys(this.headers)) {
      if (header.toLowerCase() === "content-type") {
        delete this.headers[header];
      }
    }

    return this;
  }

  attachMany(attachments) {
    if (!Array.isArray(attachments)) {
      throw new TypeError("Fetch attachments must be an array.");
    }

    const normalized = attachments.map((attachment, index) => {
      if (!attachment || typeof attachment !== "object") {
        throw new TypeError(
          `Fetch attachment at index ${index} must be an object.`,
        );
      }

      const { name, path, filename = null, mimeType = null } = attachment;

      if (typeof name !== "string" || typeof path !== "string") {
        throw new TypeError(
          `Fetch attachment at index ${index} requires string name and path values.`,
        );
      }

      return { name, path, filename, mimeType };
    });

    for (const attachment of normalized) {
      this.attach(
        attachment.name,
        attachment.path,
        attachment.filename,
        attachment.mimeType,
      );
    }

    return this;
  }

  get(url, query = {}) {
    if (this.attachments.length > 0) {
      throw new TypeError("Fetch attachments cannot be sent with GET.");
    }

    return this.send("GET", url, query, {});
  }

  post(url, data = {}) {
    return this.send("POST", url, {}, data);
  }

  put(url, data = {}) {
    return this.send("PUT", url, {}, data);
  }

  patch(url, data = {}) {
    return this.send("PATCH", url, {}, data);
  }

  delete(url, data = {}) {
    return this.send("DELETE", url, {}, data);
  }

  download(url, destination, options = {}) {
    if (typeof destination !== "string" || destination.trim() === "") {
      throw new TypeError("Fetch download destination cannot be empty.");
    }

    const { query = {}, overwrite = false } = options;

    return downloadNative({
      request_id: this.requestId,
      url,
      destination,
      headers: { ...this.headers },
      query,
      timeout: this.timeoutSeconds,
      overwrite: Boolean(overwrite),
    }).then(() => this.requestId);
  }

  cancel() {
    return cancel(this.requestId);
  }

  send(method, url, query, data) {
    return start({
      request_id: this.requestId,
      method,
      url,
      headers: { ...this.headers },
      query,
      timeout: this.timeoutSeconds,
      body: this.bodyPayload(method, data),
    }).then(() => this.requestId);
  }

  bodyPayload(method, data) {
    if (method === "GET") {
      return null;
    }

    if (this.attachments.length > 0) {
      const fields = {};

      for (const [name, value] of Object.entries(data)) {
        fields[name] =
          value === null
            ? ""
            : typeof value === "object"
              ? JSON.stringify(value)
              : String(value);
      }

      return {
        type: "multipart",
        fields,
        files: this.attachments.map((file) => ({ ...file })),
      };
    }

    if (!data || Object.keys(data).length === 0) {
      return null;
    }

    return { type: this.sendJson ? "json" : "raw", data };
  }
}

export function request() {
  return new PendingRequest();
}

export const withHeaders = (headers) => request().withHeaders(headers);
export const withHeader = (name, value) => request().withHeader(name, value);
export const withToken = (token, type = "Bearer") =>
  request().withToken(token, type);
export const acceptJson = () => request().acceptJson();
export const asJson = () => request().asJson();
export const timeout = (seconds) => request().timeout(seconds);
export const attach = (name, path, filename = null, mimeType = null) =>
  request().attach(name, path, filename, mimeType);
export const attachMany = (attachments) => request().attachMany(attachments);

export const get = (url, query = {}) => request().get(url, query);
export const post = (url, data = {}) => request().post(url, data);
export const put = (url, data = {}) => request().put(url, data);
export const patch = (url, data = {}) => request().patch(url, data);
export const del = (url, data = {}) => request().delete(url, data);
export { del as delete };
export const download = (url, destination, options = {}) =>
  request().download(url, destination, options);

export const Fetch = {
  request,
  withHeaders,
  withHeader,
  withToken,
  acceptJson,
  asJson,
  timeout,
  attach,
  attachMany,
  get,
  post,
  put,
  patch,
  delete: del,
  download,
  cancel,
  start,
  downloadNative,
};

export { Fetch as fetch };
export default Fetch;
