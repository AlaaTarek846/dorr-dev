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
        // LiveKit (the consultation video session, FL-L16) depends on audioswitch, which is only published on JitPack.
        maven { url = uri("https://jitpack.io") }
    }
}

// The DORR freelancer app «دُرر للمحترفين» (docs/modules/freelance/APP.md) — its own project, separate from androidApp
// (the client app) and driverApp. Its account is the server's `freelance_api` guard (/api/freelance/v1/...).
rootProject.name = "DorrFreelance"
include(":app")
// :core is shared, not copied: the network base (headers, the API envelope and its errors) and the session store live
// once, in driverApp/core, for every DORR app.
include(":core")
project(":core").projectDir = file("../driverApp/core")
