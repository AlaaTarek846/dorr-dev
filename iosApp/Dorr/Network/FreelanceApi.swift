import Foundation

// androidApp `network/FreelanceApi.kt` — freelance work, the client's side (Modules/Freelance, FL-201 …): the
// specialty and skill catalogs, my projects (a draft saved as it is typed, its files, review, withdraw, cancel), the
// offers (compare, shortlist, reject, choose), the agreement and its parts (fund, approve, revision, cancel), changes,
// disputes, receipts, the record of approvals, ratings, favourites, invitations and the room about an offer.
// Money is in minor units of the country's currency. DTOs are plain optional structs read with `ApiClient.snake`
// (snake_case keys → camelCase properties, missing keys stay nil); the defaults Android writes in its data
// classes are the `?? …` at the place of use.

struct FreelanceSpecialtyNodeDto: Codable, Equatable {
    var id: Int
    var name: String?
    var description: String?
    var children: [FreelanceSpecialtyNodeDto]?
}

struct FreelanceSkillDto: Codable, Equatable {
    var id: Int
    var name: String?
}

struct FreelanceBudgetRulesDto: Codable, Equatable {
    var minMinor: Int?
    var maxMinor: Int?
    var currency: String?
}

struct FreelanceNamedDto: Codable, Equatable, Hashable {
    var id: Int
    var name: String?
}

struct FreelanceBudgetDto: Codable, Equatable {
    var type: String?
    var minMinor: Int?
    var maxMinor: Int?
    var currency: String?
}

struct FreelanceAttachmentDto: Codable, Equatable {
    var id: Int
    var name: String?
    var mime: String?
    var size: Int?
    var path: String?
}

struct FreelanceProjectCanDto: Codable, Equatable {
    var edit: Bool?
    var submit: Bool?
    var withdraw: Bool?
    var cancel: Bool?
    var delete: Bool?
}

/// The window in which a published project takes offers (FL-204).
struct FreelanceOffersDto: Codable, Equatable {
    var accepting: Bool?
    var deadlineAt: String?
    var closedAt: String?
    var closeReason: String?
    var canClose: Bool?
    var canReopen: Bool?
    var reopensLeft: Int?
    /// How many offers the project has (not counting the ones taken back).
    var count: Int?
    /// The offer the client chose (FL-309).
    var chosen: String?
}

struct FreelanceAgreementRefDto: Codable, Equatable {
    var uuid: String
    var status: String?
}

/// FL-205: invitations to a private project.
struct FreelanceInvitationsSummaryDto: Codable, Equatable {
    var sent: Int?
    var accepted: Int?
    var declined: Int?
    var max: Int?
    var canInvite: Bool?
    var canMakePublic: Bool?
}

struct FreelanceProjectEventDto: Codable, Equatable {
    var type: String?
    var from: String?
    var to: String?
    var at: String?
}

struct FreelanceProjectDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    var statusLabel: String?
    var statusReason: String?
    var title: String?
    var description: String?
    /// FL-307: the same questions for every freelancer who offers.
    var questions: [String]?
    var specialty: FreelanceNamedDto?
    var skills: [FreelanceNamedDto]?
    var budget: FreelanceBudgetDto?
    var durationDays: Int?
    var visibility: String?
    var attachments: [FreelanceAttachmentDto]?
    var missing: [String]?
    var can: FreelanceProjectCanDto?
    var offers: FreelanceOffersDto?
    /// FL-401: the agreement with the chosen freelancer.
    var agreement: FreelanceAgreementRefDto?
    /// FL-205: the numbers of a private project's invitations (nil on a public one).
    var invitations: FreelanceInvitationsSummaryDto?
    var timeline: [FreelanceProjectEventDto]?
    var createdAt: String?
    var submittedAt: String?

    var id: String { uuid }
    var state: String { status ?? "draft" }
}

struct FreelanceOfferChatDto: Codable, Equatable {
    var uuid: String
    var unread: Int?
    var canWrite: Bool?
}

struct FreelanceAnswerDto: Codable, Equatable {
    var question: String?
    var answer: String?
}

struct FreelanceOfferCanDto: Codable, Equatable {
    var chat: Bool?
    var shortlist: Bool?
    var unshortlist: Bool?
    var reject: Bool?
    var choose: Bool?
}

struct FreelanceClientCostDto: Codable, Equatable {
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
    var currency: String?
}

struct FreelanceOfferFreelancerDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var verified: Bool?
    /// 0–100 (FL-102).
    var completeness: Int?
    var yearsOfExperience: Int?
    var portfolioCount: Int?
    var matchingSkills: [FreelanceNamedDto]?
    var projectSkillsCount: Int?
    /// The average once there are three ratings (FL-701), else nil and the app says «new».
    var rating: Double?
    var reviewsCount: Int?
    /// FL-703: finished projects, how many on time and finished, and projects like this one.
    var completedProjects: Int?
    var onTimeRate: Int?
    var completionRate: Int?
    var similarProjects: Int?
    /// I keep this freelancer (FL-306).
    var isFavorite: Bool?
}

/// An offer on my project (FL-301).
struct FreelanceOfferDto: Codable, Equatable, Identifiable {
    var uuid: String
    var status: String?
    var priceMinor: Int?
    var currency: String?
    var durationDays: Int?
    var details: String?
    /// FL-307: each of the project's questions with this offer's answer.
    var answers: [FreelanceAnswerDto]?
    var attachments: [FreelanceAttachmentDto]?
    var freelancer: FreelanceOfferFreelancerDto?
    /// The freelancer changed it after sending (FL-302).
    var edited: Bool?
    var createdAt: String?
    var can: FreelanceOfferCanDto?
    /// What the client pays if they choose it (FL-309).
    var clientCost: FreelanceClientCostDto?
    /// The room about this offer (FL-308): nil until the client opens it.
    var chat: FreelanceOfferChatDto?

    var id: String { uuid }
    var state: String { status ?? "submitted" }
}

/// The offer that stands out in each row (its uuid).
struct FreelanceCompareBestDto: Codable, Equatable {
    var price: String?
    var duration: String?
    var completeness: String?
    var skills: String?
}

struct FreelanceCompareDto: Codable, Equatable {
    var offers: [FreelanceOfferDto]?
    var best: FreelanceCompareBestDto?
}

struct FreelanceFavoriteDto: Codable, Equatable, Identifiable {
    var uuid: String
    var name: String?
    var headline: String?
    var avatarUrl: String?
    var verified: Bool?
    var completeness: Int?

    var id: String { uuid }
}

struct FreelanceReviewLineDto: Codable, Equatable {
    var stars: Double?
    var comment: String?
    var project: String?
    var at: String?
}

struct FreelanceExperienceDto: Codable, Equatable {
    var title: String?
    var company: String?
    var startedOn: String?
    var endedOn: String?
    var isCurrent: Bool?
    var description: String?
}

struct FreelanceCertificateDto: Codable, Equatable {
    var title: String?
    var issuer: String?
    var issuedOn: String?
}

/// A signed link that expires (FL-105).
struct FreelancePortfolioImageDto: Codable, Equatable {
    var id: Int?
    var url: String?
}

struct FreelancePortfolioDto: Codable, Equatable {
    var uuid: String?
    var title: String?
    var description: String?
    var images: [FreelancePortfolioImageDto]?
}

/// The freelancer's profile as the client of one of their offers reads it (FL-305).
struct FreelancerProfileDto: Codable, Equatable {
    var uuid: String?
    var name: String?
    var headline: String?
    var bio: String?
    var avatarUrl: String?
    var verified: Bool?
    var city: String?
    var languages: [String]?
    var memberSince: String?
    var completeness: Int?
    var yearsOfExperience: Int?
    var specialties: [FreelanceNamedDto]?
    var skills: [FreelanceNamedDto]?
    var matchingSkills: [FreelanceNamedDto]?
    var experiences: [FreelanceExperienceDto]?
    var certificates: [FreelanceCertificateDto]?
    var portfolio: [FreelancePortfolioDto]?
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
    var onTimeRate: Int?
    /// FL-701: what clients said.
    var reviews: [FreelanceReviewLineDto]?
    var isFavorite: Bool?
}

struct FreelanceChatPeerDto: Codable, Equatable {
    var name: String?
}

struct FreelanceChatProjectDto: Codable, Equatable {
    var uuid: String?
    var title: String?
}

struct FreelanceChatRoomDto: Codable, Equatable {
    var uuid: String
    var canWrite: Bool?
    /// Before the agreement phone numbers, emails and links are not allowed.
    var guarded: Bool?
    var peer: FreelanceChatPeerDto?
    var project: FreelanceChatProjectDto?
}

struct FreelanceChatMessageDto: Codable, Equatable, Identifiable {
    var id: Int
    var mine: Bool?
    var kind: String?
    var body: String?
    var createdAt: String?
    var readAt: String?
}

/// FL-310: a short interview in a chat room.
struct FreelanceInterviewDto: Codable, Equatable {
    var uuid: String?
    /// proposed, confirmed, declined, cancelled or superseded.
    var status: String?
    var proposedBy: String?
    var startsAt: String?
    var durationMinutes: Int?
    var note: String?
}

struct FreelanceChecklistItemDto: Codable, Equatable {
    var uuid: String?
    var text: String?
    var checked: Bool?
}

struct FreelanceDeliveryFileDto: Codable, Equatable {
    var id: Int?
    var name: String?
    var mime: String?
    var size: Int?
    var isImage: Bool?
    /// Under mobile/v1/freelance/.
    var path: String?
}

struct FreelanceDeliveryDto: Codable, Equatable {
    var uuid: String?
    var number: Int?
    var note: String?
    var deliveredAt: String?
    var revisionReason: String?
    var files: [FreelanceDeliveryFileDto]?
}

struct FreelanceMilestoneCanDto: Codable, Equatable {
    var fund: Bool?
    var approve: Bool?
    var revision: Bool?
    var checklistEdit: Bool?
    var checklistTick: Bool?
    var cancel: Bool?
    var cancelAccept: Bool?
    var cancelReject: Bool?
    var dispute: Bool?
}

struct FreelanceDisputeRefDto: Codable, Equatable {
    var uuid: String
    var status: String?
}

struct FreelanceMilestoneDto: Codable, Equatable, Identifiable {
    var uuid: String
    var position: Int?
    var title: String?
    var description: String?
    var amountMinor: Int?
    var durationDays: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
    var status: String?
    var deliveryNote: String?
    var revisionsUsed: Int?
    /// FL-501: held for it in my wallet.
    var heldMinor: Int?
    /// FL-504: an unanswered delivery is approved and paid by itself then.
    var autoApproveAt: String?
    /// FL-407: the free rounds of changes.
    var revisionsLeft: Int?
    var revisionsMax: Int?
    /// FL-506: who asked to end it, and what went back to my wallet.
    var cancelRequestedBy: String?
    var refundedMinor: Int?
    /// FL-704: its dispute, if any.
    var dispute: FreelanceDisputeRefDto?
    /// FL-601 / FL-604: how many versions were delivered, and what I check before approving.
    var deliveriesCount: Int?
    var checklist: [FreelanceChecklistItemDto]?
    var can: FreelanceMilestoneCanDto?

    var id: String { uuid }
    var state: String { status ?? "unfunded" }
}

struct FreelanceMilestoneRulesDto: Codable, Equatable {
    var maxCount: Int?
    var minAmountMinor: Int?
}

struct FreelanceAgreementMoneyDto: Codable, Equatable {
    var priceMinor: Int?
    var clientFeeMinor: Int?
    var totalMinor: Int?
    /// FL-510: the total told before choosing (a split can change it).
    var quotedTotalMinor: Int?
}

struct FreelanceAgreementTermsDto: Codable, Equatable {
    var reviewDays: Int?
    var freeRevisions: Int?
}

struct FreelanceAgreementCanDto: Codable, Equatable {
    var edit: Bool?
    var send: Bool?
    var cancel: Bool?
    var split: Bool?
}

struct FreelanceDisputePartDto: Codable, Equatable {
    var uuid: String?
    var title: String?
    var amountMinor: Int?
}

struct FreelanceChangeCanDto: Codable, Equatable {
    var accept: Bool?
    var reject: Bool?
    var withdraw: Bool?
}

struct FreelanceChangeDto: Codable, Equatable {
    var uuid: String
    var type: String?
    var status: String?
    var mine: Bool?
    var milestone: FreelanceDisputePartDto?
    /// Its keys arrive camel-cased by the snake decoder; read them with `JSONAny.int(_:)` (which tries both).
    var payload: JSONAny?
    var reason: String?
    var respondBy: String?
    var can: FreelanceChangeCanDto?
}

struct FreelanceRatingDto: Codable, Equatable {
    var stars: Double?
    var comment: String?
}

struct FreelanceReviewDto: Codable, Equatable {
    var criteria: [String]?
    var canRate: Bool?
    var rateUntil: String?
    var mine: FreelanceRatingDto?
    var theirs: FreelanceRatingDto?
    var theirsWaiting: Bool?
}

struct FreelanceAgreementDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var version: Int?
    var scope: String?
    var includes: String?
    var excludes: String?
    var priceMinor: Int?
    var currency: String?
    var durationDays: Int?
    var money: FreelanceAgreementMoneyDto?
    var terms: FreelanceAgreementTermsDto?
    var expiresAt: String?
    var freelancer: FreelanceChatPeerDto?
    var milestones: [FreelanceMilestoneDto]?
    /// FL-501: what I can spend now (for funding a part).
    var walletAvailableMinor: Int?
    /// FL-404 / FL-405: the changes asked, newest first.
    var changes: [FreelanceChangeDto]?
    /// FL-701: rating each other once it is completed.
    var review: FreelanceReviewDto?
    var milestoneRules: FreelanceMilestoneRulesDto?
    var can: FreelanceAgreementCanDto?

    var state: String { status ?? "draft" }
}

struct FreelanceDisputeDecisionDto: Codable, Equatable {
    var type: String?
    var freelancerBp: Int?
    var note: String?
    var executesAt: String?
    var final: Bool?
}

struct FreelanceDisputeAppealDto: Codable, Equatable {
    var by: String?
    var reason: String?
}

struct FreelanceDisputeEvidenceDto: Codable, Equatable {
    var id: Int?
    var side: String?
    var mine: Bool?
    var body: String?
    var file: FreelanceAttachmentDto?
    var at: String?
}

struct FreelanceDisputeCanDto: Codable, Equatable {
    var evidence: Bool?
    var appeal: Bool?
}

struct FreelanceDisputeDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var reason: String?
    var reasonLabel: String?
    var details: String?
    var openedBy: String?
    var mine: Bool?
    var respondBy: String?
    var milestone: FreelanceDisputePartDto?
    var currency: String?
    var decision: FreelanceDisputeDecisionDto?
    var appeal: FreelanceDisputeAppealDto?
    var resolvedAt: String?
    var evidence: [FreelanceDisputeEvidenceDto]?
    var can: FreelanceDisputeCanDto?
}

struct FreelanceRefDto: Codable, Equatable {
    var uuid: String?
    var title: String?
}

/// FL-511: a receipt of one money movement of a part. `text` / `reasonText` come with the single receipt.
struct FreelanceReceiptDto: Codable, Equatable, Identifiable {
    var uuid: String
    var number: String?
    var kind: String?
    var title: String?
    var reason: String?
    var reasonText: String?
    var project: FreelanceRefDto?
    var milestone: FreelanceRefDto?
    var currency: String?
    var amountMinor: Int?
    var feeMinor: Int?
    var totalMinor: Int?
    var refundedMinor: Int?
    var issuedAt: String?
    var text: String?

    var id: String { uuid }
}

/// FL-406: one step of the record of approvals.
struct FreelanceTrailDto: Codable, Equatable {
    var id: Int?
    var action: String?
    var label: String?
    var actor: String?
    var mine: Bool?
    var at: String?
}

struct FreelanceInviteeDto: Codable, Equatable {
    var uuid: String
    var name: String?
    var headline: String?
    var specialties: [FreelanceNamedDto]?
    var rating: Double?
    var reviewsCount: Int?
    var completedProjects: Int?
}

struct FreelanceInvitationDto: Codable, Equatable {
    var uuid: String
    var status: String?
    var freelancer: FreelanceInviteeDto?
    /// The uuid of their offer, once they sent one.
    var offer: String?
    var canWithdraw: Bool?
}

struct FreelanceInvitationsDto: Codable, Equatable {
    var summary: FreelanceInvitationsSummaryDto?
    var invitations: [FreelanceInvitationDto]?
}

/// The whole project form, sent as it is: an empty field is sent as null (a draft may be half done).
struct FreelanceProjectRequest {
    var title: String?
    var description: String?
    var specialtyId: Int?
    var skillIds: [Int]
    var budgetType: String
    var budgetMinMinor: Int?
    var budgetMaxMinor: Int?
    var durationDays: Int?
    var visibility: String
    var questions: [String]

    var json: [String: JSONValue] {
        [
            "title": title.map(JSONValue.string) ?? .null,
            "description": description.map(JSONValue.string) ?? .null,
            "specialty_id": specialtyId.map(JSONValue.int) ?? .null,
            "skill_ids": .array(skillIds.map(JSONValue.int)),
            "budget_type": .string(budgetType),
            "budget_min_minor": budgetMinMinor.map(JSONValue.int) ?? .null,
            "budget_max_minor": budgetMaxMinor.map(JSONValue.int) ?? .null,
            "duration_days": durationDays.map(JSONValue.int) ?? .null,
            "visibility": .string(visibility),
            "questions": .array(questions.map(JSONValue.string)),
        ]
    }
}

/// FL-404 / FL-405: asking for a change to the agreement.
struct FreelanceChangeRequest {
    var type: String
    var milestone: String?
    var days: Int?
    var amountMinor: Int?
    var title: String?
    var scope: String?
    var includes: String?
    var excludes: String?
    var reason: String?

    var json: [String: JSONValue] {
        [
            "type": .string(type),
            "milestone": milestone.map(JSONValue.string) ?? .null,
            "days": days.map(JSONValue.int) ?? .null,
            "amount_minor": amountMinor.map(JSONValue.int) ?? .null,
            "title": title.map(JSONValue.string) ?? .null,
            "scope": scope.map(JSONValue.string) ?? .null,
            "includes": includes.map(JSONValue.string) ?? .null,
            "excludes": excludes.map(JSONValue.string) ?? .null,
            "reason": reason.map(JSONValue.string) ?? .null,
        ]
    }
}

enum FreelanceApi {
    private static let base = "mobile/v1/freelance"

    private static func reason(_ text: String?) -> [String: JSONValue] { ["reason": text.map(JSONValue.string) ?? .null] }

    static func specialties() async throws -> ApiEnvelope<[FreelanceSpecialtyNodeDto]> {
        try await ApiClient.snake("\(base)/specialties")
    }

    static func skills(q: String?, perPage: Int = 30) async throws -> ApiEnvelope<[FreelanceSkillDto]> {
        try await ApiClient.snake("\(base)/skills", query: ["q": q, "per_page": String(perPage)])
    }

    static func projects(status: String? = nil, page: Int = 1, perPage: Int = 15) async throws -> ApiEnvelope<[FreelanceProjectDto]> {
        try await ApiClient.snake("\(base)/projects", query: ["status": status, "page": String(page), "per_page": String(perPage)])
    }

    static func budgetRules(specialtyId: Int?) async throws -> ApiEnvelope<FreelanceBudgetRulesDto> {
        try await ApiClient.snake("\(base)/projects/budget-rules", query: ["specialty_id": specialtyId.map(String.init)])
    }

    static func project(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)")
    }

    static func create(_ body: FreelanceProjectRequest) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects", method: "POST", body: body.json)
    }

    static func update(_ uuid: String, _ body: FreelanceProjectRequest) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)", method: "PUT", body: body.json)
    }

    static func delete(_ uuid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/projects/\(uuid)", method: "DELETE")
    }

    static func submit(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/submit", method: "POST", body: [:])
    }

    static func withdraw(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/withdraw", method: "POST", body: [:])
    }

    /// The offers on my project (FL-305): sort = newest · cheapest · fastest · completeness; status = shortlisted … (nil: all).
    static func offers(_ uuid: String, sort: String?, status: String?) async throws -> ApiEnvelope<[FreelanceOfferDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers", query: ["sort": sort, "status": status])
    }

    /// Two or three offers side by side (ids comma separated).
    static func compareOffers(_ uuid: String, ids: String) async throws -> ApiEnvelope<FreelanceCompareDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/compare", query: ["ids": ids])
    }

    static func offer(_ uuid: String, _ offer: String) async throws -> ApiEnvelope<FreelanceOfferDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/\(offer)")
    }

    /// The freelancer who sent it, as a client may read them (no phone, no email).
    static func offerFreelancer(_ uuid: String, _ offer: String) async throws -> ApiEnvelope<FreelancerProfileDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/\(offer)/freelancer")
    }

    /// action = shortlist · unshortlist · reject (reason optional) · choose (FL-303 / FL-309).
    static func decideOffer(_ uuid: String, _ offer: String, action: String, reason text: String? = nil) async throws -> ApiEnvelope<FreelanceOfferDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/\(offer)/\(action)", method: "POST", body: reason(text))
    }

    /// FL-401: the agreement with the chosen freelancer.
    static func agreement(_ uuid: String) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement")
    }

    static func updateAgreement(_ uuid: String, scope: String, includes: String?, excludes: String?) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement", method: "PUT", body: [
            "scope": .string(scope), "includes": includes.map(JSONValue.string) ?? .null, "excludes": excludes.map(JSONValue.string) ?? .null,
        ])
    }

    /// FL-402: the parts it is paid in — the whole list each time.
    static func setMilestones(_ uuid: String, _ parts: [(title: String, amountMinor: Int)]) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        let list = parts.map { JSONValue.object(["title": .string($0.title), "amount_minor": .int($0.amountMinor), "duration_days": .null]) }
        return try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/milestones", method: "PUT", body: ["milestones": .array(list)])
    }

    /// FL-403: action = fund · approve · revision {reason} · cancel · cancel-accept · cancel-reject.
    static func milestoneAction(_ uuid: String, _ milestone: String, action: String, reason text: String? = nil) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/milestones/\(milestone)/\(action)", method: "POST", body: reason(text))
    }

    static func sendAgreement(_ uuid: String) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/send", method: "POST", body: [:])
    }

    static func cancelAgreement(_ uuid: String) async throws -> ApiEnvelope<FreelanceAgreementDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/cancel", method: "POST", body: [:])
    }

    /// FL-511: my receipts of the project money, newest first; one with its text to share.
    static func receipts() async throws -> ApiEnvelope<[FreelanceReceiptDto]> {
        try await ApiClient.snake("\(base)/receipts")
    }

    static func receipt(_ uuid: String) async throws -> ApiEnvelope<FreelanceReceiptDto> {
        try await ApiClient.snake("\(base)/receipts/\(uuid)")
    }

    /// FL-406: the project's record of approvals.
    static func trail(_ uuid: String) async throws -> ApiEnvelope<[FreelanceTrailDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/trail")
    }

    /// FL-701: rate the freelancer after the project.
    static func review(_ uuid: String, scores: [String: Int], comment: String?) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/review", method: "POST", body: [
            "scores": .object(scores.mapValues(JSONValue.int)), "comment": comment.map(JSONValue.string) ?? .null,
        ])
    }

    /// FL-404 / FL-405: ask for a change to the agreement, or answer one (accept · reject · withdraw).
    static func requestChange(_ uuid: String, _ body: FreelanceChangeRequest) async throws -> ApiEnvelope<[FreelanceChangeDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/changes", method: "POST", body: body.json)
    }

    static func answerChange(_ uuid: String, _ change: String, action: String) async throws -> ApiEnvelope<[FreelanceChangeDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/agreement/changes/\(change)/\(action)", method: "POST", body: [:])
    }

    /// FL-704: a dispute on a part.
    static func openDispute(_ uuid: String, _ milestone: String, reason: String, details: String) async throws -> ApiEnvelope<FreelanceDisputeDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/milestones/\(milestone)/dispute", method: "POST", body: ["reason": .string(reason), "details": .string(details)])
    }

    static func dispute(_ uuid: String, _ dispute: String) async throws -> ApiEnvelope<FreelanceDisputeDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/disputes/\(dispute)")
    }

    static func disputeEvidence(_ uuid: String, _ dispute: String, body text: String) async throws -> ApiEnvelope<FreelanceDisputeDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/disputes/\(dispute)/evidence", method: "POST", body: ["body": .string(text), "reason": .null])
    }

    static func appealDispute(_ uuid: String, _ dispute: String, reason text: String) async throws -> ApiEnvelope<FreelanceDisputeDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/disputes/\(dispute)/appeal", method: "POST", body: ["body": .null, "reason": .string(text)])
    }

    /// FL-306: the freelancers I keep.
    static func favorites() async throws -> ApiEnvelope<[FreelanceFavoriteDto]> {
        try await ApiClient.snake("\(base)/favorites")
    }

    static func addFavorite(_ uuid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/favorites/\(uuid)", method: "POST", body: [:])
    }

    static func removeFavorite(_ uuid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/favorites/\(uuid)", method: "DELETE")
    }

    /// The talk about an offer (FL-308): opens the room (or finds it).
    static func openChat(_ uuid: String, _ offer: String) async throws -> ApiEnvelope<FreelanceChatRoomDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/\(offer)/chat", method: "POST", body: [:])
    }

    static func chatRoom(_ uuid: String) async throws -> ApiEnvelope<FreelanceChatRoomDto> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)")
    }

    /// Newest first; `before` is the id to continue from.
    static func chatMessages(_ uuid: String, before: Int? = nil, limit: Int = 40) async throws -> ApiEnvelope<[FreelanceChatMessageDto]> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/messages", query: ["before": before.map(String.init), "limit": String(limit)])
    }

    static func chatSend(_ uuid: String, body text: String, clientId: String) async throws -> ApiEnvelope<FreelanceChatMessageDto> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/messages", method: "POST", body: ["body": .string(text), "client_id": .string(clientId)])
    }

    /// FL-310: the interviews of this room; propose a time; confirm / decline / cancel one.
    static func interviews(_ uuid: String) async throws -> ApiEnvelope<[FreelanceInterviewDto]> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/interviews")
    }

    static func proposeInterview(_ uuid: String, startsAt: String, durationMinutes: Int) async throws -> ApiEnvelope<[FreelanceInterviewDto]> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/interviews", method: "POST", body: [
            "starts_at": .string(startsAt), "duration_minutes": .int(durationMinutes), "note": .null,
        ])
    }

    static func answerInterview(_ uuid: String, _ interview: String, action: String) async throws -> ApiEnvelope<[FreelanceInterviewDto]> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/interviews/\(interview)/\(action)", method: "POST", body: [:])
    }

    static func chatRead(_ uuid: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.snake("\(base)/chat/conversations/\(uuid)/read", method: "POST", body: [:])
    }

    /// FL-602 / FL-604: the versions delivered for a part, the checklist I write before the work, and ticking a line.
    static func deliveries(_ uuid: String, _ milestone: String) async throws -> ApiEnvelope<[FreelanceDeliveryDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/milestones/\(milestone)/deliveries")
    }

    static func setChecklist(_ uuid: String, _ milestone: String, items: [String]) async throws -> ApiEnvelope<[FreelanceChecklistItemDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/milestones/\(milestone)/checklist", method: "PUT", body: ["items": .array(items.map(JSONValue.string))])
    }

    static func tickChecklist(_ uuid: String, _ milestone: String, item: String, state: String) async throws -> ApiEnvelope<[FreelanceChecklistItemDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/milestones/\(milestone)/checklist/\(item)/\(state)", method: "POST", body: [:])
    }

    /// A file the server gave as a `path` (under mobile/v1/freelance/), downloaded with the sign-in.
    static func file(_ path: String) async throws -> Data {
        try await ApiClient.raw("\(base)/\(path)", auth: true)
    }

    /// FL-205: the invitations to a private project; who to invite; inviting; taking one back; opening it to everyone.
    static func invitations(_ uuid: String) async throws -> ApiEnvelope<FreelanceInvitationsDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/invitations")
    }

    static func invitees(_ uuid: String, q: String?, all: Bool) async throws -> ApiEnvelope<[FreelanceInviteeDto]> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/invitees", query: ["q": q, "all": all ? "1" : nil])
    }

    static func invite(_ uuid: String, freelancers: [String]) async throws -> ApiEnvelope<FreelanceInvitationsDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/invitations", method: "POST", body: ["freelancers": .array(freelancers.map(JSONValue.string))])
    }

    static func withdrawInvitation(_ uuid: String, _ invitation: String) async throws -> ApiEnvelope<FreelanceInvitationsDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/invitations/\(invitation)", method: "DELETE")
    }

    static func makePublic(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/make-public", method: "POST", body: [:])
    }

    static func closeOffers(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/close", method: "POST", body: [:])
    }

    static func reopenOffers(_ uuid: String) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/offers/reopen", method: "POST", body: [:])
    }

    static func cancel(_ uuid: String, reason text: String?) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/cancel", method: "POST", body: reason(text))
    }

    static func addAttachment(_ uuid: String, file: ApiClient.FilePart) async throws -> ApiEnvelope<FreelanceProjectDto> {
        let data = try await ApiClient.uploadRaw("\(base)/projects/\(uuid)/attachments", files: [file])
        return try ApiClient.snakeDecoder.decode(ApiEnvelope<FreelanceProjectDto>.self, from: data)
    }

    static func removeAttachment(_ uuid: String, media: Int) async throws -> ApiEnvelope<FreelanceProjectDto> {
        try await ApiClient.snake("\(base)/projects/\(uuid)/attachments/\(media)", method: "DELETE")
    }
}
