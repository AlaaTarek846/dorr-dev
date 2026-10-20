package com.dorr.driver

import android.app.Application
import com.dorr.driver.net.DriverBackend

class DriverApp : Application() {
    override fun onCreate() {
        super.onCreate()
        com.dorr.driver.ui.theme.DriverPrefs.init(this)
        DriverBackend.init(this)
        // O-53: the admin's map engine (Google or Mapbox), from the phone at once — the server's latest is read
        // in the background once signed in, and saved for the next start.
        com.dorr.driver.map.MapEngine.init(this)
        // Push is ready before the first screen: a request can wake the app on its own.
        com.dorr.driver.live.DriverPush.attach(this)
    }
}
