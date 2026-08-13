import Foundation

private final class FetchClient: NSObject,
    URLSessionTaskDelegate,
    URLSessionDownloadDelegate {

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

    private static let eventDownloadProgress =
        "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadProgress"

    private static let eventDownloadCompleted =
        "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadCompleted"

    private static let eventRetrying =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestRetrying"

    private let lock = NSLock()

    private var tasks: [String: URLSessionTask] = [:]

    private var requestIdsByTaskIdentifier: [Int: String] = [:]

    private var temporaryUploadFiles: [String: URL] = [:]

    private var uploadProgressTimes: [String: TimeInterval] = [:]

    private var downloads: [String: DownloadState] = [:]

    private var activeDownloadDestinations: [String: String] = [:]

    private var retryOperations: [String: StandardRetryState] = [:]

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
        timeout: TimeInterval,
        retryPolicy: RetryPolicy?
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

            let state = StandardRetryState(
                requestId: requestId,
                request: request,
                uploadFile: multipart.fileURL,
                policy: retryPolicy
            )
            retryOperations[requestId] = state

            emitStarted(
                requestId: requestId,
                method: normalizedMethod,
                url: url.absoluteString
            )

            startStandardAttempt(state)

            return
        }

        if let body {
            try applyBody(
                body,
                to: &request
            )
        }

        let state = StandardRetryState(
            requestId: requestId,
            request: request,
            uploadFile: nil,
            policy: retryPolicy
        )
        retryOperations[requestId] = state

        emitStarted(
            requestId: requestId,
            method: normalizedMethod,
            url: url.absoluteString
        )

        startStandardAttempt(state)
    }

    private func startStandardAttempt(_ state: StandardRetryState) {
        lock.lock()
        guard !state.cancelled, !state.terminal else {
            lock.unlock()
            return
        }
        state.attempt += 1
        lock.unlock()

        let completion: (Data?, URLResponse?, Error?) -> Void = {
            [weak self, weak state] data, response, error in
            guard let self, let state else { return }
            self.handleStandardCompletion(
                state: state,
                data: data,
                response: response,
                error: error
            )
        }

        let task: URLSessionTask
        if let uploadFile = state.uploadFile {
            task = session.uploadTask(
                with: state.request,
                fromFile: uploadFile,
                completionHandler: completion
            )
        } else {
            task = session.dataTask(
                with: state.request,
                completionHandler: completion
            )
        }

        lock.lock()
        guard !state.cancelled, !state.terminal else {
            lock.unlock()
            task.cancel()
            return
        }
        state.task = task
        tasks[state.requestId] = task
        requestIdsByTaskIdentifier[task.taskIdentifier] = state.requestId
        lock.unlock()
        task.resume()
    }

    func download(
        requestId: String,
        urlString: String,
        destinationPath: String,
        headers: [String: String],
        query: [String: Any],
        timeout: TimeInterval,
        overwrite: Bool,
        retryPolicy: RetryPolicy?
    ) throws {
        guard !destinationPath.trimmingCharacters(
            in: .whitespacesAndNewlines
        ).isEmpty else {
            throw FetchNativeError.download(
                code: "invalid_destination",
                message: "The download destination is invalid."
            )
        }

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

        let destination = URL(fileURLWithPath: destinationPath)
            .standardizedFileURL
        let destinationKey = destination.path
        let parent = destination.deletingLastPathComponent()
        let fileManager = FileManager.default

        guard !destination.lastPathComponent.isEmpty else {
            throw FetchNativeError.download(
                code: "invalid_destination",
                message: "The download destination is invalid."
            )
        }

        do {
            try fileManager.createDirectory(
                at: parent,
                withIntermediateDirectories: true
            )
        } catch {
            throw FetchNativeError.download(
                code: "destination_unwritable",
                message: "The download destination directory could not be created."
            )
        }

        guard fileManager.isWritableFile(atPath: parent.path) else {
            throw FetchNativeError.download(
                code: "destination_unwritable",
                message: "The download destination directory is not writable."
            )
        }

        if fileManager.fileExists(atPath: destination.path), !overwrite {
            throw FetchNativeError.download(
                code: "destination_exists",
                message: "The download destination already exists."
            )
        }

        let partial = parent.appendingPathComponent(
            ".fetch-\(requestId).part"
        )

        lock.lock()
        let requestInUse = tasks[requestId] != nil
        let destinationInUse =
            activeDownloadDestinations[destinationKey] != nil

        if !requestInUse && !destinationInUse {
            activeDownloadDestinations[destinationKey] = requestId
        }
        lock.unlock()

        guard !requestInUse else {
            throw FetchNativeError.download(
                code: "network_error",
                message: "This request ID already has an active operation."
            )
        }

        guard !destinationInUse else {
            throw FetchNativeError.download(
                code: "destination_exists",
                message: "Another download is already using this destination."
            )
        }

        do {
            if fileManager.fileExists(atPath: partial.path) {
                try fileManager.removeItem(at: partial)
            }

            var request = URLRequest(
                url: url,
                cachePolicy: .useProtocolCachePolicy,
                timeoutInterval: timeout
            )
            request.httpMethod = "GET"

            for (name, value) in headers {
                request.setValue(value, forHTTPHeaderField: name)
            }

            let task = session.downloadTask(with: request)
            let state = DownloadState(
                requestId: requestId,
                request: request,
                destination: destination,
                destinationKey: destinationKey,
                partial: partial,
                overwrite: overwrite,
                policy: retryPolicy
            )
            state.task = task
            state.attempt = 1

            lock.lock()
            tasks[requestId] = task
            requestIdsByTaskIdentifier[task.taskIdentifier] = requestId
            downloads[requestId] = state
            lock.unlock()

            emitStarted(
                requestId: requestId,
                method: "GET",
                url: url.absoluteString
            )

            task.resume()
        } catch let error as FetchNativeError {
            releaseDownloadDestination(
                destinationKey,
                requestId: requestId
            )
            throw error
        } catch {
            releaseDownloadDestination(
                destinationKey,
                requestId: requestId
            )
            throw FetchNativeError.download(
                code: "destination_unwritable",
                message: "The partial download file could not be prepared."
            )
        }
    }

    func cancel(
        requestId: String
    ) -> Bool {

        lock.lock()

        let task =
            tasks[requestId]
        let retryOperation = retryOperations[requestId]
        let downloadState = downloads[requestId]

        if let operation = retryOperation {
            if operation.terminal {
                lock.unlock()
                return false
            }
            operation.cancelled = true
            operation.retryWorkItem?.cancel()
        }

        if let state = downloadState {
            if state.terminal {
                lock.unlock()
                return false
            }

            state.cancelled = true
            state.retryWorkItem?.cancel()
        }

        lock.unlock()

        guard task != nil || retryOperation != nil || downloadState != nil else {
            return false
        }

        task?.cancel()

        if let operation = retryOperation {
            finishStandardCancelled(operation)
        }

        if downloadState != nil {
            finishDownload(
                requestId: requestId,
                cancelled: true
            )
        }

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

        let now = Date().timeIntervalSince1970
        let previous = requestId.flatMap {
            uploadProgressTimes[$0]
        } ?? 0
        let finished = totalBytesExpectedToSend > 0
            && totalBytesSent >= totalBytesExpectedToSend
        let shouldEmit = previous == 0
            || finished
            || now - previous >= 0.1

        if let requestId, shouldEmit {
            uploadProgressTimes[requestId] = now
        }

        lock.unlock()

        guard let requestId, shouldEmit else {
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

    func urlSession(
        _ session: URLSession,
        downloadTask: URLSessionDownloadTask,
        didWriteData bytesWritten: Int64,
        totalBytesWritten: Int64,
        totalBytesExpectedToWrite: Int64
    ) {
        lock.lock()

        guard let requestId = requestIdsByTaskIdentifier[
            downloadTask.taskIdentifier
        ], let state = downloads[requestId],
           !state.cancelled,
           !state.terminal else {
            lock.unlock()
            return
        }

        let now = Date().timeIntervalSince1970
        let total = totalBytesExpectedToWrite >= 0
            ? totalBytesExpectedToWrite
            : nil
        let finished = total.map {
            totalBytesWritten >= $0
        } ?? false
        let shouldEmit = finished || now - state.lastProgressAt >= 0.1

        if shouldEmit {
            state.lastProgressAt = now
        }

        lock.unlock()

        if shouldEmit {
            emitDownloadProgress(
                requestId: requestId,
                bytesReceived: totalBytesWritten,
                bytesTotal: total
            )
        }
    }

    func urlSession(
        _ session: URLSession,
        downloadTask: URLSessionDownloadTask,
        didFinishDownloadingTo location: URL
    ) {
        lock.lock()

        guard let requestId = requestIdsByTaskIdentifier[
            downloadTask.taskIdentifier
        ], let state = downloads[requestId],
           !state.terminal else {
            lock.unlock()
            return
        }

        if state.cancelled {
            lock.unlock()
            finishDownload(
                requestId: requestId,
                cancelled: true
            )
            return
        }

        guard let response = downloadTask.response as? HTTPURLResponse else {
            lock.unlock()
            finishDownload(
                requestId: requestId,
                message: "The server returned an invalid HTTP response.",
                code: "network_error"
            )
            return
        }

        guard (200...299).contains(response.statusCode) else {
            lock.unlock()
            if state.policy?.statuses.contains(response.statusCode) == true,
               scheduleDownloadRetry(
                    state: state,
                    reason: "http_status",
                    status: response.statusCode,
                    retryAfter: response.value(forHTTPHeaderField: "Retry-After")
               ) {
                return
            }
            finishDownload(
                requestId: requestId,
                message: "Download failed with HTTP \(response.statusCode).",
                code: "http_error"
            )
            return
        }

        if downloadTask.countOfBytesExpectedToReceive >= 0,
           downloadTask.countOfBytesReceived
            != downloadTask.countOfBytesExpectedToReceive {
            lock.unlock()
            finishDownload(
                requestId: requestId,
                message: "The server closed the download before all bytes were received.",
                code: "network_error"
            )
            return
        }

        do {
            try promoteDownload(
                location: location,
                state: state
            )

            state.terminal = true
            removeDownloadStateLocked(
                requestId: requestId,
                taskIdentifier: downloadTask.taskIdentifier,
                destinationKey: state.destinationKey
            )
            lock.unlock()

            let bytesReceived = max(
                downloadTask.countOfBytesReceived,
                0
            )
            let expected = downloadTask.countOfBytesExpectedToReceive >= 0
                ? downloadTask.countOfBytesExpectedToReceive
                : nil

            emitDownloadProgress(
                requestId: requestId,
                bytesReceived: bytesReceived,
                bytesTotal: expected,
                forceComplete: expected != nil
            )
            emitDownloadCompleted(
                requestId: requestId,
                status: response.statusCode,
                headers: responseHeaders(response),
                path: state.destination.path,
                bytesReceived: bytesReceived
            )
        } catch let error as FetchNativeError {
            lock.unlock()

            if case let .download(code, message) = error {
                finishDownload(
                    requestId: requestId,
                    message: message,
                    code: code
                )
            } else {
                finishDownload(
                    requestId: requestId,
                    message: "The completed download could not be moved into place.",
                    code: "move_failed"
                )
            }
        } catch {
            lock.unlock()
            finishDownload(
                requestId: requestId,
                message: "The completed download could not be moved into place.",
                code: "move_failed"
            )
        }
    }

    func urlSession(
        _ session: URLSession,
        task: URLSessionTask,
        didCompleteWithError error: Error?
    ) {
        lock.lock()
        let requestId = requestIdsByTaskIdentifier[task.taskIdentifier]
        let isDownload = requestId.flatMap { downloads[$0] } != nil
        lock.unlock()

        guard isDownload, let requestId else {
            return
        }

        if let urlError = error as? URLError {
            if urlError.code == .cancelled {
                finishDownload(
                    requestId: requestId,
                    cancelled: true
                )
            } else {
                let reason = failureCode(urlError)
                if isRetryableNetwork(reason), scheduleDownloadRetry(
                    state: downloads[requestId],
                    reason: reason
                ) {
                    return
                }
                finishDownload(
                    requestId: requestId,
                    message: urlError.localizedDescription,
                    code: failureCode(urlError)
                )
            }
        } else if let error {
            if scheduleDownloadRetry(
                state: downloads[requestId],
                reason: "network_error"
            ) {
                return
            }
            finishDownload(
                requestId: requestId,
                message: error.localizedDescription,
                code: "network_error"
            )
        } else {
            finishDownload(
                requestId: requestId,
                message: "The download ended before its file was finalized.",
                code: "network_error"
            )
        }
    }

    private func handleStandardCompletion(
        state: StandardRetryState,
        data: Data?,
        response: URLResponse?,
        error: Error?
    ) {
        if state.cancelled {
            finishStandardCancelled(state)
            return
        }

        if let urlError = error as? URLError {
            if urlError.code == .cancelled {
                finishStandardCancelled(state)
            } else {
                let reason = failureCode(urlError)
                if isRetryableNetwork(reason), scheduleStandardRetry(
                    state: state,
                    reason: reason
                ) {
                    return
                }
                finishStandardFailure(
                    state,
                    message: urlError.localizedDescription,
                    code: reason
                )
            }
            return
        }

        if let error {
            if scheduleStandardRetry(
                state: state,
                reason: "network_error"
            ) {
                return
            }
            finishStandardFailure(
                state,
                message: error.localizedDescription,
                code: "network_error"
            )
            return
        }

        guard let httpResponse = response as? HTTPURLResponse else {
            finishStandardFailure(
                state,
                message: "The server returned an invalid HTTP response.",
                code: "invalid_response"
            )
            return
        }

        if state.policy?.statuses.contains(httpResponse.statusCode) == true {
            if scheduleStandardRetry(
                state: state,
                reason: "http_status",
                status: httpResponse.statusCode,
                retryAfter: httpResponse.value(
                    forHTTPHeaderField: "Retry-After"
                )
            ) {
                return
            }
            finishStandardFailure(
                state,
                message: "HTTP request failed with status \(httpResponse.statusCode).",
                code: "http_error"
            )
            return
        }

        lock.lock()
        guard !state.cancelled, !state.terminal else {
            lock.unlock()
            return
        }
        state.terminal = true
        removeStandardStateLocked(state)
        lock.unlock()

        let body = data.flatMap { String(data: $0, encoding: .utf8) } ?? ""
        emitCompleted(
            requestId: state.requestId,
            status: httpResponse.statusCode,
            headers: responseHeaders(httpResponse),
            body: body
        )
    }

    private func scheduleStandardRetry(
        state: StandardRetryState,
        reason: String,
        status: Int? = nil,
        retryAfter: String? = nil
    ) -> Bool {
        lock.lock()
        guard let policy = state.policy,
              !state.cancelled,
              !state.terminal,
              state.attempt < policy.maxAttempts else {
            lock.unlock()
            return false
        }

        let delay = retryDelay(
            policy: policy,
            completedAttempt: state.attempt,
            retryAfter: retryAfter
        )
        let nextAttempt = state.attempt + 1
        let item = DispatchWorkItem { [weak self, weak state] in
            guard let self, let state else { return }
            self.startStandardAttempt(state)
        }
        state.retryWorkItem = item
        if let task = state.task {
            requestIdsByTaskIdentifier.removeValue(forKey: task.taskIdentifier)
        }
        tasks.removeValue(forKey: state.requestId)
        lock.unlock()

        emitRetrying(
            requestId: state.requestId,
            attempt: nextAttempt,
            maxAttempts: policy.maxAttempts,
            delayMs: delay,
            reason: reason,
            status: status
        )
        DispatchQueue.global(qos: .utility).asyncAfter(
            deadline: .now() + .milliseconds(delay),
            execute: item
        )
        return true
    }

    private func finishStandardFailure(
        _ state: StandardRetryState,
        message: String,
        code: String
    ) {
        lock.lock()
        guard !state.terminal, !state.cancelled else {
            lock.unlock()
            return
        }
        state.terminal = true
        removeStandardStateLocked(state)
        lock.unlock()
        emitFailure(requestId: state.requestId, message: message, code: code)
    }

    private func finishStandardCancelled(_ state: StandardRetryState) {
        lock.lock()
        guard !state.terminal else {
            lock.unlock()
            return
        }
        state.cancelled = true
        state.terminal = true
        state.retryWorkItem?.cancel()
        removeStandardStateLocked(state)
        lock.unlock()
        emitCancelled(requestId: state.requestId)
    }

    private func removeStandardStateLocked(_ state: StandardRetryState) {
        if let task = state.task {
            requestIdsByTaskIdentifier.removeValue(forKey: task.taskIdentifier)
        }
        tasks.removeValue(forKey: state.requestId)
        retryOperations.removeValue(forKey: state.requestId)
        uploadProgressTimes.removeValue(forKey: state.requestId)
        if let uploadFile = state.uploadFile {
            try? FileManager.default.removeItem(at: uploadFile)
        }
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

        uploadProgressTimes.removeValue(
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

    private func promoteDownload(
        location: URL,
        state: DownloadState
    ) throws {
        let fileManager = FileManager.default

        if fileManager.fileExists(atPath: state.partial.path) {
            try? fileManager.removeItem(at: state.partial)
        }

        do {
            try fileManager.moveItem(
                at: location,
                to: state.partial
            )
        } catch {
            throw FetchNativeError.download(
                code: "write_failed",
                message: "The downloaded temporary file could not be prepared."
            )
        }

        do {
            let destinationExists = fileManager.fileExists(
                atPath: state.destination.path
            )

            if destinationExists, !state.overwrite {
                throw FetchNativeError.download(
                    code: "destination_exists",
                    message: "The download destination already exists."
                )
            }

            if destinationExists {
                _ = try fileManager.replaceItemAt(
                    state.destination,
                    withItemAt: state.partial
                )
            } else {
                try fileManager.moveItem(
                    at: state.partial,
                    to: state.destination
                )
            }
        } catch let error as FetchNativeError {
            try? fileManager.removeItem(at: state.partial)
            throw error
        } catch {
            try? fileManager.removeItem(at: state.partial)
            throw FetchNativeError.download(
                code: "move_failed",
                message: "The completed download could not be moved into place."
            )
        }
    }

    private func finishDownload(
        requestId: String,
        cancelled: Bool = false,
        message: String = "The download failed.",
        code: String = "network_error"
    ) {
        lock.lock()

        guard let state = downloads[requestId],
              !state.terminal else {
            lock.unlock()
            return
        }

        state.terminal = true
        let taskIdentifier = tasks[requestId]?.taskIdentifier

        removeDownloadStateLocked(
            requestId: requestId,
            taskIdentifier: taskIdentifier,
            destinationKey: state.destinationKey
        )
        lock.unlock()

        try? FileManager.default.removeItem(at: state.partial)

        if cancelled || state.cancelled {
            emitCancelled(requestId: requestId)
        } else {
            emitFailure(
                requestId: requestId,
                message: message,
                code: code
            )
        }
    }

    private func scheduleDownloadRetry(
        state: DownloadState?,
        reason: String,
        status: Int? = nil,
        retryAfter: String? = nil
    ) -> Bool {
        guard let state else { return false }

        lock.lock()
        guard let policy = state.policy,
              !state.cancelled,
              !state.terminal,
              state.attempt < policy.maxAttempts else {
            lock.unlock()
            return false
        }

        let delay = retryDelay(
            policy: policy,
            completedAttempt: state.attempt,
            retryAfter: retryAfter
        )
        let nextAttempt = state.attempt + 1
        state.attempt = nextAttempt
        try? FileManager.default.removeItem(at: state.partial)

        let item = DispatchWorkItem { [weak self, weak state] in
            guard let self, let state else { return }
            self.lock.lock()
            guard !state.cancelled, !state.terminal else {
                self.lock.unlock()
                return
            }
            try? FileManager.default.removeItem(at: state.partial)
            let task = self.session.downloadTask(with: state.request)
            state.task = task
            self.tasks[state.requestId] = task
            self.requestIdsByTaskIdentifier[task.taskIdentifier] = state.requestId
            self.lock.unlock()
            task.resume()
        }
        state.retryWorkItem = item
        if let task = state.task {
            requestIdsByTaskIdentifier.removeValue(forKey: task.taskIdentifier)
        }
        tasks.removeValue(forKey: state.requestId)
        lock.unlock()

        emitRetrying(
            requestId: state.requestId,
            attempt: nextAttempt,
            maxAttempts: policy.maxAttempts,
            delayMs: delay,
            reason: reason,
            status: status
        )
        DispatchQueue.global(qos: .utility).asyncAfter(
            deadline: .now() + .milliseconds(delay),
            execute: item
        )
        return true
    }

    private func removeDownloadStateLocked(
        requestId: String,
        taskIdentifier: Int?,
        destinationKey: String
    ) {
        tasks.removeValue(forKey: requestId)
        downloads.removeValue(forKey: requestId)
        activeDownloadDestinations.removeValue(forKey: destinationKey)

        if let taskIdentifier {
            requestIdsByTaskIdentifier.removeValue(
                forKey: taskIdentifier
            )
        }
    }

    private func releaseDownloadDestination(
        _ destinationKey: String,
        requestId: String
    ) {
        lock.lock()
        if activeDownloadDestinations[destinationKey] == requestId {
            activeDownloadDestinations.removeValue(
                forKey: destinationKey
            )
        }
        lock.unlock()
    }

    private func responseHeaders(
        _ response: HTTPURLResponse
    ) -> [String: String] {
        var headers: [String: String] = [:]

        for (key, value) in response.allHeaderFields {
            headers[String(describing: key)] = String(describing: value)
        }

        return headers
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

    private func emitDownloadProgress(
        requestId: String,
        bytesReceived: Int64,
        bytesTotal: Int64?,
        forceComplete: Bool = false
    ) {
        let progress: Double?

        if forceComplete {
            progress = 1
        } else if let bytesTotal, bytesTotal > 0 {
            progress = min(
                max(Double(bytesReceived) / Double(bytesTotal), 0),
                1
            )
        } else if bytesTotal == 0 {
            progress = 0
        } else {
            progress = nil
        }

        let totalValue: Any = bytesTotal.map { $0 } ?? NSNull()
        let progressValue: Any = progress.map { $0 } ?? NSNull()

        dispatchEvent(
            name: Self.eventDownloadProgress,
            payload: [
                "requestId": requestId,
                "bytesReceived": max(bytesReceived, 0),
                "bytesTotal": totalValue,
                "progress": progressValue,
            ]
        )
    }

    private func emitDownloadCompleted(
        requestId: String,
        status: Int,
        headers: [String: String],
        path: String,
        bytesReceived: Int64
    ) {
        dispatchEvent(
            name: Self.eventDownloadCompleted,
            payload: [
                "requestId": requestId,
                "status": status,
                "headers": headers,
                "path": path,
                "bytesReceived": bytesReceived,
            ]
        )
    }

    private func emitRetrying(
        requestId: String,
        attempt: Int,
        maxAttempts: Int,
        delayMs: Int,
        reason: String,
        status: Int?
    ) {
        dispatchEvent(
            name: Self.eventRetrying,
            payload: [
                "requestId": requestId,
                "attempt": attempt,
                "maxAttempts": maxAttempts,
                "delayMs": delayMs,
                "reason": reason,
                "status": status.map { $0 } ?? NSNull(),
            ]
        )
    }

    private func retryDelay(
        policy: RetryPolicy,
        completedAttempt: Int,
        retryAfter: String?
    ) -> Int {
        let exponent = max(completedAttempt - 1, 0)
        let calculated = Double(policy.delay) * pow(
            policy.multiplier,
            Double(exponent)
        )
        let headerDelay = parseRetryAfter(retryAfter)
        let delay = headerDelay ?? Int(
            min(calculated, Double(Int.max))
        )
        let jittered = Double(max(delay, 0)) * Double.random(in: 0.8...1.2)
        let capped = policy.maxDelay.map {
            min(jittered, Double($0))
        } ?? jittered
        return Int(min(max(capped, 0), Double(Int.max)))
    }

    private func parseRetryAfter(_ value: String?) -> Int? {
        guard let text = value?.trimmingCharacters(
            in: .whitespacesAndNewlines
        ), !text.isEmpty else { return nil }

        if let seconds = Int(text) {
            return max(seconds, 0) > Int.max / 1000
                ? Int.max
                : max(seconds, 0) * 1000
        }

        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.dateFormat = "EEE',' dd MMM yyyy HH':'mm':'ss z"
        guard let date = formatter.date(from: text) else { return nil }
        return max(Int(date.timeIntervalSinceNow * 1000), 0)
    }

    private func isRetryableNetwork(_ code: String) -> Bool {
        [
            "timeout",
            "offline",
            "dns_failure",
            "connection_failed",
            "network_error",
        ].contains(code)
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

private final class DownloadState {
    let requestId: String
    let request: URLRequest
    let destination: URL
    let destinationKey: String
    let partial: URL
    let overwrite: Bool
    let policy: RetryPolicy?
    var cancelled = false
    var terminal = false
    var lastProgressAt: TimeInterval = 0
    var attempt = 0
    weak var task: URLSessionTask?
    var retryWorkItem: DispatchWorkItem?

    init(
        requestId: String,
        request: URLRequest,
        destination: URL,
        destinationKey: String,
        partial: URL,
        overwrite: Bool,
        policy: RetryPolicy?
    ) {
        self.requestId = requestId
        self.request = request
        self.destination = destination
        self.destinationKey = destinationKey
        self.partial = partial
        self.overwrite = overwrite
        self.policy = policy
    }
}

private struct RetryPolicy {
    let times: Int
    let delay: Int
    let multiplier: Double
    let maxDelay: Int?
    let statuses: Set<Int>

    var maxAttempts: Int { times + 1 }
}

private final class StandardRetryState {
    let requestId: String
    let request: URLRequest
    let uploadFile: URL?
    let policy: RetryPolicy?
    var attempt = 0
    var cancelled = false
    var terminal = false
    weak var task: URLSessionTask?
    var retryWorkItem: DispatchWorkItem?

    init(
        requestId: String,
        request: URLRequest,
        uploadFile: URL?,
        policy: RetryPolicy?
    ) {
        self.requestId = requestId
        self.request = request
        self.uploadFile = uploadFile
        self.policy = policy
    }
}

private enum FetchNativeError: Error {

    case invalidURL
    case invalidBody
    case invalidFile
    case fileNotFound(String)
    case multipartBuildFailed
    case download(code: String, message: String)
}

private func retryPolicy(from value: Any?) -> RetryPolicy? {
    guard let retry = value as? [String: Any] else { return nil }
    let defaults: Set<Int> = [408, 429, 500, 502, 503, 504]
    let custom = Set(
        (retry["statuses"] as? [Any] ?? []).compactMap {
            ($0 as? NSNumber)?.intValue
        }
    )

    return RetryPolicy(
        times: (retry["times"] as? NSNumber)?.intValue ?? 3,
        delay: (retry["delay"] as? NSNumber)?.intValue ?? 500,
        multiplier: (retry["multiplier"] as? NSNumber)?.doubleValue ?? 2,
        maxDelay: (retry["max_delay"] as? NSNumber)?.intValue,
        statuses: custom.isEmpty ? defaults : custom
    )
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
            let retry = retryPolicy(from: parameters["retry"])

            do {
                try FetchClient.shared.start(
                    requestId: requestId,
                    method: method,
                    urlString: url,
                    headers: headers,
                    query: query,
                    body: body,
                    timeout: timeout,
                    retryPolicy: retry
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

    class Download: BridgeFunction {

        func execute(
            parameters: [String: Any]
        ) throws -> [String: Any] {
            guard let requestId = parameters["request_id"] as? String else {
                return BridgeResponse.error(
                    code: "fetch.missing_request_id",
                    message: "Fetch.Download requires request_id."
                )
            }

            guard let url = parameters["url"] as? String else {
                return BridgeResponse.error(
                    code: "fetch.missing_url",
                    message: "Fetch.Download requires url."
                )
            }

            guard let destination = parameters["destination"] as? String else {
                return BridgeResponse.error(
                    code: "fetch.invalid_destination",
                    message: "Fetch.Download requires destination."
                )
            }

            let timeout = (parameters["timeout"] as? NSNumber)?.doubleValue
                ?? 30
            let overwrite = parameters["overwrite"] as? Bool
                ?? false
            let retry = retryPolicy(from: parameters["retry"])
            let query = parameters["query"] as? [String: Any]
                ?? [:]
            var headers: [String: String] = [:]

            if let rawHeaders = parameters["headers"] as? [String: Any] {
                for (name, value) in rawHeaders {
                    headers[name] = String(describing: value)
                }
            }

            do {
                try FetchClient.shared.download(
                    requestId: requestId,
                    urlString: url,
                    destinationPath: destination,
                    headers: headers,
                    query: query,
                    timeout: timeout,
                    overwrite: overwrite,
                    retryPolicy: retry
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
            } catch let FetchNativeError.download(code, message) {
                return BridgeResponse.error(
                    code: "fetch.\(code)",
                    message: message
                )
            } catch {
                return BridgeResponse.error(
                    code: "fetch.download_failed",
                    message: error.localizedDescription
                )
            }
        }
    }
}
