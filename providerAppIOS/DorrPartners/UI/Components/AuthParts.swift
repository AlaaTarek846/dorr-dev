import SwiftUI

// providerApp `ui/components/AuthParts.kt`.

/// The ar ⇄ en switch, a small pill (the DORR app's language picker, with the two bundled languages).
struct LanguageSwitch: View {
    @Environment(\.wa) private var wa
    var label = L("language_switch")

    var body: some View {
        Button { AppLanguage.shared.toggle() } label: {
            HStack(spacing: 6) {
                Image(systemName: "globe").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.red)
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .background(wa.settingsCard, in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : wa.red.opacity(0.18), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

extension View {
    /// `MessageToast` / `ErrorBanner`: a message shown as the DORR app shows it outside the sign-in pages — a short toast,
    /// again whenever the text changes (the screens clear it before the next request).
    func messageToast(_ message: String?) -> some View {
        onChange(of: message) { text in
            if let text, !text.trimmingCharacters(in: .whitespaces).isEmpty { Task { @MainActor in AppToast.show(text) } }
        }
    }
}

/// A short error line on a tinted card (a refused sign-in, a wrong code) — the sign-in page banner.
struct InlineErrorBanner: View {
    @Environment(\.wa) private var wa
    let message: String?

    var body: some View {
        Group {
            if let message {
                HStack(spacing: 10) {
                    Image(systemName: "exclamationmark.circle").font(.system(size: 18)).foregroundStyle(wa.red)
                    Text(verbatim: message).font(.dorr(12, .medium)).foregroundStyle(wa.night ? wa.darkAccent : Color(hex: 0x001B53))
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 12)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.darkAccent : Color(hex: 0xFBC8B7), lineWidth: 1))
                .padding(.top, 16)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .animation(.easeOut(duration: 0.2), value: message)
    }
}

/// The phone field of the sign-in: a country picker (flag + dial code, with a search) and the number, always
/// left-to-right. The number's length and first digits come from the selected country.
struct PhoneField: View {
    @Environment(\.wa) private var wa
    let countries: [CountryDto]
    let selectedCountryId: Int?
    let flagCode: String
    let dialCode: String
    @Binding var menuExpanded: Bool
    let onCountrySelected: (CountryDto) -> Void
    let phoneStartsWith: String
    let phoneLength: Int
    @Binding var phone: String
    let canSubmit: Bool
    let onSubmit: () -> Void
    @FocusState private var focused: Bool
    @State private var countryQuery = ""

    var body: some View {
        let muted = wa.night ? wa.mut : Color(hex: 0x9CA3AF)
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        let placeholder = phoneStartsWith + String(repeating: "*", count: max(phoneLength - phoneStartsWith.count, 0))
        HStack(spacing: 0) {
            Image(systemName: "phone.fill").font(.system(size: 15)).foregroundStyle(muted)
            separator
            Button { menuExpanded = true } label: {
                HStack(spacing: 4) {
                    FlagImage(code: flagCode)
                    Text(verbatim: dialCode).font(.dorr(14, .bold)).foregroundStyle(ink)
                    if !countries.isEmpty {
                        Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold)).foregroundStyle(wa.night ? wa.mut : AppColors.textSecondary)
                            .accessibilityLabel(L("login_country_code"))
                    }
                }
                .padding(6)
            }
            .buttonStyle(.plain)
            .disabled(countries.isEmpty)
            separator
            ZStack(alignment: .leading) {
                if phone.isEmpty {
                    Text(verbatim: placeholder).font(.dorr(14, .medium)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                }
                TextField("", text: Binding(get: { phone }, set: { input in
                    let filtered = input.filter(\.isNumber).filter(\.isASCII)
                    if filtered.count <= phoneLength { phone = filtered }
                }))
                .font(.dorr(14, .medium))
                .foregroundStyle(ink)
                .tint(wa.red)
                .keyboardType(.phonePad)
                .textContentType(.telephoneNumber)
                .focused($focused)
                .submitLabel(.done)
                .onSubmit { if canSubmit { onSubmit() } }
            }
            if !phone.isEmpty {
                Button { phone = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 12, weight: .bold)).foregroundStyle(muted).frame(width: 28, height: 28)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 44)
        .background(wa.night ? wa.darkCard : .white, in: Capsule())
        .overlay { if focused { Capsule().stroke(wa.red, lineWidth: 1.5) } }
        .shadow(color: wa.night ? .clear : .black.opacity(0.08), radius: 3, y: 1)
        .environment(\.layoutDirection, .leftToRight)
        .sheet(isPresented: $menuExpanded, onDismiss: { countryQuery = "" }) { countrySheet }
    }

    private var separator: some View {
        Rectangle().fill(wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 1, height: 18).padding(.horizontal, 8)
    }

    private var countrySheet: some View {
        let query = countryQuery.trimmingCharacters(in: .whitespaces)
        let visible = countries.filter { c in
            query.isEmpty || c.name.localizedCaseInsensitiveContains(query) || c.code.localizedCaseInsensitiveContains(query) || c.dialCode.contains(query)
        }
        let ink = wa.night ? wa.ink : AppColors.textPrimary
        return VStack(spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(wa.night ? wa.mut : AppColors.textMuted)
                TextField(L("login_country_search"), text: $countryQuery).font(.dorr(14)).foregroundStyle(ink).tint(wa.red)
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 8)
            .background(wa.night ? wa.darkWell : Color(hex: 0xF3F4F6), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            .padding(12)
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(visible) { country in
                        let selected = country.id == selectedCountryId
                        Button { onCountrySelected(country) } label: {
                            HStack(spacing: 10) {
                                FlagImage(code: country.flag?.code ?? country.code)
                                Text(verbatim: country.name.isEmpty ? country.code : country.name)
                                    .font(.dorr(14, selected ? .bold : .regular)).foregroundStyle(selected ? wa.red : ink).lineLimit(1)
                                Spacer(minLength: 8)
                                Text(verbatim: country.formattedDial).font(.dorr(13, .semibold))
                                    .foregroundStyle(selected ? wa.red : (wa.night ? wa.mut : AppColors.textSecondary))
                            }
                            .padding(.horizontal, 16)
                            .padding(.vertical, 12)
                            .background(selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.14)) : .clear)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .background(wa.night ? wa.darkCard : .white)
        .environment(\.layoutDirection, .leftToRight)
        .presentationDetents([.medium, .large])
    }
}
