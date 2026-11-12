import Foundation

// providerApp `net/WorkspaceApi.kt` — where the provider works (docs/moving_tasks.md phase A, Modules/Provider/routes/app.php):
// the establishments I hold a seat in and the invitations waiting for me, creating my own establishment, its papers, and
// answering an invitation. Every call carries the chosen establishment as `X-Establishment` (Api).

struct WorkspaceDto: Decodable, Equatable {
    var memberships: [MembershipDto]?
    var invitations: [MembershipDto]?
    var ownsEstablishment: Bool?
}

/// A seat in an establishment (`MembershipResource`).
struct MembershipDto: Decodable, Equatable, Hashable {
    var uuid: String
    var status: String?
    var isOwner: Bool?
    /// «حصري»: while I am in this establishment, my own establishment cannot send offers (§14).
    var isExclusive: Bool?
    var allBranches: Bool?
    var branchIds: [Int]?
    var offerLimitMinor: Int?
    var permissions: [String]?
    var phone: String?
    var establishment: MembershipEstablishmentDto?
    var joinedAt: String?
}

extension Optional where Wrapped == MembershipDto {
    /// Whether this seat holds `permission` (the owner holds them all; the server checks every one again).
    func can(_ permission: String) -> Bool {
        guard let seat = self else { return false }
        return seat.isOwner == true || (seat.permissions ?? []).contains(permission)
    }
}

struct MembershipEstablishmentDto: Decodable, Equatable, Hashable {
    var uuid: String?
    var name: String?
    var type: String?
    var status: String?
    var logo: String?
}

struct WorkspaceOptionsDto: Decodable {
    var services: [ServiceOptionDto]?
    var vehicleTypes: [VehicleTypeDto]?
    /// group → its permission keys, in the server's order (config `provider.permissions`).
    var permissionGroups: [String: [String]]?
    /// branch_manager / quoter / driver → the keys each template ticks.
    var permissionTemplates: [String: [String]]?

    /// `permissionGroups` in the server's order (Android's map keeps it; a Swift dictionary doesn't). The order is config
    /// `provider.permissions`; a group the app doesn't know yet comes last, by name.
    var orderedGroups: [(group: String, keys: [String])] {
        let known = ["requests", "jobs", "driving", "fleet", "money", "customers", "management"]
        return (permissionGroups ?? [:]).sorted { orderRank($0.key, known) < orderRank($1.key, known) }
            .map { (group: $0.key, keys: $0.value) }
    }

    /// `permissionTemplates` in the server's order (config `provider.permission_templates`). The decoder's snake-case rule also
    /// rewrites dictionary keys, so `branch_manager` arrives here as `branchManager`: it is only ever shown through `templateLabel`.
    var orderedTemplates: [(template: String, keys: [String])] {
        let known = ["branchManager", "quoter", "driver"]
        return (permissionTemplates ?? [:]).sorted { orderRank($0.key, known) < orderRank($1.key, known) }
            .map { (template: $0.key, keys: $0.value) }
    }
}

/// The place of `key` in `known`, then unknown keys by name.
private func orderRank(_ key: String, _ known: [String]) -> String {
    if let i = known.firstIndex(of: key) { return String(format: "0%03d", i) }
    return "1" + key
}

/// A service the establishment can offer, in the admin's order with its image (§12).
struct ServiceOptionDto: Decodable, Equatable, Hashable, Identifiable {
    var id: Int
    var moduleName: String?
    var name: String?
    var description: String?
    var image: String?
}

struct CreateEstablishmentRequest {
    /// `individual` or `company`.
    var type: String
    var name: String
    var countryId: Int
    var commercialRegisterNo: String?
    var taxNumber: String?
    var serviceIds: [Int]
    var branch: BranchRequest

    /// Creating: a value not given stays out of the body, as Android's plain `@Body` does.
    var body: JsonBody {
        withoutNulls([
            "type": type, "name": name, "country_id": countryId,
            "commercial_register_no": commercialRegisterNo, "tax_number": taxNumber,
            "service_ids": serviceIds, "branch": branch.body,
        ])
    }
}

struct BranchRequest {
    var name: String?
    var lat: Double
    var lng: Double
    var radiusKm: Double?
    var address: String?

    /// Only inside a new establishment, where a value not given stays out of the body. Editing a branch is `BranchBody`
    /// (`ManageApi`), whose nulls are sent: there a missing key means "no change".
    var body: JsonBody {
        withoutNulls(["name": name, "lat": lat, "lng": lng, "radius_km": radiusKm, "address": address])
    }
}

struct EstablishmentShowDto: Decodable {
    var establishment: EstablishmentDto?
    var membership: MembershipDto?
}

/// `EstablishmentResource`: status is draft / under_review / approved / rejected / suspended.
struct EstablishmentDto: Decodable, Equatable {
    var uuid: String?
    var type: String?
    var name: String?
    var countryId: Int?
    var commercialRegisterNo: String?
    var taxNumber: String?
    var status: String?
    var statusReason: String?
    var logo: String?
    var services: [EstablishmentServiceDto]?
    var branches: [BranchDto]?
}

struct EstablishmentServiceDto: Decodable, Equatable {
    var id: Int?
    var moduleName: String?
    var name: String?
    var image: String?
    var on: Bool?
}

struct BranchDto: Decodable, Equatable {
    var id: Int?
    var uuid: String?
    var name: String?
    var isMain: Bool?
    var lat: Double?
    var lng: Double?
    var radiusKm: Double?
    var address: String?
    /// Takes requests (the main branch always does).
    var status: Bool?
}

struct DocumentsDto: Decodable, Equatable {
    var required: [DocumentTypeDto]?
    var documents: [ProviderDocumentDto]?
    /// The codes of the required types not uploaded yet (or whose last upload was rejected / expired).
    var missing: [String]?
}

/// `DocumentTypeResource`.
struct DocumentTypeDto: Decodable, Equatable, Identifiable {
    var id: Int
    var code: String?
    var subject: String?
    var kind: String?
    var name: String?
    var instructions: String?
    var hasNumber: Bool?
    var hasExpiry: Bool?
    var needsBackSide: Bool?
}

/// `ProviderDocumentResource`: status is pending / approved / rejected / expired.
struct ProviderDocumentDto: Decodable, Equatable {
    var uuid: String?
    var documentTypeId: Int?
    var code: String?
    var number: String?
    var expiresOn: String?
    var status: String?
    var rejectionReason: String?
    var front: String?
    var back: String?
}

enum WorkspaceApi {
    private static let base = Api.provider

    /// My seats (owner first) and the invitations waiting for an answer — read when the app opens.
    static func workspace() async -> ApiResult<WorkspaceDto> { await Api.call("GET", base + "workspace") }

    /// The services to pick from (image and order from the admin), the vehicle types and the permission groups.
    static func options(countryId: Int? = nil) async -> ApiResult<WorkspaceOptionsDto> {
        await Api.call("GET", base + "workspace/options", query: ["country_id": countryId.map(String.init)])
    }

    /// My own establishment, after the sign-in (C-02): its type, name, services and main branch.
    static func create(_ request: CreateEstablishmentRequest) async -> ApiResult<MembershipDto> {
        await Api.call("POST", base + "establishments", body: request.body)
    }

    /// The establishment the app works in, with my seat in it (permissions).
    static func establishment() async -> ApiResult<EstablishmentShowDto> { await Api.call("GET", base + "establishment") }

    /// Sends the establishment for review; refused with `provider_documents_missing` (data.missing) while papers are missing.
    static func submit() async -> ApiResult<EstablishmentDto> { await Api.call("POST", base + "establishment/submit") }

    static func accept(_ uuid: String) async -> ApiResult<MembershipDto> { await Api.call("POST", base + "invitations/\(uuid)/accept") }

    /// Answers with an empty `data`: nil when it went through, else the text to show (`apiDone`).
    static func decline(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "invitations/\(uuid)/decline", offline: offline, generic: generic)
    }

    /// Leaves an establishment I work in (not my own); answers with an empty `data` (`apiDone`).
    static func leave(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("POST", base + "memberships/\(uuid)/leave", offline: offline, generic: generic)
    }

    /// What the establishment (or one driver, by `driverUuid`) must upload, what was uploaded, and what is still missing.
    static func documents(driverUuid: String? = nil) async -> ApiResult<DocumentsDto> {
        await Api.call("GET", base + "documents", query: ["driver_uuid": driverUuid])
    }

    /// One paper: `subject`, `subject_uuid` (not for the establishment), `document_type_id`, `number`, `expires_on`; the front and back.
    /// `progress` hears how much of the files has gone (0...1, off the main thread) — providerApp counts it in `uploadPaper`.
    static func upload(_ fields: [String: String], front: FilePart, back: FilePart?,
                       progress: (@Sendable (Double) -> Void)? = nil) async -> ApiResult<ProviderDocumentDto> {
        await Api.upload(base + "documents", fields: fields, files: [front.asField("front")] + (back.map { [$0.asField("back")] } ?? []),
                         progress: progress)
    }
}
