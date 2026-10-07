package com.dorr.app.network

import okhttp3.OkHttpClient
import java.util.concurrent.TimeUnit
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
private const val LAN_HOST = "192.168.1.5"
private const val EMULATOR_HOST = "10.0.2.2"
private const val NGROK_HOST = "zane-metazoal-max.ngrok-free.dev"

/** Emulator: [EMULATOR_HOST] · Phone on Wi‑Fi: [LAN_HOST] · Remote: [NGROK_HOST] */
private const val BASE_HOST = NGROK_HOST

private fun apiBaseUrl(host: String): String =
    if (host == NGROK_HOST) "https://$host/api/" else "http://$host/api/"

private val BASE_URL = apiBaseUrl(BASE_HOST)
// NOTE: BASE_HOST must be this PC's current Wi-Fi IP (check `ipconfig`) AND be
// listed as ServerAlias in C:/laragon/etc/apache2/sites-enabled/auto.dorr.test.conf,
// otherwise the phone gets connection-refused or 404. Reload Apache after changing it.

private const val LOCAL_MEDIA_HOST = "dorr.test"

object ApiClient {
    /** Shared with the image loader so media requests get the same dev Host header. */
    val okHttpClient: OkHttpClient = OkHttpClient.Builder()
        // Root-cause fix (real, observed bug): OkHttp's own defaults - 10s
        // connect/read/write - are fine for plain JSON endpoints but far too
        // short for two real calls this app makes: (1) AI image generation/
        // editing (Modules/AI AiGateway::generateImage()/editImage()) routinely
        // takes well past 10s through the OpenAI images API, especially over the
        // ngrok dev tunnel - the request was genuinely still being processed
        // server-side (and completed successfully, saving the message/image)
        // when the phone had already given up and shown "تعذر إرسال الرسالة";
        // (2) Coil's app-wide ImageLoader shares this exact client (see
        // DorrApp.newImageLoader()'s own docblock), so downloading a freshly
        // generated image over the same slow tunnel could ALSO hit the 10s read
        // timeout on the very first attempt - the bubble rendered blank until
        // the next app launch cleared Coil's in-memory failure and it was
        // fetched again, succeeding this time only because nothing else had
        // changed except that the earlier failed attempt was gone. Raising the
        // ceiling costs nothing for the app's many fast JSON calls (this is a
        // cap, not a fixed delay) and gives slow AI/media calls room to
        // actually finish instead of failing a request the server was about to
        // complete successfully anyway.
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(90, TimeUnit.SECONDS)
        .writeTimeout(90, TimeUnit.SECONDS)
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
            // Session expired only when we sent a Bearer token and the server rejected it.
            // Public auth routes (OTP) must not wipe state or show the expired banner.
            if (response.code == 401 && request.header("Authorization")?.startsWith("Bearer ") == true) {
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
    val aiSites: AiSitesApi by lazy { retrofit.create(AiSitesApi::class.java) }

    /** The API's own origin — real-time auth (`/broadcasting/auth`) lives next to `/api`. */

    val addresses: AddressApi by lazy { retrofit.create(AddressApi::class.java) }
    val appearance: AppearanceApi by lazy { retrofit.create(AppearanceApi::class.java) }
    val mobileAppearanceDefaults: MobileAppearanceDefaultsApi by lazy {
        retrofit.create(MobileAppearanceDefaultsApi::class.java)
    }
    val profile: ProfileApi by lazy { retrofit.create(ProfileApi::class.java) }
    val content: ContentApi by lazy { retrofit.create(ContentApi::class.java) }


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
