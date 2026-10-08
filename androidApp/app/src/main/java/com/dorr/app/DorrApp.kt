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

class DorrApp : Application(), ImageLoaderFactory {
    /**
     * Every AsyncImage uses the API OkHttp client (same Host / ngrok headers as media
     * URLs) plus SVG and animated GIF / WebP. Coil's default loader would skip those
     * interceptors and get ngrok's HTML warning instead of image bytes.
     */
    override fun newImageLoader(): ImageLoader = ImageLoader.Builder(this)
        .okHttpClient(ApiClient.okHttpClient)
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
