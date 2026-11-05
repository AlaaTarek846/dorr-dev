import SwiftUI

// driverApp `ui/kit/DatePicker.kt` — the DORR app's calendar (`DorrDatePickerDialog`), the pill that opens it (`DateField`)
// and `prettyDate`. Plus `LocalDate`, the small stand-in for `java.time.LocalDate` the calendar and its callers use.

/// `java.time.LocalDate`: a day without a time or a zone. `description` / `toString()` is `yyyy-MM-dd`, what the server takes.
struct LocalDate: Hashable, Comparable, CustomStringConvertible {
    let year: Int
    let month: Int
    let day: Int

    init(year: Int, month: Int, day: Int) {
        self.year = year
        self.month = month
        self.day = day
    }

    fileprivate static var calendar: Calendar {
        var c = Calendar(identifier: .gregorian)
        c.timeZone = .current
        return c
    }

    /// The day `date` falls on, in the phone's zone.
    init(_ date: Date) {
        let c = Self.calendar.dateComponents([.year, .month, .day], from: date)
        self.init(year: c.year ?? 1970, month: c.month ?? 1, day: c.day ?? 1)
    }

    static func now() -> LocalDate { LocalDate(Date()) }

    /// "2026-10-15" (or a longer text starting with it); nil if it isn't one.
    static func parse(_ text: String?) -> LocalDate? {
        guard let text else { return nil }
        let parts = text.prefix(10).split(separator: "-")
        guard parts.count == 3, parts[0].count == 4, let y = Int(parts[0]), let m = Int(parts[1]), let d = Int(parts[2]),
              (1...12).contains(m), d >= 1, d <= LocalDate(year: y, month: m, day: 1).lengthOfMonth else { return nil }
        return LocalDate(year: y, month: m, day: d)
    }

    /// Midnight of this day in the phone's zone.
    var date: Date {
        Self.calendar.date(from: DateComponents(year: year, month: month, day: day)) ?? Date()
    }

    var lengthOfMonth: Int { Self.calendar.range(of: .day, in: .month, for: LocalDate(year: year, month: month, day: 1).date)?.count ?? 30 }
    /// ISO day of week: 1 = Monday … 7 = Sunday.
    var dayOfWeek: Int { (Self.calendar.component(.weekday, from: date) + 5) % 7 + 1 }

    func plusDays(_ n: Int) -> LocalDate { LocalDate(Self.calendar.date(byAdding: .day, value: n, to: date) ?? date) }
    func plusMonths(_ n: Int) -> LocalDate { LocalDate(Self.calendar.date(byAdding: .month, value: n, to: date) ?? date) }
    func plusYears(_ n: Int) -> LocalDate { LocalDate(Self.calendar.date(byAdding: .year, value: n, to: date) ?? date) }
    func minusDays(_ n: Int) -> LocalDate { plusDays(-n) }
    func isBefore(_ other: LocalDate) -> Bool { self < other }
    func isAfter(_ other: LocalDate) -> Bool { self > other }

    static func < (a: LocalDate, b: LocalDate) -> Bool { (a.year, a.month, a.day) < (b.year, b.month, b.day) }

    var description: String { String(format: "%04d-%02d-%02d", year, month, day) }
    func toString() -> String { description }
}

/// "15 Oct 2026" in the app's language; "" for nothing, the text itself if it isn't a date.
func prettyDate(_ iso: String?) -> String {
    guard let iso, !iso.trimmingCharacters(in: .whitespaces).isEmpty else { return "" }
    guard let day = LocalDate.parse(iso) else { return iso }
    let f = DateFormatter()
    f.locale = Locale(identifier: DriverPrefs.locale + "@numbers=latn")
    f.calendar = LocalDate.calendar
    f.dateStyle = .medium
    f.timeStyle = .none
    return f.string(from: day.date)
}

/// A month of a year (`java.time.YearMonth`).
private struct YearMonth: Equatable {
    var year: Int
    var month: Int

    init(_ d: LocalDate) {
        year = d.year
        month = d.month
    }

    func atDay(_ n: Int) -> LocalDate { LocalDate(year: year, month: month, day: n) }
    var lengthOfMonth: Int { atDay(1).lengthOfMonth }
    func plusMonths(_ n: Int) -> YearMonth { YearMonth(atDay(1).plusMonths(n)) }
    func withYear(_ y: Int) -> YearMonth { var c = self; c.year = y; return c }
    func isAfter(_ o: YearMonth) -> Bool { (year, month) > (o.year, o.month) }
    func isBefore(_ o: YearMonth) -> Bool { (year, month) < (o.year, o.month) }
}

/// The DORR app's calendar (month grid, today ringed, the chosen day filled) in the app's language — plus a year list
/// behind the title, so a date of birth isn't thirty years of "next month" away. Days outside `min`…`max` can't be
/// picked. The dialog card itself: show it with `.dorrDialog(isPresented:) { DorrDatePickerDialog(…) }`.
struct DorrDatePickerDialog: View {
    @Environment(\.pal) private var pal
    let initial: LocalDate?
    var min: LocalDate? = nil
    var max: LocalDate? = nil
    let onDismiss: () -> Void
    let onPick: (LocalDate) -> Void

    @State private var month: YearMonth
    @State private var chosen: LocalDate?
    @State private var years = false

    init(initial: LocalDate?, min: LocalDate? = nil, max: LocalDate? = nil, onDismiss: @escaping () -> Void,
         onPick: @escaping (LocalDate) -> Void) {
        self.initial = initial
        self.min = min
        self.max = max
        self.onDismiss = onDismiss
        self.onPick = onPick
        let today = LocalDate.now()
        var start = initial ?? (max.flatMap { $0.isBefore(today) ? $0 : nil } ?? today)
        if let min, start.isBefore(min) { start = min } else if let max, start.isAfter(max) { start = max }
        _month = State(initialValue: YearMonth(start))
        _chosen = State(initialValue: initial)
    }

    private func allowed(_ d: LocalDate) -> Bool { (min == nil || !d.isBefore(min!)) && (max == nil || !d.isAfter(max!)) }

    var body: some View {
        let locale = Locale(identifier: DriverPrefs.locale + "@numbers=latn")
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                arrow("chevron.backward", enabled: !years && (min == nil || month.isAfter(YearMonth(min!)))) { month = month.plusMonths(-1) }
                HStack(spacing: 0) {
                    Text(title(locale)).font(.dorr(16, .heavy)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
                    DorrIcon("arrowtriangle.down.fill", size: 14, tint: pal.accent).padding(.horizontal, 4)
                }
                .padding(.vertical, 6)
                .frame(maxWidth: .infinity)
                .contentShape(RoundedRectangle(cornerRadius: 12))
                .onTapGesture { years.toggle() }
                arrow("chevron.forward", enabled: !years && (max == nil || month.isBefore(YearMonth(max!)))) { month = month.plusMonths(1) }
            }
            VSpace(8)
            ZStack {
                if years {
                    yearGrid.transition(.asymmetric(insertion: .opacity.animation(.tween(180)), removal: .opacity.animation(.tween(120))))
                } else {
                    monthGrid(locale).transition(.asymmetric(insertion: .opacity.animation(.tween(180)), removal: .opacity.animation(.tween(120))))
                }
            }
            .animation(.tween(180), value: years)
            HStack(spacing: 10) {
                OutlineButton(L("cancel"), color: pal.mut, fillMaxWidth: true, onClick: onDismiss)
                PrimaryButton(L("date_ok"), enabled: chosen != nil) { if let chosen { onPick(chosen) } }
                    .frame(maxWidth: .infinity)
            }
            .padding(.top, 10)
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .surface(28, elevation: 12)
        .padding(.horizontal, 24)
    }

    private func title(_ locale: Locale) -> String {
        let f = DateFormatter()
        f.locale = locale
        f.calendar = LocalDate.calendar
        f.dateFormat = "LLLL yyyy"
        return f.string(from: month.atDay(1).date)
    }

    private var yearGrid: some View {
        let today = LocalDate.now()
        let first = min?.year ?? (today.year - 100)
        let last = max?.year ?? (today.year + 20)
        let list = Array(stride(from: last, through: first, by: -1))
        return ScrollViewReader { reader in
            ScrollView {
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 0), count: 3), spacing: 0) {
                    ForEach(list, id: \.self) { y in
                        let on = y == month.year
                        Text(String(y))
                            .font(.dorr(15, on ? .heavy : .medium))
                            .foregroundStyle(on ? Color.white : pal.ink)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(RoundedRectangle(cornerRadius: 12).fill(on ? pal.accent : .clear))
                            .contentShape(RoundedRectangle(cornerRadius: 12))
                            .onTapGesture {
                                month = month.withYear(y)
                                years = false
                            }
                            .padding(4)
                            .id(y)
                    }
                }
            }
            .frame(height: 300)
            .onAppear {
                if let index = list.firstIndex(of: month.year) { reader.scrollTo(list[Swift.max(index - 6, 0)], anchor: .top) }
            }
        }
    }

    private func monthGrid(_ locale: Locale) -> some View {
        var cal = LocalDate.calendar
        cal.locale = locale
        let firstDay = cal.firstWeekday // 1 = Sunday … 7 = Saturday
        let f = DateFormatter()
        f.locale = locale
        let names = f.shortWeekdaySymbols ?? []
        let today = LocalDate.now()
        // ISO (Mon=1…Sun=7) of the first column.
        let firstIso = (firstDay + 5) % 7 + 1
        let offset = (month.atDay(1).dayOfWeek - firstIso + 7) % 7
        let length = month.lengthOfMonth
        let rows = (offset + length + 6) / 7
        return VStack(spacing: 0) {
            HStack(spacing: 0) {
                ForEach(0..<7, id: \.self) { i in
                    let symbol = names.isEmpty ? "" : names[(firstDay - 1 + i) % 7]
                    Text(symbol).font(.dorr(11.5, .bold)).foregroundStyle(pal.mut).lineLimit(1)
                        .frame(maxWidth: .infinity)
                }
            }
            VSpace(4)
            ForEach(0..<rows, id: \.self) { row in
                HStack(spacing: 0) {
                    ForEach(0..<7, id: \.self) { col in
                        let n = row * 7 + col - offset + 1
                        let day: LocalDate? = (1...length).contains(n) ? month.atDay(n) : nil
                        ZStack {
                            if let day {
                                let on = day == chosen
                                let ok = allowed(day)
                                Text(String(day.day))
                                    .font(.dorr(14, on ? .heavy : .medium))
                                    .foregroundStyle(on ? Color.white : (ok ? pal.ink : pal.faint.opacity(0.5)))
                                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                                    .background(Circle().fill(on ? pal.accent : .clear))
                                    .overlay { if day == today && !on { Circle().strokeBorder(pal.accent, lineWidth: 1.5) } }
                                    .contentShape(Circle())
                                    .onTapGesture { if ok { chosen = day } }
                            }
                        }
                        .aspectRatio(1, contentMode: .fit)
                        .padding(2)
                        .frame(maxWidth: .infinity)
                    }
                }
            }
        }
    }

    private func arrow(_ icon: String, enabled: Bool, onClick: @escaping () -> Void) -> some View {
        DorrIcon(icon, size: 22, tint: enabled ? pal.accent : pal.faint.opacity(0.4))
            .frame(width: 36, height: 36)
            .background(Circle().fill(enabled ? pal.well : .clear))
            .contentShape(Circle())
            .onTapGesture { if enabled { onClick() } }
    }
}

/// A pill field that opens the calendar — looks like every other field, never opens the keyboard. Answers `yyyy-MM-dd`.
struct DateField: View {
    @Environment(\.pal) private var pal
    let label: String?
    let value: String?
    let onPick: (String) -> Void
    var placeholder: String? = nil
    var min: LocalDate? = nil
    var max: LocalDate? = nil
    var error = false
    var enabled = true
    @State private var open = false

    init(label: String?, value: String?, onPick: @escaping (String) -> Void, placeholder: String? = nil,
         min: LocalDate? = nil, max: LocalDate? = nil, error: Bool = false, enabled: Bool = true) {
        self.label = label
        self.value = value
        self.onPick = onPick
        self.placeholder = placeholder
        self.min = min
        self.max = max
        self.error = error
        self.enabled = enabled
    }

    var body: some View {
        DorrTextField(value: prettyDate(value), onValueChange: { _ in }, label: label, placeholder: placeholder ?? L("date_pick"),
                      icon: "calendar", error: error, enabled: enabled, onClick: { open = true })
            .dorrDialog(isPresented: $open) {
                DorrDatePickerDialog(initial: LocalDate.parse(value), min: min, max: max, onDismiss: { open = false }) {
                    onPick($0.toString())
                    open = false
                }
            }
    }
}
