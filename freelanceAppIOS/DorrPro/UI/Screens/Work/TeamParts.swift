import SwiftUI
import UIKit

// freelanceApp `ui/screens/TeamParts.kt`.

/*
 * FS-209: the small pieces the team screens share — a team's logo, the role pill, a person's initials, a switch row, and the copy / share of
 * an invitation code.
 */

/// A team's logo in a rounded square, or a group icon when it has none (or it can't be reached). `version` makes a new upload show at once.
struct TeamLogo: View {
    @Environment(\.wa) private var wa
    let url: String?
    let size: CGFloat
    var onBrand = false
    var version: Int64 = 0

    var body: some View {
        let tint = onBrand ? Color.white : wa.red
        let shape = RoundedRectangle(cornerRadius: size * 0.28, style: .continuous)
        let shown: URL? = Api.mediaUrl(url).flatMap { base in
            if version == 0 { return base }
            let text = base.absoluteString
            return URL(string: text + (text.contains("?") ? "&" : "?") + "v=\(version)")
        }
        ZStack {
            shape.fill(tint.opacity(onBrand ? 0.22 : 0.12))
            if let shown {
                AsyncImage(url: shown) { phase in
                    if let image = phase.image {
                        image.resizable().scaledToFill()
                    } else {
                        Image(systemName: "person.3.fill").font(.system(size: size / 2.4)).foregroundStyle(tint)
                    }
                }
            } else {
                Image(systemName: "person.3.fill").font(.system(size: size / 2.4)).foregroundStyle(tint)
            }
        }
        .frame(width: size, height: size)
        .clipShape(shape)
    }
}

func roleLabel(_ role: String) -> String {
    switch role {
    case "owner": return L("team_role_owner")
    case "pm": return L("team_role_pm")
    default: return L("team_role_member")
    }
}

func roleHint(_ role: String) -> String {
    switch role {
    case "owner": return L("team_role_owner_hint")
    case "pm": return L("team_role_pm_hint")
    default: return L("team_role_member_hint")
    }
}

func roleTint(_ role: String) -> Color {
    switch role {
    case "owner": return Tints.amber
    case "pm": return Tints.blue
    default: return Tints.teal
    }
}

struct RolePill: View {
    let role: String

    var body: some View {
        StatusPill(text: roleLabel(role), tint: roleTint(role))
    }
}

/// A person without a photo: the first letter of the name on a soft disc.
struct InitialsBadge: View {
    @Environment(\.wa) private var wa
    let name: String?
    let size: CGFloat
    /// nil = the app colour.
    var tint: Color?

    var body: some View {
        let tint = self.tint ?? wa.red
        let letter = name?.trimmingCharacters(in: .whitespacesAndNewlines).first.map { String($0).uppercased() } ?? "?"
        Text(verbatim: letter).font(.dorr(size * 0.42, .black)).foregroundStyle(tint)
            .frame(width: size, height: size)
            .background(tint.opacity(0.14), in: Circle())
    }
}

/// A switch with its title and a line under it; the whole row is the tap target.
struct TeamSwitchRow: View {
    @Environment(\.wa) private var wa
    let title: String
    var subtitle: String?
    let on: Bool
    var enabled = true
    let onToggle: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                if let subtitle {
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            RedToggle(on: on, action: {}).allowsHitTesting(false).opacity(enabled ? 1 : 0.5)
        }
        .padding(.vertical, 6)
        .contentShape(Rectangle())
        .onTapGesture { if enabled { onToggle() } }
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isButton)
    }
}

/// An ISO date as a short date in the app's language (nil when it can't be read).
func teamDate(_ iso: String?) -> String? {
    guard let date = parseInstant(iso) else { return nil }
    let formatter = DateFormatter()
    formatter.locale = AppLocale.locale
    formatter.dateStyle = .medium
    formatter.timeStyle = .none
    return formatter.string(from: date)
}

/// `copyText` of the Android file: the text on the clipboard.
func teamCopyText(_ text: String) {
    UIPasteboard.general.string = text
}

/// `shareText` of the Android file (`ACTION_SEND` through the chooser): the system share sheet over the top screen.
@MainActor
func teamShareText(_ text: String) {
    guard let top = WalletTopController.find() else { return }
    let sheet = UIActivityViewController(activityItems: [text], applicationActivities: nil)
    sheet.popoverPresentationController?.sourceView = top.view
    top.present(sheet, animated: true)
}
