package com.dorr.freelance

import android.app.Application
import coil.ImageLoader
import coil.ImageLoaderFactory
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import com.dorr.freelance.net.Session
import com.dorr.freelance.ui.theme.AppearanceStore

class FreelanceApp : Application(), ImageLoaderFactory {
    private val appScope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    /**
     * Every image goes through the API's own OkHttp client, like the DORR app: the same headers (the dev tunnel's
     * skip-warning one included) and the signed-in token for the freelancer's own private files.
     */
    override fun newImageLoader(): ImageLoader = ImageLoader.Builder(this)
        .okHttpClient { ApiClient.okHttpClient }
        .build()

    override fun onCreate() {
        super.onCreate()
        Session.attach(this)
        AppearanceStore.attach(this)
        Realtime.attach(this)
        // The live connection and the push id follow the session: on while signed in, off the moment it ends (sign-out, or a 401).
        appScope.launch { Session.signedIn.collect { signedIn -> if (signedIn) Realtime.start() else Realtime.stop() } }
    }
}
