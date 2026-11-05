package com.dorr.provider.net

import com.dorr.core.network.CoreHttp
import com.dorr.core.network.coreGson
import com.dorr.provider.BuildConfig
import java.io.IOException
import java.net.ConnectException
import java.net.NoRouteToHostException
import java.net.UnknownHostException
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * Which backend this build talks to is each developer's own setting, in providerApp/local.properties (not committed),
 * exactly like androidApp: `dorr.apiHost` / `dorr.apiScheme` (see app/build.gradle.kts).
 *
 * The providers app's own API is `/api/provider/v1/...` ([PROVIDER]); the shared public endpoints the DORR apps read
 * (the appearance colours, the countries…) live next to it under `/api/general/v1/...`, so Retrofit's base is `/api/`.
 */
private const val BASE_HOST = BuildConfig.API_HOST
private const val BASE_URL = "${BuildConfig.API_SCHEME}://$BASE_HOST/api/"

/** The host Laravel builds absolute media URLs with on a developer's machine. */
private const val LOCAL_MEDIA_HOST = "dorr.test"

/** "Samsung SM-S918B" — ASCII only, as an HTTP header must be. */
private val DEVICE_NAME: String = run {
    val maker = android.os.Build.MANUFACTURER.orEmpty().replaceFirstChar { it.uppercase() }
    val model = android.os.Build.MODEL.orEmpty()
    val name = if (model.startsWith(maker, ignoreCase = true)) model else "$maker $model"
    name.filter { it.code in 0x20..0x7E }.trim().take(120).ifEmpty { "Android" }
}

object ApiClient {
    /** Path prefix of every providers-app endpoint, for the Retrofit interfaces. */
    const val PROVIDER = "provider/v1/"

    /**
     * The establishment the app works in (docs/moving_tasks.md T5, `EstablishmentAccess::HEADER`): sent on every request once
     * one is chosen, so the server answers for that establishment — its papers, its permissions and its wallet.
     */
    const val ESTABLISHMENT_HEADER = "X-Establishment"

    /** The device name the sign-in sends, shown in the provider's devices list. */
    val deviceName: String get() = DEVICE_NAME

    /**
     * :core's client (Accept, X-Locale in the app's language, X-Device-Id, the Bearer token, a 401 ends the session)
     * plus what this app adds: the connectivity signal for the offline screen and the app's own headers.
     */
    val okHttpClient: OkHttpClient = CoreHttp.client(
        deviceId = { Session.deviceId },
        token = { Session.token },
        onUnauthorized = { Session.clear() },
        debug = BuildConfig.DEBUG,
        locale = { AppLocale.current },
    ).newBuilder()
        // A completed round-trip proves we are online; a failure before any HTTP answer (unknown host, refused or
        // unreachable route) raises the offline screen. Timeouts and TLS errors stay with the calling screen.
        .addInterceptor { chain ->
            try {
                chain.proceed(chain.request()).also { NetworkMonitor.current()?.reportReachable() }
            } catch (e: IOException) {
                if (e is UnknownHostException || e is ConnectException || e is NoRouteToHostException) {
                    NetworkMonitor.current()?.reportUnreachable()
                }
                throw e
            }
        }
        .addInterceptor { chain ->
            chain.proceed(
                chain.request().newBuilder()
                    .header("ngrok-skip-browser-warning", "1")
                    .header("X-Device-Name", DEVICE_NAME)
                    .header("X-Platform", "android")
                    .header("X-App-Version", BuildConfig.VERSION_NAME)
                    .header("X-Timezone", java.util.TimeZone.getDefault().id)
                    .build(),
            )
        }
        // The establishment chosen in the app (Session.establishment) — never sent before one is chosen.
        .addInterceptor { chain ->
            val establishment = Session.establishment.value
            val request = chain.request()
            // A call that names its establishment keeps it: the truck's position belongs to the job's establishment even if the
            // driver has switched to another one meanwhile.
            chain.proceed(
                if (establishment.isNullOrBlank() || request.header(ESTABLISHMENT_HEADER) != null) request
                else request.newBuilder().header(ESTABLISHMENT_HEADER, establishment).build(),
            )
        }
        .build()

    private val retrofit = Retrofit.Builder()
        .baseUrl(BASE_URL)
        .client(okHttpClient)
        .addConverterFactory(GsonConverterFactory.create(coreGson()))
        .build()

    val general: GeneralApi by lazy { retrofit.create(GeneralApi::class.java) }
    val auth: AuthApi by lazy { retrofit.create(AuthApi::class.java) }
    val workspace: WorkspaceApi by lazy { retrofit.create(WorkspaceApi::class.java) }
    val manage: ManageApi by lazy { retrofit.create(ManageApi::class.java) }
    val moving: MovingApi by lazy { retrofit.create(MovingApi::class.java) }
    val notifications: NotificationsApi by lazy { retrofit.create(NotificationsApi::class.java) }
    val wallet: WalletApi by lazy { retrofit.create(WalletApi::class.java) }
    val appearance: AppearanceApi by lazy { retrofit.create(AppearanceApi::class.java) }
    val translations: TranslationsApi by lazy { retrofit.create(TranslationsApi::class.java) }

    /** The API's own origin — the live connection's channel authorization (`/broadcasting/auth`) lives next to `/api`. */
    val origin: String = BASE_URL.removeSuffix("api/").removeSuffix("/")

    /** The API's own address for a path of the providers API (an image read signed in, through Coil). */
    fun url(path: String): String = BASE_URL + PROVIDER + path

    /**
     * Media URLs come back absolute for the server's own host (`http://dorr.test/...`), which a phone can't resolve —
     * point them at the reachable host, in the API's own scheme (http on a LAN IP, https on a tunnel).
     */
    fun mediaUrl(url: String?): String? {
        val scheme = if (BASE_URL.startsWith("https://")) "https" else "http"
        return url
            ?.let { if (it.startsWith("/")) "$scheme://$LOCAL_MEDIA_HOST$it" else it }
            ?.replace("http://$LOCAL_MEDIA_HOST", "$scheme://$BASE_HOST")
            ?.replace("https://$LOCAL_MEDIA_HOST", "$scheme://$BASE_HOST")
            ?.replace(Regex("""^https?://(127[.]0[.]0[.]1|localhost)(:[0-9]+)?"""), "$scheme://$BASE_HOST")
    }
}
