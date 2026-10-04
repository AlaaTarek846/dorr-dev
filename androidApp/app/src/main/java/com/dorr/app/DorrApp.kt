package com.dorr.app

import android.app.Application
import com.dorr.app.network.AppearanceStore
import com.dorr.app.network.AuthSession
import com.dorr.app.network.DeviceId
import com.dorr.app.network.CountryCache
import com.dorr.app.network.OnboardingStore

class DorrApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        DeviceId.attach(this)
        OnboardingStore.attach(this)
        com.dorr.app.chat.ChatStore.attach(this)
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
