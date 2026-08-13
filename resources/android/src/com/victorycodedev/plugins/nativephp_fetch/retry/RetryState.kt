package com.victorycodedev.plugins.nativephp_fetch

import androidx.fragment.app.FragmentActivity
import okhttp3.Call
import java.util.concurrent.ScheduledFuture

internal class RetryOperation(val requestId: String, val policy: RetryPolicy?) {
    lateinit var activity: FragmentActivity
    var attempt = 0
    var cancelled = false
    var terminal = false
    var call: Call? = null
    var retryFuture: ScheduledFuture<*>? = null
}
