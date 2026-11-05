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

// The DORR providers app «شركاء دُرر» (docs/moving_tasks.md phase C, providerApp/README.md) — its own project, separate from
// androidApp (the client app), driverApp and freelanceApp. Its account is the server's `provider_api` guard (/api/provider/v1/...).
rootProject.name = "DorrProvider"
include(":app")
// :core is shared, not copied: the network base (headers, the API envelope and its errors) and the session store live
// once, in driverApp/core, for every DORR app.
include(":core")
project(":core").projectDir = file("../driverApp/core")
