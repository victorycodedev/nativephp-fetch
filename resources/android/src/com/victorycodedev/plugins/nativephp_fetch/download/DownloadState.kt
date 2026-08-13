package com.victorycodedev.plugins.nativephp_fetch

import androidx.fragment.app.FragmentActivity
import okhttp3.Call
import java.io.File
import java.io.IOException
import java.util.concurrent.ScheduledFuture

internal class DownloadStartException(val code: String, override val message: String) : Exception(message)
internal class DownloadFileException(val code: String, override val message: String) : IOException(message)

internal data class DownloadState(
    val activity: FragmentActivity,
    val requestId: String,
    val destination: File,
    val destinationKey: String,
    val partial: File,
    val overwrite: Boolean,
    val policy: RetryPolicy?,
    @Volatile var cancelled: Boolean = false,
    @Volatile var terminal: Boolean = false,
    @Volatile var attempt: Int = 0,
    @Volatile var call: Call? = null,
    @Volatile var retryFuture: ScheduledFuture<*>? = null,
)
