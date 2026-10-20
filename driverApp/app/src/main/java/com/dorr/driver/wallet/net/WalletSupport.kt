package com.dorr.driver.wallet.net

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import com.dorr.core.network.CoreHttp
import com.dorr.driver.BuildConfig
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.theme.DriverPrefs
import com.google.gson.JsonElement
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/*
 * The DORR app's wallet (O-35) runs in the captain app on this thin layer: the same answers and failures,
 * the captain's own session, and the captain's `driver/v1` wallet routes — so the wallet screens are the
 * DORR app's, unchanged.
 */

data class ApiEnvelope<T>(
    val success: Boolean,
    val status: String,
    val code: Int,
    val message: String,
    val data: T?,
    /** Present only on paginated lists. */
    val pagination: PaginationDto? = null,
)

data class PaginationDto(
    @SerializedName("current_page") val currentPage: Int,
    @SerializedName("has_more_pages") val hasMorePages: Boolean,
)

/** A refused request, read without ever throwing (same as the DORR app). `message` is null offline. */
data class ApiFailure(
    val message: String?,
    val errorCode: String?,
    val httpStatus: Int?,
    /** `data.locked_until` on a `wallet_pin_locked` (423) answer — an ISO-8601 instant. */
    val lockedUntil: String? = null,
    val retryAfter: Int? = null,
)

fun Throwable.apiFailure(): ApiFailure {
    if (this !is retrofit2.HttpException) return ApiFailure(null, null, null)
    val raw = runCatching { response()?.errorBody()?.string() }.getOrNull()
    val root = raw?.let { runCatching { JsonParser.parseString(it) }.getOrNull() }?.takeIf { it.isJsonObject }?.asJsonObject
    fun JsonElement?.text(): String? = this?.takeIf { it.isJsonPrimitive }?.asString
    val firstError = root?.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()?.firstOrNull()?.value
        ?.let { if (it.isJsonArray) it.asJsonArray.firstOrNull() else it }.text()
    val message = firstError ?: root?.get("message").text()
    val errorCode = root?.get("error_code").text()
    val data = root?.get("data")?.takeIf { it.isJsonObject }?.asJsonObject
    val lockedUntil = data?.get("locked_until").text()
    val retryAfter = data?.get("retry_after")?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asInt }.getOrNull() }
    return ApiFailure(message, errorCode, code(), lockedUntil, retryAfter)
}

data class WalletUserCountry(val code: String?)

/** Who is signed in, as the wallet screens ask it: the captain's token (a captain's phone country is not needed there). */
data class WalletUser(val phone: String?, val country: WalletUserCountry?)

object AuthSession {
    val token: String? get() = DriverBackend.session.token
    val user: WalletUser? get() = null
}

/** The app language sent to the server (X-Locale) and used for dates. */
object AppLocale {
    val current: String
        get() = DriverPrefs.language.takeIf { it == "ar" || it == "en" }
            ?: java.util.Locale.getDefault().language.takeIf { it == "ar" || it == "en" } ?: "ar"

    fun java(): java.util.Locale = java.util.Locale.forLanguageTag(current)
}

/** The wallet's calls, on the captain's session — with the chosen wallet's country (X-Country), like the DORR app. */
object ApiClient {
    val wallet: WalletApi by lazy {
        val base = CoreHttp.client(
            deviceId = { DriverBackend.session.deviceId },
            token = { DriverBackend.session.token },
            onUnauthorized = {},
            debug = BuildConfig.DEBUG,
        )
        val client = base.newBuilder().addInterceptor { chain ->
            val country = WalletCountry.headerFor(chain.request().url.encodedPath)
            chain.proceed(
                chain.request().newBuilder()
                    .apply { if (country != null) header("X-Country", country) }
                    .header("X-Locale", AppLocale.current)
                    .build(),
            )
        }.build()
        Retrofit.Builder()
            .baseUrl("${BuildConfig.API_SCHEME}://${BuildConfig.API_HOST}/api/")
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(WalletApi::class.java)
    }
}

/**
 * Bumps when the connection comes back, so a wallet page that failed offline reloads once — the
 * captain app's real-time connection is the signal (never a timer).
 */
@Composable
fun collectReconnectTick(): Int {
    val connected by com.dorr.driver.live.DriverLive.connected.collectAsState()
    var tick by remember { mutableIntStateOf(0) }
    var wasDown by remember { mutableIntStateOf(0) }
    LaunchedEffect(connected) {
        if (!connected) wasDown = 1 else if (wasDown == 1) { wasDown = 0; tick++ }
    }
    return tick
}
