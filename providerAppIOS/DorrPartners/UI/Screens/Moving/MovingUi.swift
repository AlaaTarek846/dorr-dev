// providerApp `ui/screens/MovingUi.kt`
import SwiftUI

// What the moving and management screens share: the labels of every server value in the app language, the status pill, a
// label/value line, a card row that opens a page, and dates. All built from the copied kit (settingsSurface cards, the app colour,
// the same type sizes) — nothing new in the look.

let Green = Color(hex: 0x16A34A)
let Amber = Color(hex: 0xD97706)
let Red = Color(hex: 0xDC2626)
let Slate = Color(hex: 0x64748B)
let Blue = Color(hex: 0x2563EB)

/// "24 نوفمبر 2026 · 14:30" in the app language and the phone's zone; "" when there is no time.
func dateTimeText(_ iso: String?) -> String {
    let day = longDate(iso)
    if day.trimmingCharacters(in: .whitespaces).isEmpty { return "" }
    return day + " · \u{200E}" + timeOf(iso)
}

/// Text that isn't only spaces, else nil (Kotlin's `takeIf { it.isNotBlank() }`).
func notBlank(_ text: String?) -> String? {
    guard let text, !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return nil }
    return text
}

/// A small coloured pill: a status, "my offer", "exclusive"…
struct StatusPill: View {
    let text: String
    let color: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
            .padding(.horizontal, 9)
            .padding(.vertical, 3)
            .background(color.opacity(0.12), in: Capsule())
    }
}

/// A label and its value on one line (the value takes the room it needs, from the trailing side). Nothing when there is no value.
struct InfoLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String?

    init(_ label: String, _ value: String?) {
        self.label = label
        self.value = value
    }

    var body: some View {
        if let shown = notBlank(value) {
            HStack(alignment: .top, spacing: 10) {
                Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: shown).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            }
        }
    }
}

/// A card row that opens a page: an icon on a soft tile, a title, a line under it, an optional pill and the chevron.
struct RowCard: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let subtitle: String?
    var pill: AnyView?
    let onClick: (() -> Void)?

    init(icon: String, title: String, subtitle: String?, pill: AnyView? = nil, onClick: (() -> Void)?) {
        self.icon = icon
        self.title = title
        self.subtitle = subtitle
        self.pill = pill
        self.onClick = onClick
    }

    var body: some View {
        if let onClick {
            Button(action: onClick) { row }.buttonStyle(.plain)
        } else {
            row
        }
    }

    private var row: some View {
        HStack(spacing: 12) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 40, height: 40)
                .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                if let line = notBlank(subtitle) {
                    Text(verbatim: line).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(3).lineSpacing(2)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let pill { pill }
            if onClick != nil {
                Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity)
        .settingsSurface(20)
        .contentShape(Rectangle())
    }
}

/// A number tile of the home (the DORR app's quick cards): the count big, the label under it.
struct CountTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let count: Int
    let label: String
    let onClick: () -> Void

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 14, style: .continuous)
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 6) {
                HStack {
                    Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.red)
                        .frame(width: 32, height: 32)
                        .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                    Spacer(minLength: 0)
                    Text(verbatim: "\(count)").font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                }
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).lineSpacing(2)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 84, alignment: .topLeading)
            .background(wa.settingsCard, in: shape)
            .overlay(shape.stroke(wa.night ? wa.line : wa.red.opacity(0.10), lineWidth: 1))
            .contentShape(shape)
        }
        .buttonStyle(.plain)
    }
}

/// The extras the customer asked for, as pills.
struct ExtrasRow: View {
    @Environment(\.wa) private var wa
    let move: MovingRequestDto

    var body: some View {
        let shown = shownKeys
        if !shown.isEmpty {
            FlowLayout(spacing: 6) {
                ForEach(shown, id: \.self) { key in StatusPill(text: L(key), color: wa.red) }
            }
        }
    }

    private var shownKeys: [String] {
        var keys: [String] = []
        if move.extras?.packing == true { keys.append("mv_extra_packing") }
        if move.extras?.disassembly == true { keys.append("mv_extra_disassembly") }
        if move.extras?.crane == true { keys.append("mv_extra_crane") }
        if move.extras?.technician == true { keys.append("mv_extra_technician") }
        return keys
    }
}

/// "النزهة ← الملقا" (the arrow follows the language): the neighbourhoods only (the server never sends the exact address before the job is ours).
func routeText(_ move: MovingRequestDto) -> String {
    let from = notBlank(move.pickup?.area) ?? L("mv_area_unknown")
    let to = notBlank(move.dropoff?.area) ?? L("mv_area_unknown")
    return L("mv_route", from, to)
}

func loadTypeLabel(_ type: String?) -> String {
    switch type {
    case "single_piece": return L("mv_load_single")
    case "home": return L("mv_load_home")
    case "commercial": return L("mv_load_commercial")
    default: return type ?? ""
    }
}

func moveStatusLabel(_ status: String?) -> String {
    switch status {
    case "open": return L("mv_status_open")
    case "expired": return L("mv_status_expired")
    case "withdrawn": return L("mv_status_withdrawn")
    case "confirmed": return L("mv_status_confirmed")
    case "assigned": return L("mv_status_assigned")
    case "driver_on_way": return L("mv_status_driver_on_way")
    case "arrived": return L("mv_status_arrived")
    case "loading": return L("mv_status_loading")
    case "in_transit": return L("mv_status_in_transit")
    case "unloading": return L("mv_status_unloading")
    case "delivered": return L("mv_status_delivered")
    case "completed": return L("mv_status_completed")
    case "cancelled": return L("mv_status_cancelled")
    case "disputed": return L("mv_status_disputed")
    default: return status ?? ""
    }
}

func moveStatusColor(_ status: String?) -> Color {
    switch status {
    case "open", "confirmed": return Amber
    case "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading": return Blue
    case "delivered", "completed": return Green
    case "cancelled", "disputed", "expired", "withdrawn": return Red
    default: return Slate
    }
}

func offerStatusLabel(_ status: String?) -> String {
    switch status {
    case "awaiting_approval": return L("mv_offer_awaiting_approval")
    case "sent": return L("mv_offer_sent")
    case "withdrawn": return L("mv_offer_withdrawn")
    case "accepted": return L("mv_offer_accepted")
    case "declined": return L("mv_offer_declined")
    case "expired": return L("mv_offer_expired")
    default: return status ?? ""
    }
}

func offerStatusColor(_ status: String?) -> Color {
    switch status {
    case "awaiting_approval": return Amber
    case "sent": return Blue
    case "accepted": return Green
    default: return Slate
    }
}

func ticketStatusLabel(_ status: String?) -> String {
    switch status {
    case "new": return L("mv_ticket_new")
    case "awaiting_company": return L("mv_ticket_awaiting_company")
    case "company_replied": return L("mv_ticket_company_replied")
    case "escalated": return L("mv_ticket_escalated")
    case "resolved": return L("mv_ticket_resolved")
    case "unresolved": return L("mv_ticket_unresolved")
    default: return status ?? ""
    }
}

func ticketStatusColor(_ status: String?) -> Color {
    switch status {
    case "new", "awaiting_company": return Amber
    case "company_replied": return Blue
    case "escalated": return Red
    case "resolved": return Green
    default: return Slate
    }
}

func vehicleStatusLabel(_ status: String?) -> String {
    switch status {
    case "pending": return L("vh_status_pending")
    case "approved": return L("vh_status_approved")
    case "rejected": return L("vh_status_rejected")
    case "inactive": return L("vh_status_inactive")
    default: return status ?? ""
    }
}

func vehicleStatusColor(_ status: String?) -> Color {
    switch status {
    case "approved": return Green
    case "pending": return Amber
    case "rejected": return Red
    default: return Slate
    }
}

/// The name of an `includes` / extras key of an offer.
func includeLabel(_ key: String) -> String {
    switch key {
    case "packing": return L("mv_extra_packing")
    case "disassembly": return L("mv_extra_disassembly")
    case "crane": return L("mv_extra_crane")
    case "technician": return L("mv_extra_technician")
    case "tax": return L("mv_include_tax")
    default: return key
    }
}

// MARK: - permissions (§17)

/// The name of a permission key (config `provider.permissions`); an unknown key shows as it is.
func permissionLabel(_ key: String) -> String {
    switch key {
    case "requests.view": return L("perm_requests_view")
    case "offers.send": return L("perm_offers_send")
    case "offers.manage": return L("perm_offers_manage")
    case "offers.approve": return L("perm_offers_approve")
    case "requests.questions": return L("perm_requests_questions")
    case "jobs.assign": return L("perm_jobs_assign")
    case "jobs.track": return L("perm_jobs_track")
    case "jobs.change_price": return L("perm_jobs_change_price")
    case "jobs.cancel": return L("perm_jobs_cancel")
    case "driving.work": return L("perm_driving_work")
    case "driving.instant": return L("perm_driving_instant")
    case "fleet.vehicles": return L("perm_fleet_vehicles")
    case "fleet.drivers": return L("perm_fleet_drivers")
    case "wallet.view": return L("perm_wallet_view")
    case "wallet.withdraw": return L("perm_wallet_withdraw")
    case "tickets.handle": return L("perm_tickets_handle")
    case "ratings.view": return L("perm_ratings_view")
    case "branches.manage": return L("perm_branches_manage")
    case "members.manage": return L("perm_members_manage")
    case "reports.view": return L("perm_reports_view")
    default: return key
    }
}

/// One short line saying what the permission lets the person do.
func permissionHint(_ key: String) -> String {
    switch key {
    case "requests.view": return L("perm_requests_view_hint")
    case "offers.send": return L("perm_offers_send_hint")
    case "offers.manage": return L("perm_offers_manage_hint")
    case "offers.approve": return L("perm_offers_approve_hint")
    case "requests.questions": return L("perm_requests_questions_hint")
    case "jobs.assign": return L("perm_jobs_assign_hint")
    case "jobs.track": return L("perm_jobs_track_hint")
    case "jobs.change_price": return L("perm_jobs_change_price_hint")
    case "jobs.cancel": return L("perm_jobs_cancel_hint")
    case "driving.work": return L("perm_driving_work_hint")
    case "driving.instant": return L("perm_driving_instant_hint")
    case "fleet.vehicles": return L("perm_fleet_vehicles_hint")
    case "fleet.drivers": return L("perm_fleet_drivers_hint")
    case "wallet.view": return L("perm_wallet_view_hint")
    case "wallet.withdraw": return L("perm_wallet_withdraw_hint")
    case "tickets.handle": return L("perm_tickets_handle_hint")
    case "ratings.view": return L("perm_ratings_view_hint")
    case "branches.manage": return L("perm_branches_manage_hint")
    case "members.manage": return L("perm_members_manage_hint")
    case "reports.view": return L("perm_reports_view_hint")
    default: return ""
    }
}

func permissionGroupLabel(_ group: String) -> String {
    switch group {
    case "requests": return L("perm_group_requests")
    case "jobs": return L("perm_group_jobs")
    case "driving": return L("perm_group_driving")
    case "fleet": return L("perm_group_fleet")
    case "money": return L("perm_group_money")
    case "customers": return L("perm_group_customers")
    case "management": return L("perm_group_management")
    default: return group
    }
}

/// `branchManager`: the snake-case decoder rewrites this dictionary key (see `WorkspaceOptionsDto.orderedTemplates`).
func templateLabel(_ template: String) -> String {
    switch template {
    case "branch_manager", "branchManager": return L("perm_template_branch_manager")
    case "quoter": return L("perm_template_quoter")
    case "driver": return L("perm_template_driver")
    default: return template
    }
}

/// A picture read through `Api.mediaUrl`: `fit` for an admin's image of a type, else cropped to fill.
struct MediaThumb: View {
    let url: String?
    var fit = false

    var body: some View {
        AsyncImage(url: Api.mediaUrl(url)) { phase in
            if let image = phase.image {
                if fit { image.resizable().scaledToFit() } else { image.resizable().scaledToFill() }
            } else {
                Color.gray.opacity(0.12)
            }
        }
    }
}
