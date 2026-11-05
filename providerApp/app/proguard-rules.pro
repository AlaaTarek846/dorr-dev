# Gson reads the API models by reflection: keep their names (same approach as androidApp and driverApp).
-dontobfuscate
-keep class com.dorr.provider.net.** { *; }
-keep class com.dorr.core.network.** { *; }
-keep class com.pusher.** { *; }
-keep class com.journeyapps.barcodescanner.** { *; }
