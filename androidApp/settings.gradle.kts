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
        // LiveKit (chat calls) depends on audioswitch, which is only published on JitPack.
        maven { url = uri("https://jitpack.io") }
        // O-53: the Mapbox Maps SDK (the second map engine) — public, no download token needed.
        maven { url = uri("https://api.mapbox.com/downloads/v2/releases/maven") }
    }
}

rootProject.name = "Dorr"
include(":app")
