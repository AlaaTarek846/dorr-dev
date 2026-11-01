import SwiftUI

@main
struct DorrApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var router = AppRouter()
    @StateObject private var appearance = AppearanceState.shared
    @StateObject private var session = AuthSession.shared
    @StateObject private var network = NetworkMonitor.shared

    var body: some Scene {
        WindowGroup {
            RootView()
                // The wallet palette doubles as the app's settings palette (settingsAccent, AccountDark…).
                // Inside the environment objects below, which it reads.
                .waThemed()
                .environmentObject(router)
                .environmentObject(appearance)
                .environmentObject(session)
                .environmentObject(network)
                .preferredColorScheme(appearance.colorSchemeOverride)
                .environment(\.layoutDirection, router.isRtl ? .rightToLeft : .leftToRight)
                .environment(\.locale, Locale(identifier: router.locale))
                // A language or font switch rebuilds every screen so `L(...)` re-reads the new strings.
                .id("\(router.locale)-\(router.stringsRevision)-\(appearance.fontRevision)")
                // A downloaded language is checked against the server once per launch.
                .task { await router.syncLanguage() }
                // O-53 / O-72: the admin's map engine, from the phone at once — then from the server, saved for
                // the next start, so a map never waits on the network to know how to draw.
                // Notifications start with the app id kept from last time (the server confirms it later).
                .task { ChatPush.shared.attach() }
                // The widgets talk to the same server as this account.
                .task {
                    SportsWidgetStore.origin = ApiClient.origin
                    SportsWidgetStore.token = AuthSession.shared.token
                    LocalIntelligence.language = AppLocale.current
                }
                .task {
                    MapEngine.shared.load()
                    if !(AuthSession.shared.token ?? "").isEmpty { await MapEngine.shared.refresh() }
                }
                // The account's font, downloaded once and kept on the phone.
                .task(id: "\(appearance.snapshot?.font?.id ?? 0)-\(appearance.snapshot?.font?.slug ?? "")") { await appearance.loadFont() }
                // dorr://chat/{token}?text=… («click to chat»), dorr://chat/join/{code}, dorr://chat/call/{token} — and
                // dorr://sports/match/{id} from the home-screen widgets.
                .onOpenURL { url in
                    guard let link = DeepLinks.of(url) else { return }
                    ChatPush.shared.open(link)
                }
        }
    }
}

/// What must run at launch even when iOS starts the app in the background (a VoIP push for a ringing call).
final class AppDelegate: NSObject, UIApplicationDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        CrashReports.start()
        MainActor.assumeIsolated { CallSystem.shared.start() }
        return true
    }
}

/// androidApp `MainActivity.openChatLink` / `openSports` — links into the app from outside it.
enum DeepLinks {
    static func of(_ url: URL) -> ChatDeepLink? {
        guard url.scheme == "dorr" else { return nil }
        let parts = url.pathComponents.filter { $0 != "/" }
        switch url.host {
        case "chat":
            // An invite (from the /j/ web page or a QR): the join sheet.
            if parts.first == "join", parts.count > 1 { return .join(parts[1]) }
            // A call link (the /call/ web page): who made it, then «Join».
            if parts.first == "call", parts.count > 1 { return .callLink(parts[1]) }
            return .chatLink(url.absoluteString)
        case "sports":
            // dorr://sports/match/{id} — or Sports itself.
            return .sports(parts.first == "match" && parts.count > 1 ? parts[1] : nil)
        default:
            return nil
        }
    }
}
