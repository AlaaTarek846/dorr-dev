// :core — shared by the DORR app and the driver app (docs/rides-plan.md §4.3): the network base
// (headers, the API envelope and its errors, Idempotency-Key), the signed-in session and the brand
// theme. Ride logic for the driver stays in :app, never here (R-198, R-200).
plugins {
    id("com.android.library")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.core"
    compileSdk = 34

    defaultConfig {
        minSdk = 26
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
    api(composeBom)
    api("androidx.core:core-ktx:1.13.1")
    api("androidx.compose.ui:ui")
    api("androidx.compose.material3:material3")

    // Same network stack as :app.
    api("com.squareup.retrofit2:retrofit:2.11.0")
    api("com.squareup.retrofit2:converter-gson:2.11.0")
    api("com.google.code.gson:gson:2.11.0")
    api("com.squareup.okhttp3:logging-interceptor:4.12.0")

    testImplementation("junit:junit:4.13.2")
}
