package com.dorr.app

import android.app.Application
import android.os.Build
import coil.ImageLoader
import coil.ImageLoaderFactory
import coil.decode.GifDecoder
import coil.decode.ImageDecoderDecoder
import coil.decode.SvgDecoder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppearanceStore
import com.dorr.app.network.AuthSession
import com.dorr.app.network.CountryCache
import com.dorr.app.network.DeviceId
import com.dorr.app.network.OnboardingStore
import kotlinx.coroutines.launch

class DorrApp : Application(), ImageLoaderFactory {
    /**
     * Every AsyncImage in the app goes through the API's own OkHttpClient (bug fix 2026-09-29):
     * Coil's default loader built its own client without ApiClient's interceptors, so over the
     * ngrok dev tunnel images came back as ngrok's HTML warning page and showed blank. It also
     * decodes SVG icons (chat categories…) and animated GIFs.
     */
    override fun newImageLoader(): ImageLoader = ImageLoader.Builder(this)
        .okHttpClient { ApiClient.okHttpClient }
        .components {
            add(SvgDecoder.Factory())
            if (Build.VERSION.SDK_INT >= 28) {
                add(ImageDecoderDecoder.Factory())
            } else {
                add(GifDecoder.Factory())
            }
        }
        .build()

    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        // O-53: the admin's map engine (Google or Mapbox), from the phone at once — then from the server in the
        // background, saved for the next start, so a map never waits on the network to know how to draw.
        com.dorr.app.map.MapEngine.init(this)
        if (!AuthSession.token.isNullOrBlank()) {
            kotlinx.coroutines.MainScope().launch {
                com.dorr.app.map.MapEngine.refresh(this@DorrApp) {
                    ApiClient.rides.mapConfig("Bearer ${AuthSession.token.orEmpty()}").data?.let { Triple(it.provider, it.mapboxToken, it.mapboxStyle) }
                }
            }
        }
        // Data saver, lite mode and usage stats (spec 82, 87, 83) are read before anything loads.
        com.dorr.app.chat.ChatDataSaver.attach(this)
        com.dorr.app.chat.ChatLite.attach(this)
        com.dorr.app.chat.ChatUsage.attach(this)
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
}
