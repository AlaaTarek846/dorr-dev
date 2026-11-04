import Foundation

/// freelanceApp `net/CurrencyLabelAdapter.kt` — reads a currency code from the API as the sign people know: "SAR" shows as
/// «﷼» everywhere a price is printed. A code without an entry in `signs` stays as the code (add the country's sign there to
/// change that). A price is never sent back, so this only reads. Use it on the DTO fields Kotlin marks with
/// `@JsonAdapter(CurrencyLabelAdapter::class)`: `@CurrencyLabel var currency: String?`.
@propertyWrapper
struct CurrencyLabel: Decodable, Equatable, Hashable {
    static let signs = ["SAR": "﷼"]

    var wrappedValue: String?

    init(wrappedValue: String?) { self.wrappedValue = wrappedValue }

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        let code = c.decodeNil() ? nil : (try? c.decode(String.self))
        wrappedValue = code.map { Self.signs[$0.trimmingCharacters(in: .whitespaces).uppercased()] ?? $0 }
    }
}

extension KeyedDecodingContainer {
    /// A missing `currency` key leaves the field nil (instead of failing the whole answer).
    func decode(_ type: CurrencyLabel.Type, forKey key: Key) throws -> CurrencyLabel {
        try decodeIfPresent(type, forKey: key) ?? CurrencyLabel(wrappedValue: nil)
    }
}

/// freelanceApp `net/ConsultationSessionApi.kt` `ServerClock` — the server's clock as seen from the phone: the phone's own clock
/// plus a correction taken once — from the first answer's `Date` header, and precisely from the join ticket's `server_time`.
/// Countdowns read `now`; nothing asks the server on a timer.
enum ServerClock {
    private static let lock = NSLock()
    private static var offset: TimeInterval = 0
    private static var precise = false
    private static var seen = false

    /// The `Date` header of an answer (seconds only): taken once, and never over a precise value.
    static func observeHeader(_ server: Date) {
        lock.lock()
        defer { lock.unlock() }
        if seen || precise { return }
        seen = true
        offset = server.timeIntervalSinceNow
    }

    /// The join ticket's `server_time`.
    static func observe(_ iso: String?) {
        guard let at = parseInstant(iso) else { return }
        lock.lock()
        defer { lock.unlock() }
        offset = at.timeIntervalSinceNow
        precise = true
        seen = true
    }

    static var now: Date {
        lock.lock()
        defer { lock.unlock() }
        return Date().addingTimeInterval(offset)
    }

    static var nowMs: Int { Int(now.timeIntervalSince1970 * 1000) }

    /// An HTTP `Date` header ("Tue, 04 Nov 2026 10:00:00 GMT").
    static func httpDate(_ text: String) -> Date? {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "GMT")
        f.dateFormat = "EEE, dd MMM yyyy HH:mm:ss zzz"
        return f.date(from: text)
    }
}
