import QuickLook
import SwiftUI
import UIKit

// androidApp `freelance/FreelanceOffers.kt` — the client compares the offers on a project and chooses one
// (FL-305 / FL-309): the list (sorted, the shortlisted only), two or three side by side, one offer with its files
// and what can be done with it (shortlist, turn down with an optional reason, choose after seeing what it costs),
// the profile of the freelancer who sent it and the freelancers I keep. Each page reads once when it opens and
// when the connection comes back — nothing on a timer.

private let sorts = ["recommended", "newest", "cheapest", "fastest", "completeness"]
private let compareMax = 3

private func sortLabel(_ sort: String) -> String {
    switch sort {
    case "recommended": return L("fl_sort_recommended")
    case "cheapest": return L("fl_sort_cheapest")
    case "fastest": return L("fl_sort_fastest")
    case "completeness": return L("fl_sort_completeness")
    default: return L("fl_sort_newest")
    }
}

func offerStatusLabel(_ status: String) -> String {
    switch status {
    case "viewed": return L("fl_offer_st_viewed")
    case "shortlisted": return L("fl_offer_st_shortlisted")
    case "rejected": return L("fl_offer_st_rejected")
    case "accepted": return L("fl_offer_st_accepted")
    default: return L("fl_offer_st_new")
    }
}

private func offerStatusColor(_ status: String) -> Color {
    switch status {
    case "accepted": return flGreen
    case "shortlisted": return Color(hex: 0x2563EB)
    case "rejected": return flRed
    case "viewed": return Color(hex: 0x6B7280)
    default: return flAmber
    }
}

private struct OfferStatusPill: View {
    let status: String
    var body: some View { FlPill(text: offerStatusLabel(status), color: offerStatusColor(status)) }
}

private struct FlAvatar: View {
    @Environment(\.wa) private var wa
    let name: String?
    let url: String?
    var size: CGFloat = 42

    var body: some View {
        ZStack {
            Circle().fill(wa.accent.opacity(0.12))
            if let url, !url.isEmpty, let u = URL(string: url) {
                AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Text(verbatim: String((name ?? "").prefix(1))).font(.dorr(size / 2.4, .heavy)).foregroundStyle(wa.accent)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}

/// «New on DORR» until ratings exist (FL-701).
private func ratingText(_ f: FreelanceOfferFreelancerDto?) -> String {
    if let r = f?.rating { return L("fl_rating", r, f?.reviewsCount ?? 0) }
    if let n = f?.reviewsCount, n > 0 { return L("fl_rating_few", n) }
    return L("fl_rating_new")
}

private func skillsMatch(_ f: FreelanceOfferFreelancerDto?) -> String? {
    guard let f, let total = f.projectSkillsCount, total > 0 else { return nil }
    return L("fl_skills_match", (f.matchingSkills ?? []).count, total)
}

private struct VerifiedMark: View {
    var size: CGFloat = 16
    var body: some View {
        Image(systemName: "checkmark.seal.fill").font(.system(size: size - 2)).foregroundStyle(flGreen).accessibilityLabel(L("fl_verified"))
    }
}

// MARK: - The list

private func sortIcon(_ sort: String) -> String {
    switch sort {
    case "recommended": return "rosette"
    case "cheapest": return "banknote"
    case "fastest": return "bolt.fill"
    case "completeness": return "checkmark.seal.fill"
    default: return "clock"
    }
}

@MainActor
struct OffersPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onCompare: ([String]) -> Void
    @State private var sort = "recommended"
    @State private var onlyShortlisted = false
    // A new sort or filter is a new list (it starts from the first page); the next fifteen come while I scroll.
    @StateObject private var paged = PagedRows<FreelanceOfferDto>(key: { $0.uuid })
    @State private var picked: [String] = []

    var body: some View {
        let offers: [FreelanceOfferDto]? = paged.loaded && (paged.error == nil || !paged.rows.isEmpty) ? paged.rows : nil
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_offers"), onBack: onBack)
            FlTabs(tabs: [FlTab(icon: "square.grid.2x2.fill", label: L("fl_filter_all")), FlTab(icon: "star.fill", label: L("fl_offer_st_shortlisted"))],
                   selected: onlyShortlisted ? 1 : 0) { onlyShortlisted = $0 == 1 }
                .padding(.horizontal, 16)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(sorts, id: \.self) { s in FlChip(text: sortLabel(s), selected: sort == s, icon: sortIcon(s)) { sort = s } }
                }
                .padding(.horizontal, 14).padding(.vertical, 10)
            }
            if sort == "recommended" { FlHint(text: L("fl_sort_recommended_hint")).padding(.horizontal, 18).padding(.bottom, 4) }
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 12) {
                    if offers == nil && paged.error == nil {
                        ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 150) }
                    } else if let list = offers, list.isEmpty {
                        WaEmpty(icon: "doc.text.fill", tone: .blue, title: L(onlyShortlisted ? "fl_no_shortlisted" : "fl_no_offers"), text: "")
                    } else if let list = offers {
                        if list.count >= 2 { FlNotice(text: L("fl_compare_hint", compareMax), icon: "arrow.left.arrow.right", color: wa.accent) }
                        ForEach(Array(list.enumerated()), id: \.element.uuid) { i, o in
                            let isPicked = picked.contains(o.uuid)
                            OfferCard(o: o, picked: isPicked, canPick: list.count >= 2 && (isPicked || picked.count < compareMax),
                                      onPick: { if isPicked { picked.removeAll { $0 == o.uuid } } else { picked.append(o.uuid) } },
                                      onClick: { onOpen(o.uuid) })
                                .onAppear { paged.appeared(i) }
                        }
                    }
                    PagedSpinner(paged: paged)
                    PagedError(paged: paged)
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            // Pulling down reads the offers again (the client's own gesture, not a timer).
            .refreshable { await paged.reload() }
            // The offers picked to compare, and «Compare» — in reach as soon as one is picked.
            if !picked.isEmpty {
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: L("flo_picked", picked.count, compareMax)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                        Button { picked = [] } label: { Text(L("flo_clear")).font(.dorr(12, .bold)).foregroundStyle(wa.accent) }.buttonStyle(.plain)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    FlAction(text: L("fl_compare_n", picked.count), icon: "arrow.left.arrow.right", tone: .primary, enabled: picked.count >= 2) { onCompare(picked) }
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
                .background(wa.night ? wa.darkCard : Color.white)
            }
        }
        .task(id: "\(sort)|\(onlyShortlisted)") {
            let (s, status, project) = (sort, onlyShortlisted ? "shortlisted" : nil, projectUuid)
            paged.reset { page in try await FreelanceApi.offers(project, sort: s, status: status, page: page) }
        }
        .task(id: "\(sort)|\(onlyShortlisted)|\(network.reconnectTick)") { await paged.reload() }
    }
}

private struct OfferCard: View {
    @Environment(\.wa) private var wa
    let o: FreelanceOfferDto
    let picked: Bool
    let canPick: Bool
    let onPick: () -> Void
    let onClick: () -> Void

    var body: some View {
        let f = o.freelancer
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                FlAvatar(name: f?.name, url: f?.avatarUrl, size: 46)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 4) {
                        Text(verbatim: f?.name ?? "—").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if f?.verified == true { VerifiedMark() }
                        PlanBadge(badge: f?.badge).padding(.leading, 2)
                        if f?.isFavorite == true { Image(systemName: "heart.fill").font(.system(size: 13)).foregroundStyle(flRed).accessibilityLabel(L("fl_favorite")) }
                    }
                    if let h = f?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    SkillBadges(badges: f?.skillBadges ?? []).padding(.top, 2)
                }
                Spacer(minLength: 0)
                OfferStatusPill(status: o.state)
            }
            OfferPriceRow(o: o)
            // FL-L09: the loyalty discount this freelancer gives me, with the usual price.
            LoyaltyWasLine(bp: o.loyaltyBp, list: o.listPriceMinor.map { flAmount($0, o.currency) })
            // FL-210: dearer than the most I said I'd pay.
            if o.overCap == true { FlNotice(text: L("fl_over_cap"), icon: "exclamationmark.triangle.fill", color: flRed) }
            Text(verbatim: [f.map { L("fl_completeness", $0.completeness ?? 0) }, f.map { L("fl_portfolio_n", $0.portfolioCount ?? 0) },
                            skillsMatch(f), ratingText(f)].compactMap { $0 }.joined(separator: "  ·  "))
                .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            Text(verbatim: o.details ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5).lineLimit(3)
            HStack(spacing: 0) {
                // FL-L13: who owns the work once it is paid.
                if let r = o.rights { FlPill(text: r.modelLabel ?? "", color: rightsModelColor(r.model)).padding(.trailing, 8) }
                if o.edited == true { Text(L("fl_offer_edited")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.trailing, 10) }
                if let files = o.attachments, !files.isEmpty {
                    Image(systemName: "paperclip").font(.system(size: 11)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: L("fl_offer_files", files.count)).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
                Spacer()
                if canPick {
                    Button(action: onPick) {
                        HStack(spacing: 4) {
                            Image(systemName: picked ? "checkmark.circle.fill" : "circle").font(.system(size: 16)).foregroundStyle(wa.accent)
                            Text(L("fl_compare")).font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                        }
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(wa.accent.opacity(picked ? 0.16 : 0.08), in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
        .overlay { if picked { RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous).stroke(wa.accent, lineWidth: 2) } }
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
    }
}

/// The price of an offer big on the app's colour, and the days beside it.
private struct OfferPriceRow: View {
    @Environment(\.wa) private var wa
    let o: FreelanceOfferDto

    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("fl_price")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: flAmount(o.priceMinor, o.currency)).font(.dorr(19, .heavy)).foregroundStyle(wa.accent).lineLimit(1).minimumScaleFactor(0.8)
            }
            Spacer(minLength: 8)
            HStack(spacing: 5) {
                Image(systemName: "clock").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                Text(verbatim: L("fl_days", o.durationDays ?? 0)).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
        .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A square button at the foot of a page (talk, more), with a count when something waits.
struct FootIcon: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    var badge = 0
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .bold)).foregroundStyle(wa.accent)
                .frame(width: 48, height: 48)
                .background(wa.accent.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(alignment: .topTrailing) { FlCountBadge(count: badge).offset(x: 4, y: -4) }
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

// MARK: - Side by side

@MainActor
struct ComparePage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let ids: [String]
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onChosen: () -> Void
    @State private var data: FreelanceCompareDto?
    @State private var error: String?
    /// The one being chosen right here (its sheet), so the client need not go back to the offer page.
    @State private var choosing: FreelanceOfferDto?
    @State private var busy = false

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_compare_title"), onBack: onBack)
            if let d = data {
                let offers = d.offers ?? []
                let best = d.best ?? FreelanceCompareBestDto()
                ScrollView {
                    VStack(alignment: .leading, spacing: 8) {
                        FlHint(text: L("fl_compare_best_hint")).padding(.horizontal, 4)
                        // The heads: who, tap to open the offer.
                        HStack(alignment: .top, spacing: 8) {
                            ForEach(offers) { o in
                                Button { onOpen(o.uuid) } label: {
                                    VStack(spacing: 4) {
                                        FlAvatar(name: o.freelancer?.name, url: o.freelancer?.avatarUrl, size: 48)
                                        PlanBadge(badge: o.freelancer?.badge)
                                        Text(verbatim: o.freelancer?.name ?? "—").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.center)
                                        OfferStatusPill(status: o.state)
                                        if o.can?.choose == true {
                                            Text(L("fl_choose_offer")).font(.dorr(12, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center)
                                                .frame(maxWidth: .infinity).padding(.vertical, 8).background(wa.accent, in: Capsule())
                                                .onTapGesture { choosing = o }
                                        }
                                    }
                                    .frame(maxWidth: .infinity)
                                    .padding(10)
                                    .settingsSurface(16)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        row(L("fl_price"), offers) { cell(flAmount($0.priceMinor, $0.currency), best: best.price == $0.uuid) }
                        row(L("fl_you_pay"), offers) { o in cell(o.clientCost.map { flAmount($0.totalMinor, $0.currency) } ?? "—") }
                        row(L("fl_duration"), offers) { cell(L("fl_days", $0.durationDays ?? 0), best: best.duration == $0.uuid) }
                        row(L("fl_profile_complete"), offers) { cell("\(flNum($0.freelancer?.completeness ?? 0))%", best: best.completeness == $0.uuid) }
                        row(L("fl_skills_fit"), offers) { o in
                            cell(skillsMatch(o.freelancer) ?? "—", best: best.skills == o.uuid && !(o.freelancer?.matchingSkills ?? []).isEmpty)
                        }
                        row(L("fl_verified"), offers) { cell(L($0.freelancer?.verified == true ? "fl_yes" : "fl_no")) }
                        row(L("fl_experience_years"), offers) { o in cell(o.freelancer?.yearsOfExperience.map { L("fl_years", $0) } ?? "—") }
                        row(L("fl_portfolio"), offers) { cell(flNum($0.freelancer?.portfolioCount ?? 0)) }
                        // FL-L13: the ownership model each one offers.
                        if offers.contains(where: { $0.rights != nil }) { row(L("rights_compare"), offers) { cell($0.rights?.modelLabel ?? "—") } }
                        // FL-703: what they finished, and on time.
                        row(L("fl_st_completed"), offers) { o in
                            let similar = (o.freelancer?.similarProjects).flatMap { $0 > 0 ? " · " + L("fl_st_similar", $0) : nil } ?? ""
                            cell(flNum(o.freelancer?.completedProjects ?? 0) + similar)
                        }
                        row(L("fl_st_on_time"), offers) { o in cell(o.freelancer?.onTimeRate.map { "\(flNum($0))%" } ?? "—") }
                        row(L("fl_rating_label"), offers) { cell(ratingText($0.freelancer)) }
                        row(L("fl_offer_details"), offers) { cell($0.details ?? "", small: true) }
                        // FL-307: the same question, each one's answer.
                        ForEach(Array((offers.first?.answers ?? []).enumerated()), id: \.offset) { i, a in
                            row(a.question ?? "", offers) { o in
                                let answers = o.answers ?? []
                                cell((answers.indices.contains(i) ? answers[i].answer : nil) ?? "—", small: true)
                            }
                        }
                        Spacer(minLength: 16)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 8)
                }
            } else {
                FlLoading(error: error)
            }
        }
        .dorrDialog(isPresented: Binding(get: { choosing != nil }, set: { if !$0 && !busy { choosing = nil } }), bottom: true) {
            if let o = choosing {
                ChooseSheet(o: o, busy: busy, onDismiss: { if !busy { choosing = nil } }, onConfirm: { choose(o) })
            }
        }
        .task(id: network.reconnectTick) {
            do {
                data = try await FreelanceApi.compareOffers(projectUuid, ids: ids.joined(separator: ",")).data
                error = nil
            } catch {
                self.error = flError(error)
            }
        }
    }

    private func choose(_ o: FreelanceOfferDto) {
        busy = true
        Task {
            do {
                _ = try await FreelanceApi.decideOffer(projectUuid, o.uuid, action: "choose", reason: nil)
                choosing = nil
                onChosen()
            } catch {
                self.error = flError(error)
                choosing = nil
            }
            busy = false
        }
    }

    private func row<C: View>(_ label: String, _ offers: [FreelanceOfferDto], @ViewBuilder cell: @escaping (FreelanceOfferDto) -> C) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
            HStack(alignment: .top, spacing: 8) {
                ForEach(offers) { o in cell(o).frame(maxWidth: .infinity, alignment: .leading) }
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(14)
    }

    private func cell(_ text: String, best: Bool = false, small: Bool = false) -> some View {
        Text(verbatim: text).font(.dorr(small ? 12 : 14, best ? .heavy : .semibold)).foregroundStyle(best ? flGreen : wa.settingsInk).lineLimit(small ? 6 : 2)
    }
}

// MARK: - One offer

@MainActor
struct OfferPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let offerUuid: String
    let onBack: () -> Void
    let onProfile: () -> Void
    let onChat: (String) -> Void
    let onChosen: () -> Void
    @State private var offer: FreelanceOfferDto?
    @State private var error: String?
    @State private var notice: String?
    @State private var busy = false
    // "reject", "choose" or "chat" (opening the room)
    @State private var sheet: String?
    @State private var opening: Int?

    private func decide(_ action: String, reason: String? = nil) {
        busy = true
        error = nil
        notice = nil
        Task {
            do {
                let envelope = try await FreelanceApi.decideOffer(projectUuid, offerUuid, action: action, reason: reason)
                offer = envelope.data
                notice = envelope.message
                sheet = nil
                if action == "choose" { onChosen() }
            } catch {
                self.error = flError(error)
            }
            busy = false
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_offer"), onBack: onBack)
            if let o = offer {
                ScrollView { content(o).padding(.horizontal, 16) }
                footer(o)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do { offer = try await FreelanceApi.offer(projectUuid, offerUuid).data } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { sheet == "more" }, set: { if !$0 { sheet = nil } }), bottom: true) {
            if let o = offer {
                let can = o.can ?? FreelanceOfferCanDto()
                FlSheet(icon: "ellipsis.circle.fill", title: L("flo_more_title"), text: o.freelancer?.name ?? "") {
                    if can.shortlist == true && o.state != "shortlisted" { FlAction(text: L("fl_shortlist"), icon: "star") { sheet = nil; decide("shortlist") } }
                    if can.unshortlist == true { FlAction(text: L("fl_unshortlist"), icon: "star.fill") { sheet = nil; decide("unshortlist") } }
                    if can.reject == true { FlAction(text: L("fl_reject_offer"), icon: "xmark.circle.fill", tone: .danger) { sheet = "reject" } }
                }
            }
        }
        .dorrDialog(isPresented: Binding(get: { sheet == "choose" || sheet == "reject" }, set: { if !$0 && !busy { sheet = nil } }), bottom: true) {
            if sheet == "choose", let o = offer {
                ChooseSheet(o: o, busy: busy, onDismiss: { if !busy { sheet = nil } }, onConfirm: { decide("choose") })
            } else {
                RejectSheet(busy: busy, onDismiss: { if !busy { sheet = nil } }, onConfirm: { decide("reject", reason: $0) })
            }
        }
    }

    @ViewBuilder
    private func content(_ o: FreelanceOfferDto) -> some View {
        let f = o.freelancer
        let can = o.can ?? FreelanceOfferCanDto()
        VStack(alignment: .leading, spacing: 12) {
            // Who sent it — tap for the profile.
            Button(action: onProfile) {
                HStack(spacing: 12) {
                    FlAvatar(name: f?.name, url: f?.avatarUrl, size: 52)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 4) {
                            Text(verbatim: f?.name ?? "—").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            if f?.verified == true { VerifiedMark(size: 18) }
                            PlanBadge(badge: f?.badge).padding(.leading, 2)
                        }
                        if let h = f?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                        Text(verbatim: ratingText(f)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Text(L("fl_see_profile")).font(.dorr(12, .bold)).foregroundStyle(wa.accent)
                        OfferStatusPill(status: o.state)
                    }
                    Spacer(minLength: 0)
                }
                .padding(14)
                .settingsSurface(18)
            }
            .buttonStyle(.plain)
            FlCard {
                OfferPriceRow(o: o)
                LoyaltyWasLine(bp: o.loyaltyBp, list: o.listPriceMinor.map { flAmount($0, o.currency) })
                FlField(label: L("fl_offer_details"), value: o.details)
                ForEach(Array((o.answers ?? []).enumerated()), id: \.offset) { _, a in FlField(label: a.question ?? "", value: a.answer) }
                if o.edited == true { Text(L("fl_offer_edited")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            }
            // FL-L13: who owns the work once it is paid and what I may do with it.
            RightsCard(rights: o.rights)
            if let f {
                FlCard(icon: "person.fill", title: L("fl_freelancer_profile")) {
                    HStack(spacing: 8) {
                        FlStat(icon: "checkmark.seal.fill", label: L("fl_profile_complete"), value: "\(f.completeness ?? 0)%")
                        FlStat(icon: "photo.on.rectangle", label: L("fl_portfolio"), value: "\(f.portfolioCount ?? 0)")
                    }
                    let names = (f.matchingSkills ?? []).compactMap(\.name).joined(separator: "، ")
                    FlField(label: L("fl_skills_fit"), value: (skillsMatch(f).map { "\($0) — " } ?? "") + (names.isEmpty ? "—" : names))
                }
            }
            if let files = o.attachments, !files.isEmpty {
                FlCard(icon: "paperclip", title: L("fl_files")) {
                    ForEach(files, id: \.id) { a in
                        Button {
                            guard let path = a.path else { return }
                            opening = a.id
                            Task {
                                if !(await flOpenFile(path: path, name: a.name ?? "file")) { error = L("fl_file_open_failed") }
                                opening = nil
                            }
                        } label: {
                            HStack(spacing: 8) {
                                if opening == a.id { ProgressView().tint(wa.accent).scaleEffect(0.8) } else {
                                    Image(systemName: "doc.text").font(.system(size: 15)).foregroundStyle(wa.accent)
                                }
                                Text(verbatim: a.name ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.accent).lineLimit(1)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 10)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .disabled(opening != nil || a.path == nil)
                    }
                }
            }
            FlNotices(notice: notice, error: error)
            if can.choose == true && o.overCap == true { FlNotice(text: L("fl_over_cap_note"), icon: "exclamationmark.triangle.fill", color: flRed) }
            Spacer(minLength: 16)
        }
    }

    /// FL-308: talking to them before choosing (phone numbers and links are not allowed until the agreement).
    private func openChat(_ o: FreelanceOfferDto) {
        if let existing = o.chat?.uuid { onChat(existing); return }
        busy = true
        sheet = "chat"
        error = nil
        Task {
            do {
                if let id = try await FreelanceApi.openChat(projectUuid, offerUuid).data?.uuid { onChat(id) } else { error = L("fl_error_generic") }
            } catch {
                self.error = flError(error)
            }
            busy = false
            sheet = nil
        }
    }

    /// Choose, talk and the rest, always in reach at the foot.
    @ViewBuilder
    private func footer(_ o: FreelanceOfferDto) -> some View {
        let can = o.can ?? FreelanceOfferCanDto()
        let canTalk = can.chat == true || o.chat != nil
        let hasMore = (can.shortlist == true && o.state != "shortlisted") || can.unshortlist == true || can.reject == true
        let unread = o.chat?.unread ?? 0
        let talk = L("fl_chat_open") + (unread > 0 ? " (\(unread))" : "")
        if can.choose == true || canTalk || hasMore {
            HStack(spacing: 10) {
                if can.choose == true {
                    FlAction(text: L("fl_choose_offer"), icon: "person.2.fill", tone: .primary, enabled: o.overCap != true, loading: busy && sheet == nil) { sheet = "choose" }
                }
                if canTalk {
                    if can.choose == true {
                        FootIcon(icon: "bubble.left.and.bubble.right.fill", label: talk, badge: unread) { openChat(o) }
                    } else {
                        FlAction(text: talk, icon: "bubble.left.and.bubble.right.fill", loading: busy && sheet == "chat") { openChat(o) }
                    }
                }
                if hasMore { FootIcon(icon: "ellipsis", label: L("flo_more_title")) { sheet = "more" } }
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .background(wa.night ? wa.darkCard : Color.white)
        }
    }
}

/// What choosing costs, said before it is done (FL-309).
private struct ChooseSheet: View {
    @Environment(\.wa) private var wa
    let o: FreelanceOfferDto
    let busy: Bool
    let onDismiss: () -> Void
    let onConfirm: () -> Void

    var body: some View {
        let cost = o.clientCost
        FlSheet(icon: "person.2.fill", title: L("fl_choose_title", o.freelancer?.name ?? "—"), text: L("fl_choose_note")) {
            VStack(alignment: .leading, spacing: 8) {
                FlSumLine(label: L("fl_price"), value: flAmount(cost?.priceMinor ?? o.priceMinor, o.currency))
                FlSumLine(label: L("fl_duration"), value: L("fl_days", o.durationDays ?? 0))
                if (cost?.clientFeeMinor ?? 0) > 0 { FlSumLine(label: L("fl_client_fee"), value: flAmount(cost?.clientFeeMinor, o.currency)) }
                FlSumLine(label: L("fl_total"), value: flAmount(cost?.totalMinor ?? o.priceMinor, o.currency), bold: true)
            }
            .padding(14)
            .background(wa.accent.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            LoyaltyOfferNote(bp: o.loyaltyBp)
            FlAction(text: L("fl_choose_confirm"), icon: "person.2.fill", tone: .primary, loading: busy, action: onConfirm)
            FlAction(text: L("fl_not_now"), action: onDismiss)
        }
    }
}

struct FlSumLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var bold = false

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(14)).foregroundStyle(wa.settingsMut)
            Spacer()
            Text(verbatim: value).font(.dorr(bold ? 16 : 14, bold ? .heavy : .semibold)).foregroundStyle(wa.settingsInk)
        }
    }
}

private struct RejectSheet: View {
    @Environment(\.wa) private var wa
    let busy: Bool
    let onDismiss: () -> Void
    let onConfirm: (String?) -> Void
    @State private var reason = ""

    var body: some View {
        FlSheet(icon: "xmark.circle.fill", title: L("fl_reject_offer"), text: L("fl_reject_hint")) {
            DorrTextArea(value: Binding(get: { reason }, set: { if $0.count <= 500 { reason = $0 } }), label: L("fl_reject_reason"), icon: "text.alignleft", minHeight: 90)
            FlAction(text: L("fl_reject_confirm"), icon: "xmark.circle.fill", tone: .danger, loading: busy) {
                let t = reason.trimmingCharacters(in: .whitespacesAndNewlines)
                onConfirm(t.isEmpty ? nil : t)
            }
            FlAction(text: L("fl_not_now"), action: onDismiss)
        }
    }
}

// MARK: - Opening a file (never a public link)

/// Downloads a file the server gave as a `path` with the client's own sign-in and shows it in Quick Look
/// (Android hands it to a viewer app through a chooser).
@MainActor
func flOpenFile(path: String, name: String) async -> Bool {
    guard let data = try? await FreelanceApi.file(path) else { return false }
    let safe = String(name.filter { $0.isLetter || $0.isNumber || "._- ".contains($0) })
    let dir = FileManager.default.temporaryDirectory.appendingPathComponent("exports", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let url = dir.appendingPathComponent(safe.isEmpty ? "file" : safe)
    guard (try? data.write(to: url)) != nil else { return false }
    guard let root = UIApplication.shared.connectedScenes.compactMap({ ($0 as? UIWindowScene)?.keyWindow }).first?.rootViewController else { return false }
    var top = root
    while let presented = top.presentedViewController { top = presented }
    let preview = QLPreviewController()
    let source = FlPreviewSource(url: url)
    preview.dataSource = source
    objc_setAssociatedObject(preview, &FlPreviewSource.key, source, .OBJC_ASSOCIATION_RETAIN)
    top.present(preview, animated: true)
    return true
}

private final class FlPreviewSource: NSObject, QLPreviewControllerDataSource {
    static var key = 0
    let url: URL

    init(url: URL) { self.url = url }

    func numberOfPreviewItems(in controller: QLPreviewController) -> Int { 1 }
    func previewController(_ controller: QLPreviewController, previewItemAt index: Int) -> QLPreviewItem { url as NSURL }
}

// MARK: - The freelancer

@MainActor
struct FreelancerProfilePage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let offerUuid: String
    let onBack: () -> Void
    var onBookConsultation: (String) -> Void = { _ in }
    @State private var profile: FreelancerProfileDto?
    @State private var error: String?
    // FL-L13: the portfolio work being reported as not theirs.
    @State private var reporting: String?

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_freelancer_profile"), onBack: onBack)
            if let p = profile {
                ScrollView { content(p).padding(.horizontal, 16) }
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do { profile = try await FreelanceApi.offerFreelancer(projectUuid, offerUuid).data } catch { self.error = flError(error) }
        }
        .dorrDialog(isPresented: Binding(get: { reporting != nil }, set: { if !$0 { reporting = nil } }), bottom: true) {
            if let id = reporting { ReportWorkSheet(subjectType: "portfolio_item", subject: id) { reporting = nil } }
        }
    }

    private func toggleFavorite(_ p: FreelancerProfileDto) {
        guard let uuid = p.uuid else { return }
        let keep = !(p.isFavorite ?? false)
        profile?.isFavorite = keep
        Task {
            do {
                if keep { _ = try await FreelanceApi.addFavorite(uuid) } else { _ = try await FreelanceApi.removeFavorite(uuid) }
            } catch {
                profile?.isFavorite = !keep
            }
        }
    }

    @ViewBuilder
    private func content(_ p: FreelancerProfileDto) -> some View {
        let head = FreelanceOfferFreelancerDto(uuid: p.uuid, name: p.name, avatarUrl: p.avatarUrl, verified: p.verified, rating: p.rating, reviewsCount: p.reviewsCount)
        let fav = p.isFavorite ?? false
        VStack(alignment: .leading, spacing: 12) {
            VStack(spacing: 4) {
                FlAvatar(name: p.name, url: p.avatarUrl, size: 76)
                HStack(spacing: 4) {
                    Text(verbatim: p.name ?? "—").font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                    if p.verified == true { VerifiedMark(size: 18) }
                }
                PlanBadge(badge: p.badge, compact: false)
                SkillBadges(badges: p.skillBadges ?? [], max: 6, compact: false).padding(.top, 4)
                // FL-306: keep this freelancer to find them again.
                Button { toggleFavorite(p) } label: {
                    HStack(spacing: 4) {
                        Image(systemName: fav ? "heart.fill" : "heart").font(.system(size: 15))
                        Text(L(fav ? "fl_favorite_on" : "fl_favorite_add")).font(.dorr(12, .bold))
                    }
                    .foregroundStyle(flRed)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
                if let h = p.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center) }
                Text(verbatim: [ratingText(head), L("fl_completeness", p.completeness ?? 0), p.yearsOfExperience.map { L("fl_years_exp", $0) }]
                    .compactMap { $0 }.joined(separator: "  ·  "))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                // FL-L07: talk to them as a paid consultation.
                if let items = p.consultations, !items.isEmpty { ConsultTeaser(items: items, onBook: onBookConsultation).padding(.top, 8) }
                Text(L("fl_contact_in_dorr")).font(.dorr(11)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            .padding(16)
            .settingsSurface(18)
            // FL-L09: what returning clients get from this freelancer, and where I stand.
            HStack(spacing: 8) {
                FlStat(icon: "checkmark.seal.fill", label: L("fl_st_completed"), value: "\(p.completedProjects ?? 0)")
                FlStat(icon: "clock", label: L("fl_st_on_time"), value: p.onTimeRate.map { "\($0)%" } ?? "—")
                FlStat(icon: "photo.on.rectangle", label: L("fl_portfolio"), value: "\(p.portfolio?.count ?? 0)")
            }
            LoyaltyCard(view: p.loyalty)
            if let bio = p.bio, !bio.isEmpty {
                FlCard(icon: "info.circle.fill", title: L("fl_about")) {
                    Text(verbatim: bio).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(5)
                }
            }
            let specialties = p.specialties ?? []
            let skills = p.skills ?? []
            if !specialties.isEmpty || !skills.isEmpty {
                FlCard(icon: "bolt.fill", title: L("fl_skills")) {
                    if !specialties.isEmpty { FlField(label: L("fl_specialty"), value: specialties.compactMap(\.name).joined(separator: "، ")) }
                    if !skills.isEmpty {
                        let matching = Set((p.matchingSkills ?? []).map(\.id))
                        FlowLayout(spacing: 6) {
                            ForEach(skills, id: \.id) { s in
                                let hit = matching.contains(s.id)
                                Text(verbatim: s.name ?? "").font(.dorr(12, .semibold)).foregroundStyle(hit ? .white : wa.settingsInk)
                                    .padding(.horizontal, 10).padding(.vertical, 4)
                                    .background(hit ? flGreen : wa.accent.opacity(0.08), in: Capsule())
                            }
                        }
                        if !matching.isEmpty { Text(L("fl_skills_green")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
                    }
                }
            }
            let languages = p.languages ?? []
            if !languages.isEmpty || (p.city?.isEmpty == false) {
                FlCard(icon: "globe", title: L("fl_languages")) {
                    if !languages.isEmpty { FlField(label: L("fl_languages"), value: languages.joined(separator: "، ")) }
                    if let city = p.city, !city.isEmpty { FlField(label: L("fl_city"), value: city) }
                    if let since = p.memberSince { FlField(label: L("fl_member_since"), value: "\u{200E}" + String(since.prefix(7))) }
                }
            }
            if let ex = p.experiences, !ex.isEmpty {
                FlCard(icon: "briefcase.fill", title: L("fl_work_history")) {
                    ForEach(Array(ex.enumerated()), id: \.offset) { _, e in
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: [e.title, e.company].compactMap { $0 }.joined(separator: " — ")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: "\u{200E}\(e.startedOn ?? "") – " + (e.isCurrent == true ? L("fl_now") : (e.endedOn ?? ""))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            if let d = e.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsInk).lineLimit(4) }
                        }
                        .padding(.vertical, 4)
                    }
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
            // FL-701 / FL-703: what clients said, and their numbers.
            let reviews = p.reviews ?? []
            if !reviews.isEmpty || (p.completedProjects ?? 0) > 0 {
                FlCard(icon: "star.fill", title: L("fl_rv_about")) {
                    Text(verbatim: [p.completedProjects.map { L("fl_st_completed_n", $0) }, p.onTimeRate.map { L("fl_st_on_time_n", $0) }]
                        .compactMap { $0 }.joined(separator: "  ·  "))
                        .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    ForEach(Array(reviews.enumerated()), id: \.offset) { _, r in
                        VStack(alignment: .leading, spacing: 2) {
                            ReviewStars(stars: r.stars ?? 0)
                            if let c = r.comment { Text(verbatim: c).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
                            Text(verbatim: [r.kind == "consultation" ? L("cs_review_kind") : nil, r.project, r.at.map { "\u{200E}" + String($0.prefix(10)) }].compactMap { $0 }.joined(separator: " · "))
                                .font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            if let certs = p.certificates, !certs.isEmpty {
                FlCard(icon: "graduationcap.fill", title: L("fl_certificates")) {
                    ForEach(Array(certs.enumerated()), id: \.offset) { _, c in
                        Text(verbatim: [c.title, c.issuer, c.issuedOn.map { "\u{200E}" + $0 }].compactMap { $0 }.joined(separator: " · ")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                }
            }
            Spacer(minLength: 20)
        }
    }
}

// MARK: - The freelancers I keep (FL-306)

@MainActor
struct FavoritesPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    @StateObject private var paged = PagedRows<FreelanceFavoriteDto>(key: { $0.uuid })

    var body: some View {
        let list: [FreelanceFavoriteDto]? = paged.loaded && (paged.error == nil || !paged.rows.isEmpty) ? paged.rows : nil
        VStack(spacing: 0) {
            FlPageHeader(title: L("fl_favorites"), onBack: onBack)
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 10) {
                    if list == nil && paged.error == nil {
                        ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 80) }
                    } else if let rows = list, rows.isEmpty {
                        WaEmpty(icon: "heart.fill", tone: .pink, title: L("fl_favorites"), text: L("fl_favorites_empty"))
                    } else if let rows = list {
                        ForEach(Array(rows.enumerated()), id: \.element.uuid) { i, f in
                            HStack(spacing: 10) {
                                FlAvatar(name: f.name, url: f.avatarUrl)
                                VStack(alignment: .leading, spacing: 0) {
                                    HStack(spacing: 4) {
                                        Text(verbatim: f.name ?? "—").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                        if f.verified == true { VerifiedMark() }
                                    }
                                    if let h = f.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                                    Text(verbatim: L("fl_completeness", f.completeness ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                                Button {
                                    Task { if (try? await FreelanceApi.removeFavorite(f.uuid)) != nil { paged.remove { $0.uuid == f.uuid } } }
                                } label: {
                                    Image(systemName: "heart.fill").font(.system(size: 18)).foregroundStyle(flRed).frame(width: 34, height: 34)
                                }
                                .buttonStyle(.plain)
                                .accessibilityLabel(L("fl_favorite_remove"))
                            }
                            .padding(14)
                            .settingsSurface(18)
                            .onAppear { paged.appeared(i) }
                        }
                    }
                    PagedSpinner(paged: paged)
                    PagedError(paged: paged)
                }
                .padding(.horizontal, 16).padding(.vertical, 10)
            }
            .refreshable { await paged.reload() }
        }
        .task { paged.reset { page in try await FreelanceApi.favorites(page: page) } }
        .task(id: network.reconnectTick) { await paged.reload() }
    }
}
