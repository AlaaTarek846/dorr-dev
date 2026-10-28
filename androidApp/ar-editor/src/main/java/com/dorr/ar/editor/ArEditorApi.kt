package com.dorr.ar.editor

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** Minimal HTTP client so this module does not depend on the app's Retrofit setup. [auth] is the full "Bearer ..." header. */
class ArEditorApi(private val base: String, private val auth: String) {
    class AssetInfo(val id: Int, val name: String)

    suspend fun assets(): List<AssetInfo> {
        val data = JSONObject(call("GET", "user/v1/ai-ar/assets")).getJSONArray("data")
        return (0 until data.length()).map { data.getJSONObject(it) }.map { AssetInfo(it.getInt("id"), it.optString("name")) }
    }

    /** Fresh signed link (valid about 10 minutes) for the model's GLB. */
    suspend fun downloadUrl(assetId: Int): String =
        JSONObject(call("GET", "user/v1/ai-ar/assets/$assetId")).getJSONObject("data").getString("download_url")

    suspend fun download(url: String): ByteArray = withContext(Dispatchers.IO) {
        val c = (URL(url).openConnection() as HttpURLConnection).apply { connectTimeout = 15000; readTimeout = 60000 }
        try {
            if (c.responseCode !in 200..299) error("HTTP ${c.responseCode}")
            c.inputStream.use { it.readBytes() }
        } finally { c.disconnect() }
    }

    suspend fun saveScene(experienceId: Int, body: JSONObject) {
        call("PUT", "user/v1/ai-ar/experiences/$experienceId/scene", body.toString())
    }

    private suspend fun call(method: String, path: String, body: String? = null): String = withContext(Dispatchers.IO) {
        val c = (URL(base + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15000
            readTimeout = 30000
            setRequestProperty("Authorization", auth)
            setRequestProperty("Accept", "application/json")
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
                outputStream.use { it.write(body.toByteArray()) }
            }
        }
        try {
            val stream = if (c.responseCode in 200..299) c.inputStream else c.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            if (c.responseCode !in 200..299) error(runCatching { JSONObject(text).optString("message") }.getOrNull()?.takeIf { it.isNotBlank() } ?: "HTTP ${c.responseCode}")
            text
        } finally { c.disconnect() }
    }
}
