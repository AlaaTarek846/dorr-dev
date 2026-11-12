import Combine
import Foundation

// driverApp `wallet/net/WalletSupport.kt` — the DORR app's wallet (O-35) runs in the captain app on this thin layer: the
// same answers and failures, the captain's own session, and the captain's `driver/v1` wallet routes — so the wallet
// screens are the DORR app's, unchanged. (Named `Wallet…` here: Swift has one namespace, Kotlin had the package.)

/// `ApiEnvelope<T>` of the wallet calls.
struct WalletEnvelope<T: Decodable>: Decodable {
    let success: Bool?
    let status: String?
    let code: Int?
    let message: String?
    let data: T?
    /// Present only on paginated lists.
    let pagination: WalletPagination?
}

/// `PaginationDto`.
struct WalletPagination: Decodable {
    let currentPage: Int?
    let hasMorePages: Bool?

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page", hasMorePages = "has_more_pages"
    }
}

/// A body-less request (`Optional<Empty>.none`).
struct WalletNoBody: Codable {}

/// A refused wallet request (a 4xx / 5xx answer), kept for [Error.walletFailure].
struct WalletHttpError: Error {
    let status: Int
    let body: Data
}

/// `ApiFailure` — a refused request, read without ever throwing (same as the DORR app). `message` is nil offline.
struct WalletFailure {
    let message: String?
    let errorCode: String?
    let httpStatus: Int?
    /// `data.locked_until` on a `wallet_pin_locked` (423) answer — an ISO-8601 instant.
    let lockedUntil: String?
    let retryAfter: Int?
}

extension Error {
    /// `Throwable.apiFailure()`: the first field error, else the message; `httpStatus` nil when no answer came at all.
    var walletFailure: WalletFailure {
        guard let http = self as? WalletHttpError else {
            return WalletFailure(message: nil, errorCode: nil, httpStatus: nil, lockedUntil: nil, retryAfter: nil)
        }
        let root = (try? JSONSerialization.jsonObject(with: http.body)) as? [String: Any]
        var firstError: String?
        if let errors = root?["errors"] as? [String: Any] {
            // JSONSerialization forgets key order; the first field error is the one written first in the body.
            let text = String(data: http.body, encoding: .utf8) ?? ""
            let first = errors.keys.min { a, b in
                (text.range(of: "\"\(a)\"")?.lowerBound ?? text.endIndex) < (text.range(of: "\"\(b)\"")?.lowerBound ?? text.endIndex)
            }
            if let value = first.flatMap({ errors[$0] }) { firstError = (value as? [Any])?.first as? String ?? value as? String }
        }
        let data = root?["data"] as? [String: Any]
        return WalletFailure(
            message: firstError ?? root?["message"] as? String,
            errorCode: root?["error_code"] as? String,
            httpStatus: http.status,
            lockedUntil: data?["locked_until"] as? String,
            retryAfter: (data?["retry_after"] as? NSNumber)?.intValue
        )
    }
}

/// `AppLocale` — the app language sent to the server (X-Locale) and used for dates.
enum WalletLocale {
    static var current: String {
        let chosen = DriverPrefs.locale
        return chosen == "ar" || chosen == "en" ? chosen : "ar"
    }

    static var isArabic: Bool { current == "ar" }
}

/// `ApiClient.wallet`'s HTTP client: the captain's session (CoreHttp's headers + Bearer) with the chosen wallet's country
/// (`X-Country`), like the DORR app. Its `onUnauthorized` is empty on Android: a 401 here never signs the captain out.
enum WalletClient {
    private static let encoder = JSONEncoder()
    private static let decoder = JSONDecoder()

    static func get<T: Decodable>(_ path: String, query: [String: String?] = [:]) async throws -> WalletEnvelope<T> {
        try await send(path, method: "GET", query: query, body: Optional<WalletNoBody>.none)
    }

    static func post<T: Decodable, B: Encodable>(_ path: String, query: [String: String?] = [:], body: B, headers: [String: String] = [:]) async throws -> WalletEnvelope<T> {
        try await send(path, method: "POST", query: query, body: body, headers: headers)
    }

    static func put<T: Decodable, B: Encodable>(_ path: String, body: B, headers: [String: String] = [:]) async throws -> WalletEnvelope<T> {
        try await send(path, method: "PUT", body: body, headers: headers)
    }

    static func delete<T: Decodable>(_ path: String) async throws -> WalletEnvelope<T> {
        try await send(path, method: "DELETE", body: Optional<WalletNoBody>.none)
    }

    static func send<T: Decodable, B: Encodable>(_ path: String, method: String, query: [String: String?] = [:], body: B?,
                                                 headers: [String: String] = [:]) async throws -> WalletEnvelope<T> {
        let data = try body.map { try encoder.encode($0) }
        let raw = try await perform(path, method: method, query: query, body: data, contentType: data == nil ? nil : "application/json", headers: headers)
        return try decoder.decode(WalletEnvelope<T>.self, from: raw)
    }

    /// `@Multipart`: plain text fields plus files.
    static func upload<T: Decodable>(_ path: String, fields: [String: String] = [:], files: [FilePart], headers: [String: String] = [:]) async throws -> WalletEnvelope<T> {
        let boundary = "dorr-\(UUID().uuidString)"
        var body = Data()
        func append(_ text: String) { body.append(Data(text.utf8)) }
        for (name, value) in fields.sorted(by: { $0.key < $1.key }) {
            append("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(name)\"\r\n\r\n\(value)\r\n")
        }
        for file in files {
            append("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(file.field)\"; filename=\"\(file.filename)\"\r\n")
            append("Content-Type: \(file.mimeType)\r\n\r\n")
            body.append(file.data)
            append("\r\n")
        }
        append("--\(boundary)--\r\n")
        let raw = try await perform(path, method: "POST", query: [:], body: body, contentType: "multipart/form-data; boundary=\(boundary)", headers: headers)
        return try decoder.decode(WalletEnvelope<T>.self, from: raw)
    }

    private static func perform(_ path: String, method: String, query: [String: String?], body: Data?, contentType: String?,
                                headers: [String: String]) async throws -> Data {
        var components = URLComponents(url: Api.baseURL.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        let items = query.compactMap { key, value in value.map { URLQueryItem(name: key, value: $0) } }
        if !items.isEmpty { components.queryItems = items }
        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        // CoreHttp: Accept, X-Locale, X-Device-Id, the Bearer token — then the wallet's own X-Country and X-Locale.
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(DriverBackend.deviceId, forHTTPHeaderField: "X-Device-Id")
        if let token = DriverBackend.token, !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let country = WalletCountry.headerFor(path: components.path) { request.setValue(country, forHTTPHeaderField: "X-Country") }
        request.setValue(WalletLocale.current, forHTTPHeaderField: "X-Locale")
        headers.forEach { request.setValue($1, forHTTPHeaderField: $0) }
        if let body {
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
            request.httpBody = body
        }
        let (data, response) = try await Api.session.data(for: request)
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        guard (200..<300).contains(status) else { throw WalletHttpError(status: status, body: data) }
        return data
    }
}

/// `collectReconnectTick()` — bumps when the connection comes back, so a wallet page that failed offline reloads once.
/// The captain app's real-time connection is the signal (never a timer).
@MainActor
final class WalletReconnect: ObservableObject {
    static let shared = WalletReconnect()

    @Published private(set) var reconnectTick = 0
    private var wasDown = false
    private var watch: AnyCancellable?

    private init() {
        watch = DriverLive.shared.$connected.removeDuplicates().sink { [weak self] connected in
            guard let self else { return }
            if !connected { self.wasDown = true } else if self.wasDown { self.wasDown = false; self.reconnectTick += 1 }
        }
    }
}
