package com.victorycodedev.plugins.nativephp_fetch

import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import kotlin.math.pow
import kotlin.random.Random

internal data class RetryPolicy(
    val times: Int,
    val delay: Long,
    val multiplier: Double,
    val maxDelay: Long?,
    val statuses: Set<Int>,
) {
    val maxAttempts: Int = times + 1
}

internal fun bridgeRetryPolicy(value: Any?): RetryPolicy? {
    val retry = bridgeMap(value) ?: return null
    val defaults = setOf(408, 429, 500, 502, 503, 504)
    val custom = bridgeList(retry["statuses"]).mapNotNull { (it as? Number)?.toInt() }.toSet()
    return RetryPolicy(
        times = (retry["times"] as? Number)?.toInt() ?: 3,
        delay = (retry["delay"] as? Number)?.toLong() ?: 500L,
        multiplier = (retry["multiplier"] as? Number)?.toDouble() ?: 2.0,
        maxDelay = (retry["max_delay"] as? Number)?.toLong(),
        statuses = custom.ifEmpty { defaults },
    )
}

internal fun retryDelay(policy: RetryPolicy, completedAttempt: Int, retryAfter: String?): Int {
    val exponent = (completedAttempt - 1).coerceAtLeast(0)
    val calculated = (policy.delay.toDouble() * policy.multiplier.pow(exponent.toDouble()))
        .coerceAtMost(Long.MAX_VALUE.toDouble()).toLong()
    val base = parseRetryAfter(retryAfter) ?: calculated
    val jittered = base.toDouble() * Random.nextDouble(0.8, 1.2)
    val capped = policy.maxDelay?.let { minOf(jittered, it.toDouble()) } ?: jittered
    return capped.coerceIn(0.0, Int.MAX_VALUE.toDouble()).toInt()
}

private fun parseRetryAfter(value: String?): Long? {
    val text = value?.trim()?.takeIf { it.isNotEmpty() } ?: return null
    text.toLongOrNull()?.let { return it.coerceAtLeast(0L).coerceAtMost(Long.MAX_VALUE / 1000L) * 1000L }
    return try {
        (ZonedDateTime.parse(text, DateTimeFormatter.RFC_1123_DATE_TIME).toInstant().toEpochMilli()
            - System.currentTimeMillis()).coerceAtLeast(0L)
    } catch (_: Exception) { null }
}
