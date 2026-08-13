package com.victorycodedev.plugins.nativephp_fetch

import org.json.JSONArray
import org.json.JSONObject

internal fun bridgeMap(value: Any?): Map<String, Any>? = when (value) {
    is Map<*, *> -> value.entries.mapNotNull { (key, item) ->
        val normalized = normalizeBridgeValue(item)
        if (key == null || normalized == null) null else key.toString() to normalized
    }.toMap()
    is JSONObject -> value.keys().asSequence().mapNotNull { key ->
        normalizeBridgeValue(value.opt(key))?.let { key to it }
    }.toMap()
    else -> null
}

internal fun bridgeList(value: Any?): List<Any> = when (value) {
    is Collection<*> -> value.mapNotNull(::normalizeBridgeValue)
    is JSONArray -> (0 until value.length()).mapNotNull { normalizeBridgeValue(value.opt(it)) }
    else -> emptyList()
}

internal fun normalizeBridgeValue(value: Any?): Any? = when (value) {
    null, JSONObject.NULL -> null
    is Map<*, *>, is JSONObject -> bridgeMap(value)
    is Collection<*>, is JSONArray -> bridgeList(value)
    else -> value
}
