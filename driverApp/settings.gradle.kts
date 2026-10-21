pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        // LiveKit (O-09 in-app calls) depends on audioswitch, which is only published on JitPack.
        maven { url = uri("https://jitpack.io") }
        // O-53: the Mapbox Maps SDK (the second map engine) — public, no download token needed.
        maven { url = uri("https://api.mapbox.com/downloads/v2/releases/maven") }
    }
}

// The DORR driver app (docs/rides-plan.md §17) — its own project, separate from androidApp (the
// rider / DORR app). :core holds what both apps can share; androidApp can include it later with
// project(":core").projectDir = file("../driverApp/core").
rootProject.name = "DorrDriver"
include(":app")
include(":core")
