package com.victorycodedev.plugins.nativephp_fetch

import okhttp3.RequestBody
import okio.BufferedSink
import okio.ForwardingSink
import okio.buffer

internal class ProgressRequestBody(
    private val delegate: RequestBody,
    private val onProgress: (bytesSent: Long, bytesTotal: Long) -> Unit,
) : RequestBody() {
    override fun contentType() = delegate.contentType()
    override fun contentLength() = delegate.contentLength()
    override fun isOneShot() = delegate.isOneShot()

    override fun writeTo(sink: BufferedSink) {
        val totalBytes = contentLength()
        var bytesWritten = 0L
        var lastEmitAt = 0L
        var lastEmittedBytes = -1L
        val forwardingSink = object : ForwardingSink(sink) {
            override fun write(source: okio.Buffer, byteCount: Long) {
                super.write(source, byteCount)
                bytesWritten += byteCount
                val now = System.currentTimeMillis()
                val finished = totalBytes > 0L && bytesWritten >= totalBytes
                if (finished || lastEmitAt == 0L || now - lastEmitAt >= 100L) {
                    lastEmitAt = now
                    lastEmittedBytes = bytesWritten
                    onProgress(bytesWritten, if (totalBytes > 0L) totalBytes else bytesWritten)
                }
            }
        }
        val bufferedSink = forwardingSink.buffer()
        delegate.writeTo(bufferedSink)
        bufferedSink.flush()
        val finalTotal = if (totalBytes > 0L) totalBytes else bytesWritten
        val finalSent = if (totalBytes > 0L) totalBytes else bytesWritten
        if (lastEmittedBytes != finalSent) onProgress(finalSent, finalTotal)
    }
}
