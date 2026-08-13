package com.victorycodedev.plugins.nativephp_fetch

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
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
import okio.BufferedSink
import okio.ForwardingSink
import okio.buffer
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
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

private fun bridgeMap(value: Any?): Map<String, Any>? =
    when (value) {
        is Map<*, *> -> value.entries.mapNotNull { (key, item) ->
            val normalized = normalizeBridgeValue(item)
            if (key == null || normalized == null) {
                null
            } else {
                key.toString() to normalized
            }
        }.toMap()
        is JSONObject -> value.keys().asSequence().mapNotNull { key ->
            val normalized = normalizeBridgeValue(value.opt(key))
            normalized?.let { key to it }
        }.toMap()
        else -> null
    }

private fun bridgeList(value: Any?): List<Any> =
    when (value) {
        is Collection<*> -> value.mapNotNull(::normalizeBridgeValue)
        is JSONArray -> (0 until value.length()).mapNotNull { index ->
            normalizeBridgeValue(value.opt(index))
        }
        else -> emptyList()
    }

private fun normalizeBridgeValue(value: Any?): Any? =
    when (value) {
        null, JSONObject.NULL -> null
        is Map<*, *>, is JSONObject -> bridgeMap(value)
        is Collection<*>, is JSONArray -> bridgeList(value)
        else -> value
    }

private object FetchClient {

    private const val TAG = "NativePHPFetch"

    private const val EVENT_STARTED =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestStarted"

    private const val EVENT_COMPLETED =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCompleted"

    private const val EVENT_FAILED =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestFailed"

    private const val EVENT_CANCELLED =
        "Victorycodedev\\NativephpFetch\\Events\\FetchRequestCancelled"

    private const val EVENT_UPLOAD_PROGRESS =
        "Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress"

    private const val EVENT_DOWNLOAD_PROGRESS =
        "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadProgress"

    private const val EVENT_DOWNLOAD_COMPLETED =
        "Victorycodedev\\NativephpFetch\\Events\\FetchDownloadCompleted"

    private val client = OkHttpClient.Builder()
        .retryOnConnectionFailure(false)
        .build()

    private val calls = ConcurrentHashMap<String, Call>()

    private val downloads = ConcurrentHashMap<String, DownloadState>()

    private val activeDestinations = ConcurrentHashMap<String, String>()

    fun start(
        activity: FragmentActivity,
        requestId: String,
        method: String,
        url: String,
        headers: Map<String, String>,
        query: Map<String, Any>,
        body: Map<String, Any>?,
        timeoutSeconds: Long,
    ) {
        val parsedUrl = url.toHttpUrlOrNull()
            ?: run {
                emitFailure(
                    activity = activity,
                    requestId = requestId,
                    message = "The supplied URL is invalid.",
                    code = "invalid_url",
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

        val call = timedClient.newCall(request)
        calls[requestId] = call

        emitStarted(
            activity = activity,
            requestId = requestId,
            method = method.uppercase(),
            url = request.url.toString(),
        )

        call.enqueue(object : Callback {
            override fun onFailure(
                call: Call,
                exception: IOException,
            ) {
                calls.remove(requestId)

                if (call.isCanceled()) {
                    emitCancelled(
                        activity = activity,
                        requestId = requestId,
                    )
                    return
                }

                emitFailure(
                    activity = activity,
                    requestId = requestId,
                    message = exception.message ?: "Network request failed.",
                    code = failureCode(exception),
                )
            }

            override fun onResponse(
                call: Call,
                response: Response,
            ) {
                response.use {
                    calls.remove(requestId)

                    val responseHeaders = JSONObject()

                    response.headers.names().forEach { name ->
                        val values = response.headers.values(name)

                        if (values.size == 1) {
                            responseHeaders.put(name, values.first())
                        } else {
                            val array = JSONArray()
                            values.forEach { value ->
                                array.put(value)
                            }
                            responseHeaders.put(name, array)
                        }
                    }

                    val responseBody = response.body?.string().orEmpty()

                    val payload = JSONObject().apply {
                        put("requestId", requestId)
                        put("status", response.code)
                        put("headers", responseHeaders)
                        put("body", responseBody)
                    }

                    dispatchEvent(
                        activity = activity,
                        eventClass = EVENT_COMPLETED,
                        payload = payload,
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

        val call = try {
            val requestBuilder = Request.Builder()
                .url(urlBuilder.build())
                .get()

            headers.forEach { (name, value) ->
                requestBuilder.header(name, value)
            }

            val timedClient = client.newBuilder()
                .callTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .writeTimeout(timeoutSeconds, TimeUnit.SECONDS)
                .build()

            timedClient.newCall(requestBuilder.build())
        } catch (exception: Exception) {
            activeDestinations.remove(destinationKey, requestId)
            partial.delete()
            throw DownloadStartException(
                "network_error",
                exception.message ?: "The native download could not be prepared.",
            )
        }
        val state = DownloadState(
            requestId = requestId,
            destination = destination,
            destinationKey = destinationKey,
            partial = partial,
            overwrite = overwrite,
        )

        downloads[requestId] = state
        calls[requestId] = call

        emitStarted(
            activity = activity,
            requestId = requestId,
            method = "GET",
            url = call.request().url.toString(),
        )

        val callback = object : Callback {
            override fun onFailure(call: Call, exception: IOException) {
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

    fun cancel(requestId: String): Boolean {
        val call = calls[requestId] ?: return false

        downloads[requestId]?.let { state ->
            synchronized(state) {
                if (state.terminal) {
                    return false
                }
                state.cancelled = true
            }
        }

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
        call: Call,
        state: DownloadState,
        exception: Exception,
    ) {
        val cancelled = call.isCanceled() || state.cancelled
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
        call: Call,
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

        if (cancelled || state.cancelled || call.isCanceled()) {
            emitCancelled(activity, state.requestId)
        } else {
            emitFailure(
                activity,
                state.requestId,
                failureMessage,
                failureCode,
            )
        }
    }

    private fun cleanupDownload(
        call: Call,
        state: DownloadState,
        removePartial: Boolean,
    ) {
        calls.remove(state.requestId, call)
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
            "multipart" -> {
                buildMultipartRequestBody(
                    activity = activity,
                    requestId = requestId,
                    body = body,
                )
            }
            else -> BodyBuildResult()
        }
    }

    private fun buildMultipartRequestBody(
        activity: FragmentActivity,
        requestId: String,
        body: Map<String, Any>,
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
                    )

            val path =
                fileSpec["path"] as? String
                    ?: return multipartFailure(
                        activity,
                        requestId,
                        "Multipart file is missing its path.",
                        "invalid_file",
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
    ): BodyBuildResult {
        emitFailure(
            activity = activity,
            requestId = requestId,
            message = message,
            code = code,
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

        dispatchEvent(
            activity = activity,
            eventClass = EVENT_STARTED,
            payload = payload,
        )
    }

    private fun emitFailure(
        activity: FragmentActivity,
        requestId: String,
        message: String,
        code: String?,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("message", message)
            if (code != null) {
                put("code", code)
            }
        }

        dispatchEvent(
            activity = activity,
            eventClass = EVENT_FAILED,
            payload = payload,
        )
    }

    private fun emitCancelled(
        activity: FragmentActivity,
        requestId: String,
    ) {
        val payload = JSONObject().apply {
            put("requestId", requestId)
        }

        dispatchEvent(
            activity = activity,
            eventClass = EVENT_CANCELLED,
            payload = payload,
        )
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

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("bytesSent", safeSent)
            put("bytesTotal", safeTotal)
            put("progress", progress)
        }

        dispatchEvent(
            activity = activity,
            eventClass = EVENT_UPLOAD_PROGRESS,
            payload = payload,
        )
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

        val payload = JSONObject().apply {
            put("requestId", requestId)
            put("bytesReceived", safeReceived)
            put("bytesTotal", bytesTotal ?: JSONObject.NULL)
            put("progress", progress ?: JSONObject.NULL)
        }

        dispatchEvent(activity, EVENT_DOWNLOAD_PROGRESS, payload)
    }

    private fun emitDownloadCompleted(
        activity: FragmentActivity,
        requestId: String,
        status: Int,
        response: Response,
        path: String,
        bytesReceived: Long,
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

        dispatchEvent(activity, EVENT_DOWNLOAD_COMPLETED, payload)
    }

    private fun dispatchEvent(
        activity: FragmentActivity,
        eventClass: String,
        payload: JSONObject,
    ) {
        Handler(Looper.getMainLooper()).post {
            try {
                NativeActionCoordinator.dispatchEvent(
                    activity,
                    eventClass,
                    payload.toString(),
                )
            } catch (exception: Exception) {
                Log.e(
                    TAG,
                    "Failed to dispatch $eventClass: ${exception.message}",
                    exception,
                )
            }
        }
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

private class DownloadStartException(
    val code: String,
    override val message: String,
) : Exception(message)

private class DownloadFileException(
    val code: String,
    override val message: String,
) : IOException(message)

private data class DownloadState(
    val requestId: String,
    val destination: File,
    val destinationKey: String,
    val partial: File,
    val overwrite: Boolean,
    @Volatile var cancelled: Boolean = false,
    @Volatile var terminal: Boolean = false,
)

private class ProgressRequestBody(
    private val delegate: RequestBody,
    private val onProgress: (
        bytesSent: Long,
        bytesTotal: Long,
    ) -> Unit,
) : RequestBody() {

    override fun contentType() =
        delegate.contentType()

    override fun contentLength(): Long =
        delegate.contentLength()

    override fun isOneShot(): Boolean =
        delegate.isOneShot()

    override fun writeTo(sink: BufferedSink) {
        val totalBytes = contentLength()

        var bytesWritten = 0L
        var lastEmitAt = 0L
        var lastEmittedBytes = -1L

        val forwardingSink =
            object : ForwardingSink(sink) {
                override fun write(
                    source: okio.Buffer,
                    byteCount: Long,
                ) {
                    super.write(source, byteCount)
                    bytesWritten += byteCount

                    val now = System.currentTimeMillis()

                    val finished =
                        totalBytes > 0L && bytesWritten >= totalBytes

                    val shouldEmit =
                        finished ||
                            lastEmitAt == 0L ||
                            (now - lastEmitAt >= 100L)

                    if (shouldEmit) {
                        lastEmitAt = now
                        lastEmittedBytes = bytesWritten

                        onProgress(
                            bytesWritten,
                            if (totalBytes > 0L) totalBytes else bytesWritten,
                        )
                    }
                }
            }

        val bufferedSink = forwardingSink.buffer()

        delegate.writeTo(bufferedSink)
        bufferedSink.flush()

        val finalTotal =
            if (totalBytes > 0L) totalBytes else bytesWritten

        val finalSent =
            if (totalBytes > 0L) {
                totalBytes
            } else {
                bytesWritten
            }

        if (lastEmittedBytes != finalSent) {
            onProgress(finalSent, finalTotal)
        }
    }
}

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
