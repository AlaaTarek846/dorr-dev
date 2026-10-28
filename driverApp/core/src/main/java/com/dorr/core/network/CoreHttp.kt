package com.dorr.core.network

import java.util.Locale
import java.util.UUID
import java.util.concurrent.TimeUnit
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor

/**
 * The OkHttp client both apps build on: the headers every DORR request carries (`Accept`,
 * `X-Locale`, `X-Device-Id`), the Bearer token when signed in, and a 401 hook so the app can send
 * the person back to sign-in.
 */
object CoreHttp {
    /** Header the server reads to make a sensitive command safe to retry (docs/rides-plan.md §3). */
    const val IDEMPOTENCY_KEY = "Idempotency-Key"

    /** A fresh key for one command; resend the same key when retrying that same command. */
    fun newIdempotencyKey(): String = UUID.randomUUID().toString()

    fun client(
        deviceId: () -> String,
        token: () -> String?,
        onUnauthorized: () -> Unit,
        debug: Boolean,
        // The app's own language when it has a picker (the freelancer app); the phone's otherwise.
        locale: () -> String = { Locale.getDefault().language.takeIf { it == "ar" || it == "en" } ?: "ar" },
    ): OkHttpClient {
        val headers = Interceptor { chain ->
            val builder = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("X-Locale", locale())
                .header("X-Device-Id", deviceId())
            val bearer = token()
            if (bearer != null) builder.header("Authorization", "Bearer $bearer")

            val response = chain.proceed(builder.build())
            // Only a request that carried a token can mean "your session ended".
            if (response.code == 401 && bearer != null) onUnauthorized()
            response
        }

        return OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .addInterceptor(headers)
            .apply {
                if (debug) addInterceptor(HttpLoggingInterceptor().setLevel(HttpLoggingInterceptor.Level.BASIC))
            }
            .build()
    }
}
