package com.dorr.driver.net

import android.content.Context
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.coreGson
import com.dorr.core.session.TokenStore
import com.dorr.driver.BuildConfig
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * The driver app's single connection to the server: its own session file (`driver_session`, never
 * the DORR app's) and a signed-out signal the navigation listens to when a token stops working.
 */
object DriverBackend {
    lateinit var session: TokenStore
        private set

    lateinit var api: DriverApi
        private set

    /** O-20..O-24: the captain's buses. */
    lateinit var bus: DriverBusApi
        private set

    /** O-25: the captain's boat. */
    lateinit var marine: DriverMarineApi
        private set

    private val _signedOut = MutableStateFlow(0)

    /** Ticks each time the server answers 401 to a signed-in call. */
    val signedOut: StateFlow<Int> = _signedOut.asStateFlow()

    fun isReady(): Boolean = ::session.isInitialized

    fun init(context: Context) {
        session = TokenStore(context, "driver_session")
        val client = CoreHttp.client(
            deviceId = { session.deviceId },
            token = { session.token },
            onUnauthorized = {
                com.dorr.driver.live.DriverLive.stop()
                session.clear()
                _signedOut.value += 1
            },
            debug = BuildConfig.DEBUG,
        )
        val retrofit = Retrofit.Builder()
            .baseUrl("${BuildConfig.API_SCHEME}://${BuildConfig.API_HOST}/api/")
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(coreGson()))
            .build()
        api = retrofit.create(DriverApi::class.java)
        bus = retrofit.create(DriverBusApi::class.java)
        marine = retrofit.create(DriverMarineApi::class.java)
    }
}
