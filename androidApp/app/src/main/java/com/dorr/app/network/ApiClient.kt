package com.dorr.app.network

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.io.IOException
import java.net.ConnectException
import java.net.NoRouteToHostException
import java.net.UnknownHostException

/**
 * Bug fix (2026-09-29): the ngrok tunnel forwards requests with
 * --host-header=dorr.test, but this project's Laragon folder used to be
 * named dorr-dev (vhost dorr-dev.test) - a mismatched Host header, so
 * Apache/Laravel resolved every phone request to a DIFFERENT, unrelated
 * project+database than the one this codebase and the web admin panel
 * both point at. Every write the phone made (chat messages, AI
 * requests, orders, ...) landed in that other database, and every
 * admin screen reading from the real database looked permanently
 * empty. Fixed by renaming the project folder itself to `dorr` (vhost
 * dorr.test) to match what the ngrok tunnel already sends - not by
 * changing the host header - so LOCAL_MEDIA_HOST stays dorr.test.
 */
private const val BASE_HOST = "zane-metazoal-max.ngrok-free.dev"
private const val BASE_URL = "https://$BASE_HOST/api/"
// LAN alternative: BASE_HOST = "192.168.1.4", BASE_URL = "http://$BASE_HOST/api/"
private const val LOCAL_MEDIA_HOST = "dorr.test"

object ApiClient {
    /** Shared with the image loader so media requests get the same dev Host header. */
    val okHttpClient: OkHttpClient = OkHttpClient.Builder()
        // Connectivity signal first: a completed round-trip proves we are
        // online (clearing a stale offline state), while a failure before any
        // HTTP response — unknown host, refused/unreachable route — raises the
        // app-wide offline screen. Timeouts and TLS errors pass through: those
        // belong to the calling screen, not to "no internet".
        .addInterceptor { chain ->
            try {
                val response = chain.proceed(chain.request())
                NetworkMonitor.current()?.reportReachable()
                response
            } catch (e: IOException) {
                if (e is UnknownHostException || e is ConnectException || e is NoRouteToHostException) {
                    NetworkMonitor.current()?.reportUnreachable()
                }
                throw e
            }
        }
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("ngrok-skip-browser-warning", "1")
                .header("Accept", "application/json")
                .header("X-Locale", AppLocale.current)
                .header("X-Device-Id", DeviceId.current)
                .build()
            chain.proceed(request)
        }
        .addInterceptor { chain ->
            val request = chain.request()
            val response = chain.proceed(request)
            // Any API request returning 401 (unauthorized / session expired)
            // drops session locally and routes the user back to the login screen.
            if (response.code == 401) {
                AuthSession.clear()
                AuthSession.onUnauthorized?.invoke()
            }
            response
        }
        .addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
        .build()

    private val retrofit = Retrofit.Builder()
        .baseUrl(BASE_URL)
        .client(okHttpClient)
        .addConverterFactory(GsonConverterFactory.create())
        .build()

    val countries: CountryApi by lazy { retrofit.create(CountryApi::class.java) }
    val languages: LanguageApi by lazy { retrofit.create(LanguageApi::class.java) }
    val branding: BrandingApi by lazy { retrofit.create(BrandingApi::class.java) }
    val mobileAuth: MobileAuthApi by lazy { retrofit.create(MobileAuthApi::class.java) }
    val wallet: WalletApi by lazy { retrofit.create(WalletApi::class.java) }
    val notifications: NotificationApi by lazy { retrofit.create(NotificationApi::class.java) }
    val services: ServiceApi by lazy { retrofit.create(ServiceApi::class.java) }
    val chat: ChatApi by lazy { retrofit.create(ChatApi::class.java) }
    val aiChat: AiChatApi by lazy { retrofit.create(AiChatApi::class.java) }
    val addresses: AddressApi by lazy { retrofit.create(AddressApi::class.java) }
    val appearance: AppearanceApi by lazy { retrofit.create(AppearanceApi::class.java) }
    val profile: ProfileApi by lazy { retrofit.create(ProfileApi::class.java) }

    /** The API's own origin — real-time auth (`/broadcasting/auth`) lives next to `/api`. */
    val ORIGIN = BASE_URL.removeSuffix("/api/")

    /**
     * Media URLs come back absolute for the server's own host (`http://dorr.test/...`),
     * which the device can't resolve — point them at the reachable host.
     *
     * The scheme follows BASE_URL on purpose: on the LAN the app talks cleartext
     * HTTP (no TLS cert exists for a bare IP, so forcing https silently breaks
     * every image), while on ngrok it stays https like the API itself.
     */
    fun mediaUrl(url: String?): String? {
        val scheme = if (BASE_URL.startsWith("https://")) "https" else "http"
        return url
            ?.let { if (it.startsWith("/")) "$scheme://$LOCAL_MEDIA_HOST$it" else it }
            ?.replace("http://$LOCAL_MEDIA_HOST", "$scheme://$BASE_HOST")
            ?.replace("https://$LOCAL_MEDIA_HOST", "$scheme://$BASE_HOST")
    }
}
