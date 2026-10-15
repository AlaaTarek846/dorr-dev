package com.dorr.driver

import android.app.Application
import com.dorr.driver.net.DriverBackend

class DriverApp : Application() {
    override fun onCreate() {
        super.onCreate()
        DriverBackend.init(this)
        // Push is ready before the first screen: a request can wake the app on its own.
        com.dorr.driver.live.DriverPush.attach(this)
    }
}
