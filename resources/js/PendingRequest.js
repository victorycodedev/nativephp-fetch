import { cancel, downloadNative, start } from "./bridge.js";
import { requestId } from "./request-id.js";
import { normalizeRetry } from "./retry.js";

export class PendingRequest {
  constructor(id = requestId()) {
    this.requestId = id;
    this.headers = {};
    this.attachments = [];
    this.timeoutSeconds = 30;
    this.sendJson = true;
    this.retryPolicy = null;
  }

  id() {
    return this.requestId;
  }
  withHeaders(headers) {
    for (const [name, value] of Object.entries(headers))
      this.headers[String(name)] = String(value);
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
    if (this.attachments.length === 0)
      this.withHeader("Content-Type", "application/json");
    return this;
  }
  timeout(seconds) {
    if (!Number.isInteger(seconds) || seconds < 1)
      throw new TypeError("Fetch timeout must be a positive integer.");
    this.timeoutSeconds = seconds;
    return this;
  }
  retry(options = 3) {
    this.retryPolicy = normalizeRetry(options);
    return this;
  }
  attach(name, path, filename = null, mimeType = null) {
    if (typeof name !== "string" || name.trim() === "")
      throw new TypeError("Fetch attachment name cannot be empty.");
    if (typeof path !== "string" || path.trim() === "")
      throw new TypeError("Fetch attachment path cannot be empty.");
    this.attachments.push({
      field: name,
      path,
      filename: filename || path.split("/").pop(),
      mime_type: mimeType || "application/octet-stream",
    });
    for (const header of Object.keys(this.headers))
      if (header.toLowerCase() === "content-type") delete this.headers[header];
    return this;
  }
  attachMany(attachments) {
    if (!Array.isArray(attachments))
      throw new TypeError("Fetch attachments must be an array.");
    const normalized = attachments.map((attachment, index) => {
      if (!attachment || typeof attachment !== "object")
        throw new TypeError(
          `Fetch attachment at index ${index} must be an object.`,
        );
      const { name, path, filename = null, mimeType = null } = attachment;
      if (typeof name !== "string" || typeof path !== "string")
        throw new TypeError(
          `Fetch attachment at index ${index} requires string name and path values.`,
        );
      return { name, path, filename, mimeType };
    });
    for (const file of normalized)
      this.attach(file.name, file.path, file.filename, file.mimeType);
    return this;
  }
  get(url, query = {}) {
    if (this.attachments.length)
      throw new TypeError("Fetch attachments cannot be sent with GET.");
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
    if (typeof destination !== "string" || destination.trim() === "")
      throw new TypeError("Fetch download destination cannot be empty.");
    const { query = {}, overwrite = false } = options;
    return downloadNative({
      request_id: this.requestId,
      url,
      destination,
      headers: { ...this.headers },
      query,
      timeout: this.timeoutSeconds,
      overwrite: Boolean(overwrite),
      retry: this.retryPolicy ? { ...this.retryPolicy } : null,
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
      retry: this.retryPolicy ? { ...this.retryPolicy } : null,
    }).then(() => this.requestId);
  }
  bodyPayload(method, data) {
    if (method === "GET") return null;
    if (this.attachments.length) {
      const fields = {};
      for (const [name, value] of Object.entries(data))
        fields[name] =
          value === null
            ? ""
            : typeof value === "object"
              ? JSON.stringify(value)
              : String(value);
      return {
        type: "multipart",
        fields,
        files: this.attachments.map((file) => ({ ...file })),
      };
    }
    if (!data || Object.keys(data).length === 0) return null;
    return { type: this.sendJson ? "json" : "raw", data };
  }
}
