import Foundation

// freelanceApp `net/ListsApi.kt`.

/// The freelancer's specialties, skills, work history and certificates (FL-104), under `/api/freelance/v1`.
enum ListsApi {
    private static let base = Api.freelance

    // The catalogs the freelancer picks from.
    static func specialtyTree() async -> ApiResult<[SpecialtyNodeDto]> { await Api.call("GET", base + "specialties") }

    static func skillCatalog(_ q: String?, perPage: Int = 30) async -> ApiResult<[CatalogSkillDto]> {
        await Api.call("GET", base + "skills", query: ["q": q, "per_page": String(perPage)])
    }

    static func suggestSkill(_ body: SuggestSkillRequest) async -> ApiResult<CatalogSkillDto> {
        var json: JsonBody = ["name": body.name]
        if let locale = body.locale { json["locale"] = locale }
        return await Api.call("POST", base + "skills/suggest", body: json)
    }

    // Mine.
    static func mySpecialties() async -> ApiResult<[MySpecialtyDto]> { await Api.call("GET", base + "profile/specialties") }

    static func setSpecialties(_ body: SetSpecialtiesRequest) async -> ApiResult<[MySpecialtyDto]> {
        await Api.call("PUT", base + "profile/specialties", body: ["specialty_ids": body.specialtyIds, "primary_id": body.primaryId])
    }

    static func mySkills() async -> ApiResult<[MySkillDto]> { await Api.call("GET", base + "profile/skills") }

    static func setSkills(_ body: SetSkillsRequest) async -> ApiResult<[MySkillDto]> {
        // Gson leaves a null level out of the row.
        let rows: [[String: Any]] = body.skills.map { row in
            var json: [String: Any] = ["skill_id": row.skillId]
            if let level = row.level { json["level"] = level }
            return json
        }
        return await Api.call("PUT", base + "profile/skills", body: ["skills": rows])
    }

    static func experiences() async -> ApiResult<[ExperienceDto]> { await Api.call("GET", base + "profile/experiences") }

    static func addExperience(_ body: JsonBody) async -> ApiResult<ExperienceDto> { await Api.call("POST", base + "profile/experiences", body: body) }

    static func updateExperience(_ id: Int, _ body: JsonBody) async -> ApiResult<ExperienceDto> {
        await Api.call("PUT", base + "profile/experiences/\(id)", body: body)
    }

    /// `apiDone`: nil when it went through, else the text to show.
    static func deleteExperience(_ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "profile/experiences/\(id)", offline: offline, generic: generic)
    }

    static func certificates() async -> ApiResult<[CertificateDto]> { await Api.call("GET", base + "profile/certificates") }

    /// Multipart: the text fields as `fields`, the proof file (optional) as `proof`.
    static func addCertificate(_ fields: [String: String], _ proof: FilePart?) async -> ApiResult<CertificateDto> {
        await Api.upload(base + "profile/certificates", fields: fields, files: proof.map { [$0] } ?? [])
    }

    /// Edited with POST so a multipart body works.
    static func updateCertificate(_ id: Int, _ fields: [String: String], _ proof: FilePart?) async -> ApiResult<CertificateDto> {
        await Api.upload(base + "profile/certificates/\(id)", fields: fields, files: proof.map { [$0] } ?? [])
    }

    static func deleteCertificate(_ id: Int, offline: String, generic: String) async -> String? {
        await Api.done("DELETE", base + "profile/certificates/\(id)", offline: offline, generic: generic)
    }

    /// The proof file: private, only its owner, signed in.
    static func certificateProof(_ id: Int) async -> Data? { await Api.bytes(base + "profile/certificates/\(id)/proof") }
}

struct SpecialtyNodeDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var description: String?
    var children: [SpecialtyNodeDto]?
}

struct MySpecialtyDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var parent: String?
    var isPrimary: Bool?
    var isActive: Bool?
}

struct SetSpecialtiesRequest {
    var specialtyIds: [Int]
    var primaryId: Int
}

struct CatalogSkillDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var pending: Bool?
    var created: Bool?
}

struct SuggestSkillRequest {
    var name: String
    var locale: String?
}

struct MySkillDto: Decodable, Equatable {
    var id: Int?
    var name: String?
    var level: String?
    var pending: Bool?
}

struct SkillRow {
    var skillId: Int
    var level: String?
}

struct SetSkillsRequest {
    var skills: [SkillRow]
}

struct ExperienceDto: Decodable, Equatable {
    var id: Int?
    var title: String?
    var company: String?
    var startedOn: String?
    var endedOn: String?
    var isCurrent: Bool?
    var description: String?
}

struct CertificateDto: Decodable, Equatable {
    var id: Int?
    var title: String?
    var issuer: String?
    var issuedOn: String?
    var expiresOn: String?
    var isExpired: Bool?
    var credentialUrl: String?
    var proof: ProofDto?
}

struct ProofDto: Decodable, Equatable {
    var name: String?
    var mime: String?
    var size: Int64?
}
