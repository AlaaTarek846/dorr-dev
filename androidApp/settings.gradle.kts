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
    }
}

rootProject.name = "Dorr"
include(":app")
