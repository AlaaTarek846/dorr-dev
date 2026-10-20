package com.dorr.driver

import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.dorr.driver.duty.DriverDutyService
import com.dorr.driver.live.DriverLive
import com.dorr.driver.live.DriverPush
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.DriverNavHost
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.DriverTheme
import com.dorr.driver.ui.theme.withDriverLanguage

// A FragmentActivity (still a ComponentActivity) so the wallet can ask for the fingerprint / face (O-35).
class MainActivity : androidx.fragment.app.FragmentActivity() {
    /** Built on the language the captain chose in the app (Arabic / English), before anything is drawn. */
    override fun attachBaseContext(newBase: Context) {
        DriverPrefs.init(newBase)
        super.attachBaseContext(newBase.withDriverLanguage())
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        wakeForOffer(intent)
        setContent {
            DriverTheme {
                DriverNavHost()
            }
        }
    }

    override fun onStart() {
        super.onStart()
        DriverLive.inForeground = true
        if (DriverBackend.session.isSignedIn) {
            // Connect (idempotent); coming back to the screen reloads once for anything missed.
            DriverLive.start(this)
            DriverLive.requestSync()
            DriverLive.refreshUnread()
            DriverPush.requestPermission()
        }
    }

    override fun onStop() {
        DriverLive.inForeground = false
        super.onStop()
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
