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
    }
}

// The DORR driver app (docs/rides-plan.md §17) — its own project, separate from androidApp (the
// rider / DORR app). :core holds what both apps can share; androidApp can include it later with
// project(":core").projectDir = file("../driverApp/core").
rootProject.name = "DorrDriver"
include(":app")
include(":core")
