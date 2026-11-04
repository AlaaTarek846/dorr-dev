import SwiftUI

// driverApp `wallet/WaRecoveryFields.kt` + `wallet/compat/DorrTextField.kt` (the DORR app's; ported from iosApp
// `UI/Wallet/WaRecoveryFields.swift`).

/// androidApp `WaRecoveryFields.kt` — inputs of the wallet's recovery screens, drawn like the app's
/// personal-data screens: a pill field with a brand icon, four rounded boxes for a code, and a date of
/// birth typed as day / month / year.

/// androidApp `ui/components/DorrTextField` (single-line form): label above, pill field, brand icon,
/// an eye to reveal a password.
struct WaTextField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    var label: String?
    var placeholder: String?
    var icon: String?
    var password = false
    var keyboard: UIKeyboardType = .default
    var error = false

    @FocusState private var focused: Bool
    @State private var revealed = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let label {
                Text(label).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.bottom, 8)
            }
            HStack(spacing: 8) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(error ? wa.danger : (focused ? wa.red : wa.red.opacity(0.8)))
                }
                ZStack(alignment: .leading) {
                    if value.isEmpty, let placeholder {
                        Text(placeholder).font(.dorr(15)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0xB4BAC4))
                    }
                    Group {
                        if password && !revealed {
                            SecureField("", text: $value)
                        } else {
                            TextField("", text: $value)
                        }
                    }
                    .font(.dorr(15))
                    .foregroundStyle(wa.ink)
                    .tint(wa.red)
                    .keyboardType(keyboard)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($focused)
                }
                if password {
                    Button { revealed.toggle() } label: {
                        Image(systemName: revealed ? "eye.slash.fill" : "eye.fill")
                            .font(.system(size: 15))
                            .foregroundStyle(wa.night ? wa.mut : Color(hex: 0x9CA3AF))
                            .frame(width: 30, height: 30)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .frame(minHeight: 46)
            .background(wa.night ? wa.bg : (focused ? wa.surface : Color(hex: 0xFBF7F8)), in: Capsule())
            .overlay(
                Capsule().stroke(
                    error ? wa.danger : (focused ? wa.red : (wa.night ? wa.line : Color(hex: 0xF3D5DB))),
                    lineWidth: focused || error ? 1.5 : 1
                )
            )
            .animation(.easeOut(duration: 0.2), value: focused)
        }
    }
}

/// A pill-shaped text field with a brand-coloured icon in front (the e-mail field of the personal data screen).
struct WaPillField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    let placeholder: String
    let icon: String
    var keyboard: UIKeyboardType = .default
    var error = false
    @FocusState private var focused: Bool

    var body: some View {
        // E-mail addresses and numbers read left-to-right in every language.
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
            ZStack(alignment: .leading) {
                if value.isEmpty { Text(placeholder).font(.dorr(15)).foregroundStyle(wa.soft) }
                TextField("", text: $value)
                    .font(.dorr(15))
                    .foregroundStyle(wa.ink)
                    .tint(wa.red)
                    .keyboardType(keyboard)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($focused)
            }
        }
        .padding(.horizontal, 16)
        .frame(height: 50)
        .background(wa.field, in: Capsule())
        .overlay(Capsule().stroke(error ? wa.danger : (focused ? wa.red : wa.line), lineWidth: 1))
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// [length] rounded boxes holding a one-time code. One invisible text field takes the typing (so the
/// keyboard, paste and backspace just work); the boxes only draw what it holds.
struct WaOtpBoxes: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    var error = false
    var length = 4
    @FocusState private var focused: Bool

    var body: some View {
        ZStack {
            HStack(spacing: 12) {
                ForEach(0..<length, id: \.self) { index in
                    let chars = Array(value)
                    let digit = index < chars.count ? String(chars[index]) : nil
                    let current = focused && index == min(chars.count, length - 1)
                    let border: Color = error ? wa.danger : ((current || digit != nil) ? wa.red : wa.red.opacity(0.3))
                    Text(digit ?? "")
                        .font(.dorr(22, .heavy))
                        .foregroundStyle(wa.ink)
                        .frame(width: 48, height: 56)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(border, lineWidth: 1.5))
                }
            }
            .allowsHitTesting(false)
            TextField("", text: $value)
                .keyboardType(.numberPad)
                .textContentType(.oneTimeCode)
                .focused($focused)
                .foregroundStyle(.clear)
                .tint(.clear)
                .opacity(0.02)
                .onChange(of: value) { raw in
                    let digits = String(asciiDigits(raw).prefix(length))
                    if digits != raw { value = digits }
                }
        }
        .frame(maxWidth: .infinity)
        .contentShape(Rectangle())
        .onTapGesture { focused = true }
        .onAppear { focused = true }
        // The digits read left-to-right in every language.
        .environment(\.layoutDirection, .leftToRight)
    }
}

// MARK: - Date of birth

/// "1990-09-05" when the three numbers make a real past date from 1900 on, else nil.
func birthDateOrNull(_ day: String, _ month: String, _ year: String) -> String? {
    guard year.count == 4, let d = Int(day), let m = Int(month), let y = Int(year), y >= 1900 else { return nil }
    var components = DateComponents()
    components.year = y
    components.month = m
    components.day = d
    let calendar = Calendar(identifier: .gregorian)
    guard let date = calendar.date(from: components),
          calendar.component(.day, from: date) == d, calendar.component(.month, from: date) == m,
          date < calendar.startOfDay(for: Date()) else { return nil }
    return String(format: "%04d-%02d-%02d", y, m, d)
}

/// What is wrong with the date typed so far, or nil while it can still become a valid one.
private enum BirthProblem { case day, month, year, future }

private func birthProblem(_ day: String, _ month: String, _ year: String) -> BirthProblem? {
    let m = Int(month)
    let y = Int(year)
    if !month.isEmpty && (m == nil || m! > 12 || (month.count == 2 && m == 0)) { return .month }
    // A year is judged once all four digits are there (typing "19" on the way to 1990 is fine).
    if year.count == 4, let y, y < 1900 { return .year }
    if year.count == 4, let y, y > Calendar(identifier: .gregorian).component(.year, from: Date()) { return .future }
    let d = Int(day)
    if !day.isEmpty && (d == nil || d! > 31 || (day.count == 2 && d == 0)) { return .day }
    // Only a real month (1..12) can say how long the month is; a lone "0" is still on its way to 05, 09...
    if let d, let m, (1...12).contains(m) {
        var components = DateComponents()
        components.year = year.count == 4 ? (y ?? 2000) : 2000
        components.month = m
        let calendar = Calendar(identifier: .gregorian)
        let max = calendar.date(from: components).flatMap { calendar.range(of: .day, in: .month, for: $0)?.count } ?? 31
        if d > max { return .day }
    }
    if birthDateOrNull(day, month, year) == nil && !day.isEmpty && !month.isEmpty && year.count == 4 {
        // Complete, a real calendar date, but not in the past.
        return .future
    }
    return nil
}

/// One typed number of the date (day, month or year): a small label over a pill, digits only, centred.
private struct WaDateBox: View {
    @Environment(\.wa) private var wa
    let label: String
    @Binding var value: String
    let placeholder: String
    let maxLength: Int
    let invalid: Bool
    var focus: FocusState<Int?>.Binding
    let index: Int
    let next: Int?

    var body: some View {
        let focused = focus.wrappedValue == index
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.bottom, 6).padding(.leading, 2)
            ZStack {
                if value.isEmpty { Text(placeholder).font(.dorr(17, .medium)).foregroundStyle(wa.soft) }
                TextField("", text: $value)
                    .font(.dorr(19, .heavy))
                    .foregroundStyle(invalid ? wa.danger : wa.ink)
                    .multilineTextAlignment(.center)
                    .keyboardType(.numberPad)
                    .tint(wa.red)
                    .focused(focus, equals: index)
                    .onChange(of: value) { raw in
                        let digits = String(asciiDigits(raw).prefix(maxLength))
                        if digits != raw { value = digits; return }
                        // A full day or month hands the caret to the next box, like typing a date on paper.
                        if digits.count == maxLength, let next { focus.wrappedValue = next }
                    }
            }
            .padding(.horizontal, 8)
            .frame(height: 52)
            .background(wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(invalid ? wa.danger : (focused ? wa.red : wa.line), lineWidth: 1.5))
        }
    }
}

/// The date of birth typed as day / month / year (numbers only), with the chosen date shown in full above
/// the boxes and a short message under them as soon as something cannot be right.
struct WaBirthPicker: View {
    @Environment(\.wa) private var wa
    @Binding var day: String
    @Binding var month: String
    @Binding var year: String
    var error = false
    @FocusState private var focus: Int?

    var body: some View {
        let problem = birthProblem(day, month, year)
        let complete = birthDateOrNull(day, month, year) != nil
        let preview = complete ? "\(Int(day) ?? 0) \(monthNames()[(Int(month) ?? 1) - 1]) \(year)" : L("wa_rec_birth_pick")
        let message: String? = {
            switch problem {
            case .day: return "wa_rec_birth_bad_day"
            case .month: return "wa_rec_birth_bad_month"
            case .year: return "wa_rec_birth_bad_year"
            case .future: return "wa_rec_birth_future"
            case nil: return error ? "wa_rec_birth_invalid" : nil
            }
        }()

        WaCard(padding: 16) {
            Text(L("wa_rec_birth_label")).font(.dorr(12)).foregroundStyle(wa.mut)
            Text(preview).font(.dorr(17, .heavy)).foregroundStyle(complete ? wa.ink : wa.soft).padding(.top, 2)
            // Dates are typed left to right in every language: day, month, year.
            WaWeightedRow(spacing: 10) {
                WaDateBox(label: L("wa_rec_day"), value: $day, placeholder: "DD", maxLength: 2, invalid: problem == .day, focus: $focus, index: 0, next: 1)
                    .layoutWeight(1)
                WaDateBox(label: L("wa_rec_month"), value: $month, placeholder: "MM", maxLength: 2, invalid: problem == .month, focus: $focus, index: 1, next: 2)
                    .layoutWeight(1)
                WaDateBox(label: L("wa_rec_year"), value: $year, placeholder: "YYYY", maxLength: 4, invalid: problem == .year || problem == .future, focus: $focus, index: 2, next: nil)
                    .layoutWeight(1.4)
            }
            .environment(\.layoutDirection, .leftToRight)
            .padding(.top, 16)
            if let message {
                Text(L(message)).font(.dorr(12, .semibold)).foregroundStyle(wa.danger).padding(.top, 10).padding(.leading, 2)
            }
        }
    }
}
