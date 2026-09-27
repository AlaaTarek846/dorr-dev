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
    }
}

dependencies {
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
    // Wallet QR: scans someone else's code (camera screen included); it brings zxing-core, which
    // also draws the wallet's own code.
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")
    // Chat real-time: speaks the Pusher protocol, so it keeps working when we move to our own
    // Soketi / Reverb server (host comes from GET chat/realtime-config, not from the app).
    implementation("com.pusher:pusher-java-client:2.4.4")
    // Chat calls (voice / video) — LiveKit Cloud now, self-hosted LiveKit later (same SDK).
    implementation("io.livekit:livekit-android:2.5.0")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
