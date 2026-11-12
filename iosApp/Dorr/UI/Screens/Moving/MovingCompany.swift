// androidApp `ui/screens/moving/MovingCompany.kt`
import SwiftUI

// Knowing the company before choosing it (owner, 2026-11-12): its rating by criteria — as the rider sees the captain's — and
// where its branches are, the one that answered first, each with how far it is from the pickup point and a tap to open it on the
// map.

private func one(_ v: Double) -> String { String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), v) }

/// The company's stars: the overall average big, then each criterion with its bar and number (as rides score the captain).
struct MvCompanyRatingCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let e: MovingOfferEstablishmentDto

    var body: some View {
        MvCard {
            MvCardTitle(text: L("mv_company_rating")).padding(.bottom, 8)
            if e.count == 0 || e.average == nil {
                Text(L("mv_company_rating_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            } else if let average = e.average {
                HStack(spacing: 10) {
                    Text(verbatim: one(average)).font(.dorr(30, .black)).foregroundStyle(wa.settingsInk)
                    VStack(alignment: .leading, spacing: 2) {
                        starsRow(average, 16)
                        Text(verbatim: L("mv_ratings_n", e.count) + " · " + L("mv_finished_moves", e.jobs)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                let scored = e.criteria.filter { $0.average != nil }
                if !scored.isEmpty {
                    VStack(spacing: 8) {
                        ForEach(scored, id: \.key) { c in bar(c.label ?? c.key, c.average ?? 0) }
                    }
                    .padding(.top, 10)
                }
            }
        }
    }

    private func starsRow(_ average: Double, _ size: CGFloat) -> some View {
        let full = Int(average.rounded())
        return HStack(spacing: 1) {
            ForEach(1...5, id: \.self) { i in
                Image(systemName: i <= full ? "star.fill" : "star").font(.system(size: size)).foregroundStyle(i <= full ? AppColors.warning : wa.settingsMut)
            }
        }
    }

    private func bar(_ label: String, _ average: Double) -> some View {
        HStack(spacing: 8) {
            Text(verbatim: label).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                .frame(maxWidth: .infinity, alignment: .leading)
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.mvInset(onPanel))
                    Capsule().fill(AppColors.warning).frame(width: proxy.size.width * CGFloat(min(max(average / 5, 0), 1)))
                }
            }
            .frame(height: 7)
            .frame(maxWidth: .infinity)
            .environment(\.layoutDirection, .leftToRight)
            Text(verbatim: one(average)).font(.dorr(13, .black)).foregroundStyle(wa.settingsInk)
        }
    }
}

/// The company's branches: the one that answered first, then the nearest; the address, the distance and the map.
struct MvCompanyBranchesCard: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let branches: [MovingOfferBranchDto]

    var body: some View {
        if !branches.isEmpty {
            MvCard {
                MvCardTitle(text: L("mv_company_branches")).padding(.bottom, 8)
                ForEach(Array(branches.enumerated()), id: \.offset) { index, b in
                    if index > 0 { Rectangle().fill(wa.mvInset(onPanel)).frame(height: 1).padding(.vertical, 8) }
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: b.answering ? "building.2.fill" : "mappin.circle.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                            .frame(width: 38, height: 38)
                            .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        VStack(alignment: .leading, spacing: 4) {
                            Text(verbatim: b.name ?? L("mv_branch_unnamed")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            FlowLayout(spacing: 6, lineSpacing: 4) {
                                if b.answering { MvChip(text: L("mv_branch_answering"), color: wa.accent) }
                                if b.isMain { MvChip(text: L("mv_branch_main"), color: AppColors.info) }
                                if let km = b.distanceKm { MvChip(text: L("mv_branch_distance", one(km)), color: wa.settingsMut) }
                            }
                            if let address = b.address, !address.trimmingCharacters(in: .whitespaces).isEmpty {
                                Text(verbatim: address).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        if let lat = b.lat, let lng = b.lng {
                            Button { openMap(lat, lng, b.name) } label: {
                                Image(systemName: "map.fill").font(.system(size: 15)).foregroundStyle(wa.accent)
                                    .frame(width: 38, height: 38)
                                    .background(wa.mvInset(onPanel), in: Circle())
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel(L("mv_branch_on_map"))
                        }
                    }
                }
            }
        }
    }

    private func openMap(_ lat: Double, _ lng: Double, _ name: String?) {
        let label = (name ?? "").addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        if let url = URL(string: "http://maps.apple.com/?ll=\(lat),\(lng)&q=\(label)") { UIApplication.shared.open(url) }
    }
}

/// The rating's criteria, as rides ask the rider: a row of stars per criterion, each starting at the overall stars and moved on
/// its own. `scores` keeps what was moved; an untouched criterion counts as the overall stars.
struct MvCriteriaStars: View {
    @Environment(\.wa) private var wa
    @Environment(\.mvOnPanel) private var onPanel
    let criteria: [MovingCriterionDto]
    let overall: Int
    @Binding var scores: [String: Int]

    var body: some View {
        if !criteria.isEmpty && overall > 0 {
            VStack(spacing: 0) {
                ForEach(criteria, id: \.key) { c in
                    let value = scores[c.key] ?? overall
                    HStack(spacing: 2) {
                        Text(verbatim: c.label ?? c.key).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        ForEach(1...5, id: \.self) { i in
                            Button { scores[c.key] = i } label: {
                                Image(systemName: i <= value ? "star.fill" : "star").font(.system(size: 20))
                                    .foregroundStyle(i <= value ? AppColors.warning : wa.settingsMut)
                                    .frame(width: 28, height: 28)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .background(wa.mvInset(onPanel), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }
}
