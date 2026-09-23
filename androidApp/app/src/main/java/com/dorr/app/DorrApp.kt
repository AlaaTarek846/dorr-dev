package com.dorr.app

import android.app.Application
import com.dorr.app.network.AuthSession

class DorrApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AuthSession.attach(this)
    }
}
