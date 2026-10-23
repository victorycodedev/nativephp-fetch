package com.victorycodedev.plugins.nativephp_fetch

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import okhttp3.Call
import okhttp3.Callback
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.io.InterruptedIOException
import java.net.UnknownHostException
import java.nio.file.AtomicMoveNotSupportedException
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

private object FetchClient {

    private val client = OkHttpClient.Builder()
        .retryOnConnectionFailure(false)
        .build()

    private val calls = ConcurrentHashMap<String, Call>()

    private val downloads = ConcurrentHashMap<String, DownloadState>()

    private val activeDestinations = ConcurrentHashMap<String, String>()

    private val retryOperations = ConcurrentHashMap<String, RetryOperation>()

    private val announcedActiveRequests = ConcurrentHashMap.newKeySet<String>()

    private val activeProgressTimes = ConcurrentHashMap<String, Long>()

    private val retryScheduler = Executors.newSingleThreadScheduledExecutor()

    fun start(
        activity: FragmentActivity,
        requestId: String,
        method: String,
        url: String,
        headers: Map<String, String>,
        query: Map<String, Any>,
        body: Map<String, Any>?,
        timeoutSeconds: Long,
        retryPolicy: RetryPolicy?,
        sharedEvent: String?,
    ) {
        val parsedUrl = url.toHttpUrlOrNull()
            ?: run {
                emitFailure(
                    activity = activity,
                    requestId = requestId,
                    message = "The supplied URL is invalid.",
                    code = "invalid_url",
                    sharedEvent = sharedEvent,
                )
                return
            }

        val urlBuilder = parsedUrl.newBuilder()

        query.forEach { (name, value) ->
            when (value) {
                is Iterable<*> -> {
                    value.forEach { item ->
                        item?.let {
                            urlBuilder.addQueryParameter(
                                name,
                                it.toString(),
                            )
                        }
                    }
                }
                else -> {
                    urlBuilder.addQueryParameter(
                        name,
                        value.toString(),
                    )
                }
            }
        }

        val requestBuilder = Request.Builder()
            .url(urlBuilder.build())

        headers.forEach { (name, value) ->
            requestBuilder.header(name, value)
        }

        val bodyResult = buildRequestBody(
            activity = activity,
            requestId = requestId,
            body = body,
            sharedEvent = sharedEvent,
        )

        if (bodyResult.failed) {
            return
        }

        val requestBody = bodyResult.body

        when (method.uppercase()) {
            "GET" -> {
                requestBuilder.get()
            }
            "POST" -> {
                requestBuilder.post(
                    requestBody ?: ByteArray(0).toRequestBody(null)
                )
            }
            "PUT" -> {
                requestBuilder.put(
                    requestBody ?: ByteArray(0).toRequestBody(null)
                )
            }
            "PATCH" -> {
                requestBuilder.patch(
                    requestBody ?: ByteArray(0).toRequestBody(null)
                )
            }
            "DELETE" -> {
                if (requestBody != null) {
                    requestBuilder.delete(requestBody)
                } else {
                    requestBuilder.delete()
                }
            }
            else -> {
                emitFailure(
                    activity = activity,
                    requestId = requestId,
                    message = "HTTP method $method is not supported.",
                    code = "unsupported_method",
                    sharedEvent = sharedEvent,
                )
                return
            }
        }

        val request = requestBuilder.build()

        val timedClient = client.newBuilder()
            .callTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .writeTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .build()

        val operation = RetryOperation(
            requestId,
            retryPolicy,
            sharedEvent,
            request.method,
            request.url.toString(),
        )
        operation.activity = activity
        retryOperations[requestId] = operation
        emitStarted(
            activity = activity,
            requestId = requestId,
            method = method.uppercase(),
            url = request.url.toString(),
        )
        enqueueStandardAttempt(activity, operation, timedClient, request)
    }

    private fun enqueueStandardAttempt(
        activity: FragmentActivity,
        operation: RetryOperation,
        client: OkHttpClient,
        request: Request,
    ) {
        val resumed = synchronized(operation) {
            if (operation.cancelled || operation.terminal) return
            val isRetry = operation.attempt > 0
            operation.attempt++
            operation.state = "running"
            operation.nextRetryAt = null
            operation.bytesTransferred = 0L
            operation.bytesTotal = null
            operation.retryFuture = null
            isRetry
        }

        if (resumed) {
            emitActiveRequestsChanged(activity, operation.requestId, "running")
        }

        val call = client.newCall(request)
        operation.call = call
        calls[operation.requestId] = call

        call.enqueue(object : Callback {
            override fun onFailure(call: Call, exception: IOException) {
                // OkHttp also marks a call as canceled when its call timeout fires.
                // Only our logical flag proves that Fetch.Cancel was requested.
                if (isRetryCancelled(operation)) {
                    finishRetryCancelled(activity, operation)
                    return
                }

                val code = failureCode(exception)
                if (isRetryableNetwork(code) && scheduleRetry(
                        activity, operation, code, null, null
                    ) { enqueueStandardAttempt(activity, operation, client, request) }
                ) return

                finishRetryFailure(
                    activity,
                    operation,
                    exception.message ?: "Network request failed.",
                    code,
                )
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    if (isRetryCancelled(operation)) {
                        finishRetryCancelled(activity, operation)
                        return
                    }

                    if (operation.policy?.statuses?.contains(response.code) == true) {
                        if (scheduleRetry(
                            activity,
                            operation,
                            "http_status",
                            response.code,
                            response.header("Retry-After"),
                        ) { enqueueStandardAttempt(activity, operation, client, request) }
                        ) return

                        finishRetryFailure(
                            activity,
                            operation,
                            "HTTP request failed with status ${response.code}.",
                            "http_error",
                        )
                        return
                    }

                    if (!markRetryTerminal(operation)) return
                    calls.remove(operation.requestId, call)
                    retryOperations.remove(operation.requestId, operation)

                    val responseHeaders = JSONObject()
                    response.headers.names().forEach { name ->
                        val values = response.headers.values(name)
                        responseHeaders.put(
                            name,
                            if (values.size == 1) values.first() else JSONArray(values),
                        )
                    }

                    val payload = JSONObject().apply {
                        put("requestId", operation.requestId)
                        put("status", response.code)
                        put("headers", responseHeaders)
                        put("body", response.body?.string().orEmpty())
                    }
                    NativeEventDispatcher.dispatch(activity, FetchEvents.COMPLETED, payload)
                    emitSharedCompleted(
                        activity = activity,
                        sharedEvent = operation.sharedEvent,
                        requestId = operation.requestId,
                        httpStatus = response.code,
                        headers = responseHeaders,
                        body = payload.getString("body"),
                    )
                    emitActiveRequestsChanged(
                        activity,
                        operation.requestId,
                        "finished",
                    )
                }
            }
        })
    }

    @Throws(DownloadStartException::class)
    fun download(
        activity: FragmentActivity,
        requestId: String,
        url: String,
        destinationPath: String,
        headers: Map<String, String>,
        query: Map<String, Any>,
        timeoutSeconds: Long,
        overwrite: Boolean,
        retryPolicy: RetryPolicy?,
        sharedEvent: String?,
    ) {
        val parsedUrl = url.toHttpUrlOrNull()
            ?: throw DownloadStartException(
                "invalid_url",
                "The supplied URL is invalid.",
            )

        val destination = try {
            File(destinationPath).canonicalFile
        } catch (exception: IOException) {
            throw DownloadStartException(
                "invalid_destination",
                "The download destination is invalid.",
            )
        }

        if (destinationPath.isBlank() || destination.name.isBlank()) {
            throw DownloadStartException(
                "invalid_destination",
                "The download destination is invalid.",
            )
        }

        val parent = destination.parentFile
            ?: throw DownloadStartException(
                "invalid_destination",
                "The download destination must have a parent directory.",
            )

        if ((!parent.exists() && !parent.mkdirs()) || !parent.isDirectory) {
            throw DownloadStartException(
                "destination_unwritable",
                "The download destination directory could not be created.",
            )
        }

        if (!parent.canWrite()) {
            throw DownloadStartException(
                "destination_unwritable",
                "The download destination directory is not writable.",
            )
        }

        if (destination.exists() && !overwrite) {
            throw DownloadStartException(
                "destination_exists",
                "The download destination already exists.",
            )
        }

        val destinationKey = destination.path

        if (calls.containsKey(requestId)) {
            throw DownloadStartException(
                "network_error",
                "This request ID already has an active operation.",
            )
        }

        val existingRequest = activeDestinations.putIfAbsent(
            destinationKey,
            requestId,
        )

        if (existingRequest != null) {
            throw DownloadStartException(
                "destination_exists",
                "Another download is already using this destination.",
            )
        }

        val partial = File(parent, ".fetch-$requestId.part")
        if (partial.exists() && !partial.delete()) {
            activeDestinations.remove(destinationKey, requestId)
            throw DownloadStartException(
                "destination_unwritable",
                "A stale partial download could not be removed.",
            )
        }

        val urlBuilder = parsedUrl.newBuilder()
        query.forEach { (name, value) ->
            if (value is Iterable<*>) {
                value.forEach { item ->
                    item?.let { urlBuilder.addQueryParameter(name, it.toString()) }
                }
            } else {
                urlBuilder.addQueryParameter(name, value.toString())
            }
        }

        val request: Request
        val timedClient: OkHttpClient
        try {
            val requestBuilder = Request.Builder()
                .url(urlBuilder.build())
                .get()

            headers.forEach { (name, value) ->
                requestBuilder.header(name, value)
            }

            timedClient = client.newBuilder()
                .callTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .writeTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .build()

            request = requestBuilder.build()
        } catch (exception: Exception) {
            activeDestinations.remove(destinationKey, requestId)
            partial.delete()
            throw DownloadStartException(
                "network_error",
                exception.message ?: "The native download could not be prepared.",
            )
        }
        val state = DownloadState(
            activity = activity,
            requestId = requestId,
            destination = destination,
            destinationKey = destinationKey,
            partial = partial,
            overwrite = overwrite,
            policy = retryPolicy,
            sharedEvent = sharedEvent,
            url = request.url.toString(),
        )

        state.attempt = 1

        val call = timedClient.newCall(request)
        state.call = call

        downloads[requestId] = state
        calls[requestId] = call

        emitStarted(
            activity = activity,
            requestId = requestId,
            method = "GET",
            url = call.request().url.toString(),
        )

        lateinit var callback: Callback
        callback = object : Callback {
            override fun onFailure(call: Call, exception: IOException) {
                if (!call.isCanceled() && !state.cancelled &&
                    isRetryableNetwork(failureCode(exception)) &&
                    scheduleDownloadRetry(
                        activity,
                        state,
                        timedClient,
                        request,
                        callback,
                        failureCode(exception),
                    )
                ) return

                finishDownloadFailure(
                    activity = activity,
                    call = call,
                    state = state,
                    exception = exception,
                )
            }

            override fun onResponse(call: Call, response: Response) {
                try {
                    response.use {
                        if (call.isCanceled() || state.cancelled) {
                            throw InterruptedIOException("Download cancelled.")
                        }

                        if (response.code !in 200..299) {
                            if (state.policy?.statuses?.contains(response.code) == true &&
                                scheduleDownloadRetry(
                                    activity,
                                    state,
                                    timedClient,
                                    request,
                                    callback,
                                    "http_status",
                                    response.code,
                                    response.header("Retry-After"),
                                )
                            ) return

                            finishDownload(
                                activity = activity,
                                call = call,
                                state = state,
                                failureMessage =
                                    "Download failed with HTTP ${response.code}.",
                                failureCode = "http_error",
                            )
                            return
                        }

                        val responseBody = response.body
                            ?: run {
                                finishDownload(
                                    activity = activity,
                                    call = call,
                                    state = state,
                                    failureMessage = "The server returned an empty response body.",
                                    failureCode = "network_error",
                                )
                                return
                            }

                        val contentLength = responseBody.contentLength()
                        val bytesTotal = contentLength.takeIf { it >= 0L }
                        var bytesReceived = 0L
                        var lastEmitAt = 0L

                        emitDownloadProgress(
                            activity,
                            requestId,
                            0L,
                            bytesTotal,
                        )

                        responseBody.byteStream().use { input ->
                            val fileOutput = try {
                                FileOutputStream(partial)
                            } catch (exception: IOException) {
                                throw DownloadFileException(
                                    "write_failed",
                                    "The partial download file could not be created.",
                                )
                            }

                            fileOutput.buffered(64 * 1024).use { output ->
                                val buffer = ByteArray(64 * 1024)

                                while (true) {
                                    if (call.isCanceled() || state.cancelled) {
                                        throw InterruptedIOException("Download cancelled.")
                                    }

                                    val count = input.read(buffer)
                                    if (count == -1) {
                                        break
                                    }

                                    try {
                                        output.write(buffer, 0, count)
                                    } catch (exception: IOException) {
                                        throw DownloadFileException(
                                            "write_failed",
                                            "The downloaded data could not be written to disk.",
                                        )
                                    }
                                    bytesReceived += count.toLong()

                                    val now = System.currentTimeMillis()
                                    if (now - lastEmitAt >= 100L) {
                                        lastEmitAt = now
                                        emitDownloadProgress(
                                            activity,
                                            requestId,
                                            bytesReceived,
                                            bytesTotal,
                                        )
                                    }
                                }

                                try {
                                    output.flush()
                                } catch (exception: IOException) {
                                    throw DownloadFileException(
                                        "write_failed",
                                        "The downloaded data could not be flushed to disk.",
                                    )
                                }
                            }
                        }

                        if (bytesTotal != null && bytesReceived != bytesTotal) {
                            throw IOException(
                                "The server closed the download before all bytes were received."
                            )
                        }

                        synchronized(state) {
                            if (state.cancelled || call.isCanceled()) {
                                throw InterruptedIOException("Download cancelled.")
                            }

                            promoteDownload(state)
                            state.terminal = true
                        }

                        cleanupDownload(call, state, removePartial = false)

                        emitDownloadProgress(
                            activity,
                            requestId,
                            bytesReceived,
                            bytesTotal,
                            forceComplete = bytesTotal != null,
                        )
                        emitDownloadCompleted(
                            activity,
                            requestId,
                            response.code,
                            response,
                            destination.path,
                            bytesReceived,
                            state.sharedEvent,
                        )
                    }
                } catch (exception: Exception) {
                    finishDownloadFailure(
                        activity = activity,
                        call = call,
                        state = state,
                        exception = exception,
                    )
                }
            }
        }

        try {
            call.enqueue(callback)
        } catch (exception: Exception) {
            synchronized(state) {
                state.terminal = true
            }
            cleanupDownload(call, state, removePartial = true)
            throw DownloadStartException(
                "network_error",
                exception.message ?: "The native download could not be started.",
            )
        }
    }

    private fun scheduleDownloadRetry(
        activity: FragmentActivity,
        state: DownloadState,
        client: OkHttpClient,
        request: Request,
        callback: Callback,
        reason: String,
        status: Int? = null,
        retryAfter: String? = null,
    ): Boolean {
        val (delay, maxAttempts) = synchronized(state) {
            val policy = state.policy ?: return false
            if (state.cancelled || state.terminal ||
                state.attempt >= policy.maxAttempts
            ) return false

            state.partial.delete()
            val value = retryDelay(policy, state.attempt, retryAfter)
            state.attempt++
            state.state = "waiting_retry"
            state.nextRetryAt = System.currentTimeMillis() + value
            value to policy.maxAttempts
        }

        emitRetrying(
            activity,
            state.requestId,
            state.attempt,
            maxAttempts,
            delay,
            reason,
            status,
        )

        calls.remove(state.requestId)
        state.retryFuture = retryScheduler.schedule({
            synchronized(state) {
                if (state.cancelled || state.terminal) return@schedule
                state.partial.delete()
                state.state = "running"
                state.nextRetryAt = null
                state.bytesTransferred = 0L
                state.bytesTotal = null
                state.retryFuture = null
                val call = client.newCall(request)
                state.call = call
                calls[state.requestId] = call
                emitActiveRequestsChanged(activity, state.requestId, "running")
                call.enqueue(callback)
            }
        }, delay.toLong(), TimeUnit.MILLISECONDS)
        return true
    }

    fun activeRequests(): List<Map<String, Any>> {
        val requests = retryOperations.values.mapNotNull { operation ->
            synchronized(operation) {
                if (operation.terminal || operation.cancelled) null else mapOf<String, Any>(
                    "requestId" to operation.requestId,
                    "method" to operation.method,
                    "url" to operation.url,
                    "type" to "request",
                    "attempt" to operation.attempt.coerceAtLeast(1),
                    "state" to operation.state,
                    "bytesTransferred" to operation.bytesTransferred,
                    "bytesTotal" to (operation.bytesTotal ?: JSONObject.NULL),
                    "progress" to progressValue(
                        operation.bytesTransferred,
                        operation.bytesTotal,
                    ),
                    "nextRetryAt" to (operation.nextRetryAt ?: JSONObject.NULL),
                )
            }
        }.toMutableList()

        downloads.values.mapNotNullTo(requests) { state ->
            synchronized(state) {
                if (state.terminal || state.cancelled) null else mapOf<String, Any>(
                    "requestId" to state.requestId,
                    "method" to "GET",
                    "url" to state.url,
                    "type" to "download",
                    "attempt" to state.attempt.coerceAtLeast(1),
                    "state" to state.state,
                    "bytesTransferred" to state.bytesTransferred,
                    "bytesTotal" to (state.bytesTotal ?: JSONObject.NULL),
                    "progress" to progressValue(state.bytesTransferred, state.bytesTotal),
                    "nextRetryAt" to (state.nextRetryAt ?: JSONObject.NULL),
                )
            }
        }

        return requests.sortedBy { it["requestId"].toString() }
    }

    fun cancelAll(): Int {
        val requestIds = (retryOperations.keys + downloads.keys).distinct()
        return requestIds.count(::cancel)
    }

    fun cancel(requestId: String): Boolean {
        retryOperations[requestId]?.let { operation ->
            synchronized(operation) {
                if (operation.terminal) return false
                operation.cancelled = true
                operation.retryFuture?.cancel(false)
                operation.call?.cancel()
            }
            finishRetryCancelled(operation.activity, operation)
            return true
        }

        downloads[requestId]?.let { state ->
            synchronized(state) {
                if (state.terminal) {
                    return false
                }
                state.cancelled = true
                state.retryFuture?.cancel(false)
                state.call?.cancel()
            }
            finishDownload(
                activity = state.activity,
                call = state.call ?: calls[requestId],
                state = state,
                cancelled = true,
                failureMessage = "Download cancelled.",
                failureCode = "network_error",
            )
            return true
        }

        val call = calls[requestId] ?: return false
        call.cancel()
        return true
    }

    private fun promoteDownload(state: DownloadState) {
        if (!state.overwrite && state.destination.exists()) {
            throw DownloadFileException(
                "destination_exists",
                "The download destination already exists.",
            )
        }

        val options = if (state.overwrite) {
            arrayOf(StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING)
        } else {
            arrayOf(StandardCopyOption.ATOMIC_MOVE)
        }

        try {
            Files.move(state.partial.toPath(), state.destination.toPath(), *options)
        } catch (exception: AtomicMoveNotSupportedException) {
            val fallback = if (state.overwrite) {
                arrayOf(StandardCopyOption.REPLACE_EXISTING)
            } else {
                emptyArray()
            }

            try {
                Files.move(state.partial.toPath(), state.destination.toPath(), *fallback)
            } catch (moveException: IOException) {
                throw DownloadFileException(
                    "move_failed",
                    "The completed download could not be moved into place.",
                )
            }
        } catch (exception: IOException) {
            throw DownloadFileException(
                if (!state.overwrite && state.destination.exists()) {
                    "destination_exists"
                } else {
                    "move_failed"
                },
                if (!state.overwrite && state.destination.exists()) {
                    "The download destination already exists."
                } else {
                    "The completed download could not be moved into place."
                },
            )
        }
    }

    private fun finishDownloadFailure(
        activity: FragmentActivity,
        call: Call?,
        state: DownloadState,
        exception: Exception,
    ) {
        val cancelled = state.cancelled
        val fileException = exception as? DownloadFileException

        finishDownload(
            activity = activity,
            call = call,
            state = state,
            cancelled = cancelled,
            failureMessage = fileException?.message
                ?: exception.message
                ?: "The download failed.",
            failureCode = fileException?.code
                ?: if (exception is IOException) failureCode(exception) else "write_failed",
        )
    }

    private fun finishDownload(
        activity: FragmentActivity,
        call: Call?,
        state: DownloadState,
        cancelled: Boolean = false,
        failureMessage: String,
        failureCode: String,
    ) {
        synchronized(state) {
            if (state.terminal) {
                return
            }
            state.terminal = true
        }

        cleanupDownload(call, state, removePartial = true)

        if (cancelled || state.cancelled) {
            emitCancelled(activity, state.requestId, state.sharedEvent)
        } else {
            emitFailure(
                activity,
                state.requestId,
                failureMessage,
                failureCode,
                state.sharedEvent,
            )
        }
    }

    private fun cleanupDownload(
        call: Call?,
        state: DownloadState,
        removePartial: Boolean,
    ) {
        state.retryFuture?.cancel(false)
        state.retryFuture = null
        state.call = null

        if (call != null) {
            calls.remove(state.requestId, call)
        } else {
            calls.remove(state.requestId)
        }
        downloads.remove(state.requestId, state)
        activeDestinations.remove(state.destinationKey, state.requestId)

        if (removePartial) {
            state.partial.delete()
        }
    }

    private fun buildRequestBody(
        activity: FragmentActivity,
        requestId: String,
        body: Map<String, Any>?,
        sharedEvent: String?,
    ): BodyBuildResult {
        if (body == null) {
            return BodyBuildResult()
        }

        return when (body["type"] as? String) {
            "json" -> {
                val data = body["data"]

                if (data == null) {
                    BodyBuildResult()
                } else {
                    val json = when (data) {
                        is Map<*, *> -> JSONObject(data as Map<*, *>).toString()
                        is Collection<*> -> JSONArray(data).toString()
                        else -> JSONObject.wrap(data)?.toString() ?: "null"
                    }

                    BodyBuildResult(
                        body = json.toRequestBody(
                            "application/json; charset=utf-8".toMediaType()
                        )
                    )
                }
            }
            "form" -> BodyBuildResult(
                body = (body["data"] as? String ?: "").toRequestBody(
                    "application/x-www-form-urlencoded; charset=utf-8".toMediaType()
                )
            )
            "raw" -> BodyBuildResult(
                body = (body["data"] as? String ?: "").toRequestBody(null)
            )
            "multipart" -> {
                buildMultipartRequestBody(
                    activity = activity,
                    requestId = requestId,
                    body = body,
                    sharedEvent = sharedEvent,
                )
            }
            else -> BodyBuildResult()
        }
    }

    private fun buildMultipartRequestBody(
        activity: FragmentActivity,
        requestId: String,
        body: Map<String, Any>,
        sharedEvent: String?,
    ): BodyBuildResult {
        val builder = MultipartBody.Builder()
            .setType(MultipartBody.FORM)

        val fields = bridgeMap(body["fields"])
            ?: emptyMap()

        fields.forEach { (name, value) ->
            builder.addFormDataPart(
                name,
                value.toString(),
            )
        }

        val files = bridgeList(body["files"])
            .mapNotNull(::bridgeMap)

        files.forEach { fileSpec ->
            val field =
                fileSpec["field"] as? String
                    ?: return multipartFailure(
                        activity,
                        requestId,
                        "Multipart file is missing its field name.",
                        "invalid_file",
                        sharedEvent,
                    )

            val path =
                fileSpec["path"] as? String
                    ?: return multipartFailure(
                        activity,
                        requestId,
                        "Multipart file is missing its path.",
                        "invalid_file",
                        sharedEvent,
                    )

            val filename =
                fileSpec["filename"] as? String
                    ?: File(path).name

            val mimeType =
                fileSpec["mime_type"] as? String
                    ?: "application/octet-stream"

            val file = File(path)

            if (!file.exists() || !file.isFile) {
                return multipartFailure(
                    activity,
                    requestId,
                    "Upload file does not exist or is not readable: $path",
                    "file_not_found",
                    sharedEvent,
                )
            }

            val mediaType = mimeType.toMediaType()

            builder.addFormDataPart(
                field,
                filename,
                file.asRequestBody(mediaType),
            )
        }

        val multipartBody = builder.build()

        val progressBody = ProgressRequestBody(
            delegate = multipartBody,
        ) { bytesSent, bytesTotal ->
            emitUploadProgress(
                activity = activity,
                requestId = requestId,
                bytesSent = bytesSent,
                bytesTotal = bytesTotal,
            )
        }

        return BodyBuildResult(
            body = progressBody,
        )
    }

    private fun multipartFailure(
        activity: FragmentActivity,
        requestId: String,
        message: String,
        code: String,
        sharedEvent: String?,
    ): BodyBuildResult {
        emitFailure(
            activity = activity,
            requestId = requestId,
            message = message,
            code = code,
            sharedEvent = sharedEvent,
        )

        return BodyBuildResult(
            failed = true,
        )
    }

    private fun emitStarted(
        activity: FragmentActivity,
        requestId: String,
        method: String,
        url: String,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("method", method)
            put("url", url)
        }

        NativeEventDispatcher.dispatch(
            activity = activity,
            eventClass = FetchEvents.STARTED,
            payload = payload,
        )
        announcedActiveRequests.add(requestId)
        emitActiveRequestsChanged(activity, requestId, "started")
    }

    private fun emitFailure(
        activity: FragmentActivity,
        requestId: String,
        message: String,
        code: String?,
        sharedEvent: String? = null,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("message", message)
            if (code != null) {
                put("code", code)
            }
        }

        NativeEventDispatcher.dispatch(
            activity = activity,
            eventClass = FetchEvents.FAILED,
            payload = payload,
        )

        emitSharedTerminal(activity, sharedEvent, "failed", payload)
        emitActiveRequestsChanged(activity, requestId, "finished")
    }

    private fun emitCancelled(
        activity: FragmentActivity,
        requestId: String,
        sharedEvent: String? = null,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
        }

        NativeEventDispatcher.dispatch(
            activity = activity,
            eventClass = FetchEvents.CANCELLED,
            payload = payload,
        )

        emitSharedTerminal(activity, sharedEvent, "cancelled", payload)
        emitActiveRequestsChanged(activity, requestId, "finished")
    }

    private fun emitUploadProgress(
        activity: FragmentActivity,
        requestId: String,
        bytesSent: Long,
        bytesTotal: Long,
    ) {
        val safeTotal = if (bytesTotal > 0L) bytesTotal else bytesSent
        val safeSent = if (bytesTotal > 0L) {
            bytesSent.coerceIn(0L, bytesTotal)
        } else {
            bytesSent.coerceAtLeast(0L)
        }

        val progress = if (safeTotal > 0L) {
            (safeSent.toDouble() / safeTotal.toDouble()).coerceIn(0.0, 1.0)
        } else {
            0.0
        }

        retryOperations[requestId]?.let { operation ->
            synchronized(operation) {
                operation.bytesTransferred = safeSent
                operation.bytesTotal = safeTotal.takeIf { it > 0L }
            }
        }

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("bytesSent", safeSent)
            put("bytesTotal", safeTotal)
            put("progress", progress)
        }

        NativeEventDispatcher.dispatch(
            activity = activity,
            eventClass = FetchEvents.UPLOAD_PROGRESS,
            payload = payload,
        )
        emitActiveRequestsChanged(activity, requestId, "progress", throttle = true)
    }

    private fun emitDownloadProgress(
        activity: FragmentActivity,
        requestId: String,
        bytesReceived: Long,
        bytesTotal: Long?,
        forceComplete: Boolean = false,
    ) {
        val safeReceived = bytesReceived.coerceAtLeast(0L)
        val progress = when {
            forceComplete -> 1.0
            bytesTotal != null && bytesTotal > 0L ->
                (safeReceived.toDouble() / bytesTotal.toDouble())
                    .coerceIn(0.0, 1.0)
            bytesTotal == 0L -> 0.0
            else -> null
        }

        downloads[requestId]?.let { state ->
            synchronized(state) {
                state.bytesTransferred = safeReceived
                state.bytesTotal = bytesTotal
            }
        }

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("bytesReceived", safeReceived)
            put("bytesTotal", bytesTotal ?: JSONObject.NULL)
            put("progress", progress ?: JSONObject.NULL)
        }

        NativeEventDispatcher.dispatch(activity, FetchEvents.DOWNLOAD_PROGRESS, payload)
        emitActiveRequestsChanged(activity, requestId, "progress", throttle = true)
    }

    private fun emitDownloadCompleted(
        activity: FragmentActivity,
        requestId: String,
        status: Int,
        response: Response,
        path: String,
        bytesReceived: Long,
        sharedEvent: String?,
    ) {
        val responseHeaders = JSONObject()
        response.headers.names().forEach { name ->
            val values = response.headers.values(name)
            if (values.size == 1) {
                responseHeaders.put(name, values.first())
            } else {
                responseHeaders.put(name, JSONArray(values))
            }
        }

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("status", status)
            put("headers", responseHeaders)
            put("path", path)
            put("bytesReceived", bytesReceived)
        }

        NativeEventDispatcher.dispatch(activity, FetchEvents.DOWNLOAD_COMPLETED, payload)
        emitSharedCompleted(
            activity = activity,
            sharedEvent = sharedEvent,
            requestId = requestId,
            httpStatus = status,
            headers = responseHeaders,
            path = path,
            bytesReceived = bytesReceived,
        )
        emitActiveRequestsChanged(activity, requestId, "finished")
    }

    private fun emitSharedCompleted(
        activity: FragmentActivity,
        sharedEvent: String?,
        requestId: String,
        httpStatus: Int,
        headers: JSONObject,
        body: String? = null,
        path: String? = null,
        bytesReceived: Long? = null,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("status", "completed")
            put("httpStatus", httpStatus)
            put("headers", headers)
            body?.let { put("body", it) }
            path?.let { put("path", it) }
            bytesReceived?.let { put("bytesReceived", it) }
        }

        emitSharedTerminal(activity, sharedEvent, "completed", payload)
    }

    private fun emitSharedTerminal(
        activity: FragmentActivity,
        sharedEvent: String?,
        status: String,
        source: JSONObject,
    ) {
        val event = sharedEvent?.takeIf { it.isNotBlank() } ?: return
        val payload = JSONObject(source.toString()).apply {
            put("status", status)
            if (status == "failed" && !has("code")) {
                put("code", JSONObject.NULL)
            }
        }
        NativeEventDispatcher.dispatch(activity, event, payload)
    }

    private fun scheduleRetry(
        activity: FragmentActivity,
        operation: RetryOperation,
        reason: String,
        status: Int?,
        retryAfter: String?,
        action: () -> Unit,
    ): Boolean {
        val policy = operation.policy ?: return false
        val delay = synchronized(operation) {
            if (operation.cancelled || operation.terminal ||
                operation.attempt >= policy.maxAttempts
            ) return false

            retryDelay(policy, operation.attempt, retryAfter).also {
                operation.state = "waiting_retry"
                operation.nextRetryAt = System.currentTimeMillis() + it
            }
        }

        emitRetrying(
            activity,
            operation.requestId,
            operation.attempt + 1,
            policy.maxAttempts,
            delay,
            reason,
            status,
        )
        calls.remove(operation.requestId)
        operation.retryFuture = retryScheduler.schedule({
            synchronized(operation) {
                if (operation.cancelled || operation.terminal) return@schedule
            }
            action()
        }, delay.toLong(), TimeUnit.MILLISECONDS)
        return true
    }

    private fun progressValue(bytesTransferred: Long, bytesTotal: Long?): Any =
        if (bytesTotal != null && bytesTotal > 0L) {
            (bytesTransferred.toDouble() / bytesTotal.toDouble()).coerceIn(0.0, 1.0)
        } else {
            JSONObject.NULL
        }

    private fun isRetryableNetwork(code: String): Boolean = code in setOf(
        "timeout",
        "offline",
        "dns_failure",
        "connection_failed",
        "network_error",
    )

    private fun markRetryTerminal(operation: RetryOperation): Boolean =
        synchronized(operation) {
            if (operation.terminal || operation.cancelled) false
            else {
                operation.terminal = true
                true
            }
        }

    private fun isRetryCancelled(operation: RetryOperation): Boolean =
        synchronized(operation) {
            operation.cancelled
        }

    private fun finishRetryFailure(
        activity: FragmentActivity,
        operation: RetryOperation,
        message: String,
        code: String,
    ) {
        if (!markRetryTerminal(operation)) return
        operation.retryFuture?.cancel(false)
        operation.retryFuture = null
        operation.call = null
        calls.remove(operation.requestId)
        retryOperations.remove(operation.requestId, operation)
        emitFailure(activity, operation.requestId, message, code, operation.sharedEvent)
    }

    private fun finishRetryCancelled(
        activity: FragmentActivity,
        operation: RetryOperation,
    ) {
        synchronized(operation) {
            if (operation.terminal) return
            operation.cancelled = true
            operation.terminal = true
            operation.retryFuture?.cancel(false)
            operation.retryFuture = null
            operation.call = null
        }
        calls.remove(operation.requestId)
        retryOperations.remove(operation.requestId, operation)
        emitCancelled(activity, operation.requestId, operation.sharedEvent)
    }

    private fun emitRetrying(
        activity: FragmentActivity,
        requestId: String,
        attempt: Int,
        maxAttempts: Int,
        delayMs: Int,
        reason: String,
        status: Int?,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("attempt", attempt)
            put("maxAttempts", maxAttempts)
            put("delayMs", delayMs)
            put("reason", reason)
            put("status", status ?: JSONObject.NULL)
        }
        NativeEventDispatcher.dispatch(activity, FetchEvents.RETRYING, payload)
        emitActiveRequestsChanged(activity, requestId, "waiting_retry")
    }

    private fun emitActiveRequestsChanged(
        activity: FragmentActivity,
        requestId: String,
        change: String,
        throttle: Boolean = false,
    ) {
        if (change == "finished") {
            if (!announcedActiveRequests.remove(requestId)) return
            activeProgressTimes.remove(requestId)
        } else if (!announcedActiveRequests.contains(requestId) && change != "started") {
            return
        }

        if (throttle) {
            val now = System.currentTimeMillis()
            var shouldEmit = false
            activeProgressTimes.compute(requestId) { _, previous ->
                if (previous == null || now - previous >= 250L) {
                    shouldEmit = true
                    now
                } else {
                    previous
                }
            }
            if (!shouldEmit) return
        }

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("change", change)
        }
        NativeEventDispatcher.dispatch(
            activity,
            FetchEvents.ACTIVE_REQUESTS_CHANGED,
            payload,
        )
    }

    private fun failureCode(
        exception: IOException,
    ): String {
        val message = exception.message
            ?.lowercase()
            ?: ""

        return when {
            exception is InterruptedIOException || "timeout" in message ->
                "timeout"
            exception is UnknownHostException ->
                "dns_failure"
            exception is SSLException ->
                "tls_failure"
            "network is unreachable" in message ||
                "no route to host" in message ->
                "offline"
            "unable to resolve host" in message ->
                "dns_failure"
            "failed to connect" in message ->
                "connection_failed"
            else ->
                "network_error"
        }
    }
}

private data class BodyBuildResult(
    val body: RequestBody? = null,
    val failed: Boolean = false,
)

object FetchFunctions {

    class Start(
        private val activity: FragmentActivity,
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>,
        ): Map<String, Any> {
            val requestId =
                parameters["request_id"] as? String
                    ?: return BridgeResponse.error(
                        "fetch.missing_request_id",
                        "Fetch.Start requires request_id."
                    )

            val method =
                (parameters["method"] as? String)
                    ?.uppercase()
                    ?: "GET"

            val url =
                parameters["url"] as? String
                    ?: return BridgeResponse.error(
                        "fetch.missing_url",
                        "Fetch.Start requires url."
                    )

            val timeout =
                (parameters["timeout"] as? Number)
                    ?.toLong()
                    ?: 30L

            val rawHeaders = bridgeMap(parameters["headers"])
                ?: emptyMap()

            val headers = rawHeaders
                .mapValues { (_, value) ->
                    value.toString()
                }

            val query = bridgeMap(parameters["query"])
                ?: emptyMap()

            val body = bridgeMap(parameters["body"])
            val retryPolicy = bridgeRetryPolicy(parameters["retry"])
            val sharedEvent = (parameters["shared_event"] as? String)
                ?.takeIf { it.isNotBlank() }

            return try {
                FetchClient.start(
                    activity = activity,
                    requestId = requestId,
                    method = method,
                    url = url,
                    headers = headers,
                    query = query,
                    body = body,
                    timeoutSeconds = timeout,
                    retryPolicy = retryPolicy,
                    sharedEvent = sharedEvent,
                )

                BridgeResponse.success(
                    mapOf(
                        "accepted" to true,
                        "request_id" to requestId,
                    )
                )
            } catch (exception: Exception) {
                BridgeResponse.error(
                    "fetch.start_failed",
                    exception.message
                        ?: "Unable to start request."
                )
            }
        }
    }

    class Cancel(
        private val context: Context,
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>,
        ): Map<String, Any> {
            val requestId =
                parameters["request_id"] as? String
                    ?: return BridgeResponse.error(
                        "fetch.missing_request_id",
                        "Fetch.Cancel requires request_id."
                    )

            val cancelled =
                FetchClient.cancel(requestId)

            return BridgeResponse.success(
                mapOf(
                    "request_id" to requestId,
                    "cancelled" to cancelled,
                )
            )
        }
    }

    class Active(
        private val context: Context,
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>,
        ): Map<String, Any> = BridgeResponse.success(
            mapOf("requests" to FetchClient.activeRequests())
        )
    }

    class CancelAll(
        private val context: Context,
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>,
        ): Map<String, Any> = BridgeResponse.success(
            mapOf("cancelled" to FetchClient.cancelAll())
        )
    }

    class Download(
        private val activity: FragmentActivity,
    ) : BridgeFunction {

        override fun execute(
            parameters: Map<String, Any>,
        ): Map<String, Any> {
            val requestId = parameters["request_id"] as? String
                ?: return BridgeResponse.error(
                    "fetch.missing_request_id",
                    "Fetch.Download requires request_id.",
                )
            val url = parameters["url"] as? String
                ?: return BridgeResponse.error(
                    "fetch.missing_url",
                    "Fetch.Download requires url.",
                )
            val destination = parameters["destination"] as? String
                ?: return BridgeResponse.error(
                    "fetch.invalid_destination",
                    "Fetch.Download requires destination.",
                )
            val timeout = (parameters["timeout"] as? Number)?.toLong() ?: 30L
            val overwrite = parameters["overwrite"] as? Boolean ?: false
            val rawHeaders = bridgeMap(parameters["headers"]) ?: emptyMap()
            val headers = rawHeaders.mapValues { (_, value) -> value.toString() }
            val query = bridgeMap(parameters["query"]) ?: emptyMap()
            val retryPolicy = bridgeRetryPolicy(parameters["retry"])
            val sharedEvent = (parameters["shared_event"] as? String)
                ?.takeIf { it.isNotBlank() }

            return try {
                FetchClient.download(
                    activity,
                    requestId,
                    url,
                    destination,
                    headers,
                    query,
                    timeout,
                    overwrite,
                    retryPolicy,
                    sharedEvent,
                )

                BridgeResponse.success(
                    mapOf(
                        "accepted" to true,
                        "request_id" to requestId,
                    )
                )
            } catch (exception: DownloadStartException) {
                BridgeResponse.error(
                    "fetch.${exception.code}",
                    exception.message,
                )
            } catch (exception: Exception) {
                BridgeResponse.error(
                    "fetch.download_failed",
                    exception.message ?: "Unable to start download.",
                )
            }
        }
    }
}
