import Foundation

private final class FetchClient: NSObject, URLSessionTaskDelegate {

    static let shared = FetchClient()

    private static let eventStarted =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestStarted"

    private static let eventCompleted =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCompleted"

    private static let eventFailed =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestFailed"

    private static let eventCancelled =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCancelled"

    private static let eventUploadProgress =
        "Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress"

    private let lock = NSLock()

    private var tasks: [String: URLSessionTask] = [:]

    private var requestIdsByTaskIdentifier: [Int: String] = [:]

    private var temporaryUploadFiles: [String: URL] = [:]

    private lazy var session: URLSession = {
        URLSession(
            configuration: .default,
            delegate: self,
            delegateQueue: nil
        )
    }()

    private override init() {
        super.init()
    }

    func start(
        requestId: String,
        method: String,
        urlString: String,
        headers: [String: String],
        query: [String: Any],
        body: [String: Any]?,
        timeout: TimeInterval
    ) throws {

        guard var components = URLComponents(string: urlString) else {
            throw FetchNativeError.invalidURL
        }

        var queryItems = components.queryItems ?? []

        for (name, value) in query {
            if let values = value as? [Any] {
                for item in values {
                    queryItems.append(
                        URLQueryItem(
                            name: name,
                            value: String(describing: item)
                        )
                    )
                }
            } else {
                queryItems.append(
                    URLQueryItem(
                        name: name,
                        value: String(describing: value)
                    )
                )
            }
        }

        if !queryItems.isEmpty {
            components.queryItems = queryItems
        }

        guard let url = components.url else {
            throw FetchNativeError.invalidURL
        }

        let normalizedMethod = method.uppercased()

        let supportedMethods = [
            "GET",
            "POST",
            "PUT",
            "PATCH",
            "DELETE",
        ]

        guard supportedMethods.contains(normalizedMethod) else {
            emitFailure(
                requestId: requestId,
                message: "HTTP method \(method) is not supported.",
                code: "unsupported_method"
            )

            return
        }

        var request = URLRequest(
            url: url,
            cachePolicy: .useProtocolCachePolicy,
            timeoutInterval: timeout
        )

        request.httpMethod = normalizedMethod

        for (name, value) in headers {
            request.setValue(
                value,
                forHTTPHeaderField: name
            )
        }

        if let body,
           body["type"] as? String == "multipart" {

            let multipart = try buildMultipartFile(
                requestId: requestId,
                body: body
            )

            request.setValue(
                "multipart/form-data; boundary=\(multipart.boundary)",
                forHTTPHeaderField: "Content-Type"
            )

            let task = session.uploadTask(
                with: request,
                fromFile: multipart.fileURL
            ) { [weak self] data, response, error in
                self?.handleCompletion(
                    requestId: requestId,
                    data: data,
                    response: response,
                    error: error
                )
            }

            storeTask(
                task,
                requestId: requestId,
                temporaryUploadFile: multipart.fileURL
            )

            emitStarted(
                requestId: requestId,
                method: normalizedMethod,
                url: url.absoluteString
            )

            task.resume()

            return
        }

        if let body {
            try applyBody(
                body,
                to: &request
            )
        }

        let task = session.dataTask(
            with: request
        ) { [weak self] data, response, error in

            self?.handleCompletion(
                requestId: requestId,
                data: data,
                response: response,
                error: error
            )
        }

        storeTask(
            task,
            requestId: requestId
        )

        emitStarted(
            requestId: requestId,
            method: normalizedMethod,
            url: url.absoluteString
        )

        task.resume()
    }

    func cancel(
        requestId: String
    ) -> Bool {

        lock.lock()

        let task =
            tasks[requestId]

        lock.unlock()

        guard let task else {
            return false
        }

        task.cancel()

        return true
    }

    func urlSession(
        _ session: URLSession,
        task: URLSessionTask,
        didSendBodyData bytesSent: Int64,
        totalBytesSent: Int64,
        totalBytesExpectedToSend: Int64
    ) {
        lock.lock()

        let requestId =
            requestIdsByTaskIdentifier[
                task.taskIdentifier
            ]

        lock.unlock()

        guard let requestId else {
            return
        }

        let total =
            max(totalBytesExpectedToSend, 0)

        let progress: Double =
            total > 0
                ? min(
                    max(
                        Double(totalBytesSent)
                            / Double(total),
                        0
                    ),
                    1
                )
                : 0

        emitUploadProgress(
            requestId: requestId,
            bytesSent: totalBytesSent,
            bytesTotal: total,
            progress: progress
        )
    }

    private func handleCompletion(
        requestId: String,
        data: Data?,
        response: URLResponse?,
        error: Error?
    ) {
        cleanupTask(
            requestId: requestId
        )

        if let urlError = error as? URLError {

            if urlError.code == .cancelled {
                emitCancelled(
                    requestId: requestId
                )

                return
            }

            emitFailure(
                requestId: requestId,
                message: urlError.localizedDescription,
                code: failureCode(urlError)
            )

            return
        }

        if let error {
            emitFailure(
                requestId: requestId,
                message: error.localizedDescription,
                code: "network_error"
            )

            return
        }

        guard let httpResponse =
            response as? HTTPURLResponse
        else {
            emitFailure(
                requestId: requestId,
                message: "The server returned an invalid HTTP response.",
                code: "invalid_response"
            )

            return
        }

        let responseBody: String

        if let data {
            responseBody = String(
                data: data,
                encoding: .utf8
            ) ?? ""
        } else {
            responseBody = ""
        }

        var responseHeaders: [String: String] = [:]

        for (key, value) in httpResponse.allHeaderFields {
            responseHeaders[
                String(describing: key)
            ] = String(describing: value)
        }

        emitCompleted(
            requestId: requestId,
            status: httpResponse.statusCode,
            headers: responseHeaders,
            body: responseBody
        )
    }

    private func applyBody(
        _ body: [String: Any],
        to request: inout URLRequest
    ) throws {
        guard let type = body["type"] as? String else {
            return
        }

        guard type == "json" else {
            return
        }

        guard let data = body["data"] else {
            return
        }

        guard JSONSerialization.isValidJSONObject(data) else {
            throw FetchNativeError.invalidBody
        }

        request.httpBody =
            try JSONSerialization.data(
                withJSONObject: data
            )

        if request.value(
            forHTTPHeaderField: "Content-Type"
        ) == nil {
            request.setValue(
                "application/json; charset=utf-8",
                forHTTPHeaderField: "Content-Type"
            )
        }
    }

    private func buildMultipartFile(
        requestId: String,
        body: [String: Any]
    ) throws -> MultipartBuildResult {

        let boundary =
            "Fetch-\(UUID().uuidString)"

        let temporaryURL =
            FileManager.default.temporaryDirectory
                .appendingPathComponent(
                    "fetch-upload-\(requestId).tmp"
                )

        FileManager.default.createFile(
            atPath: temporaryURL.path,
            contents: nil
        )

        guard let output =
            try? FileHandle(
                forWritingTo: temporaryURL
            )
        else {
            throw FetchNativeError.multipartBuildFailed
        }

        do {
            defer {
                try? output.close()
            }

            let fields =
                body["fields"]
                    as? [String: Any]
                ?? [:]

            for (name, value) in fields {
                try writeString(
                    "--\(boundary)\r\n",
                    to: output
                )

                try writeString(
                    "Content-Disposition: form-data; name=\"\(escapeHeaderValue(name))\"\r\n\r\n",
                    to: output
                )

                try writeString(
                    "\(value)\r\n",
                    to: output
                )
            }

            let files =
                body["files"]
                    as? [[String: Any]]
                ?? []

            for fileSpec in files {

                guard let field =
                    fileSpec["field"] as? String,
                      let path =
                    fileSpec["path"] as? String
                else {
                    throw FetchNativeError.invalidFile
                }

                let filename =
                    fileSpec["filename"] as? String
                    ?? URL(fileURLWithPath: path)
                        .lastPathComponent

                let mimeType =
                    fileSpec["mime_type"] as? String
                    ?? "application/octet-stream"

                let sourceURL =
                    URL(fileURLWithPath: path)

                guard FileManager.default
                    .fileExists(
                        atPath: sourceURL.path
                    )
                else {
                    throw FetchNativeError.fileNotFound(
                        path
                    )
                }

                try writeString(
                    "--\(boundary)\r\n",
                    to: output
                )

                try writeString(
                    "Content-Disposition: form-data; name=\"\(escapeHeaderValue(field))\"; filename=\"\(escapeHeaderValue(filename))\"\r\n",
                    to: output
                )

                try writeString(
                    "Content-Type: \(mimeType)\r\n\r\n",
                    to: output
                )

                let input =
                    try FileHandle(
                        forReadingFrom: sourceURL
                    )

                defer {
                    try? input.close()
                }

                while true {
                    let chunk =
                        try input.read(
                            upToCount: 64 * 1024
                        )

                    guard let chunk,
                          !chunk.isEmpty
                    else {
                        break
                    }

                    try output.write(
                        contentsOf: chunk
                    )
                }

                try writeString(
                    "\r\n",
                    to: output
                )
            }

            try writeString(
                "--\(boundary)--\r\n",
                to: output
            )
        } catch {
            try? FileManager.default
                .removeItem(
                    at: temporaryURL
                )

            throw error
        }

        return MultipartBuildResult(
            fileURL: temporaryURL,
            boundary: boundary
        )
    }

    private func writeString(
        _ value: String,
        to handle: FileHandle
    ) throws {
        guard let data =
            value.data(
                using: .utf8
            )
        else {
            throw FetchNativeError.multipartBuildFailed
        }

        try handle.write(
            contentsOf: data
        )
    }

    private func escapeHeaderValue(
        _ value: String
    ) -> String {
        value
            .replacingOccurrences(
                of: "\\",
                with: "\\\\"
            )
            .replacingOccurrences(
                of: "\"",
                with: "\\\""
            )
            .replacingOccurrences(
                of: "\r",
                with: ""
            )
            .replacingOccurrences(
                of: "\n",
                with: ""
            )
    }

    private func storeTask(
        _ task: URLSessionTask,
        requestId: String,
        temporaryUploadFile: URL? = nil
    ) {
        lock.lock()

        tasks[requestId] = task

        requestIdsByTaskIdentifier[
            task.taskIdentifier
        ] = requestId

        if let temporaryUploadFile {
            temporaryUploadFiles[
                requestId
            ] = temporaryUploadFile
        }

        lock.unlock()
    }

    private func cleanupTask(
        requestId: String
    ) {
        lock.lock()

        let task =
            tasks.removeValue(
                forKey: requestId
            )

        if let task {
            requestIdsByTaskIdentifier
                .removeValue(
                    forKey: task.taskIdentifier
                )
        }

        let temporaryURL =
            temporaryUploadFiles
                .removeValue(
                    forKey: requestId
                )

        lock.unlock()

        if let temporaryURL {
            try? FileManager.default
                .removeItem(
                    at: temporaryURL
                )
        }
    }

    private func emitStarted(
        requestId: String,
        method: String,
        url: String
    ) {
        dispatchEvent(
            name: Self.eventStarted,
            payload: [
                "requestId": requestId,
                "method": method,
                "url": url,
            ]
        )
    }

    private func emitCompleted(
        requestId: String,
        status: Int,
        headers: [String: String],
        body: String
    ) {
        dispatchEvent(
            name: Self.eventCompleted,
            payload: [
                "requestId": requestId,
                "status": status,
                "headers": headers,
                "body": body,
            ]
        )
    }

    private func emitFailure(
        requestId: String,
        message: String,
        code: String?
    ) {
        var payload: [String: Any] = [
            "requestId": requestId,
            "message": message,
        ]

        if let code {
            payload["code"] = code
        }

        dispatchEvent(
            name: Self.eventFailed,
            payload: payload
        )
    }

    private func emitCancelled(
        requestId: String
    ) {
        dispatchEvent(
            name: Self.eventCancelled,
            payload: [
                "requestId": requestId
            ]
        )
    }

    private func emitUploadProgress(
        requestId: String,
        bytesSent: Int64,
        bytesTotal: Int64,
        progress: Double
    ) {
        dispatchEvent(
            name: Self.eventUploadProgress,
            payload: [
                "requestId": requestId,
                "bytesSent": bytesSent,
                "bytesTotal": bytesTotal,
                "progress": progress,
            ]
        )
    }

    private func dispatchEvent(
        name: String,
        payload: [String: Any]
    ) {
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(
                name,
                payload
            )
        }
    }

    private func failureCode(
        _ error: URLError
    ) -> String {

        switch error.code {

        case .timedOut:
            return "timeout"

        case .notConnectedToInternet:
            return "offline"

        case .cannotFindHost:
            return "dns_failure"

        case .cannotConnectToHost:
            return "connection_failed"

        case .secureConnectionFailed,
             .serverCertificateHasBadDate,
             .serverCertificateUntrusted,
             .serverCertificateHasUnknownRoot,
             .serverCertificateNotYetValid:

            return "tls_failure"

        default:
            return "network_error"
        }
    }
}

private struct MultipartBuildResult {
    let fileURL: URL
    let boundary: String
}

private enum FetchNativeError: Error {

    case invalidURL
    case invalidBody
    case invalidFile
    case fileNotFound(String)
    case multipartBuildFailed
}

enum FetchFunctions {

    class Start: BridgeFunction {

        func execute(
            parameters: [String: Any]
        ) throws -> [String: Any] {

            guard let requestId =
                parameters["request_id"] as? String
            else {
                return BridgeResponse.error(
                    code: "fetch.missing_request_id",
                    message: "Fetch.Start requires request_id."
                )
            }

            guard let url =
                parameters["url"] as? String
            else {
                return BridgeResponse.error(
                    code: "fetch.missing_url",
                    message: "Fetch.Start requires url."
                )
            }

            let method =
                (parameters["method"] as? String)?
                    .uppercased()
                ?? "GET"

            let timeout =
                (parameters["timeout"] as? NSNumber)?
                    .doubleValue
                ?? 30.0

            var headers: [String: String] = [:]

            if let rawHeaders =
                parameters["headers"]
                    as? [String: Any] {

                for (name, value)
                    in rawHeaders {

                    headers[name] =
                        String(describing: value)
                }
            }

            let query =
                parameters["query"]
                    as? [String: Any]
                ?? [:]

            let body =
                parameters["body"]
                    as? [String: Any]

            do {
                try FetchClient.shared.start(
                    requestId: requestId,
                    method: method,
                    urlString: url,
                    headers: headers,
                    query: query,
                    body: body,
                    timeout: timeout
                )

                return BridgeResponse.success(
                    data: [
                        "accepted": true,
                        "request_id": requestId,
                    ]
                )

            } catch FetchNativeError.invalidURL {

                return BridgeResponse.error(
                    code: "fetch.invalid_url",
                    message: "The supplied URL is invalid."
                )

            } catch FetchNativeError.invalidBody {

                return BridgeResponse.error(
                    code: "fetch.invalid_body",
                    message: "The supplied request body could not be encoded."
                )

            } catch FetchNativeError.invalidFile {

                return BridgeResponse.error(
                    code: "fetch.invalid_file",
                    message: "A multipart attachment is invalid."
                )

            } catch FetchNativeError.fileNotFound(
                let path
            ) {

                return BridgeResponse.error(
                    code: "fetch.file_not_found",
                    message: "Upload file does not exist or is not readable: \(path)"
                )

            } catch FetchNativeError.multipartBuildFailed {

                return BridgeResponse.error(
                    code: "fetch.multipart_failed",
                    message: "Fetch could not build the multipart upload."
                )

            } catch {

                return BridgeResponse.error(
                    code: "fetch.start_failed",
                    message: error.localizedDescription
                )
            }
        }
    }

    class Cancel: BridgeFunction {

        func execute(
            parameters: [String: Any]
        ) throws -> [String: Any] {

            guard let requestId =
                parameters["request_id"] as? String
            else {
                return BridgeResponse.error(
                    code: "fetch.missing_request_id",
                    message: "Fetch.Cancel requires request_id."
                )
            }

            let cancelled =
                FetchClient.shared.cancel(
                    requestId: requestId
                )

            return BridgeResponse.success(
                data: [
                    "request_id": requestId,
                    "cancelled": cancelled,
                ]
            )
        }
    }
}