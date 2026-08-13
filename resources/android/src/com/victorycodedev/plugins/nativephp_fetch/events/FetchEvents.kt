package com.victorycodedev.plugins.nativephp_fetch

internal object FetchEvents {
    const val STARTED = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestStarted"
    const val COMPLETED = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCompleted"
    const val FAILED = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestFailed"
    const val CANCELLED = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCancelled"
    const val UPLOAD_PROGRESS = "Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress"
    const val DOWNLOAD_PROGRESS = "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadProgress"
    const val DOWNLOAD_COMPLETED = "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadCompleted"
    const val RETRYING = "Victorycodedev\\NativephpFetch\\Events\\FetchRequestRetrying"
}
