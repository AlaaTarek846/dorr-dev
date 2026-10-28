// OPTIONAL module: the in-app AR editor (place several models, move/rotate/scale them, save the design).
// It is NOT included in settings.gradle.kts, so the main app builds without it. See ar-editor/README.md to switch it on.
plugins {
    id("com.android.library")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.dorr.ar.editor"
    compileSdk = 34

    defaultConfig {
        minSdk = 26
    }

    buildFeatures { compose = true }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.09.03")
    implementation(composeBom)
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")
    // SceneView's AR module (ARCore + Filament). Check the current version before enabling.
    implementation("io.github.sceneview:arsceneview:4.55.0")
}
