import SwiftUI

// providerApp `ui/components/TimeField.kt`.

/// A time as a field that opens the time picker — the same pill and the same dialog buttons as `DateField`. `value` is `HH:mm`
/// (24-hour digits whatever the app language); an empty string means no time. The X clears it.
struct TimeField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    let label: String
    var enabled = true
    @State private var open = false
    @State private var picked = Date()

    var body: some View {
        DorrTextField(
            value: .constant(value), label: label, placeholder: "HH:MM", icon: "clock",
            trailing: value.isEmpty || !enabled ? nil : AnyView(
                Button { value = "" } label: { Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut) }
                    .buttonStyle(.plain)
            ),
            enabled: enabled,
            onClick: {
                picked = Self.parse(value) ?? Date()
                open = true
            }
        )
        .dorrDialog(isPresented: $open) {
            DialogCard {
                VStack(spacing: 12) {
                    DatePicker("", selection: $picked, displayedComponents: .hourAndMinute)
                        .datePickerStyle(.wheel)
                        .labelsHidden()
                        .tint(wa.red)
                        // 24-hour digits, as Android's `is24Hour = true`.
                        .environment(\.locale, Locale(identifier: "en_GB"))
                    HStack {
                        Button(L("common_cancel")) { open = false }.foregroundStyle(wa.settingsMut)
                        Spacer()
                        Button(L("common_ok")) {
                            value = Self.format(picked)
                            open = false
                        }
                        .foregroundStyle(wa.red)
                    }
                    .font(.dorr(15, .bold))
                }
                .padding(14)
            }
        }
    }

    private static let formatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "HH:mm"
        return f
    }()

    static func parse(_ s: String) -> Date? {
        guard let time = formatter.date(from: s) else { return nil }
        // Today at that time, so the picker opens on a real date.
        let parts = Calendar.current.dateComponents([.hour, .minute], from: time)
        return Calendar.current.date(bySettingHour: parts.hour ?? 0, minute: parts.minute ?? 0, second: 0, of: Date())
    }

    static func format(_ d: Date) -> String { formatter.string(from: d) }
}

/// `2026-11-20` + `14:30` → `2026-11-20T14:30:00+02:00` in the phone's zone (what the server's `date` rule takes); nil if either is missing.
func isoAt(_ date: String, _ time: String) -> String? {
    let parser = DateFormatter()
    parser.calendar = Calendar(identifier: .gregorian)
    parser.locale = Locale(identifier: "en_US_POSIX")
    parser.timeZone = .current
    parser.dateFormat = "yyyy-MM-dd HH:mm"
    guard let at = parser.date(from: "\(date) \(time)") else { return nil }
    let out = ISO8601DateFormatter()
    out.formatOptions = [.withInternetDateTime]
    out.timeZone = .current
    return out.string(from: at)
}

/// The phone's own `yyyy-MM-dd` and `HH:mm` of an instant from the server (filling a date and a time field), or nil.
func localDateAndTime(_ iso: String?) -> (date: String, time: String)? {
    guard let at = parseInstant(iso) else { return nil }
    let out = DateFormatter()
    out.calendar = Calendar(identifier: .gregorian)
    out.locale = Locale(identifier: "en_US_POSIX")
    out.timeZone = .current
    out.dateFormat = "yyyy-MM-dd"
    let day = out.string(from: at)
    out.dateFormat = "HH:mm"
    return (day, out.string(from: at))
}
