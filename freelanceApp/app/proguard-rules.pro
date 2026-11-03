# Gson reads the API models by reflection: keep their names (same approach as androidApp and driverApp).
-dontobfuscate
-keep class com.dorr.freelance.net.** { *; }
-keep class com.dorr.core.network.** { *; }
-keep class com.pusher.** { *; }
# LiveKit (the consultation video session, FL-L16): as in androidApp, its own consumer rules plus kept names (-dontobfuscate) and
# the generic types / annotations it reads at run time.
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations, AnnotationDefault
-dontwarn org.slf4j.**
