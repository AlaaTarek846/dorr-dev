import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.app"
    compileSdk = 34

    defaultConfig {
        // Placeholder application id / brand — swap to the real package name
        // and app name once the business identity for this app is decided.
        applicationId = "com.dorr.app"
        // 26+ so the adaptive launcher icon (mipmap-anydpi-v26) doesn't need
        // legacy per-density raster fallbacks.
        minSdk = 26
        targetSdk = 34
        versionCode = 2
        versionName = "1.0.1"

        // The backend each developer's phone talks to (their own ngrok tunnel / LAN IP), from
        // local.properties (not committed) so merges never swap it:
        //   dorr.apiHost=my-tunnel.ngrok-free.dev
        //   dorr.apiScheme=https        (http for a LAN IP)
        val local = Properties().apply {
            rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
        }
        buildConfigField("String", "API_HOST", "\"${local.getProperty("dorr.apiHost", "exploring-molasses-pretended.ngrok-free.dev")}\"")
        buildConfigField("String", "API_SCHEME", "\"${local.getProperty("dorr.apiScheme", "https")}\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    // ar/en ship in every install: languages downloaded at runtime fall back to the bundled
    // English, and an App Bundle split would drop the locale the device isn't set to.
    bundle {
        language {
            enableSplit = false
        }
    }
}

dependencies {
    testImplementation("junit:junit:4.13.2")
    val composeBom = platform("androidx.compose:compose-bom:2024.09.03")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")
    implementation("androidx.activity:activity-compose:1.9.2")
    // Fingerprint/Face unlock for the wallet gate — local only, never replaces the PIN for a money-moving action.
    implementation("androidx.biometric:biometric:1.1.0")
    // MainActivity is a FragmentActivity (not a plain ComponentActivity) so BiometricPrompt has a FragmentManager to use.
    implementation("androidx.fragment:fragment-ktx:1.8.4")

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.navigation:navigation-compose:2.8.0")

    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-gson:2.11.0")
    implementation("com.google.code.gson:gson:2.11.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("io.coil-kt:coil-compose:2.7.0")
    // Chat GIFs and animated stickers (GIF + animated WebP).
    implementation("io.coil-kt:coil-gif:2.7.0")
    // Wallet QR: scans someone else's code (camera screen included); it brings zxing-core, which
    // also draws the wallet's own code.
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")
    // Chat real-time: speaks the Pusher protocol, so it keeps working when we move to our own
    // Soketi / Reverb server (host comes from GET chat/realtime-config, not from the app).
    implementation("com.pusher:pusher-java-client:2.4.4")
    // Chat calls (voice / video) — LiveKit Cloud now, self-hosted LiveKit later (same SDK).
    implementation("io.livekit:livekit-android:2.5.0")
    // Phone notifications (chat messages, calls, wallet…) — the server already sends through OneSignal.
    implementation("com.onesignal:OneSignal:5.1.6")
    // Chat videos are re-encoded on the phone before upload (720p H.264) — Google's own transcoder.
    implementation("androidx.media3:media3-transformer:1.4.1")
    // The chat outbox: messages sent offline go in the background once there's a connection.
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("androidx.media3:media3-effect:1.4.1")
    implementation("androidx.media3:media3-common:1.4.1")
    // "Make a sticker from my photo": the subject is cut out of its background on the phone.
    // Unbundled — Google Play services downloads the model, so the APK stays small.
    implementation("com.google.android.gms:play-services-mlkit-subject-segmentation:16.0.0-beta1")
    // Google Play In-App Review (shown after a 4–5 star rating; a no-op until the app is on Play)
    implementation("com.google.android.play:review-ktx:2.0.2")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
