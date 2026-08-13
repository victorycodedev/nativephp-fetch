import Foundation

final class StandardRetryState {
    let requestId: String
    let request: URLRequest
    let uploadFile: URL?
    let policy: RetryPolicy?
    var attempt = 0
    var cancelled = false
    var terminal = false
    weak var task: URLSessionTask?
    var retryWorkItem: DispatchWorkItem?

    init(requestId: String, request: URLRequest, uploadFile: URL?, policy: RetryPolicy?) {
        self.requestId = requestId
        self.request = request
        self.uploadFile = uploadFile
        self.policy = policy
    }
}
