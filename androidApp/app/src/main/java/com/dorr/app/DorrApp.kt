package com.dorr.app

import android.app.Application
import coil.ImageLoader
import coil.ImageLoaderFactory
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppearanceStore
import com.dorr.app.network.AuthSession
import com.dorr.app.network.DeviceId
import com.dorr.app.network.CountryCache
import com.dorr.app.network.OnboardingStore

class DorrApp : Application(), ImageLoaderFactory {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        com.dorr.app.network.ReferralStore.attach(this)
        com.dorr.app.network.ReferralInstallReferrer.capture(this)
        DeviceId.attach(this)
        OnboardingStore.attach(this)
        com.dorr.app.chat.ChatStore.attach(this)
        com.dorr.app.chat.VoicePlayer.attach(this)
        // Messages sent offline (even before the app was closed) go as soon as there's a connection.
        com.dorr.app.chat.ChatOutbox.attach(this)
        com.dorr.app.chat.ChatOutbox.resume(this)
        // Push first: a notification tapped while the app was closed must find the listener ready.
        com.dorr.app.chat.ChatPush.attach(this)
        // Calls ring over any screen, so the call state machine listens from app start.
        com.dorr.app.chat.CallController.attach(this)
        AppearanceStore.attach(this)
        // The font chosen in the appearance settings (downloaded once, then from the phone).
        CountryCache.load(this)
    }

    /**
     * Bug fix (2026-09-29, real observed bug): sent/attached images in the
     * AI chat (and every other AsyncImage in the app) never appeared.
     * ApiClient.kt's own doc comment on okHttpClient claims it is "shared
     * with the image loader so media requests get the same dev Host
     * header" - but nothing ever actually wired that up: Coil's default
     * global ImageLoader builds its OWN internal OkHttpClient with none
     * of ApiClient's interceptors. Over the ngrok tunnel used for local
     * dev, a request missing the "ngrok-skip-browser-warning" header
     * gets ngrok's HTML interstitial warning page back instead of the
     * image bytes, so Coil silently fails to decode it and the bubble
     * renders blank. Registering this factory makes Coil's app-wide
     * ImageLoader use the exact same OkHttpClient (and therefore the
     * same headers/Host handling) as every other API/media call.
     */
    override fun newImageLoader(): ImageLoader {
        return ImageLoader.Builder(this)
            .okHttpClient(ApiClient.okHttpClient)
            .build()
    }
}
