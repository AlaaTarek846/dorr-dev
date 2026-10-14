package com.dorr.driver

import android.app.Application
import com.dorr.driver.net.DriverBackend

class DriverApp : Application() {
    override fun onCreate() {
        super.onCreate()
        DriverBackend.init(this)
    }
}
