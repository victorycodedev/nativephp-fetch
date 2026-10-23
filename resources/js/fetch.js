/** Official JavaScript client for victorycodedev/nativephp-fetch. */
import { PendingRequest } from "./PendingRequest.js";
import {
  active,
  bridgeCall,
  cancel,
  cancelAll,
  downloadNative,
  isActive,
  start,
} from "./bridge.js";

export {
  PendingRequest,
  active,
  bridgeCall,
  cancel,
  cancelAll,
  downloadNative,
  isActive,
  start,
};
export const request = () => new PendingRequest();
export const withHeaders = (headers) => request().withHeaders(headers);
export const withHeader = (name, value) => request().withHeader(name, value);
export const withToken = (token, type = "Bearer") =>
  request().withToken(token, type);
export const acceptJson = () => request().acceptJson();
export const asJson = () => request().asJson();
export const asForm = () => request().asForm();
export const withBody = (body, contentType = "text/plain") =>
  request().withBody(body, contentType);
export const timeout = (seconds) => request().timeout(seconds);
export const retry = (options = 3) => request().retry(options);
export const shared = (event) => request().shared(event);
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
  asForm,
  withBody,
  timeout,
  retry,
  shared,
  attach,
  attachMany,
  get,
  post,
  put,
  patch,
  delete: del,
  download,
  cancel,
  active,
  isActive,
  cancelAll,
  start,
  downloadNative,
};
export { Fetch as fetch };
export default Fetch;
