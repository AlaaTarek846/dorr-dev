# Gson reads the API models by reflection: keep their names (same approach as androidApp and driverApp).
-dontobfuscate
-keep class com.dorr.freelance.net.** { *; }
-keep class com.dorr.core.network.** { *; }
-keep class com.pusher.** { *; }
