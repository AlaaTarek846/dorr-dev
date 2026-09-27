package com.dorr.app

import android.app.Application
import com.dorr.app.network.AuthSession
import com.dorr.app.network.DeviceId
import com.dorr.app.network.OnboardingStore

class DorrApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        DeviceId.attach(this)
        OnboardingStore.attach(this)
        // Calls ring over any screen, so the call state machine listens from app start.
        com.dorr.app.chat.CallController.attach(this)
    }
}
