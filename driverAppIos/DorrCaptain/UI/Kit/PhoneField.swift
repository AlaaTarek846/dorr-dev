import SwiftUI

// driverApp `ui/kit/PhoneField.kt` — the sign-in phone field (country + number), `FlagImage` and `formatDialCode`.

/// "+966" from "966" or "+966".
func formatDialCode(_ value: String) -> String {
    var digits = value.trimmingCharacters(in: .whitespaces)
    if digits.hasPrefix("+") { digits.removeFirst() }
    return digits.isEmpty ? "" : "+" + digits
}

/// A country's flag from flagcdn (the same images the DORR app shows); nothing for an unknown code.
struct FlagImage: View {
    let code: String?

    init(_ code: String?) { self.code = code }

    var body: some View {
        let iso = (code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
        if iso.count == 2 {
            AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(iso).png")) { phase in
                if let image = phase.image { image.resizable().scaledToFill() } else { Color.clear }
            }
            .frame(width: 20, height: 15)
            .clipShape(RoundedRectangle(cornerRadius: 2))
        }
    }
}

/// The phone field of the DORR app's sign-in, piece for piece: a pill with the phone icon, the country (flag, dial
/// code, a searchable list of the countries open for sign-in), the number with a placeholder showing its length and
/// first digits, and a clear button. It always reads left to right, in Arabic too.
struct PhoneField: View {
    @Environment(\.pal) private var pal
    let countries: [CountryDto]
    let selected: CountryDto?
    let onCountry: (CountryDto) -> Void
    let phone: String
    let onPhone: (String) -> Void
    let error: Bool
    let onDone: () -> Void

    @State private var open = false
    @State private var query = ""
    @State private var anchor: CGRect = .zero
    @FocusState private var focused: Bool

    init(countries: [CountryDto], selected: CountryDto?, onCountry: @escaping (CountryDto) -> Void, phone: String,
         onPhone: @escaping (String) -> Void, error: Bool, onDone: @escaping () -> Void) {
        self.countries = countries
        self.selected = selected
        self.onCountry = onCountry
        self.phone = phone
        self.onPhone = onPhone
        self.error = error
        self.onDone = onDone
    }

    var body: some View {
        let length = selected?.phoneLength ?? 12
        let startsWith = selected?.phoneStartsWith ?? ""
        let shape = RoundedRectangle(cornerRadius: 999)
        let edge: Color = focused || error ? (error ? pal.danger : pal.accent) : pal.line
        let placeholder = startsWith + String(repeating: "•", count: Swift.max(length - startsWith.count, 0))
        HStack(spacing: 0) {
            DorrIcon("phone.fill", size: 18, tint: pal.faint)
            separator
            countryChip
            separator
            ZStack(alignment: .leading) {
                if phone.isEmpty {
                    Text(placeholder).font(.dorr(15, .medium)).foregroundStyle(pal.faint).tracking(1).lineLimit(1)
                }
                TextField("", text: Binding(get: { phone }, set: { input in
                    let digits = input.filter(\.isNumber)
                    if digits.count <= length { onPhone(digits) }
                }))
                .font(.dorr(15, .semibold))
                .foregroundStyle(pal.ink)
                .tint(pal.accent)
                .keyboardType(.phonePad)
                .textContentType(.telephoneNumber)
                .submitLabel(.done)
                .onSubmit(onDone)
                .focused($focused)
            }
            .frame(maxWidth: .infinity)
            if !phone.isEmpty {
                DorrIcon("xmark", size: 16, tint: pal.faint)
                    .frame(width: 28, height: 28)
                    .contentShape(Circle())
                    .onTapGesture { onPhone("") }
                    .accessibilityLabel(L("login_clear"))
            }
        }
        .padding(.horizontal, 14)
        .frame(maxWidth: .infinity)
        .frame(height: 52)
        .background(shape.fill(pal.card).shadow(color: pal.night ? .clear : .black.opacity(0.10), radius: 3, y: 1.5))
        .overlay(shape.strokeBorder(edge, lineWidth: focused || error ? 1.5 : 1).animation(.tween(200), value: edge))
        .onChange(of: open) { if !$0 { query = "" } }
        .dorrDropdown(isPresented: $open, anchor: anchor, minWidth: 260, maxHeight: 340) { menu }
        .environment(\.layoutDirection, .leftToRight)
    }

    private var countryChip: some View {
        HStack(spacing: 0) {
            FlagImage(selected?.flag?.code ?? selected?.code)
            HSpace(4)
            Text(formatDialCode(selected?.dialCode ?? "+966")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
            if countries.count > 1 {
                HSpace(2)
                DorrIcon("chevron.down", size: 18, tint: pal.mut).accessibilityLabel(L("login_country"))
            }
        }
        .padding(6)
        .contentShape(RoundedRectangle(cornerRadius: 10))
        .onTapGesture { if countries.count > 1 { open = true } }
        .background(GeometryReader { g in
            Color.clear.onAppear { anchor = g.frame(in: .global) }.onChange(of: g.frame(in: .global)) { anchor = $0 }
        })
    }

    private var separator: some View {
        Rectangle().fill(pal.line).frame(width: 1, height: 18).padding(.horizontal, 8)
    }

    @ViewBuilder private var menu: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let bare = q.hasPrefix("+") ? String(q.dropFirst()) : q
        let visible = countries.filter { c in
            q.isEmpty || (c.name ?? "").localizedCaseInsensitiveContains(q) || (c.code ?? "").localizedCaseInsensitiveContains(q)
                || (c.dialCode ?? "").contains(bare)
        }
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    DorrIcon("magnifyingglass", size: 18, tint: pal.faint)
                    HSpace(8)
                    ZStack(alignment: .leading) {
                        if query.isEmpty { Text(L("login_country_search")).font(.dorr(14)).foregroundStyle(pal.faint) }
                        TextField("", text: $query).font(.dorr(14)).foregroundStyle(pal.ink).tint(pal.accent)
                            .autocorrectionDisabled()
                    }
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(RoundedRectangle(cornerRadius: 10).fill(pal.fieldFill))
                .padding(8)
                ForEach(Array(visible.enumerated()), id: \.offset) { _, country in
                    let chosen = country.id == selected?.id
                    DropdownItem(selected: chosen, onClick: {
                        onCountry(country)
                        open = false
                    }) {
                        HStack(spacing: 0) {
                            FlagImage(country.flag?.code ?? country.code)
                            HSpace(10)
                            Text((country.name ?? "").isEmpty ? (country.code ?? "") : (country.name ?? ""))
                                .font(.dorr(14, chosen ? .bold : .regular))
                                .foregroundStyle(chosen ? pal.accent : pal.ink)
                                .lineLimit(1)
                                .frame(maxWidth: .infinity, alignment: .leading)
                            HSpace(8)
                            Text(formatDialCode(country.dialCode ?? "")).font(.dorr(13, .semibold))
                                .foregroundStyle(chosen ? pal.accent : pal.mut)
                        }
                    }
                }
            }
            .frame(width: 260)
        }
        .environment(\.layoutDirection, .leftToRight)
    }
}
