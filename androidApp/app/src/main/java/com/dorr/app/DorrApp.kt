package com.dorr.app

import android.app.Application
import com.dorr.app.network.AppearanceStore
import com.dorr.app.network.AuthSession
<<<<<<< HEAD
import com.dorr.app.network.DeviceId
=======
import com.dorr.app.network.CountryCache
>>>>>>> origin/main
import com.dorr.app.network.OnboardingStore

class DorrApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        DeviceId.attach(this)
        OnboardingStore.attach(this)
<<<<<<< HEAD
        com.dorr.app.chat.ChatStore.attach(this)
        // Push first: a notification tapped while the app was closed must find the listener ready.
        com.dorr.app.chat.ChatPush.attach(this)
        // Calls ring over any screen, so the call state machine listens from app start.
        com.dorr.app.chat.CallController.attach(this)
=======
        AppearanceStore.attach(this)
        CountryCache.load(this)
>>>>>>> origin/main
    }
}
