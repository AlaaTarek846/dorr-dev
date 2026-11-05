import Foundation

// providerApp `net/ApiBody.kt`. A `JsonBody` keeps its nils as JSON `null`: that is what editing needs, because the server reads a
// missing key as "no change" and only an explicit null as "clear it" (all branches, no offer limit, an emptied address or note).
// Android's plain `@Body` classes leave nulls out; their twins here pass through `withoutNulls`.

/// The same body without its nil values (Retrofit's Gson on a plain `@Body`): a value not given stays out of the request.
func withoutNulls(_ body: JsonBody) -> JsonBody { body.filter { $0.value != nil } }

/// The JSON of a body. Nested dictionaries and arrays are cleaned first, so a nil at any depth is a JSON `null`.
func jsonData(_ body: JsonBody) -> Data? {
    try? JSONSerialization.data(withJSONObject: jsonClean(body))
}

private func jsonClean(_ value: Any?) -> Any {
    guard let value else { return NSNull() }
    if let map = value as? [String: Any?] { return map.mapValues { jsonClean($0) } }
    if let list = value as? [Any?] { return list.map { jsonClean($0) } }
    // An `Any?` holding an inner Optional, as a dictionary literal of optionals can.
    let mirror = Mirror(reflecting: value)
    if mirror.displayStyle == .optional { return jsonClean(mirror.children.first?.value) }
    return value
}
