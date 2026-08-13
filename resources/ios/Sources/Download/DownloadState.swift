import Foundation

final class DownloadState {
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

    init(requestId: String, request: URLRequest, destination: URL, destinationKey: String, partial: URL, overwrite: Bool, policy: RetryPolicy?) {
        self.requestId = requestId
        self.request = request
        self.destination = destination
        self.destinationKey = destinationKey
        self.partial = partial
        self.overwrite = overwrite
        self.policy = policy
    }
}
