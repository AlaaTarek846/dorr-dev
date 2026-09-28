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
 * Backend reached over the LAN (phone + PC on the same Wi-Fi) while ngrok is
 * down on this machine (the agent cannot authenticate: CRL fetch failure, so
 * it never opens a tunnel). Apache serves the app for this IP via a
 * ServerAlias, cleartext HTTP is allowed by the manifest.
 * To go back to ngrok: BASE_HOST = "juncture-calibrate-tingly.ngrok-free.dev",
 * BASE_URL = "https://$BASE_HOST/api/", and start:
 * ngrok http 80 --url https://$BASE_HOST --host-header=dorr.test The local dev host below is what
 * Laravel builds absolute media URLs with, so those get rewritten to the LAN host.
 */
private const val BASE_HOST = "192.168.1.4"
//private const val BASE_HOST = "unafraid-occupy-geography.ngrok-free.dev"
private const val BASE_URL = "http://$BASE_HOST/api/"
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
    val addresses: AddressApi by lazy { retrofit.create(AddressApi::class.java) }
    val appearance: AppearanceApi by lazy { retrofit.create(AppearanceApi::class.java) }
    val profile: ProfileApi by lazy { retrofit.create(ProfileApi::class.java) }

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
