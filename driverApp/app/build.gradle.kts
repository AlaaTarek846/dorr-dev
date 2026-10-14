import java.util.Properties

// The driver app (docs/rides-plan.md §17) — its own project (driverApp/), separate from the DORR app
// in androidApp/, and its own account on the server's `driver_api` guard (/api/driver/v1/...). Sign-in, signing up, the shift
// (location + requests in a foreground service), the ride and the earnings.
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.driver"
    compileSdk = 34

    defaultConfig {
        // Placeholder identity until the store name is decided (proposed: DORR Captain).
        applicationId = "com.dorr.driver"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "0.1.0"

        // Same backend settings as androidApp (local.properties, or -P on the command line).
        val local = Properties().apply {
            rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
        }
        val apiHost = (findProperty("dorr.apiHost") as String?) ?: local.getProperty("dorr.apiHost", "unafraid-occupy-geography.ngrok-free.dev")
        val apiScheme = (findProperty("dorr.apiScheme") as String?) ?: local.getProperty("dorr.apiScheme", "https")
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
}

dependencies {
    implementation(project(":core"))

    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.navigation:navigation-compose:2.8.0")
    // The shift's location (fused provider), and pictures (avatars, documents).
    implementation("com.google.android.gms:play-services-location:21.3.0")
    implementation("io.coil-kt:coil-compose:2.7.0")

    testImplementation("junit:junit:4.13.2")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
