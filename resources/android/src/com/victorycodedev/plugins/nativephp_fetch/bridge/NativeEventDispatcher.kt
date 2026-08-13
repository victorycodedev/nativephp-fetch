package com.victorycodedev.plugins.nativephp_fetch

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

internal object NativeEventDispatcher {
    fun dispatch(activity: FragmentActivity, eventClass: String, payload: JSONObject) {
        Handler(Looper.getMainLooper()).post {
            try {
                NativeActionCoordinator.dispatchEvent(activity, eventClass, payload.toString())
            } catch (exception: Exception) {
                Log.e("NativePHPFetch", "Failed to dispatch $eventClass: ${exception.message}", exception)
            }
        }
    }
}
