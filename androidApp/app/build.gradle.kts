import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Crash reports go to Firebase Crashlytics once the Firebase project's google-services.json is
// in app/ (Firebase console → Project settings → Android app com.dorr.app). Without it the app
// builds and runs as before, it just reports nothing.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
    apply(plugin = "com.google.firebase.crashlytics")
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
        //   dorr.apiScheme=https        (http for a LAN IP)
        val local = Properties().apply {
            rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
        }
        // A build for a given server overrides it on the command line, leaving local.properties alone:
        //   gradlew assembleDebug -Pdorr.apiHost=dorr-app.com -Pdorr.apiScheme=https
        val apiHost = (findProperty("dorr.apiHost") as String?) ?: local.getProperty("dorr.apiHost", "unafraid-occupy-geography.ngrok-free.dev")
        val apiScheme = (findProperty("dorr.apiScheme") as String?) ?: local.getProperty("dorr.apiScheme", "https")
        // Only the CPU types real phones have (arm64 + 32-bit arm): x86 / x86_64 are for emulators and
        // tripled the native libraries (~85 MB of a 200 MB APK). An emulator build asks for them:
        //   gradlew assembleDebug -Pdorr.abis=arm64-v8a,x86_64
        // (a Play App Bundle also sends each phone only its own CPU type.)
        val abis = (findProperty("dorr.abis") as String?) ?: local.getProperty("dorr.abis", "arm64-v8a,armeabi-v7a")
        ndk { abiFilters += abis.split(",").map { it.trim() }.filter { it.isNotEmpty() } }
        buildConfigField("String", "API_HOST", "\"$apiHost\"")
        buildConfigField("String", "API_SCHEME", "\"$apiScheme\"")
        // The Android Maps key (restricted to this app's package), per developer / build like the host:
        //   dorr.mapsKey=AIza... in local.properties, or -Pdorr.mapsKey=... — without it the map stays blank, the rest works.
        manifestPlaceholders["mapsApiKey"] = (findProperty("dorr.mapsKey") as String?) ?: local.getProperty("dorr.mapsKey", "")
    }

    buildTypes {
        release {
            // Unused library code and resources are removed (no renaming: see proguard-rules.pro).
            isMinifyEnabled = true
            isShrinkResources = true
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
    // Vector icons from the server (chat categories…).
    implementation("io.coil-kt:coil-svg:2.7.0")
    // Wallet QR: scans someone else's code (camera screen included); it brings zxing-core, which
    // also draws the wallet's own code.
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")
    // Chat real-time: speaks the Pusher protocol, so it keeps working when we move to our own
    // Soketi / Reverb server (host comes from GET chat/realtime-config, not from the app).
    implementation("com.pusher:pusher-java-client:2.4.4")
    // Chat calls (voice / video) — LiveKit Cloud now, self-hosted LiveKit later (same SDK).
    implementation("io.livekit:livekit-android:2.5.0")
    // Camera backgrounds in video calls (blur / a picture), found on the phone (spec 94).
    implementation("com.google.mlkit:segmentation-selfie:16.0.0-beta6")
    // Realtime Voice (AI Assistant live call, Phase 7) uses the WebRTC that livekit-android already
    // ships (package livekit.org.webrtc) -- a second WebRTC artifact here added ~11 MB per CPU type.
    // Phone notifications (chat messages, calls, wallet…) — the server already sends through OneSignal.
    implementation("com.onesignal:OneSignal:5.1.6")
    // Crash reports (CrashReports.kt): every crash with its stack and the account id, in Firebase.
    implementation("com.google.firebase:firebase-crashlytics:19.2.1")
    // Chat videos are re-encoded on the phone before upload (720p H.264) — Google's own transcoder.
    implementation("androidx.media3:media3-transformer:1.4.1")
    // The chat outbox: messages sent offline go in the background once there's a connection.
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    // DORR Sports on the home screen (my team, live now).
    implementation("androidx.glance:glance-appwidget:1.1.1")
    implementation("androidx.media3:media3-effect:1.4.1")
    implementation("androidx.media3:media3-common:1.4.1")
    // "Make a sticker from my photo": the subject is cut out of its background on the phone.
    // Unbundled — Google Play services downloads the model, so the APK stays small.
    implementation("com.google.android.gms:play-services-mlkit-subject-segmentation:16.0.0-beta1")
    // Google Play In-App Review (shown after a 4–5 star rating; a no-op until the app is on Play)
    implementation("com.google.android.play:review-ktx:2.0.2")
    // Play Install Referrer (no-op until the app is published; same API as share/copy today)
    implementation("com.android.installreferrer:installreferrer:2.2")

    // Passenger rides: the map (Google Maps for Compose) and the phone's location (fused provider).
    implementation("com.google.android.gms:play-services-maps:19.0.0")
    implementation("com.google.maps.android:maps-compose:6.1.0")
    // O-53: the second map engine — the admin chooses Google or Mapbox for the whole app.
    implementation("com.mapbox.maps:android:11.8.0")
    implementation("com.google.android.gms:play-services-location:21.3.0")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
