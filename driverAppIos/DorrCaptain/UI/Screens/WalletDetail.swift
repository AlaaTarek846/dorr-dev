import SwiftUI

// driverApp `ui/WalletDetail.kt`.

/// What narrows the statement (D-213): the kind of row, money in or out, and a range of days.
struct StatementFilter: Equatable, Hashable {
    var type: String? = nil
    var direction: String? = nil
    var from: String? = nil
    var to: String? = nil

    var active: Bool { type != nil || direction != nil || from != nil || to != nil }
}

private let TYPES: [(String, String)] = [
    ("service_earning", "wl_ty_service_earning"),
    ("tip", "wl_ty_tip"),
    ("commission", "wl_ty_commission"),
    ("withdrawal", "wl_ty_withdrawal"),
    ("topup", "wl_ty_topup"),
    ("penalty", "wl_ty_penalty"),
]

/// The statement's filters: in / out, the kind of row, from–to — one scrolling row, each chip tapped again clears itself.
struct StatementFilters: View {
    @Environment(\.pal) private var pal
    let filter: StatementFilter
    let onChange: (StatementFilter) -> Void
    @State private var picking: String?

    init(_ filter: StatementFilter, onChange: @escaping (StatementFilter) -> Void) {
        self.filter = filter
        self.onChange = onChange
    }

    private func with(_ change: (inout StatementFilter) -> Void) {
        var f = filter
        change(&f)
        onChange(f)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    FilterPill(label: L("wl_filter_in"), selected: filter.direction == "credit") {
                        with { $0.direction = filter.direction == "credit" ? nil : "credit" }
                    }
                    FilterPill(label: L("wl_filter_out"), selected: filter.direction == "debit") {
                        with { $0.direction = filter.direction == "debit" ? nil : "debit" }
                    }
                    ForEach(TYPES, id: \.0) { key, label in
                        FilterPill(label: L(label), selected: filter.type == key) { with { $0.type = filter.type == key ? nil : key } }
                    }
                }
                .padding(.horizontal, 16)
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    FilterPill(label: L("wl_filter_from") + ": " + (filter.from.map { dateLabel($0) } ?? "—"), selected: filter.from != nil) {
                        if filter.from != nil { with { $0.from = nil } } else { picking = "from" }
                    }
                    FilterPill(label: L("wl_filter_to") + ": " + (filter.to.map { dateLabel($0) } ?? "—"), selected: filter.to != nil) {
                        if filter.to != nil { with { $0.to = nil } } else { picking = "to" }
                    }
                    if filter.active {
                        Text(L("wl_filter_clear"))
                            .font(.dorr(13, .semibold)).foregroundStyle(pal.orange)
                            .padding(.horizontal, 8).padding(.vertical, 6)
                            .contentShape(Capsule())
                            .onTapGesture { onChange(StatementFilter()) }
                    }
                }
                .padding(.horizontal, 16)
            }
        }
        .padding(.bottom, 8)
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            DatePickDialog(
                onPicked: { day in
                    if picking == "from" { with { $0.from = day } } else { with { $0.to = day } }
                },
                onDismiss: { picking = nil }
            )
        }
    }
}

private struct FilterPill: View {
    @Environment(\.pal) private var pal
    let label: String
    let selected: Bool
    let onClick: () -> Void

    var body: some View {
        Text(label)
            .font(.dorr(13, .semibold))
            .foregroundStyle(selected ? Color.white : pal.navy)
            .lineLimit(1)
            .padding(.horizontal, 14).padding(.vertical, 7)
            .background(Capsule().fill(selected ? pal.navy : pal.navy.opacity(0.07)))
            .animation(.tween(200), value: selected)
            .contentShape(Capsule())
            .onTapGesture(perform: onClick)
    }
}

/// One statement row in full (D-212): the amount and which way it went — said in words as well as
/// colour — the date, the note, the balances after it, the ride it came from, the other rows of the same
/// operation, and whether it was reversed. The sheet's content (show it in a `.sheet`).
struct TransactionDetailSheet: View {
    @Environment(\.pal) private var pal
    let uuid: String
    let w: WalletDto?
    let onClose: () -> Void

    @State private var detail: WalletDetailDto?
    @State private var failed = false

    init(uuid: String, w: WalletDto?, onClose: @escaping () -> Void) {
        self.uuid = uuid
        self.w = w
        self.onClose = onClose
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("wl_det_title")).font(.dorr(18, .bold)).foregroundStyle(pal.ink)
                VSpace(14)
                if let d = detail {
                    DetailBody(d: d, w: w)
                    VSpace(16)
                    Text(L("sp_not_me"))
                        .font(.dorr(13, .bold)).foregroundStyle(pal.danger)
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .contentShape(RoundedRectangle(cornerRadius: 12))
                        .onTapGesture {
                            SupportPrefill.shared.open = (category: "unauthorized_transaction", reference: uuid, text: "")
                            onClose()
                        }
                } else if failed {
                    Text(L("wl_det_failed")).font(.dorr(16)).foregroundStyle(pal.danger)
                } else {
                    CircularProgress(color: pal.orange, size: 28, lineWidth: 2)
                        .frame(maxWidth: .infinity)
                        .padding(24)
                }
            }
            .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 28)
        }
        .background(pal.card.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task(id: uuid) {
            switch await DriverApi.walletTransaction(uuid: uuid) {
            case .ok(let v, _): detail = v
            default: failed = true
            }
        }
    }
}

private struct DetailBody: View {
    @Environment(\.pal) private var pal
    let d: WalletDetailDto
    let w: WalletDto?

    var body: some View {
        let credit = d.direction == "credit"
        let color = credit ? pal.success : pal.danger
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                DorrIcon(credit ? "arrow.down" : "arrow.up", size: 22, tint: color)
                HSpace(8)
                VStack(alignment: .leading, spacing: 0) {
                    Text(d.typeLabel ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                    Text(L(credit ? "wl_det_in" : "wl_det_out")).font(.dorr(12, .semibold)).foregroundStyle(color)
                }
            }
            Text((credit ? "+" : "−") + amount(d.amountMinor ?? 0, w)).font(.dorr(30, .heavy)).foregroundStyle(color).padding(.top, 6)
            if let note = d.note, !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                Text(note).font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 4)
            }
            VSpace(12)

            DetailRow(label: L("wl_det_date"), value: whenText(d.createdAt))
            if let after = d.balanceAfterMinor {
                DetailRow(label: L("wl_det_balance_after") + " (" + bucketLabel(d.bucket ?? "") + ")", value: amount(after, w))
            }
            if let total = d.totalBalanceAfterMinor { DetailRow(label: L("wl_det_total_after"), value: amount(total, w)) }

            if let ref = d.reference, ref.type == "ride", let number = ref.number, !number.trimmingCharacters(in: .whitespaces).isEmpty {
                HStack(spacing: 0) {
                    DorrIcon("car.fill", size: 20, tint: pal.navy)
                    HSpace(10)
                    Text(L("wl_det_ride", number)).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(RoundedRectangle(cornerRadius: 14).fill(pal.navy.opacity(0.06)))
                .padding(.top, 12)
            }
            if d.reversed == true { NoticeRow(text: L("wl_det_reversed")) }
            if d.reversesUuid != nil { NoticeRow(text: L("wl_det_reversal")) }

            let related = d.related ?? []
            if !related.isEmpty {
                VSpace(16)
                Text(L("wl_det_related")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                ForEach(Array(related.enumerated()), id: \.offset) { _, r in
                    let rc = r.direction == "credit"
                    HStack(spacing: 0) {
                        DorrIcon(rc ? "arrow.down" : "arrow.up", size: 16, tint: rc ? pal.success : pal.danger)
                        HSpace(8)
                        Text(r.typeLabel ?? "").font(.dorr(13)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                        Text((rc ? "+" : "−") + amount(r.amountMinor ?? 0, w)).font(.dorr(13, .bold))
                            .foregroundStyle(rc ? pal.success : pal.danger)
                    }
                    .padding(.vertical, 6)
                }
            }
        }
    }
}

private struct DetailRow: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 0) {
            Text(label).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6)).frame(maxWidth: .infinity, alignment: .leading)
            Text(value).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
        }
        .padding(.vertical, 5)
    }
}

private struct NoticeRow: View {
    @Environment(\.pal) private var pal
    let text: String

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon("arrow.uturn.backward", size: 18, tint: pal.warning)
            HSpace(10)
            Text(text).font(.dorr(13)).foregroundStyle(pal.ink)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 14).fill(pal.warning.opacity(0.14)))
        .padding(.top, 12)
    }
}

private func bucketLabel(_ bucket: String) -> String {
    switch bucket {
    case "withdrawable": return L("wl_withdrawable")
    case "spend_only": return L("wl_spend_only")
    default: return L("wl_det_balance")
    }
}
