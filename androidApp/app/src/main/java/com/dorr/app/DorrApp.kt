package com.dorr.app

import android.app.Application
import com.dorr.app.network.AuthSession
import com.dorr.app.network.OnboardingStore

class DorrApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
        OnboardingStore.attach(this)
    }
}
