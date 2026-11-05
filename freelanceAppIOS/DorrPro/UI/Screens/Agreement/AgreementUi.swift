// freelanceApp `ui/screens/AgreementUi.kt`
import SwiftUI

/// The pieces the agreement page is built from: a form that opens from the bottom, the action buttons and the tab bar.

/// A form over the page (instead of a dialog): the same head as the offer form - an icon, a title and a line - then its fields.
/// Shown with `.formSheet(isPresented:…)` (Android `LocaleAwareBottomSheet`).
struct FormSheet<Content: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String
    var fullHeight = false
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                FormIntro(icon: icon, title: title, text: text)
                content()
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background((wa.night ? wa.darkCard : Color.white).ignoresSafeArea())
        // AppLanguage.kt: every bottom sheet opens fully (skipPartiallyExpanded).
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
    }
}

/// Carries the app's palette, language and shared objects into a sheet (it is presented outside the page's tree).
private struct FormSheetPresenter<Sheet: View>: ViewModifier {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    @Environment(\.locale) private var locale
    @EnvironmentObject private var appearance: AppearanceState
    @EnvironmentObject private var network: NetworkMonitor
    @Binding var isPresented: Bool
    let locked: Bool
    @ViewBuilder let sheet: () -> Sheet

    func body(content: Content) -> some View {
        content.sheet(isPresented: $isPresented) {
            sheet()
                .interactiveDismissDisabled(locked)
                .environment(\.wa, wa)
                .environment(\.layoutDirection, direction)
                .environment(\.locale, locale)
                .environmentObject(appearance)
                .environmentObject(network)
        }
    }
}

extension View {
    /// `FormSheet(icon, title, body, onDismiss = { if (!busy) … })`: `locked` keeps it open while a request runs.
    func formSheet<C: View>(isPresented: Binding<Bool>, icon: String, title: String, text: String, fullHeight: Bool = false, locked: Bool = false,
                            @ViewBuilder content: @escaping () -> C) -> some View {
        modifier(FormSheetPresenter(isPresented: isPresented, locked: locked) {
            FormSheet(icon: icon, title: title, text: text, fullHeight: fullHeight, content: content)
        })
    }
}

/// A `Binding<Bool>` that is true while `value` is set, and clears it when the sheet closes.
func agreementPresence<T>(_ value: Binding<T?>) -> Binding<Bool> {
    Binding(get: { value.wrappedValue != nil }, set: { if !$0 { value.wrappedValue = nil } })
}

/// Android's `internal enum class Tone` of the agreement buttons (named apart from the wallet's icon `Tone`).
enum ActionTone { case primary, soft, danger, warn }

/// A button of the agreement: filled for the main step, tinted for the others (red for stepping back, amber for a dispute).
struct ActionButton: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String?
    var tone: ActionTone = .soft
    var enabled = true
    var loading = false
    let action: () -> Void

    init(_ text: String, _ icon: String?, _ tone: ActionTone = .soft, enabled: Bool = true, loading: Bool = false, action: @escaping () -> Void) {
        self.text = text
        self.icon = icon
        self.tone = tone
        self.enabled = enabled
        self.loading = loading
        self.action = action
    }

    var body: some View {
        let base: Color = tone == .danger ? Color(hex: 0xDC2626) : tone == .warn ? Color(hex: 0xD97706) : wa.red
        let filled = tone == .primary
        let ink: Color = filled ? .white : base
        Button(action: action) {
            HStack(spacing: 8) {
                if loading {
                    ProgressView().tint(ink).scaleEffect(0.8).frame(width: 18, height: 18)
                } else if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(ink.opacity(enabled ? 1 : 0.5)).frame(width: 18, height: 18)
                }
                Text(verbatim: text).font(.dorr(14, .bold)).foregroundStyle(ink.opacity(enabled ? 1 : 0.5)).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 13)
            .padding(.horizontal, 14)
            .background(filled ? base.opacity(enabled ? 1 : 0.45) : base.opacity(enabled ? 0.10 : 0.05),
                        in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled || loading)
    }
}

struct AgreementTab {
    let icon: String
    let label: String
    var badge = 0
}

/// The sections of the agreement, side by side: the chosen one is filled.
struct AgreementTabs: View {
    @Environment(\.wa) private var wa
    let tabs: [AgreementTab]
    let selected: Int
    let onSelect: (Int) -> Void

    var body: some View {
        HStack(spacing: 4) {
            ForEach(Array(tabs.enumerated()), id: \.offset) { i, t in
                let on = i == selected
                let ink: Color = on ? .white : wa.settingsMut
                HStack(spacing: 5) {
                    Image(systemName: t.icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(ink)
                    Text(verbatim: t.label).font(.dorr(12.5, .bold)).foregroundStyle(ink).lineLimit(1)
                    if t.badge > 0 {
                        Text(verbatim: String(t.badge)).font(.dorr(10.5, .heavy)).foregroundStyle(on ? .white : wa.red)
                            .padding(.horizontal, 6).padding(.vertical, 1)
                            .background(on ? Color.white.opacity(0.25) : wa.red.opacity(0.12), in: Capsule())
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 10)
                .background(on ? wa.red : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .contentShape(Rectangle())
                .onTapGesture { onSelect(i) }
            }
        }
        .padding(4)
        .settingsSurface(18, elevation: 3)
    }
}

func milestoneColor(_ status: String?) -> Color {
    switch status {
    case "approved": return Color(hex: 0x16A34A)
    case "in_progress", "funded": return Color(hex: 0x2563EB)
    case "delivered": return Color(hex: 0x7C3AED)
    case "revision", "disputed": return Color(hex: 0xD97706)
    default: return Color(hex: 0x6B7280)
    }
}

func agreementColor(_ status: String?) -> Color {
    switch status {
    case "active": return Color(hex: 0x16A34A)
    case "pending_approval": return Color(hex: 0xD97706)
    case "completed": return Color(hex: 0x2563EB)
    default: return Color(hex: 0xDC2626)
    }
}

// MARK: - shared bits of this area (prefixed so they never clash with another area's helpers)

/// The pill to pick one reason / option (the `Pill` / `Pick` of the Kotlin files): brand-filled when chosen.
struct AgreementPill: View {
    @Environment(\.wa) private var wa
    let text: String
    let on: Bool
    var size: CGFloat = 13
    var horizontal: CGFloat = 14
    var vertical: CGFloat = 7
    let action: () -> Void

    var body: some View {
        Text(verbatim: text)
            .font(.dorr(size, .bold))
            .foregroundStyle(on ? .white : wa.settingsInk)
            .padding(.horizontal, horizontal)
            .padding(.vertical, vertical)
            .background(on ? wa.red : wa.red.opacity(0.08), in: Capsule())
            .contentShape(Capsule())
            .onTapGesture(perform: action)
    }
}

/// Material's `AlertDialog` with a title, a free body and two text buttons (dismiss, confirm), on the app's dialog card.
struct AgreementAlertCard<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    var titleBold = false
    let confirmLabel: String
    var confirmColor: Color?
    var confirmEnabled = true
    let onConfirm: () -> Void
    let dismissLabel: String
    let onDismiss: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        DialogCard {
            VStack(alignment: .leading, spacing: 14) {
                Text(verbatim: title).font(.dorr(19, titleBold ? .heavy : .semibold)).foregroundStyle(wa.settingsInk)
                ScrollView {
                    VStack(alignment: .leading, spacing: 8) { content() }.frame(maxWidth: .infinity, alignment: .leading)
                }
                .frame(maxHeight: 420)
                .fixedSize(horizontal: false, vertical: true)
                HStack(spacing: 8) {
                    Spacer(minLength: 0)
                    Button(action: onDismiss) {
                        Text(verbatim: dismissLabel).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsMut).padding(.horizontal, 10).padding(.vertical, 8)
                    }
                    .buttonStyle(.plain)
                    Button(action: onConfirm) {
                        Text(verbatim: confirmLabel).font(.dorr(14, .semibold))
                            .foregroundStyle((confirmColor ?? wa.red).opacity(confirmEnabled ? 1 : 0.38))
                            .padding(.horizontal, 10).padding(.vertical, 8)
                    }
                    .buttonStyle(.plain)
                    .disabled(!confirmEnabled)
                }
            }
            .padding(20)
        }
    }
}

/// Kotlin `take(n)` on a server date ("2026-05-01T10:00:00Z".take(10)).
func agreementTake(_ text: String?, _ n: Int) -> String { String((text ?? "").prefix(n)) }

/// `it.take(16).replace('T', ' ')` — a date and time as the server wrote it.
func agreementStamp(_ text: String?) -> String { agreementTake(text, 16).replacingOccurrences(of: "T", with: " ") }

extension String {
    /// Kotlin `trim().ifBlank { null }`.
    var agreementTrimmedOrNil: String? {
        let t = trimmingCharacters(in: .whitespacesAndNewlines)
        return t.isEmpty ? nil : t
    }

    var agreementTrimmed: String { trimmingCharacters(in: .whitespacesAndNewlines) }
}

/// `{ value = it.take(n) }` — a text field that keeps at most `n` characters (and only digits when `digits`).
func agreementLimit(_ value: Binding<String>, _ n: Int, digits: Bool = false) -> Binding<String> {
    Binding(get: { value.wrappedValue }, set: { new in
        let kept = digits ? String(new.filter { $0.isNumber && $0.wholeNumberValue != nil }) : new
        value.wrappedValue = String(kept.prefix(n))
    })
}
