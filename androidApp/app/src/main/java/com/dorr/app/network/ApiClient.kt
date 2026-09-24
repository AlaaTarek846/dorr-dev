package com.dorr.app.network

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * Points the app at the Dorr backend through a public ngrok tunnel
 * (`https://juncture-calibrate-tingly.ngrok-free.dev`), which forwards to the
 * Laragon-hosted Laravel app (`dorr.test`).
 *
 * A real public HTTPS hostname was chosen on purpose:
 *  - It works from a **physical device** on any network — no 10.0.2.2 emulator
 *    alias, no LAN-IP swap, no Wi-Fi requirement.
 *  - The same URL is used for the emulator too, so there's one BASE_URL for
 *    both cases.
 *  - HTTPS means no cleartext exception and (crucially) **no Host-header
 *    override**. ngrok returns 421 Misdirected Request when a request arrives
 *    with a `Host:` value that doesn't match the tunnel hostname, so the old
 *    `Host: dorr.test` override is gone — a real hostname routes by DNS as-is.
 *
 * Note on vhost routing: ngrok forwards to Laragon Apache, which routes
 * `dorr.test` as a name-based vhost. For the tunnel to resolve cleanly, the
 * Laragon vhost for `dorr` needs `ServerAlias juncture-calibrate-tingly.ngrok-free.dev`
 * (or a fresh tunnel must use Laragon's auto *.dorr.test wildcard). If the
 * preview returns 404, that's the missing ServerAlias — add it, then rebuild.
 */
private const val BASE_URL = "https://juncture-calibrate-tingly.ngrok-free.dev/api/"

object ApiClient {
    /** Shared with the image loader so media requests get the same dev Host header. */
    val okHttpClient: OkHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("X-Locale", AppLocale.current)
                .build()
            chain.proceed(request)
        }
        .addInterceptor { chain ->
            val request = chain.request()
            val response = chain.proceed(request)
            // Any request that carried a Bearer token and came back 401 means
            // the session is expired/revoked: drop it locally and let the UI
            // route back to Login. Public endpoints (no Authorization header)
            // are left alone.
            if (response.code == 401 && request.header("Authorization") != null) {
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
    val services: ServiceApi by lazy { retrofit.create(ServiceApi::class.java) }

    /**
     * Media URLs come back absolute for the server's own host (`http://dorr.test/...`),
     * which an emulator can't resolve — point them at the emulator's host alias.
     */
    fun mediaUrl(url: String?): String? = url?.replace("://dorr.test", "://10.0.2.2")
}
