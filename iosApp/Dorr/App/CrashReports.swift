import FirebaseCore
import FirebaseCrashlytics
import Foundation

// androidApp `CrashReports.kt` — crash reports in Firebase Crashlytics: each crash with its stack, the phone, the app
// version and the account id, so a bug report says which user hit it. Crashlytics catches the crashes by itself; this
// adds who it was and the handled errors worth seeing.
//
// Without Dorr/GoogleService-Info.plist (Android: app/google-services.json) there is no Firebase app: every call here
// does nothing.
enum CrashReports {
    /// At launch, before anything can crash.
    static func start() {
        guard FirebaseApp.app() == nil, Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist") != nil else { return }
        FirebaseApp.configure()
    }

    private static var crashlytics: Crashlytics? { FirebaseApp.app() == nil ? nil : Crashlytics.crashlytics() }

    static func setUser(_ id: Int?) {
        crashlytics?.setUserID(id.map(String.init) ?? "")
    }

    /// A line kept with the next crash report («opened chat …»).
    static func log(_ message: String) {
        crashlytics?.log(message)
    }

    /// An error the app survived but we want to see.
    static func record(_ error: Error) {
        crashlytics?.record(error: error)
    }
}
