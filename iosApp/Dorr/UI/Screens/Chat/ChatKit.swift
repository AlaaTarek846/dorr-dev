import LocalAuthentication
import SwiftUI

// The small pieces every chat page shares — androidApp `ChSheet.kt`, `ChField.kt`, MessageActions' `ChoiceSheet`,
// ChatTools' `TextInputSheet` and `ChatLock`, ChatPartialUi's `ChatPrefs` and ChatListPage's empty state, primary
// button and skeleton rows.

/// My reading and upload choices (spec 6, 13).
@MainActor
final class ChatPrefs: ObservableObject {
    static let shared = ChatPrefs()
    private let d = UserDefaults.standard

    @Published private(set) var fontScale: Double
    @Published private(set) var compactList: Bool
    /// auto (by the connection) · high · saver
    @Published private(set) var uploadQuality: String

    private init() {
        fontScale = d.object(forKey: "chat_prefs.font_scale") as? Double ?? 1
        compactList = d.bool(forKey: "chat_prefs.compact_list")
        uploadQuality = d.string(forKey: "chat_prefs.upload_quality") ?? "auto"
    }

    func save(scale: Double? = nil, compact: Bool? = nil, quality: String? = nil) {
        if let scale { fontScale = scale; d.set(scale, forKey: "chat_prefs.font_scale") }
        if let compact { compactList = compact; d.set(compact, forKey: "chat_prefs.compact_list") }
        if let quality { uploadQuality = quality; d.set(quality, forKey: "chat_prefs.upload_quality") }
    }

    /// Longest side and JPEG quality for a photo now: close to the original on a good line, lighter on a slow one.
    func photoBudget() -> (side: CGFloat, quality: CGFloat) {
        // Data saver (spec 82) wins over the quality picked here.
        if ChatDataSaver.shared.active { return (1024, 0.7) }
        switch uploadQuality {
        case "high": return (2560, 0.9)
        case "saver": return (1024, 0.7)
        default: return NetworkMonitor.shared.isExpensive ? (1600, 0.8) : (2560, 0.9)
        }
    }
}

/// The phone's own lock (Face ID / passcode) before locked chats show; circles open each with their own unlock.
@MainActor
enum ChatLock {
    private(set) static var unlocked = false
    private static var unlockedCircles = Set<String>()

    static func lock() {
        unlocked = false
        unlockedCircles.removeAll()
    }

    static func unlockCircle(_ id: String, onSuccess: @escaping () -> Void) {
        if unlockedCircles.contains(id) { onSuccess(); return }
        let was = unlocked
        unlocked = false
        unlock {
            unlockedCircles.insert(id)
            unlocked = was
            onSuccess()
        }
    }

    static func unlock(onSuccess: @escaping () -> Void) {
        if unlocked { onSuccess(); return }
        let context = LAContext()
        var error: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthentication, error: &error) else {
            // No passcode on this phone: nothing to ask.
            unlocked = true
            onSuccess()
            return
        }
        context.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: L("ch_unlock_title")) { ok, _ in
            Task { @MainActor in
                if ok {
                    unlocked = true
                    onSuccess()
                }
            }
        }
    }
}

/// A bottom sheet's card: the surface, rounded on top, the content padded.
struct ChSheetCard<Content: View>: View {
    @ObservedObject private var ch = Ch.shared
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Capsule().fill(ch.line).frame(width: 40, height: 4).frame(maxWidth: .infinity).padding(.top, 10).padding(.bottom, 14)
            content()
        }
        .padding(.horizontal, 20).padding(.bottom, 24)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(ch.surface.ignoresSafeArea(edges: .bottom))
        .clipShape(UnevenRoundedRectangleCompat(topRadius: 30))
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

/// Options in a sheet (androidApp `ChoiceSheet`): a title, an optional subtitle, rows (radio rows when `radios`).
struct ChoiceSheet: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    var subtitle: String?
    let options: [(String, () -> Void)]
    var danger = false
    var radios = false
    var selected: Int?
    var values: [String?]?
    /// A long list: the sheet keeps this height and the options scroll inside it.
    var fixedHeight: CGFloat?
    let onDismiss: () -> Void

    var body: some View {
        ChSheetCard {
            Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
            if let subtitle { Text(verbatim: subtitle).font(.dorr(13.5)).foregroundStyle(ch.mut).padding(.top, 4) }
            VStack(spacing: 8) {
                if let fixedHeight {
                    ScrollView { VStack(spacing: 8) { rows } }.frame(height: max(fixedHeight - 160, 120))
                } else {
                    rows
                }
                Button(action: onDismiss) {
                    Text(L("ch_cancel")).font(.dorr(15, .bold)).foregroundStyle(ch.mut).frame(maxWidth: .infinity).padding(.vertical, 14)
                        .background(ch.surfaceMuted.opacity(0.6), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.top, 2)
            }
            .padding(.top, 14)
        }
    }

    @ViewBuilder private var rows: some View {
                ForEach(Array(options.enumerated()), id: \.offset) { i, o in
                    Button {
                        o.1()
                        onDismiss()
                    } label: {
                        HStack {
                            (Text(verbatim: o.0).font(.dorr(15, .bold)).foregroundColor(danger ? ch.danger : ch.ink)
                                + Text(verbatim: values?[safe: i].flatMap { $0.map { " (\($0))" } } ?? "").font(.dorr(12)).foregroundColor(ch.mut))
                                .multilineTextAlignment(.leading)
                            Spacer(minLength: 0)
                            if radios {
                                Image(systemName: selected == i ? "largecircle.fill.circle" : "circle").font(.system(size: 20)).foregroundStyle(selected == i ? ch.red : ch.soft)
                            }
                        }
                        .padding(.horizontal, 16).padding(.vertical, 15)
                        .background(danger && i == 0 ? ch.danger.opacity(0.1) : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .chStagger(i)
                }
    }
}

extension Array {
    subscript(safe i: Int) -> Element? { indices.contains(i) ? self[i] : nil }
}

/// The chat's text field look (the profile pages' field).
struct ChField: View {
    @ObservedObject private var ch = Ch.shared
    let placeholder: String
    @Binding var text: String
    var icon: String?
    var lines: ClosedRange<Int> = 1...1
    var limit = 500
    var keyboard: UIKeyboardType = .default
    /// An ✕ that empties it while there is text.
    var clearable = false
    /// Ready to type in as soon as it shows (the emoji panel's search).
    var autofocus = false
    @FocusState private var focused: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            if let icon { Image(systemName: icon).font(.system(size: 16)).foregroundStyle(focused ? ch.red : ch.soft).padding(.top, 2) }
            TextField(placeholder, text: Binding(get: { text }, set: { text = String($0.prefix(limit)) }), axis: .vertical)
                .lineLimit(lines).font(.dorr(15)).foregroundStyle(ch.ink).tint(ch.red).keyboardType(keyboard).focused($focused)
            if clearable && !text.isEmpty {
                Button { text = "" } label: { Image(systemName: "xmark.circle.fill").font(.system(size: 16)).foregroundStyle(ch.soft) }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("ch_close_sheet"))
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 14)
        .background(ch.fieldFill, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(focused ? ch.red : ch.fieldLine, lineWidth: focused ? 1.5 : 1))
        .onAppear { if autofocus { DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) { focused = true } } }
    }
}

/// One short text in a sheet (a folder's name…).
struct TextInputSheet: View {
    @ObservedObject private var ch = Ch.shared
    let title: String
    var initial = ""
    let action: String
    var icon = "pencil"
    var limit = 50
    let onDismiss: () -> Void
    let onDone: (String) -> Void
    @State private var value = ""

    var body: some View {
        ChSheetCard {
            Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(ch.ink).padding(.bottom, 14)
            ChField(placeholder: title, text: $value, icon: icon, limit: limit)
            ChPrimaryButton(text: action, enabled: !value.trimmingCharacters(in: .whitespaces).isEmpty) {
                onDone(value.trimmingCharacters(in: .whitespaces))
                onDismiss()
            }
            .padding(.top, 16)
        }
        .onAppear { value = initial }
    }
}

/// The chat's main button: the app colour as a gradient, a soft glow, a press-in.
struct ChPrimaryButton: View {
    @ObservedObject private var ch = Ch.shared
    let text: String
    var icon: String?
    var enabled = true
    var full = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                if let icon { Image(systemName: icon).font(.system(size: 17, weight: .semibold)) }
                Text(verbatim: text).font(.dorr(15, .heavy))
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 26).padding(.vertical, 14)
            .frame(maxWidth: full ? .infinity : nil)
            .background(LinearGradient(colors: [ch.red, ch.redDeep], startPoint: .leading, endPoint: .trailing), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: ch.red.opacity(0.35), radius: 10, y: 4)
            .opacity(enabled ? 1 : 0.5)
        }
        .buttonStyle(WaPressStyle())
        .disabled(!enabled)
    }
}

/// Empty / error state; `animated` floats three chat bubbles around the icon.
struct ChEmptyState: View {
    @ObservedObject private var ch = Ch.shared
    let icon: String
    let title: String
    let text: String
    var action: String?
    var onAction: () -> Void = {}
    var animated = false
    @State private var float = false

    var body: some View {
        VStack(spacing: 0) {
            ZStack {
                if animated {
                    bubble(46, Color(hex: 0xFFE4E6)).offset(x: -55, y: -45 + (float ? 10 : 0))
                    bubble(36, Color(hex: 0xFDE68A)).offset(x: 55, y: -30 - (float ? 12 : 0))
                    bubble(28, Color(hex: 0xDBEAFE)).offset(x: 60, y: 55 - (float ? 8 : 0))
                }
                Image(systemName: icon).font(.system(size: 42)).foregroundStyle(.white)
                    .frame(width: 100, height: 100).background(ch.headerBrush, in: Circle())
                    .shadow(color: ch.red.opacity(0.4), radius: 18, y: 8)
                    .offset(y: float ? -6 : 0)
            }
            .frame(width: 170, height: 170)
            Text(verbatim: title).font(.dorr(19, .heavy)).foregroundStyle(ch.ink).padding(.top, 18)
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center).padding(.top, 6)
            if let action { ChPrimaryButton(text: action, full: false, action: onAction).padding(.top, 20) }
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .onAppear { withAnimation(.easeInOut(duration: 2.2).repeatForever(autoreverses: true)) { float = true } }
    }

    private func bubble(_ size: CGFloat, _ color: Color) -> some View {
        UnevenRoundedRectangleCompat(topRadius: size / 2).fill(color).frame(width: size, height: size).clipShape(RoundedRectangle(cornerRadius: size / 2.2))
    }
}

/// Grey placeholders while the first page loads.
struct ChSkeletonRows: View {
    @ObservedObject private var ch = Ch.shared
    @State private var shimmer = false

    var body: some View {
        VStack(spacing: 0) {
            ForEach(0..<8, id: \.self) { _ in
                HStack(spacing: 12) {
                    Circle().fill(ch.surfaceMuted).frame(width: 54, height: 54)
                    VStack(alignment: .leading, spacing: 8) {
                        RoundedRectangle(cornerRadius: 6).fill(ch.surfaceMuted).frame(width: 150, height: 14)
                        RoundedRectangle(cornerRadius: 6).fill(ch.surfaceMuted).frame(height: 12)
                    }
                }
                .padding(.vertical, 9)
            }
            Spacer()
        }
        .padding(.horizontal, 14)
        .opacity(shimmer ? 0.55 : 1)
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever()) { shimmer = true } }
    }
}

/// The toast over the chat (above the composer): navy, or the danger colour.
struct ChatToastView: View {
    @ObservedObject var host: ChatHost

    var body: some View {
        VStack {
            Spacer()
            if let t = host.toast {
                HStack(spacing: 10) {
                    Image(systemName: host.toastError ? "exclamationmark.circle" : "checkmark.circle.fill").font(.system(size: 17))
                    Text(verbatim: t).font(.dorr(13.5, .semibold)).multilineTextAlignment(.leading)
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 18).padding(.vertical, 12)
                .background(host.toastError ? Ch.shared.danger : Color(hex: 0x111928), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: .black.opacity(0.25), radius: 12, y: 4)
                .transition(.move(edge: .bottom).combined(with: .opacity).combined(with: .scale(scale: 0.9)))
            }
        }
        .padding(.horizontal, 16).padding(.bottom, 148)
        .allowsHitTesting(false)
    }
}

/// androidApp `IconBadge`: an icon on a soft round tint of its colour.
struct ChIconBadge: View {
    let icon: String
    let color: Color
    var size: CGFloat = 42
    var iconTint: Color?

    var body: some View {
        Image(systemName: icon).font(.system(size: size * 0.42)).foregroundStyle(iconTint ?? color)
            .frame(width: size, height: size).background(color.opacity(0.14), in: Circle())
    }
}
