import SwiftUI
import UIKit

// freelanceApp `ui/screens/ContactUsSheet.kt` — phone, WhatsApp and e-mail as tiles in a bottom panel.
//
// On Android the sheet is its own dialog (scrim + bottom panel). Here the view is the panel; present it the way the kit
// presents a dialog: `.dorrDialog(isPresented: $showContact, bottom: true) { ContactUsSheet(onDismiss: { showContact = false }) }`.

private let contactPhone = "+96522200000"
private let contactWhatsapp = "96522200000"
private let contactEmail = "support@dorr.app"

/// The phone's bottom inset (home indicator), for panels drawn edge to edge.
var supportSafeBottom: CGFloat {
    UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first?.safeAreaInsets.bottom ?? 0
}

/// A panel at the bottom edge, rounded 22 at the top, the pink backdrop behind it (Contact us, FAQ).
struct SupportBottomPanel<Content: View>: View {
    var maxHeight: CGFloat?
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(spacing: 0) {
            Spacer(minLength: 0)
            content()
                .frame(maxWidth: .infinity)
                .frame(maxHeight: maxHeight)
                .background(PinkBackdropCard())
                .clipShape(UnevenRoundedRectangleCompat(topRadius: 22))
                .contentShape(Rectangle())
                .onTapGesture {}
        }
        .ignoresSafeArea(edges: .bottom)
    }
}

struct ContactUsSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void

    var body: some View {
        SupportBottomPanel {
            VStack(spacing: 8) {
                HStack(spacing: 10) {
                    PinkIcon(icon: "phone.fill")
                    SettingsScreenTitle(title: L("contact_title"))
                    Spacer(minLength: 0)
                }
                .padding(.bottom, 4)
                ContactTile(icon: "phone.fill", label: L("contact_phone"), value: contactPhone) { open("tel:\(contactPhone)") }
                ContactTile(icon: "bubble.left.fill", label: L("contact_whatsapp"), value: contactWhatsapp) { open("https://wa.me/\(contactWhatsapp)") }
                ContactTile(icon: "envelope.fill", label: L("contact_email"), value: contactEmail) { open("mailto:\(contactEmail)") }
            }
            .padding(.horizontal, 14)
            .padding(.top, 18)
            .padding(.bottom, 24 + supportSafeBottom)
        }
    }

    /// `tryStart`: nothing happens when no app on the phone takes the link.
    private func open(_ link: String) {
        guard let url = URL(string: link) else { return }
        UIApplication.shared.open(url)
    }
}

private struct ContactTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                PinkIcon(icon: icon)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: value).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : Color(hex: 0xF8BCA9))
            }
            .padding(12)
            .settingsSurface(14)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
