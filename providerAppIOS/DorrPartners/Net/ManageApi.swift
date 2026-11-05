import Foundation

// providerApp `net/ManageApi.kt` — running the establishment (C-03, Modules/Provider/routes/app.php): its branches
// (`branches.manage`), its team and their permissions (`members.manage`), and its vehicles with their photos and papers
// (`fleet.vehicles`). The server checks every permission again; the app only hides what the seat can't do.

enum ManageApi {
    private static let base = Api.provider

    static func branches() async -> ApiResult<[BranchDto]> { await Api.call("GET", base + "branches") }

    static func addBranch(_ body: BranchBody) async -> ApiResult<BranchDto> { await Api.call("POST", base + "branches", body: body.json) }

    static func updateBranch(_ uuid: String, _ body: BranchBody) async -> ApiResult<BranchDto> {
        await Api.call("PUT", base + "branches/\(uuid)", body: body.json)
    }

    /// Answers with an empty `data` (`apiDone`).
    static func deleteBranch(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "branches/\(uuid)", offline: offline, generic: generic)
    }

    /// The team, owner first; invited people not answered yet are in it with `status = invited`.
    static func members() async -> ApiResult<[MemberDto]> { await Api.call("GET", base + "members") }

    static func invite(_ body: JsonBody) async -> ApiResult<MemberDto> { await Api.call("POST", base + "members", body: body) }

    static func updateMember(_ uuid: String, _ body: JsonBody) async -> ApiResult<MemberDto> {
        await Api.call("PUT", base + "members/\(uuid)", body: body)
    }

    /// Answers with an empty `data` (`apiDone`).
    static func removeMember(_ uuid: String, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "members/\(uuid)", offline: offline, generic: generic)
    }

    /// Who changed what in the team (REQ-002), newest first, 30 a page.
    static func memberEvents(page: Int = 1) async -> ApiResult<[MemberEventDto]> {
        await Api.call("GET", base + "members/events", query: ["page": String(page)])
    }

    static func vehicles() async -> ApiResult<[VehicleDto]> { await Api.call("GET", base + "vehicles") }

    static func addVehicle(_ body: VehicleBody) async -> ApiResult<VehiclePageDto> { await Api.call("POST", base + "vehicles", body: body.json) }

    /// The vehicle, the photos and papers it needs (`required`), what it has (`documents`) and what is still `missing`.
    static func vehicle(_ uuid: String) async -> ApiResult<VehiclePageDto> { await Api.call("GET", base + "vehicles/\(uuid)") }

    /// An approved vehicle whose data changes (anything but its branch) goes back to review (VehicleService::update).
    static func updateVehicle(_ uuid: String, _ body: VehicleBody) async -> ApiResult<VehiclePageDto> {
        await Api.call("PUT", base + "vehicles/\(uuid)", body: body.json)
    }

    static func deactivateVehicle(_ uuid: String) async -> ApiResult<VehiclePageDto> { await Api.call("POST", base + "vehicles/\(uuid)/deactivate") }
}

struct BranchBody {
    var name: String
    var lat: Double
    var lng: Double
    var radiusKm: Double?
    var address: String?
    var status = true

    /// `jsonBody`: the nulls are sent, so an emptied address or range is cleared.
    var json: JsonBody {
        ["name": name, "lat": lat, "lng": lng, "radius_km": radiusKm, "address": address, "status": status]
    }
}

/// A seat in the team list (`MembershipResource` with `person`).
struct MemberDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    var isOwner: Bool?
    var isExclusive: Bool?
    var allBranches: Bool?
    var branchIds: [Int]?
    var offerLimitMinor: Int?
    var permissions: [String]?
    var phone: String?
    var person: MemberPersonDto?
    var joinedAt: String?

    var id: String { uuid }
}

struct MemberPersonDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var phone: String?
    var avatar: String?
}

/// One line of the team's log: what happened, to whom and by whom (no id of its own).
struct MemberEventDto: Decodable {
    /// invited, joined, declined, permissions_changed, removed, left
    var action: String?
    var changes: JSONAny?
    var member: MemberEventPersonDto?
    var actor: MemberEventPersonDto?
    var createdAt: String?
}

struct MemberEventPersonDto: Decodable {
    var uuid: String?
    var name: String?
    var phone: String?
}

/// `VehicleTypeResource`: the admin's vehicle types, each with its image.
struct VehicleTypeDto: Decodable, Equatable, Hashable, Identifiable {
    var id: Int
    var code: String?
    var name: String?
    var description: String?
    var image: String?
}

/// `VehicleResource`: status is pending / approved / rejected / inactive.
struct VehicleDto: Decodable, Equatable, Identifiable {
    var uuid: String
    var branchId: Int?
    var vehicleType: VehicleTypeDto?
    var vehicleTypeId: Int?
    var plateLetters: String?
    var plateNumber: String?
    var make: String?
    var model: String?
    var color: String?
    var year: Int?
    var status: String?
    var statusReason: String?

    var id: String { uuid }

    /// "ABC 1234" as written on the plate.
    func plate() -> String {
        let letters = (plateLetters ?? "").trimmingCharacters(in: .whitespaces)
        return [letters.isEmpty ? nil : letters, plateNumber].compactMap { $0 }.joined(separator: " ")
    }
}

struct VehicleBody {
    var vehicleTypeId: Int
    var branchId: Int?
    var plateLetters: String?
    var plateNumber: String
    var make: String?
    var model: String?
    var color: String?
    var year: Int?

    /// `jsonBody`: the nulls are sent, so an emptied field is cleared.
    var json: JsonBody {
        [
            "vehicle_type_id": vehicleTypeId, "branch_id": branchId, "plate_letters": plateLetters, "plate_number": plateNumber,
            "make": make, "model": model, "color": color, "year": year,
        ]
    }
}

struct VehiclePageDto: Decodable, Equatable {
    var vehicle: VehicleDto?
    var required: [DocumentTypeDto]?
    var documents: [ProviderDocumentDto]?
    var missing: [String]?
}
