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
import java.io.IOException
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

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

    private val client = OkHttpClient.Builder()
        .retryOnConnectionFailure(false)
        .build()

    private val calls = ConcurrentHashMap<String, Call>()

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

    fun cancel(requestId: String): Boolean {
        val call = calls.remove(requestId) ?: return false
        call.cancel()
        return true
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
            "timeout" in message ->
                "timeout"
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
        var lastPercent = -1
        var lastEmitAt = 0L

        val forwardingSink =
            object : ForwardingSink(sink) {
                override fun write(
                    source: okio.Buffer,
                    byteCount: Long,
                ) {
                    super.write(source, byteCount)
                    bytesWritten += byteCount

                    val now = System.currentTimeMillis()

                    val percent =
                        if (totalBytes > 0L) {
                            ((bytesWritten * 100L) / totalBytes)
                                .toInt()
                                .coerceIn(0, 100)
                        } else {
                            -1
                        }

                    val finished =
                        totalBytes > 0L && bytesWritten >= totalBytes

                    val shouldEmit =
                        finished ||
                            (percent >= 0 && percent != lastPercent) ||
                            (now - lastEmitAt >= 100L)

                    if (shouldEmit) {
                        if (percent >= 0) {
                            lastPercent = percent
                        }
                        lastEmitAt = now

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

        onProgress(finalSent, finalTotal)
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
}
