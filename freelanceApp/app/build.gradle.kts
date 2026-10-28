import java.util.Properties

// The freelancer app «دُرر للمحترفين» / "Dorr Pro" (FL-1207) — its own project (freelanceApp/), its own account on the
// server's `freelance_api` guard (/api/freelance/v1/...). The look is the DORR app's (androidApp): same colours, font,
// fields and splash, so a person moving between the two apps sees one family.
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.freelance"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.dorr.freelance"
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

    // Avatars, portfolio and certificate images — through the API's own OkHttp client (FreelanceApp.newImageLoader).
    implementation("io.coil-kt:coil-compose:2.7.0")
    implementation("com.google.android.play:review-ktx:2.0.2")

    // Live updates: speaks the Pusher protocol, so it keeps working when we move to our own Soketi / Reverb server (the host
    // comes from GET realtime-config, never from the app).
    implementation("com.pusher:pusher-java-client:2.4.4")
    // Phone notifications — the server already sends through OneSignal; the app id comes from realtime-config.
    implementation("com.onesignal:OneSignal:5.1.6")

    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
