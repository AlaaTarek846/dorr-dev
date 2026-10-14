package com.dorr.app.chat

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import com.dorr.app.ui.locale.LocalizedApp
import com.dorr.app.ui.screens.chat.CallOverlay
import com.dorr.app.ui.screens.chat.CallPipView
import com.dorr.app.ui.theme.DorrTheme
import kotlinx.coroutines.delay

/**
 * The ringing page opened by the call notification — over the lock screen, waking the display,
 * like a phone call. It shows the same call screen as inside the app (CallOverlay, driven by
 * CallController) and closes itself when the call is over.
 */
class IncomingCallActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        addOnPictureInPictureModeChangedListener { CallController.inPip = it.isInPictureInPictureMode }

        // Opened from a notification: the app's live connection may not be running yet.
        ChatRealtime.start()
        handle(intent)

        setContent {
            LocalizedApp {
                DorrTheme(darkTheme = false) {
                    var seen by remember { mutableStateOf(false) }
                    val phase = CallController.phase
                    LaunchedEffect(phase) {
                        if (phase != null) seen = true else if (seen) finish()
                    }
                    // The call may already be over by the time the page opens: don't hang around empty.
                    LaunchedEffect(Unit) {
                        delay(8000)
                        if (!seen) finish()
                    }
                    // Back / ⌄ minimises: into the app (the call goes on, small) — or, over the lock
                    // screen, out of the way until the phone is unlocked.
                    val minimized = CallController.minimized
                    LaunchedEffect(minimized) {
                        if (!minimized) return@LaunchedEffect
                        val locked = (getSystemService(KEYGUARD_SERVICE) as android.app.KeyguardManager).isKeyguardLocked
                        if (locked) {
                            CallController.minimized = false
                            moveTaskToBack(true)
                        } else {
                            startActivity(Intent(this@IncomingCallActivity, com.dorr.app.MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP))
                            finish()
                        }
                    }
                    Box(Modifier.fillMaxSize().background(Color.Black)) { if (CallController.inPip) CallPipView() else CallOverlay() }
                }
            }
        }
    }

    /** Home mid-call: the call in a small window over other apps. */
    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        if (CallController.phase == CallPhase.Active) CallPip.enter(this)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handle(intent)
    }

    private fun handle(intent: Intent?) {
        val callId = intent?.getStringExtra(CallNotifications.EXTRA_CALL) ?: return
        CallController.loadIncoming(callId, autoAnswer = intent.getBooleanExtra(CallNotifications.EXTRA_AUTO_ANSWER, false))
    }
}
