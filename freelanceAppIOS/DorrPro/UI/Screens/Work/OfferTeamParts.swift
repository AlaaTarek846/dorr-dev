import SwiftUI

// freelanceApp `ui/screens/OfferTeamParts.kt`.

/*
 * FS-209b: an offer in a team's name — the picker «Send the offer as» (me, or a team I am Owner or PM of) with the team's quota and who signs
 * and is paid; and the box that shows the team on an offer I already sent. FS-213: the offer no longer names who works on it — the team puts
 * its people on the parts once the project starts.
 */

/// The multipart fields of a team's offer (`team`, `signer`) the server reads next to the price and the details.
func teamOfferFields(_ team: String?, _ signer: String?) -> [String: String] {
    guard let team else { return [:] }
    var out = ["team": team]
    if let signer { out["signer"] = signer }
    return out
}

/// «Send the offer as»: me, or a team I am an Owner or a PM of. For a team it shows the team's offers left this month and who signs and is
/// paid (a PM names the Owner). FS-213: not who works on it. `members` are the team's people (nil while they load). `compact` keeps only the choice
/// and the quota (the offer is blocked, so there is nothing to fill in, but the way back to «Me» stays in reach).
struct TeamOfferPicker: View {
    @Environment(\.wa) private var wa
    let teams: [TeamSummaryDto]
    let teamUuid: String?
    let onTeam: (String?) -> Void
    let quota: TeamOfferQuotaDto?
    let members: [TeamMemberDto]?
    let signer: String?
    let onSigner: (String?) -> Void
    let myUuid: String?
    var compact = false

    var body: some View {
        let team = teams.first { $0.uuid == teamUuid }
        let options: [FilterOption] = [FilterOption(key: "", label: L("tw_scope_me"), icon: "person.fill")]
            + teams.map { FilterOption(key: $0.uuid ?? "", label: $0.name ?? "", icon: "person.3.fill") }
        VStack(alignment: .leading, spacing: 10) {
            FieldLabel(text: L("tw_offer_as"))
            FilterChips(options: options, selected: teamUuid ?? "", onSelect: { onTeam($0.isEmpty ? nil : $0) })
            if let team {
                if let q = quota { quotaPill(q) }
                if !compact { details(team) }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func quotaPill(_ q: TeamOfferQuotaDto) -> some View {
        let text = q.limit != nil && q.left != nil ? L("tw_offer_quota_left", q.left ?? 0, q.limit ?? 0) : L("tw_offer_quota_free", q.used ?? 0)
        return Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(wa.red)
            .padding(.horizontal, 12).padding(.vertical, 5)
            .background(wa.red.opacity(0.10), in: Capsule())
    }

    /// Who signs and is paid, and that the whole team stands behind the offer.
    @ViewBuilder
    private func details(_ team: TeamSummaryDto) -> some View {
        let owners = (members ?? []).filter { $0.role == "owner" && $0.freelancer?.uuid != nil }
        let asPm = team.role == "pm"
        if asPm || owners.count > 1 {
            FieldLabel(text: L("tw_offer_signer"))
            Text(L(asPm ? "tw_offer_signer_pm" : "tw_offer_signer_owner")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            FilterChips(
                options: owners.map { FilterOption(key: $0.freelancer?.uuid ?? "", label: $0.freelancer?.name ?? "") },
                selected: signer ?? (asPm ? "" : (myUuid ?? "")),
                onSelect: { onSigner(!asPm && $0 == myUuid ? nil : $0) }
            )
        }
        Text(L("tw_offer_whole_team")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
    }
}

/// On an offer in a team's name: the team's name, who sent it when a PM did, and the people on it with their role and what each takes on
/// (`showPeople` off where the people are edited right below). The client sees first names; I see full names.
struct TeamOfferInfo: View {
    @Environment(\.wa) private var wa
    let team: OfferTeamDto
    let submittedBy: TeamPersonRefDto?
    var showPeople = true

    var body: some View {
        let people = team.members ?? []
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Image(systemName: "person.3.fill").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red).frame(width: 20, height: 20)
                Text(verbatim: L("tw_offer_team_badge", team.name ?? "")).font(.dorr(14, .black)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let name = submittedBy?.name { Text(verbatim: L("tw_offer_sent_by", name)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            if showPeople && !people.isEmpty {
                Text(L("tw_offer_team_people")).font(.dorr(11.5, .bold)).foregroundStyle(wa.settingsMut)
                ForEach(Array(people.enumerated()), id: \.offset) { _, m in
                    HStack(spacing: 10) {
                        InitialsBadge(name: m.name, size: 30)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(verbatim: m.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            if let words = m.responsibility, !words.agreementTrimmed.isEmpty {
                                Text(verbatim: words).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Text(L(m.role == "pm" ? "tw_offer_role_pm" : "tw_offer_role_exec")).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                            .padding(.horizontal, 9).padding(.vertical, 3)
                            .background(wa.red.opacity(0.12), in: Capsule())
                    }
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
    }
}
