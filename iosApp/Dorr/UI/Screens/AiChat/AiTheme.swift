import SwiftUI

// androidApp `aichat/AiTheme.kt`, `AiPlanGate.kt` — the DORR AI screens' colours (the brand palette, with the app's
// chosen accent), the confirm dialog, and the one «this needs a DORR AI plan» prompt any AI refusal lands on.

/// Colour tokens for the AI screens, by day or night.
struct AiColors {
    let night: Bool
    let accent: Color

    var bg: Color { night ? Color(hex: 0x0B1220) : Color(hex: 0xF7F4F2) }
    var surface: Color { night ? Color(hex: 0x172033) : .white }
    var ink: Color { night ? Color(hex: 0xF3F4F6) : Color(hex: 0x111928) }
    var mut: Color { night ? Color(hex: 0x9CA3AF) : Color(hex: 0x6B7280) }
    var line: Color { night ? Color(hex: 0x273244) : Color(hex: 0xEEF0F3) }
    /// `Ai.Red` — the app's accent colour.
    var red: Color { accent }
}

extension WaPalette {
    var ai: AiColors { AiColors(night: night, accent: accent) }
}

/// "5 min ago", "3 h ago" (androidApp `timeAgo`).
func aiTimeAgo(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    let minutes = Int(Date().timeIntervalSince(d) / 60)
    if minutes < 1 { return L("st_just_now") }
    if minutes < 60 { return L("st_minutes_ago", minutes) }
    return L("st_hours_ago", minutes / 60)
}

/// Gives under the finger a little, like a real button (Android's `Pressable`).
struct AiPressStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.96 : 1)
            .animation(.spring(response: 0.3, dampingFraction: 0.5), value: configuration.isPressed)
    }
}

/// Fades and rises into place, a beat after the section before it.
struct AiAppear: ViewModifier {
    let order: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 24)
            .onAppear { withAnimation(.easeOut(duration: 0.44).delay(Double(order) * 0.07)) { shown = true } }
    }
}

extension View {
    func aiAppear(_ order: Int) -> some View { modifier(AiAppear(order: order)) }
}

/// A round icon button without a background (the AI bars).
struct AiRoundIcon: View {
    let icon: String
    let tint: Color
    var size: CGFloat = 19
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: size, weight: .semibold)).foregroundStyle(tint)
                .flipsForRightToLeftLayoutDirection(icon.hasPrefix("chevron.left") || icon.hasPrefix("arrow.left"))
                .frame(width: 40, height: 40).contentShape(Circle())
        }
        .buttonStyle(.plain)
    }
}

/// androidApp `AiConfirmDialog` — title, message, cancel and a (red when destructive) confirm.
struct AiConfirmDialog: View {
    @Environment(\.wa) private var wa
    let title: String
    let message: String
    var confirmLabel: String?
    var dismissLabel: String?
    var destructive = true
    let onConfirm: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        let c = wa.ai
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
            Text(verbatim: message).font(.dorr(13.5)).foregroundStyle(c.mut).padding(.top, 8)
            HStack(spacing: 4) {
                Spacer()
                Button(action: onDismiss) {
                    Text(verbatim: dismissLabel ?? L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(.horizontal, 14).padding(.vertical, 8)
                }
                .buttonStyle(.plain)
                Button(action: onConfirm) {
                    Text(verbatim: confirmLabel ?? L("ai_delete")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 8)
                        .background(destructive ? Color(hex: 0xDC2626) : c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
            .padding(.top, 18)
        }
        .padding(20)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 28)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

// MARK: - The plan gate

/// Every AI tool needs a DORR AI plan. Whichever screen asked, a plan refusal from the server lands here once
/// (through the API client — `inspect`), and one prompt offers the DORR AI subscription.
@MainActor
final class AiPlanGate: ObservableObject {
    static let shared = AiPlanGate()

    /// The server's message for the refusal, while the prompt is up.
    @Published private(set) var prompt: String?
    /// Set by "Subscribe": the DORR AI screens open straight on the subscription page.
    @Published var openSubscription = false

    /// Error codes that mean "this needs (a bigger) DORR AI plan".
    nonisolated private static func isPlanCode(_ code: String) -> Bool {
        code.hasPrefix("chat_ai_plan_") || code.hasPrefix("rides_ai_plan_") || code == "chat_ai_image_not_in_plan"
            || code == "chat_ai_image_daily_limit" || code == "ai_plan_required"
    }

    /// Called by the HTTP client with a 402 / 429 answer's body.
    nonisolated static func inspect(_ body: Data) {
        guard let root = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any],
              let code = root["error_code"] as? String,
              code != "chat_ai_plan_blocked", code != "rides_ai_plan_blocked", isPlanCode(code),
              let message = root["message"] as? String else { return }
        Task { @MainActor in shared.prompt = message }
    }

    /// Offers the plans from a screen that already knows it is locked.
    func offer(_ message: String) { prompt = message }

    func dismiss() { prompt = nil }

    func subscribe() {
        prompt = nil
        openSubscription = true
    }
}

/// The prompt itself: a crown, the server's message, cancel and «subscribe».
struct AiPlanPromptDialog: View {
    @Environment(\.wa) private var wa
    let text: String
    let onSubscribe: () -> Void

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            Image(systemName: "crown.fill").font(.system(size: 24)).foregroundStyle(c.red)
                .frame(width: 52, height: 52).background(c.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            Text(L("ai_plan_required_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink).padding(.top, 12)
            Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(c.mut).multilineTextAlignment(.center).padding(.top, 6)
            HStack(spacing: 6) {
                Spacer()
                Button { AiPlanGate.shared.dismiss() } label: {
                    Text(L("ai_cancel")).font(.dorr(13, .bold)).foregroundStyle(c.mut).padding(10)
                }
                .buttonStyle(.plain)
                Button {
                    AiPlanGate.shared.subscribe()
                    onSubscribe()
                } label: {
                    Text(L("ai_plan_required_subscribe")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 16).padding(.vertical, 10)
                        .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
            .padding(.top, 18)
        }
        .padding(20)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .padding(.horizontal, 28)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}
