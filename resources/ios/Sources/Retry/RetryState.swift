import Foundation

final class StandardRetryState {
    let requestId: String
    let request: URLRequest
    let uploadFile: URL?
    let policy: RetryPolicy?
    let sharedEvent: String?
    var attempt = 0
    var cancelled = false
    var terminal = false
    weak var task: URLSessionTask?
    var retryWorkItem: DispatchWorkItem?
    var state = "running"
    var bytesTransferred: Int64 = 0
    var bytesTotal: Int64?
    var nextRetryAt: Int64?

    init(requestId: String, request: URLRequest, uploadFile: URL?, policy: RetryPolicy?, sharedEvent: String?) {
        self.requestId = requestId
        self.request = request
        self.uploadFile = uploadFile
        self.policy = policy
        self.sharedEvent = sharedEvent
    }
}
