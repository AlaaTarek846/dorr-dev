# AR editor (optional module)

Lets a customer place several models in their room, move/rotate/scale them by touch and save the design to DORR
(`PUT user/v1/ai-ar/experiences/{id}/scene`). The main app builds and works WITHOUT this module: the "Open AR editor"
button only appears when the module is present.

## Turn it on
1. `androidApp/settings.gradle.kts`: add `include(":ar-editor")`.
2. `androidApp/app/build.gradle.kts`: add `implementation(project(":ar-editor"))` under `dependencies`.
3. Sync and build. Camera permission and the optional-ARCore flag come from this module's manifest.

## Read before building
- It was written without being compiled. `ArEditorScreen.kt` is the ONLY file that uses SceneView; if the version
  (`io.github.sceneview:arsceneview`, set to 4.55.0 in `build.gradle.kts`) names something differently, fix that file only.
- Item positions are saved relative to the first model placed (an AR session's world coordinates are not stable between runs).
  Re-opening a saved design in AR needs a re-anchoring step (user taps where the first model goes) that is not built yet.
- The saved design is what the customer sends to a provider for review; once sent, it is locked until answered.
