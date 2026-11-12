import SwiftUI

// freelanceApp `NotificationSettingsScreen.kt` — Notifications under Settings, as in the DORR app.

private struct NotifToggle {
    let key: String
    let title: String
    let desc: String
    let icon: String
    let startsOn: Bool
}

/// The settings toggles of the DORR app, worded for the freelancer. The first one is the phone's push switch; the others are kept on this phone.
private let toggles = [
    NotifToggle(key: "push", title: "notif_push_title", desc: "notif_push_desc", icon: "bell.fill", startsOn: true),
    NotifToggle(key: "updates", title: "notif_updates_title", desc: "notif_updates_desc", icon: "doc.plaintext.fill", startsOn: true),
    NotifToggle(key: "promo", title: "notif_promo_title", desc: "notif_promo_desc", icon: "tag.fill", startsOn: true),
    NotifToggle(key: "email", title: "notif_email_title", desc: "notif_email_desc", icon: "envelope.fill", startsOn: false),
]

private let prefs = "freelance_notification_prefs"

@MainActor
private func saved(_ toggle: NotifToggle) -> Bool {
    if toggle.key == "push" { return Realtime.shared.pushOptedIn() }
    return UserDefaults.standard.object(forKey: "\(prefs).\(toggle.key)") as? Bool ?? toggle.startsOn
}

@MainActor
private func save(_ toggle: NotifToggle, _ on: Bool) {
    if toggle.key == "push" { Realtime.shared.setPushOptedIn(on) } else { UserDefaults.standard.set(on, forKey: "\(prefs).\(toggle.key)") }
}

/// Notifications under Settings, as in the DORR app: a card of switches with an icon, a title and a line each.
@MainActor
struct NotificationSettingsScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    @State private var states: [Bool] = toggles.map { saved($0) }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("account_notifications"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        ForEach(Array(toggles.enumerated()), id: \.offset) { index, toggle in
                            HStack(spacing: 10) {
                                PinkIcon(icon: toggle.icon)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(L(toggle.title)).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                                    Text(L(toggle.desc)).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                RedToggle(on: states[index]) {
                                    states[index].toggle()
                                    save(toggle, states[index])
                                }
                            }
                            .padding(.horizontal, 4)
                            .padding(.vertical, 12)
                            if index != toggles.count - 1 {
                                Rectangle().fill(wa.night ? wa.line : Color(hex: 0xF6E4E8)).frame(height: 1)
                            }
                        }
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .settingsSurface(18, elevation: 8)
                    .padding(.horizontal, 14)
                    .padding(.top, 6)
                    .padding(.bottom, 16)
                }
            }
        }
        .navigationBarHidden(true)
    }
}
