import SwiftUI

// driverApp `ui/kit/Chooser.kt` — a pill field that opens a list in a sheet (`SelectField` + `ChooserSheet`), and the
// colour dot (`Swatch`).

/// One choice of a `ChooserSheet`: what is sent, what is shown, and an optional flag or colour swatch.
struct Choice: Identifiable, Hashable {
    let id: String
    let label: String
    var sub: String? = nil
    var flag: String? = nil
    var colorHex: String? = nil

    init(id: String, label: String, sub: String? = nil, flag: String? = nil, colorHex: String? = nil) {
        self.id = id
        self.label = label
        self.sub = sub
        self.flag = flag
        self.colorHex = colorHex
    }
}

/// A pill field that opens a list in a sheet (make, model, colour, nationality, city…): the same look as a text field,
/// with the chosen value and a chevron. Never a cramped drop-down.
struct SelectField: View {
    @Environment(\.pal) private var pal
    let label: String?
    let icon: String
    let choices: [Choice]
    let selectedId: String?
    let onPick: (Choice) -> Void
    var placeholder: String? = nil
    /// nil: searchable when there are more than 8 choices.
    var searchable: Bool? = nil
    var enabled = true
    var error = false
    @State private var open = false

    init(label: String?, icon: String, choices: [Choice], selectedId: String?, onPick: @escaping (Choice) -> Void,
         placeholder: String? = nil, searchable: Bool? = nil, enabled: Bool = true, error: Bool = false) {
        self.label = label
        self.icon = icon
        self.choices = choices
        self.selectedId = selectedId
        self.onPick = onPick
        self.placeholder = placeholder
        self.searchable = searchable
        self.enabled = enabled
        self.error = error
    }

    var body: some View {
        let chosen = choices.first { $0.id == selectedId }
        let leading: AnyView? = {
            if let flag = chosen?.flag { return AnyView(FlagImage(flag)) }
            if let hex = chosen?.colorHex { return AnyView(Swatch(hex, 16)) }
            return nil
        }()
        DorrTextField(
            value: chosen?.label ?? "", onValueChange: { _ in }, label: label, placeholder: placeholder ?? L("pick_choose"),
            icon: icon, leading: leading,
            trailing: AnyView(DorrIcon("chevron.down", size: 20, tint: pal.faint)),
            error: error, enabled: enabled, onClick: { if !choices.isEmpty { open = true } }
        )
        .sheet(isPresented: $open) {
            ChooserSheet(title: label ?? "", choices: choices, selectedId: selectedId, searchable: searchable ?? (choices.count > 8),
                         onDismiss: { open = false }) {
                onPick($0)
                open = false
            }
            .environment(\.pal, pal)
        }
    }
}

/// The list behind a `SelectField`: an optional search, the choices with their flag / swatch, the chosen one ticked.
/// Shown in a `.sheet` sized to its content (Android's `ModalBottomSheet`, `skipPartiallyExpanded`).
struct ChooserSheet: View {
    @Environment(\.pal) private var pal
    let title: String
    let choices: [Choice]
    let selectedId: String?
    let searchable: Bool
    let onDismiss: () -> Void
    let onPick: (Choice) -> Void
    @State private var query = ""
    @State private var listHeight: CGFloat = 0
    @State private var sheetHeight: CGFloat = 0

    init(title: String, choices: [Choice], selectedId: String?, searchable: Bool, onDismiss: @escaping () -> Void,
         onPick: @escaping (Choice) -> Void) {
        self.title = title
        self.choices = choices
        self.selectedId = selectedId
        self.searchable = searchable
        self.onDismiss = onDismiss
        self.onPick = onPick
    }

    var body: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let shown = choices.filter {
            q.isEmpty || $0.label.localizedCaseInsensitiveContains(q) || ($0.sub?.localizedCaseInsensitiveContains(q) ?? false)
        }
        VStack(alignment: .leading, spacing: 0) {
            if !title.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(title).font(.dorr(18, .heavy)).foregroundStyle(pal.ink).padding(.bottom, 10)
            }
            if searchable {
                DorrTextField(value: query, onValueChange: { query = $0 }, placeholder: L("pick_search"), icon: "magnifyingglass")
                    .padding(.bottom, 8)
            }
            if shown.isEmpty {
                Text(L("pick_nothing")).font(.dorr(14)).foregroundStyle(pal.mut)
                    .padding(.vertical, 24)
                    .frame(maxWidth: .infinity)
            }
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(Array(shown.enumerated()), id: \.element.id) { index, c in
                        row(c).staggerIn(index)
                    }
                }
                .background(GeometryReader { g in
                    Color.clear.onAppear { listHeight = g.size.height }.onChange(of: g.size.height) { listHeight = $0 }
                })
            }
            .frame(height: min(listHeight, 460))
        }
        .padding(.horizontal, 16)
        .padding(.top, 22)
        .padding(.bottom, 12)
        .background(GeometryReader { g in
            Color.clear.onAppear { sheetHeight = g.size.height }.onChange(of: g.size.height) { sheetHeight = $0 }
        })
        .frame(maxHeight: .infinity, alignment: .top)
        .background(pal.card.ignoresSafeArea())
        .presentationDetents(sheetHeight > 0 ? [.height(sheetHeight)] : [.large])
        .presentationDragIndicator(.visible)
    }

    private func row(_ c: Choice) -> some View {
        let on = c.id == selectedId
        return HStack(spacing: 12) {
            if let flag = c.flag {
                FlagImage(flag)
            } else if let hex = c.colorHex {
                Swatch(hex, 22)
            }
            VStack(alignment: .leading, spacing: 0) {
                Text(c.label).font(.dorr(15, on ? .bold : .medium)).foregroundStyle(on ? pal.accent : pal.ink)
                if let sub = c.sub { Text(sub).font(.dorr(12)).foregroundStyle(pal.mut) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if on { DorrIcon("checkmark", size: 20, tint: pal.accent) }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 13)
        .background(RoundedRectangle(cornerRadius: 14).fill(on ? pal.well : .clear))
        .bounceClick { onPick(c) }
    }
}

/// A round dot of a colour ("#1A1A1A"); a hairline so white and black both show.
struct Swatch: View {
    @Environment(\.pal) private var pal
    let hex: String?
    let sizeDp: Int

    init(_ hex: String?, _ sizeDp: Int) {
        self.hex = hex
        self.sizeDp = sizeDp
    }

    var body: some View {
        Circle()
            .fill(Color(hexString: hex) ?? pal.line)
            .overlay(Circle().strokeBorder(pal.line, lineWidth: 1))
            .frame(width: CGFloat(sizeDp), height: CGFloat(sizeDp))
    }
}
