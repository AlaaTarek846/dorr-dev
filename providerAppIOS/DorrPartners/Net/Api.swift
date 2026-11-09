import Foundation
import UIKit

// providerApp `net/ApiClient.kt` + driverApp `core/network` (ApiEnvelope, ApiError, ApiResult, apiCall, CoreHttp) and
// `net/ApiText.kt`.
//
// Which backend this build talks to is each developer's own setting, in `providerAppIOS/Config/Local.xcconfig` (not
// committed), exactly like providerApp/local.properties: `DORR_API_HOST` / `DORR_API_SCHEME`. The providers app's own API is
// `/api/provider/v1/...` (`Api.provider`); the shared public endpoints live next to it under `/api/general/v1/...`.
//
// Every request carries the chosen establishment as `X-Establishment` once one is chosen (`Session.establishment`,
// `EstablishmentAccess::HEADER`), so the server answers for that establishment — its papers, its permissions and its wallet.
//
// Every call answers an `ApiResult`: the data, the server's refusal (message, `error_code`, field errors), or offline — never an
// exception for a screen to catch. DTOs are plain structs read with a snake_case decoder (`dial_code` → `dialCode`); a field the
// server leaves out stays nil (the defaults Android writes in its data classes are the `?? …` at the place of use).

/// A refused or failed call, read from the error body: the message to show and the code to branch on.
struct ApiError: Error {
    let httpStatus: Int
    let message: String
    let errorCode: String?
    let fieldErrors: [String: [String]]
    /// The envelope's `data` on a refusal (e.g. `locked_until`), as JSON.
    let data: Any?

    /// The first validation message when there is one (e.g. a wrong OTP), else the general message.
    var displayMessage: String { fieldErrors.values.first?.first ?? message }
}

/// The result of one API call: the data, or what went wrong.
enum ApiResult<T> {
    case ok(T, message: String?)
    case failed(ApiError)
    /// No connection, timeout, unreachable host.
    case offline

    var value: T? { if case .ok(let v, _) = self { return v } else { return nil } }

    /// providerApp `ApiResult.errorText(offline)`: the server's own message, or `offline` with no connection.
    func errorText(_ offline: String) -> String? {
        switch self {
        case .ok: return nil
        case .failed(let e): return e.displayMessage
        case .offline: return offline
        }
    }

    var error: ApiError? { if case .failed(let e) = self { return e } else { return nil } }
}

/// A JSON body: `[String: Any?]` with nil sent as `null`.
typealias JsonBody = [String: Any?]

/// One file of a multipart upload (`MultipartBody.Part` on Android).
struct FilePart {
    let field: String
    let filename: String
    let mimeType: String
    let data: Data
}

extension FilePart {
    /// The same file under another form field (`front` / `back`).
    func asField(_ field: String) -> FilePart { FilePart(field: field, filename: filename, mimeType: mimeType, data: data) }
}

enum Api {
    static let baseHost: String = Bundle.main.object(forInfoDictionaryKey: "DorrApiHost") as? String ?? "dorr.test"
    static let baseScheme: String = Bundle.main.object(forInfoDictionaryKey: "DorrApiScheme") as? String ?? "https"
    static let baseURL = URL(string: "\(baseScheme)://\(baseHost)/api/")!

    /// Path prefix of every providers-app endpoint.
    static let provider = "provider/v1/"

    /// The establishment the app works in (docs/moving_tasks.md T5): sent on every request once one is chosen.
    static let establishmentHeader = "X-Establishment"

    /// The API's own origin — the live connection's channel authorization (`/broadcasting/auth`) lives next to `/api`.
    static let origin = "\(baseScheme)://\(baseHost)"

    /// The host Laravel builds absolute media URLs with on a developer's machine.
    private static let localMediaHost = "dorr.test"

    static let session: URLSession = {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 120
        config.waitsForConnectivity = false
        return URLSession(configuration: config)
    }()

    static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    /// "Apple iPhone15,2" — ASCII only, as an HTTP header must be.
    static let deviceName: String = {
        var info = utsname()
        uname(&info)
        let model = withUnsafePointer(to: &info.machine) { $0.withMemoryRebound(to: CChar.self, capacity: 1) { String(cString: $0) } }
        return String("Apple \(model)".filter { $0.isASCII }.prefix(120))
    }()

    static let appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0.1.0"

    // MARK: - calls

    /// `apiCall { … }`: success with data, a 4xx/5xx with the server's message and `error_code`, or offline. A success
    /// without `data` is a failure (use `done` for those).
    static func call<T: Decodable>(_ method: String = "GET", _ path: String, query: [String: String?] = [:], body: JsonBody? = nil,
                                   headers: [String: String] = [:]) async -> ApiResult<T> {
        let payload = body.flatMap(jsonData)
        return await finish(perform(path, method: method, query: query, body: payload, contentType: payload == nil ? nil : "application/json", headers: headers))
    }

    /// `@Multipart`: plain text fields plus files. `progress` hears how much of the body has gone (0...1), off the main thread.
    static func upload<T: Decodable>(_ path: String, fields: [String: String] = [:], files: [FilePart], method: String = "POST",
                                     headers: [String: String] = [:], progress: (@Sendable (Double) -> Void)? = nil) async -> ApiResult<T> {
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
        return await finish(perform(path, method: method, query: [:], body: body, contentType: "multipart/form-data; boundary=\(boundary)", headers: headers,
                                    delegate: progress.map { UploadProgress(onProgress: $0) }))
    }

    /// providerApp `apiDone`: a call whose success carries no data (a delete) — nil when it went through, else the text to show.
    static func done(_ method: String, _ path: String, query: [String: String?] = [:], body: JsonBody? = nil, offline: String, generic: String) async -> String? {
        let payload = body.flatMap(jsonData)
        switch await perform(path, method: method, query: query, body: payload, contentType: payload == nil ? nil : "application/json", headers: [:]) {
        case .success: return nil
        case .failure(.offline): return offline
        case .failure(.http(let status, let raw)):
            let text = parseError(status, raw, nil).displayMessage
            return text.isEmpty ? generic : text
        }
    }

    /// A file the server gives behind the sign-in (a document, a delivery), as bytes.
    static func bytes(_ path: String) async -> Data? {
        if case .success(let data) = await perform(path, method: "GET", query: [:], body: nil, contentType: nil, headers: [:]) { return data }
        return nil
    }

    /// The body as it came, or the HTTP status of the refusal (0 when no answer came at all).
    static func raw(_ path: String, query: [String: String?] = [:]) async -> Result<Data, RawFailure> {
        switch await perform(path, method: "GET", query: query, body: nil, contentType: nil, headers: [:]) {
        case .success(let data): return .success(data)
        case .failure(.offline): return .failure(RawFailure(status: 0))
        case .failure(.http(let status, _)): return .failure(RawFailure(status: status))
        }
    }

    struct RawFailure: Error { let status: Int }

    /// What became of a request whose answer the caller reads by its status alone (the truck's positions).
    enum Sent {
        /// A 2xx answer.
        case delivered
        /// The server refused it with this HTTP status.
        case refused(Int)
        /// No answer at all.
        case offline
    }

    /// Sends a JSON body and says only what became of it. `headers` override the app's own (a call naming its `X-Establishment` keeps it).
    static func send(_ method: String, _ path: String, body: JsonBody, headers: [String: String] = [:]) async -> Sent {
        let payload = jsonData(body)
        switch await perform(path, method: method, query: [:], body: payload, contentType: "application/json", headers: headers) {
        case .success: return .delivered
        case .failure(.offline): return .offline
        case .failure(.http(let status, _)): return .refused(status)
        }
    }

    private enum Failure: Error {
        case offline
        case http(Int, Data)
    }

    private static func finish<T: Decodable>(_ outcome: Result<Data, Failure>) -> ApiResult<T> {
        switch outcome {
        case .failure(.offline):
            return .offline
        case .failure(.http(let status, let raw)):
            return .failed(parseError(status, raw, nil))
        case .success(let raw):
            do {
                let envelope = try decoder.decode(Envelope<T>.self, from: raw)
                if envelope.success == true, let data = envelope.data { return .ok(data, message: envelope.message) }
                return .failed(parseError(200, raw, envelope.message))
            } catch {
                // An answer the app cannot read is a failed call, not a crash.
                #if DEBUG
                print("DorrPartners decode \(T.self): \(error)")
                #endif
                return .failed(ApiError(httpStatus: 0, message: "Unreadable response", errorCode: nil, fieldErrors: [:], data: nil))
            }
        }
    }

    static func parseError(_ status: Int, _ raw: Data?, _ fallback: String?) -> ApiError {
        let json = raw.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any]
        let message = json?["message"] as? String ?? fallback ?? "HTTP \(status)"
        var fields: [String: [String]] = [:]
        if let errors = json?["errors"] as? [String: Any] {
            for (key, value) in errors { fields[key] = (value as? [Any])?.compactMap { $0 as? String } ?? [] }
        }
        // JSONSerialization forgets key order; the first field error is the one written first in the body.
        if fields.count > 1, let text = raw.flatMap({ String(data: $0, encoding: .utf8) }),
           let first = fields.keys.min(by: { (text.range(of: "\"\($0)\"")?.lowerBound ?? text.endIndex) < (text.range(of: "\"\($1)\"")?.lowerBound ?? text.endIndex) }) {
            fields = [first: fields[first] ?? []]
        }
        return ApiError(httpStatus: status, message: message, errorCode: json?["error_code"] as? String, fieldErrors: fields, data: json?["data"])
    }

    /// Sends one request with the app's headers (CoreHttp + the app's own) and returns the body of a 2xx answer.
    private static func perform(_ path: String, method: String, query: [String: String?], body: Data?, contentType: String?,
                                headers: [String: String], delegate: URLSessionTaskDelegate? = nil) async -> Result<Data, Failure> {
        var components = URLComponents(url: baseURL.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        let items = query.compactMap { key, value in value.map { URLQueryItem(name: key, value: $0) } }
        if !items.isEmpty { components.queryItems = items.sorted { $0.name < $1.name } }
        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(AppLocale.current, forHTTPHeaderField: "X-Locale")
        request.setValue(DeviceId.current, forHTTPHeaderField: "X-Device-Id")
        request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
        request.setValue(deviceName, forHTTPHeaderField: "X-Device-Name")
        request.setValue("ios", forHTTPHeaderField: "X-Platform")
        request.setValue(appVersion, forHTTPHeaderField: "X-App-Version")
        request.setValue(TimeZone.current.identifier, forHTTPHeaderField: "X-Timezone")
        let (token, establishment) = await MainActor.run { (Session.shared.token, Session.shared.establishment) }
        if let token, !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        // The establishment chosen in the app — never sent before one is chosen. A call that names its own (`headers` below) keeps
        // it: the truck's position belongs to the job's establishment even if the driver has switched to another one meanwhile.
        if let establishment, !establishment.isEmpty { request.setValue(establishment, forHTTPHeaderField: establishmentHeader) }
        headers.forEach { request.setValue($1, forHTTPHeaderField: $0) }
        if let body {
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
            request.httpBody = body
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request, delegate: delegate)
            // A completed round-trip proves we are online.
            await NetworkMonitor.shared.reportReachable()
        } catch let error as URLError {
            // No route to the server raises the offline screen; timeouts and TLS errors stay with the calling screen.
            if [.notConnectedToInternet, .cannotFindHost, .cannotConnectToHost, .networkConnectionLost, .dnsLookupFailed].contains(error.code) {
                await NetworkMonitor.shared.reportUnreachable()
            }
            return .failure(.offline)
        } catch {
            return .failure(.offline)
        }

        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        // Only a request that carried a token can mean "your session ended".
        if status == 401, token != nil { await MainActor.run { Session.shared.clear() } }
        guard (200..<300).contains(status) else { return .failure(.http(status, data)) }
        return .success(data)
    }

    /// Says how much of a request's body has been sent (an upload's progress bar).
    private final class UploadProgress: NSObject, URLSessionTaskDelegate {
        let onProgress: @Sendable (Double) -> Void

        init(onProgress: @escaping @Sendable (Double) -> Void) { self.onProgress = onProgress }

        func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64,
                        totalBytesExpectedToSend: Int64) {
            guard totalBytesExpectedToSend > 0 else { return }
            onProgress(min(1, Double(totalBytesSent) / Double(totalBytesExpectedToSend)))
        }
    }

    /// The API's own address for a path of the providers API (an image read signed in).
    static func url(_ path: String) -> URL? { URL(string: baseURL.absoluteString + provider + path) }

    /// Media URLs come back absolute for the server's own host (`http://dorr.test/...`), which a phone can't resolve — point
    /// them at the reachable host, in the API's own scheme.
    static func mediaUrl(_ url: String?) -> URL? {
        guard var value = url, !value.isEmpty else { return nil }
        let scheme = baseScheme
        if value.hasPrefix("/") { value = "\(scheme)://\(localMediaHost)\(value)" }
        value = value
            .replacingOccurrences(of: "http://\(localMediaHost)", with: "\(scheme)://\(baseHost)")
            .replacingOccurrences(of: "https://\(localMediaHost)", with: "\(scheme)://\(baseHost)")
        if let range = value.range(of: #"^https?://(127[.]0[.]0[.]1|localhost)(:[0-9]+)?"#, options: .regularExpression) {
            value.replaceSubrange(range, with: "\(scheme)://\(baseHost)")
        }
        return URL(string: value)
    }
}

/// Every DORR API answer (App\Support\Api\ApiResponse).
private struct Envelope<T: Decodable>: Decodable {
    let success: Bool?
    let message: String?
    let data: T?
}

/// A success whose data the app does not read (`ApiEnvelope<Any>`): decodes from anything.
struct Ignored: Decodable {
    init(from decoder: Decoder) throws {}
}

/// A text field of a DTO the server may send as a number (`pieces_count`, a floor): Android's Gson turns one into the other by
/// itself, Swift's decoder refuses it and the whole answer would be lost. Read the value with `.text`.
struct FlexString: Decodable, Equatable, Hashable {
    let text: String

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let s = try? c.decode(String.self) { text = s }
        else if let i = try? c.decode(Int.self) { text = String(i) }
        else { text = String(try c.decode(Double.self)) }
    }
}

/// Free-form JSON (a map of tokens, an answer kept as it came).
enum JSONAny: Codable, Equatable {
    case string(String), number(Double), bool(Bool), object([String: JSONAny]), array([JSONAny]), null

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let b = try? c.decode(Bool.self) { self = .bool(b) }
        else if let n = try? c.decode(Double.self) { self = .number(n) }
        else if let s = try? c.decode(String.self) { self = .string(s) }
        else if let a = try? c.decode([JSONAny].self) { self = .array(a) }
        else { self = .object(try c.decode([String: JSONAny].self)) }
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let s): try c.encode(s)
        case .number(let n): try c.encode(n)
        case .bool(let b): try c.encode(b)
        case .object(let o): try c.encode(o)
        case .array(let a): try c.encode(a)
        case .null: try c.encodeNil()
        }
    }

    subscript(key: String) -> JSONAny? { if case .object(let o) = self { return o[key] } else { return nil } }

    var str: String? {
        switch self {
        case .string(let s): return s
        case .number(let n): return n == n.rounded() ? String(Int(n)) : String(n)
        case .bool(let b): return b ? "true" : "false"
        default: return nil
        }
    }

    var int: Int? {
        switch self {
        case .number(let n): return Int(n)
        case .string(let s): return Int(s)
        default: return nil
        }
    }

    var bool: Bool? { if case .bool(let b) = self { return b } else { return nil } }
}

/// An ISO-8601 instant (with or without fractions) → Date.
func parseInstant(_ iso: String?) -> Date? {
    guard let iso, !iso.isEmpty else { return nil }
    let plain = ISO8601DateFormatter()
    if let date = plain.date(from: iso) { return date }
    let trimmed = iso.replacingOccurrences(of: #"(\.\d{3})\d+"#, with: "$1", options: .regularExpression)
    let fractional = ISO8601DateFormatter()
    fractional.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    return fractional.date(from: trimmed)
}
