package com.dorr.app.ui.locale

import android.content.Context
import com.dorr.app.network.ApiClient
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import retrofit2.HttpException
import java.io.File

/**
 * Interface strings of a language that is not bundled in the APK (anything but ar/en), downloaded
 * from GET general/v1/translations/{code}/android. Only the current language is kept on disk:
 * `filesDir/translations/{code}.json` holds the flat `strings` map (`key -> text`, or
 * `key -> {quantity -> text}` for plurals); its code/version/direction live in "dorr_app_prefs" so
 * a cold start without internet still has them.
 */
object DownloadedTranslations {
    private const val PREFS_NAME = "dorr_app_prefs"
    private const val KEY_CODE = "translations_code"
    private const val KEY_VERSION = "translations_version"
    private const val KEY_DIRECTION = "translations_direction"
    private const val DIRECTORY = "translations"

    data class Meta(val code: String, val version: String, val direction: String)

    sealed interface Result {
        data object Success : Result
        /** 404 — the language has no published Android strings any more (or was disabled). */
        data object NotAvailable : Result
        data object Failed : Result
    }

    @Volatile
    private var memory: Pair<String, Map<String, Any>>? = null

    fun meta(context: Context): Meta? {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val code = prefs.getString(KEY_CODE, null) ?: return null
        return Meta(
            code = code,
            version = prefs.getString(KEY_VERSION, null).orEmpty(),
            direction = prefs.getString(KEY_DIRECTION, null) ?: "ltr",
        )
    }

    /** The stored strings of [code], read from disk once and then kept in memory; null when absent. */
    fun load(context: Context, code: String): Map<String, Any>? {
        memory?.let { (cached, strings) -> if (cached == code) return strings }
        if (meta(context)?.code != code) return null

        val file = fileFor(context, code)
        if (!file.isFile) return null

        val strings = runCatching { parse(JsonParser.parseString(file.readText()).asJsonObject) }.getOrNull()
            ?: return null
        memory = code to strings
        return strings
    }

    /**
     * Downloads [code] into a temporary file and renames it into place, so a failed or interrupted
     * download never replaces the working file. Only after success are the metadata updated and any
     * other language file deleted.
     */
    suspend fun download(context: Context, code: String): Result = withContext(Dispatchers.IO) {
        val body = try {
            ApiClient.languages.androidStrings(code).string()
        } catch (e: HttpException) {
            return@withContext if (e.code() == 404) Result.NotAvailable else Result.Failed
        } catch (e: Exception) {
            return@withContext Result.Failed
        }

        val data = runCatching { JsonParser.parseString(body).asJsonObject.getAsJsonObject("data") }.getOrNull()
        val stringsJson = data?.getAsJsonObject("strings") ?: return@withContext Result.Failed
        val strings = runCatching { parse(stringsJson) }.getOrNull() ?: return@withContext Result.Failed
        val version = data.get("version")?.takeIf { !it.isJsonNull }?.asString.orEmpty()
        val direction = data.get("direction")?.takeIf { !it.isJsonNull }?.asString ?: "ltr"

        val directory = File(context.filesDir, DIRECTORY).apply { mkdirs() }
        val target = fileFor(context, code)
        val temporary = File(directory, "$code.json.part")

        val written = runCatching {
            temporary.writeText(stringsJson.toString())
            if (!temporary.renameTo(target)) {
                target.delete()
                check(temporary.renameTo(target))
            }
        }.isSuccess

        if (!written) {
            temporary.delete()
            return@withContext Result.Failed
        }

        directory.listFiles()?.filter { it.name != target.name }?.forEach { it.delete() }

        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .putString(KEY_CODE, code)
            .putString(KEY_VERSION, version)
            .putString(KEY_DIRECTION, direction)
            .apply()
        memory = code to strings

        Result.Success
    }

    /** Back to a bundled language (ar/en): nothing downloaded is kept. */
    fun clear(context: Context) {
        memory = null
        File(context.filesDir, DIRECTORY).deleteRecursively()
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit()
            .remove(KEY_CODE)
            .remove(KEY_VERSION)
            .remove(KEY_DIRECTION)
            .apply()
    }

    private fun fileFor(context: Context, code: String) = File(File(context.filesDir, DIRECTORY), "$code.json")

    private fun parse(json: JsonObject): Map<String, Any> {
        val strings = HashMap<String, Any>(json.size())
        for ((key, value) in json.entrySet()) {
            when {
                value.isJsonPrimitive -> strings[key] = value.asString
                value.isJsonObject -> strings[key] = value.asJsonObject.entrySet()
                    .filter { it.value.isJsonPrimitive }
                    .associate { it.key to it.value.asString }
            }
        }
        return strings
    }
}
