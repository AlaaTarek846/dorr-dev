import Foundation
import UIKit

/// Mirrors androidApp `network/ApiClient.kt`.
///
/// Which backend this build talks to is each developer's own setting, in
/// `iosApp/Config/Local.xcconfig` (not committed — so merges never swap it):
///
///     DORR_API_HOST = my-tunnel.ngrok-free.dev   DORR_API_SCHEME = https
///     DORR_API_HOST = 192.168.1.3                DORR_API_SCHEME = http   (phone + PC on the same Wi-Fi)
///     DORR_API_HOST = dorr.test                  DORR_API_SCHEME = http   (simulator on the same Mac)
///
/// The values reach the app through Info.plist (`DorrApiHost` / `DorrApiScheme`).
enum ApiClient {
    static let baseHost: String = Bundle.main.object(forInfoDictionaryKey: "DorrApiHost") as? String ?? "dorr.test"
    static let baseScheme: String = Bundle.main.object(forInfoDictionaryKey: "DorrApiScheme") as? String ?? "https"
    static let baseURL = URL(string: "\(baseScheme)://\(baseHost)/api/")!

    /// The API's own origin — real-time auth (`/broadcasting/auth`) lives next to `/api`.
    static let origin = "\(baseScheme)://\(baseHost)"

    private static let localMediaHost = "dorr.test"

    /// Same long ceilings as Android: AI image calls and media over a dev tunnel outlive 10 s.
    static let session: URLSession = {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 90
        config.timeoutIntervalForResource = 180
        config.waitsForConnectivity = false
        #if DEBUG
        // `-DorrFixtures YES`: answers come from Debug/DebugFixtures.swift (never in a Release build).
        config.protocolClasses = [FixtureProtocol.self] + (config.protocolClasses ?? [])
        #endif
        return URLSession(configuration: config)
    }()

    static let decoder = JSONDecoder()

    /// For DTO families written as plain optional structs (Sports): JSON snake_case keys read as camelCase
    /// properties, missing keys stay nil. Note it converts dictionary keys too ("red_card" → "redCard").
    static let snakeDecoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    static func snake<T: Decodable>(_ path: String, method: String = "GET", query: [String: String?] = [:], body: [String: JSONValue]? = nil,
                                    headers: [String: String] = [:]) async throws -> ApiEnvelope<T> {
        let data = try await perform(path, method: method, query: query, body: body.flatMap { try? JSONEncoder().encode($0) },
                                     contentType: body == nil ? nil : "application/json", auth: true, headers: headers)
        return try snakeDecoder.decode(ApiEnvelope<T>.self, from: data)
    }
    static let encoder = JSONEncoder()

    /// "Apple iPhone15,2" — ASCII only, as an HTTP header must be.
    private static let deviceName: String = {
        var info = utsname()
        uname(&info)
        let model = withUnsafePointer(to: &info.machine) {
            $0.withMemoryRebound(to: CChar.self, capacity: 1) { String(cString: $0) }
        }
        let name = "Apple \(model)".filter { $0.isASCII }
        return String(name.prefix(120))
    }()

    private static let appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"

    // MARK: - Requests

    static func get<T: Decodable>(_ path: String, query: [String: String?] = [:], auth: Bool = false) async throws -> ApiEnvelope<T> {
        try await send(path, method: "GET", query: query, body: Optional<Empty>.none, auth: auth)
    }

    static func post<T: Decodable, B: Encodable>(_ path: String, query: [String: String?] = [:], body: B, auth: Bool = false, headers: [String: String] = [:]) async throws -> ApiEnvelope<T> {
        try await send(path, method: "POST", query: query, body: body, auth: auth, headers: headers)
    }

    static func put<T: Decodable, B: Encodable>(_ path: String, body: B, auth: Bool = true, headers: [String: String] = [:]) async throws -> ApiEnvelope<T> {
        try await send(path, method: "PUT", body: body, auth: auth, headers: headers)
    }

    static func patch<T: Decodable, B: Encodable>(_ path: String, body: B, auth: Bool = true) async throws -> ApiEnvelope<T> {
        try await send(path, method: "PATCH", body: body, auth: auth)
    }

    static func delete<T: Decodable>(_ path: String, auth: Bool = true) async throws -> ApiEnvelope<T> {
        try await send(path, method: "DELETE", body: Optional<Empty>.none, auth: auth)
    }

    static func send<T: Decodable, B: Encodable>(
        _ path: String,
        method: String,
        query: [String: String?] = [:],
        body: B?,
        auth: Bool,
        headers: [String: String] = [:]
    ) async throws -> ApiEnvelope<T> {
        let data = try body.map { try encoder.encode($0) }
        return try await request(path, method: method, query: query, body: data, contentType: data == nil ? nil : "application/json", auth: auth, headers: headers)
    }

    /// One file of a multipart upload (`MultipartBody.Part` on Android).
    struct FilePart {
        let field: String
        let filename: String
        let mimeType: String
        let data: Data
    }

    /// `@Multipart` on Android: plain text fields plus files, sent as `multipart/form-data`.
    static func upload<T: Decodable>(
        _ path: String,
        query: [String: String?] = [:],
        fields: [String: String] = [:],
        files: [FilePart],
        auth: Bool = true,
        headers: [String: String] = [:]
    ) async throws -> ApiEnvelope<T> {
        let data = try await uploadRaw(path, query: query, fields: fields, files: files, auth: auth, headers: headers)
        return try decoder.decode(ApiEnvelope<T>.self, from: data)
    }

    /// The same multipart request, returning the answer's body (for DTOs read with `snakeDecoder`).
    static func uploadRaw(
        _ path: String,
        query: [String: String?] = [:],
        fields: [String: String] = [:],
        files: [FilePart],
        auth: Bool = true,
        headers: [String: String] = [:],
        progress: ((Double) -> Void)? = nil
    ) async throws -> Data {
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
        return try await perform(path, method: "POST", query: query, body: body, contentType: "multipart/form-data; boundary=\(boundary)", auth: auth, headers: headers, progress: progress)
    }

    private static func request<T: Decodable>(
        _ path: String,
        method: String,
        query: [String: String?] = [:],
        body: Data?,
        contentType: String?,
        auth: Bool,
        headers: [String: String]
    ) async throws -> ApiEnvelope<T> {
        let data = try await perform(path, method: method, query: query, body: body, contentType: contentType, auth: auth, headers: headers)
        return try decoder.decode(ApiEnvelope<T>.self, from: data)
    }

    /// The answer's body as it came (Retrofit's `ResponseBody`): for payloads stored as they are.
    static func raw(_ path: String, query: [String: String?] = [:], auth: Bool = false) async throws -> Data {
        try await perform(path, method: "GET", query: query, body: nil, contentType: nil, auth: auth, headers: [:])
    }

    /// A file as it came, with its answer's headers (Retrofit's `Response<ResponseBody>`): for a download saved under
    /// the server's name (`Content-Disposition`).
    static func rawResponse(_ path: String, query: [String: String?] = [:], auth: Bool = false) async throws -> (Data, HTTPURLResponse?) {
        try await performWithResponse(path, method: "GET", query: query, body: nil, contentType: nil, auth: auth, headers: [:])
    }

    /// Sends one request with the app's headers and returns the body of a 2xx answer.
    private static func perform(
        _ path: String,
        method: String,
        query: [String: String?],
        body: Data?,
        contentType: String?,
        auth: Bool,
        headers: [String: String],
        progress: ((Double) -> Void)? = nil
    ) async throws -> Data {
        try await performWithResponse(path, method: method, query: query, body: body, contentType: contentType, auth: auth, headers: headers, progress: progress).0
    }

    /// [perform], keeping the HTTP answer too (its headers).
    private static func performWithResponse(
        _ path: String,
        method: String,
        query: [String: String?],
        body: Data?,
        contentType: String?,
        auth: Bool,
        headers: [String: String],
        progress: ((Double) -> Void)? = nil
    ) async throws -> (Data, HTTPURLResponse?) {
        // The freelance international wallet on screen: the same wallet calls, on its own path.
        var components = URLComponents(url: baseURL.appendingPathComponent(WalletCountry.rewrite(path: path)), resolvingAgainstBaseURL: false)!
        let items = query.compactMap { key, value in value.map { URLQueryItem(name: key, value: $0) } }
        if !items.isEmpty { components.queryItems = items }

        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        // My chosen wallet (another country's wallet of mine) — on wallet requests only.
        if let walletCountry = WalletCountry.headerFor(path: components.path) {
            request.setValue(walletCountry, forHTTPHeaderField: "X-Country")
        }
        request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(AppLocale.current, forHTTPHeaderField: "X-Locale")
        request.setValue(DeviceId.current, forHTTPHeaderField: "X-Device-Id")
        // Shown in Settings → Security → Devices (and to support) to tell this phone from the others.
        request.setValue(deviceName, forHTTPHeaderField: "X-Device-Name")
        request.setValue("ios", forHTTPHeaderField: "X-Platform")
        request.setValue(appVersion, forHTTPHeaderField: "X-App-Version")
        // The recipient-time scheduling of greeting cards (spec 164) needs my zone.
        request.setValue(TimeZone.current.identifier, forHTTPHeaderField: "X-Timezone")
        let token = auth ? await MainActor.run { AuthSession.shared.token } : nil
        if let token, !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        headers.forEach { request.setValue($1, forHTTPHeaderField: $0) }
        if let body {
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
            request.httpBody = body
        }

        let data: Data
        let response: URLResponse
        do {
            if let progress, let upload = request.httpBody {
                // A file going up: the bytes sent so far reach the caller (the chat's upload ring).
                var plain = request
                plain.httpBody = nil
                (data, response) = try await session.upload(for: plain, from: upload, delegate: UploadProgress(progress))
            } else {
                (data, response) = try await session.data(for: request)
            }
            // A completed round-trip proves we are online.
            await NetworkMonitor.shared.reportReachable()
            // The chat's usage stats (spec 83): what its requests carried, up and down.
            if path.contains("/chat/") {
                ChatUsage.countRequest(up: Int64(request.httpBody?.count ?? 0), down: Int64(data.count),
                                       upload: contentType?.hasPrefix("multipart") == true,
                                       media: (response.mimeType ?? "").hasPrefix("image") || (response.mimeType ?? "").hasPrefix("video") || (response.mimeType ?? "").hasPrefix("audio"))
            }
        } catch let error as URLError {
            // No route to the server raises the app-wide offline screen; timeouts and TLS stay with the screen.
            if [.notConnectedToInternet, .cannotFindHost, .cannotConnectToHost, .networkConnectionLost, .dnsLookupFailed].contains(error.code) {
                await NetworkMonitor.shared.reportUnreachable()
            }
            throw error
        }

        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        // Session expired only when we sent a Bearer token and the server rejected it.
        // Public auth routes (OTP) must not wipe state or show the expired banner.
        if status == 401, token != nil {
            await MainActor.run {
                AuthSession.shared.clear()
                AuthSession.shared.onUnauthorized?()
            }
        }
        guard (200..<300).contains(status) else {
            // An AI tool refused for want of a DORR AI plan: one prompt offers the plans (AiPlanGate).
            if status == 402 || status == 429 { AiPlanGate.inspect(data) }
            throw ApiError.http(status: status, body: data)
        }
        return (data, response as? HTTPURLResponse)
    }

    /// Media URLs come back absolute for the server's own host (`http://dorr.test/...`),
    /// which the device can't resolve — point them at the reachable host.
    static func mediaUrl(_ url: String?) -> URL? {
        guard var value = url, !value.isEmpty else { return nil }
        let scheme = baseScheme
        if value.hasPrefix("/") { value = "\(scheme)://\(localMediaHost)\(value)" }
        value = value
            .replacingOccurrences(of: "http://\(localMediaHost)", with: "\(scheme)://\(baseHost)")
            .replacingOccurrences(of: "https://\(localMediaHost)", with: "\(scheme)://\(baseHost)")
        // `php artisan serve` / a default APP_URL builds loopback URLs, which a phone can never reach.
        if let range = value.range(of: #"^https?://(127[.]0[.]0[.]1|localhost)(:[0-9]+)?"#, options: .regularExpression) {
            value.replaceSubrange(range, with: "\(scheme)://\(baseHost)")
        }
        return URL(string: value)
    }
}

struct Empty: Codable {}

enum ApiError: Error {
    case http(status: Int, body: Data)

    var status: Int {
        switch self { case .http(let status, _): return status }
    }
}

/// androidApp `ApiFailure` — an error answer read without ever throwing. `httpStatus` is nil when no
/// HTTP answer came back at all (offline, timeout), which screens treat as "network error".
struct ApiFailure {
    let message: String?
    let errorCode: String?
    let httpStatus: Int?
    /// `data.locked_until` on a `wallet_pin_locked` (423) answer — an ISO-8601 instant.
    let lockedUntil: String?
    /// `data.retry_after` (seconds) on a `chat_slow_mode` (429) answer.
    let retryAfter: Int?
    /// The whole `data` object of the error (e.g. `errors[{line, field}]` of a refused marketing results file).
    var data: JSONAny? = nil
}

extension Error {
    /// Pulls a human-readable message out of an API error body (422 and friends).
    /// Prefers the first field error, then the top-level `message`. Backend strings
    /// are already localized via the `X-Locale` header.
    var serverMessage: String? { apiFailure.message }

    /// androidApp `Throwable.apiFailure()`. The envelope's `data` is `[]` on most errors and `errors`
    /// may be missing or not a map — every read here is optional, so a refused request never crashes.
    var apiFailure: ApiFailure {
        guard let apiError = self as? ApiError, case .http(let status, let body) = apiError else {
            return ApiFailure(message: nil, errorCode: nil, httpStatus: nil, lockedUntil: nil, retryAfter: nil)
        }
        let root = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        var firstError: String?
        if let errors = root?["errors"] as? [String: Any], let first = Self.firstErrorKey(in: body, keys: Array(errors.keys)).flatMap({ errors[$0] }) {
            firstError = (first as? [Any])?.first as? String ?? first as? String
        }
        let data = root?["data"] as? [String: Any]
        return ApiFailure(
            message: firstError ?? root?["message"] as? String,
            errorCode: root?["error_code"] as? String,
            httpStatus: status,
            lockedUntil: data?["locked_until"] as? String,
            retryAfter: (data?["retry_after"] as? NSNumber)?.intValue,
            data: (try? JSONDecoder().decode(JSONAny.self, from: body))?["data"]
        )
    }

    /// JSONSerialization forgets key order; the first field error is the one written first in the body.
    private static func firstErrorKey(in body: Data, keys: [String]) -> String? {
        guard let text = String(data: body, encoding: .utf8) else { return keys.first }
        return keys.min { a, b in
            (text.range(of: "\"\(a)\"")?.lowerBound ?? text.endIndex) < (text.range(of: "\"\(b)\"")?.lowerBound ?? text.endIndex)
        }
    }
}

/// An ISO-8601 instant (with or without fractions) → Date.
func parseInstant(_ iso: String?) -> Date? {
    guard let iso, !iso.isEmpty else { return nil }
    let plain = ISO8601DateFormatter()
    if let date = plain.date(from: iso) { return date }
    // Laravel's toISOString() sends microseconds (".612584Z"); older iOS only reads up to milliseconds.
    let trimmed = iso.replacingOccurrences(of: #"(\.\d{3})\d+"#, with: "$1", options: .regularExpression)
    let fractional = ISO8601DateFormatter()
    fractional.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    return fractional.date(from: trimmed)
}


/// Reports an upload's progress (0…1) as its bytes go out.
private final class UploadProgress: NSObject, URLSessionTaskDelegate {
    let report: (Double) -> Void
    init(_ report: @escaping (Double) -> Void) { self.report = report }

    func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
        guard totalBytesExpectedToSend > 0 else { return }
        report(Double(totalBytesSent) / Double(totalBytesExpectedToSend))
    }
}
