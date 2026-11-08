import SwiftUI

// androidApp `freelance/FreelanceFreelancerTeam.kt` — FS-210: a freelancers' team, as the client sees it. On an offer and on the agreement: the
// team's name and logo, its badge and its own figures (never its Owner's personal ones), and who works on the job — first name, role, what
// each takes on (D10). On its own page: the team's profile (about, the people who agreed to be shown, skills, work, what clients said).
// Read once when a page opens and when the connection returns.

// MARK: - The small parts

/// A team's logo, drawn as a rounded square (a person is a circle); its icon until it has one.
struct TeamLogo: View {
    @Environment(\.wa) private var wa
    let url: String?
    var size: CGFloat = 46

    var body: some View {
        ZStack {
            wa.accent.opacity(0.12)
            if let url, !url.isEmpty, let u = URL(string: url) {
                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Image(systemName: "person.3.fill").font(.system(size: size * 0.4, weight: .semibold)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .clipShape(RoundedRectangle(cornerRadius: size * 0.3, style: .continuous))
    }
}

/// «Team» — what tells a team's offer from a person's at a glance. (`TeamPill` on Android; that name is taken by the sports pages here.)
struct FreelancerTeamPill: View {
    @Environment(\.wa) private var wa

    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: "person.3.fill").font(.system(size: 9, weight: .bold))
            Text(L("fteam_label")).font(.dorr(10.5, .heavy))
        }
        .foregroundStyle(wa.accent)
        .padding(.horizontal, 8).padding(.vertical, 2)
        .background(wa.accent.opacity(0.12), in: Capsule())
    }
}

/// «New on DORR» until the team has three ratings, like a freelancer.
func teamRatingText(_ b: FreelancerTeamBadgeDto?) -> String {
    if let r = b?.rating { return L("fl_rating", r, b?.reviewsCount ?? 0) }
    if let n = b?.reviewsCount, n > 0 { return L("fl_rating_few", n) }
    return L("fl_rating_new")
}

/// The line under a team's name in a list: its rating, the projects it finished and how many people are on the offer.
func teamFigures(_ t: FreelancerTeamViewDto) -> String {
    let people = (t.members ?? []).count
    let finished: String? = (t.profile?.completedProjects).flatMap { $0 > 0 ? L("fl_st_completed_n", $0) : nil }
    let staff: String? = people > 0 ? L("fteam_staff_count", people) : nil
    let parts: [String?] = [teamRatingText(t.profile), finished, staff]
    return parts.compactMap { $0 }.joined(separator: "  ·  ")
}

private func teamRoleLabel(_ role: String?) -> String {
    switch role {
    case "pm": return L("fteam_role_pm")
    case "executor": return L("fteam_role_executor")
    case "owner": return L("fteam_role_owner")
    default: return L("fteam_role_member")
    }
}

private struct TeamRolePill: View {
    @Environment(\.wa) private var wa
    let role: String?

    var body: some View {
        let tint = (role == "pm" || role == "owner") ? wa.accent : wa.settingsMut
        Text(verbatim: teamRoleLabel(role)).font(.dorr(10.5, .bold)).foregroundStyle(tint).lineLimit(1)
            .padding(.horizontal, 8).padding(.vertical, 2)
            .background(tint.opacity(0.12), in: Capsule())
    }
}

/// A person without a photo: the first letter of their name on the app's colour.
private struct NameBubble: View {
    @Environment(\.wa) private var wa
    let name: String
    var url: String?
    var size: CGFloat = 38
    var ring: Color?

    var body: some View {
        ZStack {
            Circle().fill(wa.accent.opacity(0.14))
            if let url, !url.isEmpty, let u = URL(string: url) {
                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Text(verbatim: String(name.prefix(1))).font(.dorr(size / 2.4, .heavy)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay { if let ring { Circle().stroke(ring, lineWidth: 2) } }
    }
}

/// The people of an offer as overlapping first letters, and «+n» for the rest — the quick look in a list.
struct StaffStack: View {
    @Environment(\.wa) private var wa
    let members: [FreelancerTeamStaffDto]
    var limit = 4

    var body: some View {
        let ring = wa.night ? wa.darkCard : Color.white
        if !members.isEmpty {
            HStack(spacing: -8) {
                ForEach(Array(members.prefix(limit).enumerated()), id: \.offset) { _, m in NameBubble(name: m.name ?? "", size: 28, ring: ring) }
                if members.count > limit {
                    Text(verbatim: "+\(members.count - limit)").font(.dorr(10, .heavy)).foregroundStyle(wa.settingsMut)
                        .frame(width: 28, height: 28)
                        .background(Circle().fill(wa.settingsMut.opacity(0.18)))
                        .overlay(Circle().stroke(ring, lineWidth: 2))
                }
            }
        }
    }
}

// MARK: - On an offer and on the agreement

/// The card that says who the job is with: the team (tap for its profile). `extra` adds a line under the figures (the offer's status).
struct TeamIdentityCard<Extra: View>: View {
    @Environment(\.wa) private var wa
    let t: FreelancerTeamViewDto
    let onOpen: () -> Void
    @ViewBuilder let extra: () -> Extra

    var body: some View {
        Button(action: onOpen) {
            HStack(spacing: 12) {
                TeamLogo(url: t.profile?.logoUrl, size: 52)
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 4) {
                        Text(verbatim: t.name ?? "—").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if t.profile?.verified == true {
                            Image(systemName: "checkmark.seal.fill").font(.system(size: 16)).foregroundStyle(flGreen).accessibilityLabel(L("fl_verified"))
                        }
                    }
                    FreelancerTeamPill()
                    Text(verbatim: teamFigures(t)).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    Text(L("fteam_view_profile")).font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                    extra()
                }
                Spacer(minLength: 0)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(18)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// Each person of the offer: first name, role and what they take on.
struct TeamStaffRows: View {
    @Environment(\.wa) private var wa
    let members: [FreelancerTeamStaffDto]

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            ForEach(Array(members.enumerated()), id: \.offset) { _, m in
                HStack(alignment: .top, spacing: 10) {
                    NameBubble(name: m.name ?? "")
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(spacing: 8) {
                            Text(verbatim: m.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            TeamRolePill(role: m.role)
                        }
                        if let r = m.responsibility, !r.isEmpty { Text(verbatim: r).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(4) }
                    }
                    Spacer(minLength: 0)
                }
            }
        }
    }
}

/// «Who works on it»: the people the offer names, and — when it is known — who signs for the team (tap for that person's profile). The same
/// card sits on the agreement, where the people are the ones that were named when the work started.
struct TeamWorkCard: View {
    @Environment(\.wa) private var wa
    let members: [FreelancerTeamStaffDto]
    var signer: String?
    var onSigner: (() -> Void)?
    var title: String = L("fteam_work_title")
    /// A button to the team's profile at the foot (nil: none — the page already has the team's card).
    var onProfile: (() -> Void)?

    var body: some View {
        FlCard(icon: "person.3.fill", title: members.isEmpty ? L("fteam_with_team") : title) {
            // FS-213: an offer no longer names its people - the team puts them on the parts once the work starts (an older offer keeps its list).
            if members.isEmpty {
                Text(L("fteam_staff_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                TeamStaffRows(members: members)
            }
            if let signer, !signer.isEmpty { signerRow(signer) }
            if !members.isEmpty { Text(L("fteam_work_hint")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(3) }
            if let onProfile { FlAction(text: L("fteam_view_profile"), icon: "person.3.fill", action: onProfile) }
        }
    }

    @ViewBuilder
    private func signerRow(_ name: String) -> some View {
        let row = HStack(spacing: 10) {
            Image(systemName: "pencil").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.accent)
            VStack(alignment: .leading, spacing: 0) {
                Text(L("fteam_signs")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: name).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
            }
            Spacer(minLength: 0)
            if onSigner != nil { Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut) }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .contentShape(Rectangle())
        if let onSigner { Button(action: onSigner) { row }.buttonStyle(.plain) } else { row }
    }
}

// MARK: - The team's own page

@MainActor
struct FreelancerTeamPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let team: String
    let onBack: () -> Void
    @State private var profile: FreelancerTeamProfileDto?
    @State private var error: String?
    // FL-L13: a piece of the team's work being reported as not theirs.
    @State private var reporting: String?

    var body: some View {
        VStack(spacing: 0) {
            // FS-213: keep the team, to find it again and invite it to the next project.
            FlPageHeader(title: L("fteam_profile_title"), onBack: onBack, trailing: profile.map { loaded in
                AnyView(TeamFavoriteHeart(team: loaded.uuid ?? team, saved: loaded.isFavorite == true) { saved in profile?.isFavorite = saved })
            })
            if let p = profile {
                ScrollView { content(p).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                let read = try await FreelanceApi.freelancerTeam(team).data?.team
                profile = read
                error = read == nil ? L("fl_error_generic") : nil
            } catch {
                self.error = flError(error)
            }
        }
        .dorrDialog(isPresented: Binding(get: { reporting != nil }, set: { if !$0 { reporting = nil } }), bottom: true) {
            if let id = reporting { ReportWorkSheet(subjectType: "portfolio_item", subject: id) { reporting = nil } }
        }
    }

    @ViewBuilder
    private func content(_ p: FreelancerTeamProfileDto) -> some View {
        let s = p.stats ?? FreelancerTeamStatsDto()
        VStack(alignment: .leading, spacing: 12) {
            // Who: logo, name, badge, what it is taking on, its figures.
            VStack(spacing: 6) {
                TeamLogo(url: p.logoUrl, size: 84)
                HStack(spacing: 4) {
                    Text(verbatim: p.name ?? "—").font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                    if p.verified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 17)).foregroundStyle(flGreen).accessibilityLabel(L("fl_verified")) }
                }
                HStack(spacing: 6) {
                    FreelancerTeamPill()
                    let open = p.acceptingProjects == true
                    Text(L(open ? "fteam_accepting" : "fteam_not_accepting")).font(.dorr(10.5, .bold)).foregroundStyle(open ? flGreen : flAmber)
                        .padding(.horizontal, 8).padding(.vertical, 2)
                        .background((open ? flGreen : flAmber).opacity(0.12), in: Capsule())
                }
                let rating = teamRatingText(FreelancerTeamBadgeDto(rating: s.rating, reviewsCount: s.reviewsCount))
                let running: String? = (p.runningProjects).flatMap { $0 > 0 ? "\(L("fteam_running_n")): \($0)" : nil }
                let line: [String?] = [rating, running]
                Text(verbatim: line.compactMap { $0 }.joined(separator: "  ·  ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                Text(L("fl_contact_in_dorr")).font(.dorr(11)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(16)
            .settingsSurface(FlCorners.card)
            // FS-213: invite the team to one of my private projects (its Owner answers and offers in its name).
            if p.acceptingProjects == true { InviteTeamAction(team: p.uuid ?? team, teamName: p.name ?? "") }
            HStack(spacing: 8) {
                FlStat(icon: "checkmark.seal.fill", label: L("fl_st_completed"), value: "\(s.completedProjects ?? 0)")
                FlStat(icon: "clock", label: L("fl_st_on_time"), value: s.onTimeRate.map { "\($0)%" } ?? "—")
                FlStat(icon: "bolt.fill", label: L("fteam_completion"), value: s.completionRate.map { "\($0)%" } ?? "—")
            }
            if let bio = p.bio, !bio.isEmpty {
                FlCard(icon: "info.circle.fill", title: L("fl_about")) {
                    Text(verbatim: bio).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                }
            }
            // The people: only the ones who agreed to be shown, by first name (D10) — the rest are counted.
            FlCard(icon: "person.3.fill", title: L("fteam_members_title")) {
                let people = p.members ?? []
                if !people.isEmpty {
                    VStack(alignment: .leading, spacing: 12) {
                        ForEach(Array(people.enumerated()), id: \.offset) { _, m in FreelancerTeamPersonRow(m: m) }
                    }
                }
                Text(L("fteam_members_hint", p.membersCount ?? 0)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            let specialties = p.specialties ?? []
            let skills = p.skills ?? []
            if !specialties.isEmpty || !skills.isEmpty {
                FlCard(icon: "bolt.fill", title: L("fl_skills")) {
                    if !specialties.isEmpty { FlField(label: L("fl_specialty"), value: specialties.compactMap(\.name).joined(separator: "، ")) }
                    if !skills.isEmpty {
                        FlowLayout(spacing: 6) {
                            ForEach(skills, id: \.id) { sk in
                                Text(verbatim: sk.name ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                                    .padding(.horizontal, 10).padding(.vertical, 4)
                                    .background(wa.accent.opacity(0.08), in: Capsule())
                            }
                        }
                    }
                }
            }
            let languages = p.languages ?? []
            if !languages.isEmpty || (p.since?.isEmpty == false) {
                FlCard(icon: "globe", title: L("fl_languages")) {
                    if !languages.isEmpty { FlField(label: L("fl_languages"), value: languages.joined(separator: "، ")) }
                    if let since = p.since, !since.isEmpty { FlField(label: L("fl_member_since"), value: "\u{200E}" + String(since.prefix(7))) }
                }
            }
            if let works = p.portfolio, !works.isEmpty {
                FlCard(icon: "photo.on.rectangle", title: L("fl_portfolio") + " (\(works.count))") {
                    ForEach(Array(works.enumerated()), id: \.offset) { _, w in
                        HStack(spacing: 10) {
                            ZStack {
                                wa.accent.opacity(0.08)
                                if let cover = w.images?.first?.url, let u = URL(string: cover) {
                                    AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                                }
                            }
                            .frame(width: 56, height: 56)
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: w.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                if let d = w.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2) }
                            }
                            Spacer(minLength: 0)
                            if let id = w.uuid {
                                Button { reporting = id } label: {
                                    Image(systemName: "flag").font(.system(size: 14)).foregroundStyle(wa.settingsMut).frame(width: 34, height: 34).contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                                .accessibilityLabel(L("rights_report"))
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            // FL-701: what clients said about the team's work.
            let reviews = p.reviews ?? []
            if !reviews.isEmpty || (s.completedProjects ?? 0) > 0 {
                FlCard(icon: "star.fill", title: L("fl_rv_about")) {
                    Text(verbatim: [s.completedProjects.map { L("fl_st_completed_n", $0) }, s.onTimeRate.map { L("fl_st_on_time_n", $0) }]
                        .compactMap { $0 }.joined(separator: "  ·  "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    ForEach(Array(reviews.enumerated()), id: \.offset) { _, r in
                        VStack(alignment: .leading, spacing: 2) {
                            ReviewStars(stars: r.stars ?? 0)
                            if let c = r.comment { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
                            Text(verbatim: [r.project, r.at.map { "\u{200E}" + String($0.prefix(10)) }].compactMap { $0 }.joined(separator: " · "))
                                .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            Spacer(minLength: 20)
        }
    }
}

/// A person who agreed to be shown on the team's profile.
private struct FreelancerTeamPersonRow: View {
    @Environment(\.wa) private var wa
    let m: FreelancerTeamPersonDto

    var body: some View {
        HStack(spacing: 10) {
            NameBubble(name: m.name ?? "", url: m.avatarUrl, size: 44)
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 8) {
                    Text(verbatim: m.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    TeamRolePill(role: m.role)
                }
                // FS-213: what the person does in the team, above the headline they wrote themselves.
                if let j = m.jobTitle, !j.isEmpty { Text(verbatim: j).font(.dorr(12.5, .semibold)).foregroundStyle(wa.accent).lineLimit(1) }
                if let h = m.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(2) }
            }
            Spacer(minLength: 0)
        }
    }
}
