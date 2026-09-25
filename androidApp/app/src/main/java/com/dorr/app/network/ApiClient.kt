package com.dorr.app.network

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * Backend reached through an ngrok tunnel (HTTPS, works from any network —
 * no adb reverse or Wi-Fi IP needed). The tunnel host is the account's reserved static ngrok domain:
 * it stays the same across restarts — start the tunnel with:
 * ngrok http 80 --url https://$BASE_HOST --host-header=dorr.test The local dev host below is what
 * Laravel builds absolute media URLs with, so those get rewritten to the tunnel.
 */
private const val BASE_HOST = "juncture-calibrate-tingly.ngrok-free.dev"
private const val BASE_URL = "https://$BASE_HOST/api/"
private const val LOCAL_MEDIA_HOST = "dorr.test"

object ApiClient {
    /** Shared with the image loader so media requests get the same dev Host header. */
    val okHttpClient: OkHttpClient = OkHttpClient.Builder()
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

    /**
     * Media URLs come back absolute for the server's own host (`http://dorr.test/...`),
     * which the device can't resolve — point them at the tunnel.
     */
    fun mediaUrl(url: String?): String? = url?.replace("http://$LOCAL_MEDIA_HOST", "https://$BASE_HOST")?.replace("://$LOCAL_MEDIA_HOST", "://$BASE_HOST")
}
