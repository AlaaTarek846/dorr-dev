# Gson reads the API models by reflection: keep their names (same approach as :app).
-dontobfuscate
-keep class com.dorr.driver.net.** { *; }
-keep class com.dorr.core.network.** { *; }
