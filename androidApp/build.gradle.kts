// Top-level build file — plugin versions only, no logic. Each module applies
// what it needs.
plugins {
    id("com.android.application") version "8.6.1" apply false
    // The Compose Compiler Gradle plugin (org.jetbrains.kotlin.plugin.compose) only
    // exists for Kotlin 2.0+ — it replaces the old composeOptions.kotlinCompilerExtensionVersion
    // pinning, so both plugins must share the same Kotlin version.
    id("org.jetbrains.kotlin.android") version "2.0.21" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.0.21" apply false
    // Crash reports (Firebase Crashlytics). Applied by :app only when app/google-services.json exists.
    id("com.google.gms.google-services") version "4.4.2" apply false
    id("com.google.firebase.crashlytics") version "3.0.2" apply false
}
