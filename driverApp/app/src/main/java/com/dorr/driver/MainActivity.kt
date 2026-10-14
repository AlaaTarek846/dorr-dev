package com.dorr.driver

import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.dorr.core.ui.DorrTheme
import com.dorr.driver.duty.DriverDutyService
import com.dorr.driver.ui.DriverNavHost

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        wakeForOffer(intent)
        setContent {
            DorrTheme {
                DriverNavHost()
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        wakeForOffer(intent)
    }

    /** Opened by a ringing request: show over the lock screen and light it up, like a call. */
    private fun wakeForOffer(intent: Intent?) {
        if (intent?.getStringExtra(DriverDutyService.EXTRA_OFFER) == null) return
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
    }
}
