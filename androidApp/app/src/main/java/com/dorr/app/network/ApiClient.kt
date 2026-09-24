package com.dorr.app.network

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * Points at the Laragon-hosted Laravel backend (`dorr.test`) from an Android
 * emulator. `10.0.2.2` is the emulator's alias for the host machine's
 * localhost; Laragon serves `dorr.test` as a name-based virtual host, so we
 * also force the `Host` header — otherwise Apache would route the request to
 * whatever vhost is default instead of this project.
 *
 * On a physical device this won't resolve: swap BASE_URL for the host
 * machine's LAN IP (same Wi-Fi), keeping the `Host` header override.
 */
private const val DEV_HOST = "dorr.test"
private const val BASE_URL = "http://10.0.2.2/api/"

object ApiClient {
    private val okHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("Host", DEV_HOST)
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
}
