# Release builds remove code and resources nothing uses (R8), but keep names: Gson, Retrofit,
# OneSignal, Pusher and LiveKit read classes by reflection, and renaming saves little next to
# what shrinking removes.
-dontobfuscate

# Generic types and annotations Gson / Retrofit read at run time (TypeToken, @SerializedName).
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations, AnnotationDefault

# The app's own classes: its DTOs are filled by Gson and the Retrofit interfaces are proxied by
# reflection. They are a few MB at most; the libraries are what shrinking trims.
-keep class com.dorr.app.** { *; }

# Pusher parses its protocol messages with Gson.
-keep class com.pusher.** { *; }
-dontwarn org.slf4j.**
-dontwarn com.google.android.gms.common.annotation.NoNullnessRewrite
