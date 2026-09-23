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
        .addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
        .build()

    private val retrofit = Retrofit.Builder()
        .baseUrl(BASE_URL)
        .client(okHttpClient)
        .addConverterFactory(GsonConverterFactory.create())
        .build()

    val countries: CountryApi by lazy { retrofit.create(CountryApi::class.java) }
}
