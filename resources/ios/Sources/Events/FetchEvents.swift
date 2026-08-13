import Foundation

enum FetchEvents {
    static let started = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestStarted"
    static let completed = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCompleted"
    static let failed = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestFailed"
    static let cancelled = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCancelled"
    static let uploadProgress = "Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress"
    static let downloadProgress = "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadProgress"
    static let downloadCompleted = "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadCompleted"
    static let retrying = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestRetrying"
}
