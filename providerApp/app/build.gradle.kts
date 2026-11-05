import java.util.Properties

// The providers app «شركاء دُرر» / "Dorr Partners" (docs/moving_tasks.md T9) — its own project (providerApp/), its own account
// on the server's `provider_api` guard (/api/provider/v1/...). The look is the DORR apps' (a copy of freelanceApp's kit, itself a
// 1:1 port of androidApp's): same colours, font, fields, splash, bottom bar and wallet pages (owner's red line, §12).
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.provider"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.dorr.provider"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "0.1.0"

        // Same backend settings as androidApp (local.properties, or -P on the command line):
        //   dorr.apiHost=my-tunnel.ngrok-free.dev   dorr.apiScheme=https
        //   gradlew assembleDebug -Pdorr.apiHost=dorr-app.com -Pdorr.apiScheme=https
        val local = Properties().apply {
            rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
        }
        val apiHost = (findProperty("dorr.apiHost") as String?) ?: local.getProperty("dorr.apiHost", "unafraid-occupy-geography.ngrok-free.dev")
        val apiScheme = (findProperty("dorr.apiScheme") as String?) ?: local.getProperty("dorr.apiScheme", "https")
        // Real phones only (arm64 + 32-bit arm), like androidApp; an emulator build asks for more: -Pdorr.abis=arm64-v8a,x86_64
        val abis = (findProperty("dorr.abis") as String?) ?: local.getProperty("dorr.abis", "arm64-v8a,armeabi-v7a")
        ndk { abiFilters += abis.split(",").map { it.trim() }.filter { it.isNotEmpty() } }
        buildConfigField("String", "API_HOST", "\"$apiHost\"")
        buildConfigField("String", "API_SCHEME", "\"$apiScheme\"")
    }

    buildTypes {
        release {
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

    // ar/en ship in every install (an App Bundle split would drop the language the phone isn't set to).
    bundle {
        language {
            enableSplit = false
        }
    }
}

dependencies {
    implementation(project(":core"))

    val composeBom = platform("androidx.compose:compose-bom:2024.09.03")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")
    implementation("androidx.activity:activity-compose:1.9.2")
    // Fingerprint / face unlock for the wallet (local only, never replaces the PIN for a money-moving action).
    implementation("androidx.biometric:biometric:1.1.0")
    // MainActivity is a FragmentActivity so BiometricPrompt has a FragmentManager to use.
    implementation("androidx.fragment:fragment-ktx:1.8.4")

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.navigation:navigation-compose:2.8.0")

    // Service images, logos and avatars — through the API's own OkHttp client (ProviderApp.newImageLoader).
    implementation("io.coil-kt:coil-compose:2.7.0")
    // The main branch's place at sign-up (the phone's current location, fused provider — same version as driverApp).
    implementation("com.google.android.gms:play-services-location:21.3.0")
    // Scanning the customer's delivery QR: the same scanner driverApp and androidApp already use.
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")

    // Live updates: speaks the Pusher protocol, so it keeps working when we move to our own Soketi / Reverb server (the host
    // comes from GET realtime-config, never from the app).
    implementation("com.pusher:pusher-java-client:2.4.4")
    // Phone notifications — the server already sends through OneSignal; the app id comes from realtime-config.
    implementation("com.onesignal:OneSignal:5.1.6")

    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
