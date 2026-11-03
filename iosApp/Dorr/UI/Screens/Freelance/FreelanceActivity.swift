import SwiftUI

// androidApp `freelance/FreelanceActivity.kt` — «My activity» (the client redesign, batch F): what I bought or booked, in one
// page — my orders of ready-made services (with the monthly subscriptions), my consultations, and the special offers freelancers
// sent me (the count of those waiting on its part). Each part keeps its own statuses as chips; pulling down reads the page again.
// `start` is the part it opens on (0 orders, 1 consultations, 2 special offers). Nothing on a timer.

@MainActor
struct ActivityPage: View {
    @EnvironmentObject private var network: NetworkMonitor
    let start: Int
    let onBack: () -> Void
    let onOpenOrder: (String) -> Void
    let onBrowse: () -> Void
    let onOpenConsultation: (String) -> Void
    let onOpenSpecial: (String) -> Void
    @State private var part: Int?
    // FL-L09: how many special offers wait for me (the count on their part).
    @State private var waiting = 0

    var body: some View {
        let current = part ?? start
        VStack(spacing: 0) {
            FlPageHeader(title: L("fla_activity"), onBack: onBack, subtitle: L("fla_activity_line"))
            FlTabs(tabs: [FlTab(icon: "doc.text.fill", label: L("mk_my_orders")), FlTab(icon: "calendar.badge.checkmark", label: L("cs_mine_title")),
                          FlTab(icon: "tag.fill", label: L("spc_chip"), badge: waiting)], selected: current) { part = $0 }
                .padding(.horizontal, 16)
            switch current {
            case 0: MyOrdersPage(onBack: onBack, onOpen: onOpenOrder, onBrowse: onBrowse, embedded: true)
            case 1: MyConsultationsPage(onBack: onBack, onOpen: onOpenConsultation, embedded: true)
            default: SpecialOffersPage(onBack: onBack, onOpen: onOpenSpecial, embedded: true)
            }
        }
        // Pulling down reads the part on show again (the client's own gesture, not a timer): the same one read a notice brings.
        .refreshable { network.noticeArrived() }
        .task(id: network.reconnectTick) {
            if let n = try? await FreelanceSpecialOffersApi.waiting().data?.count { waiting = n }
        }
    }
}
