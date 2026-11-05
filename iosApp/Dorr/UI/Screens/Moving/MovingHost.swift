import SwiftUI

// androidApp `moving/MovingHost.kt` — furniture moving for the customer, opened from the services (module `moving`) or
// a notification: «طلباتي», the new request's steps, the request page that follows it by status, and what comes
// after. The payment screen opens over it (MainScreen draws it above). No page asks the server on a timer: see
// `MovingLive`.

/// A request to open when the moving screen shows: set by a tapped `moving_*` notification.
@MainActor
final class MovingLink: ObservableObject {
    static let shared = MovingLink()
    @Published var pending: String?

    func show(_ uuid: String) { pending = uuid }
}

/// The moving pages. `requestUuid`: the request a page is about, which keeps its live channel open.
enum MvRoute: Equatable {
    case home
    /// `atReview`: a request published again opens on its review, with what it still needs marked.
    case wizard(uuid: String?, atReview: Bool)
    case request(String)
    case offers(String, decimals: Int)
    case offerDetails(String, offer: MovingOfferDto, decimals: Int)
    case changes(String)
    case report(String, currency: String?, decimals: Int)
    case inventory(String, items: [MovingInventoryItemDto])
    case invoice(String, decimals: Int)
    case tickets(String)
    case newTicket(String)
    case ticket(String, ticket: String)

    var requestUuid: String? {
        switch self {
        case .home: return nil
        case .wizard: return nil
        case .request(let u), .offers(let u, _), .offerDetails(let u, _, _), .changes(let u), .report(let u, _, _),
             .inventory(let u, _), .invoice(let u, _), .tickets(let u), .newTicket(let u), .ticket(let u, _):
            return u
        }
    }

    /// The page's identity on screen (a new one is a new page with its own state).
    var key: String {
        switch self {
        case .home: return "home"
        case .wizard(let u, let review): return "wizard:\(u ?? "new"):\(review)"
        case .request(let u): return "request:\(u)"
        case .offers(let u, _): return "offers:\(u)"
        case .offerDetails(let u, let offer, _): return "offer:\(u):\(offer.uuid)"
        case .changes(let u): return "changes:\(u)"
        case .report(let u, _, _): return "report:\(u)"
        case .inventory(let u, _): return "inventory:\(u)"
        case .invoice(let u, _): return "invoice:\(u)"
        case .tickets(let u): return "tickets:\(u)"
        case .newTicket(let u): return "newTicket:\(u)"
        case .ticket(let u, let t): return "ticket:\(u):\(t)"
        }
    }
}

/// The page stack, and whether the service turned out to be off in this country.
@MainActor
final class MovingNav: ObservableObject {
    @Published private(set) var stack: [MvRoute] = [.home]
    @Published private(set) var forward = true
    @Published var serviceOff = false
    /// Bumped on every move, so the page change animates once.
    @Published private(set) var version = 0

    var top: MvRoute { stack.last ?? .home }

    /// The request the open pages are about (its channel stays open while any of them shows).
    var liveUuid: String? { stack.last { $0.requestUuid != nil }?.requestUuid }

    func push(_ route: MvRoute) {
        forward = true
        stack.append(route)
        version += 1
    }

    @discardableResult
    func pop() -> Bool {
        guard stack.count > 1 else { return false }
        forward = false
        stack.removeLast()
        version += 1
        return true
    }

    /// This page gives way to another (a draft published, a request still being a draft).
    func replace(_ route: MvRoute) {
        forward = true
        stack[stack.count - 1] = route
        version += 1
    }

    /// From anywhere (a notification): the home page with the request on it.
    func openRequest(_ uuid: String) {
        forward = true
        stack = [.home, .request(uuid)]
        version += 1
    }

    /// Back to the request's own page once paid or done (the page reloads when it shows).
    func backToRequest(_ uuid: String) {
        forward = false
        while stack.count > 1 && stack.last != .request(uuid) { stack.removeLast() }
        if stack.last != .request(uuid) { stack.append(.request(uuid)) }
        version += 1
    }
}

@MainActor
struct MovingHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = MovingLink.shared
    @StateObject private var nav = MovingNav()
    let onExit: () -> Void
    /// The private channel open now: one at a time, the request the open pages are about. Kept here, above the pages,
    /// so that moving from one page to another of the same request never drops it.
    @State private var watching: String?

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            page(nav.top)
                .id(nav.top.key)
                .transition(.asymmetric(insertion: .move(edge: nav.forward ? .trailing : .leading).combined(with: .opacity), removal: .opacity))
        }
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: nav.version)
        // The admin's map engine, as on the rides screen.
        .task { await MapEngine.shared.refresh() }
        .task(id: link.pending) {
            guard let uuid = link.pending else { return }
            link.pending = nil
            nav.openRequest(uuid)
        }
        .onAppear { follow(nav.liveUuid) }
        .onChange(of: nav.liveUuid) { follow($0) }
        .onDisappear {
            if let watching { ChatRealtime.shared.unwatchMoving(watching) }
            watching = nil
        }
        #if DEBUG
        // -DorrMovingRoute wizard|request:<uuid> (with -DorrMainRoute moving): straight to that page.
        .task {
            guard let route = UserDefaults.standard.string(forKey: "DorrMovingRoute") else { return }
            if route == "wizard" { nav.push(.wizard(uuid: nil, atReview: false)) }
            if route.hasPrefix("request:") { nav.openRequest(String(route.dropFirst(8))) }
        }
        #endif
    }

    private func follow(_ uuid: String?) {
        guard uuid != watching else { return }
        if let watching { ChatRealtime.shared.unwatchMoving(watching) }
        if let uuid { ChatRealtime.shared.watchMoving(uuid) }
        watching = uuid
    }

    @ViewBuilder
    private func page(_ route: MvRoute) -> some View {
        switch route {
        case .home: MovingHomePage(nav: nav, onExit: onExit)
        case .wizard(let uuid, let atReview): MovingWizard(initialUuid: uuid, nav: nav, atReview: atReview)
        case .request(let uuid): MovingRequestPage(uuid: uuid, nav: nav)
        case .offers(let uuid, let decimals): MovingOffersPage(uuid: uuid, decimals: decimals, nav: nav)
        case .offerDetails(let uuid, let offer, let decimals): MovingOfferDetailsPage(uuid: uuid, offer: offer, decimals: decimals, nav: nav)
        case .changes(let uuid): MovingChangesPage(uuid: uuid, nav: nav)
        case .report(let uuid, let currency, let decimals): MovingReportPage(uuid: uuid, currency: currency, decimals: decimals, nav: nav)
        case .inventory(_, let items): MovingInventoryPage(items: items, nav: nav)
        case .invoice(let uuid, let decimals): MovingInvoicePage(uuid: uuid, decimals: decimals, nav: nav)
        case .tickets(let uuid): MovingTicketsPage(uuid: uuid, nav: nav)
        case .newTicket(let uuid): MovingNewTicketPage(uuid: uuid, nav: nav)
        case .ticket(_, let ticket): MovingTicketPage(ticketUuid: ticket, nav: nav)
        }
    }
}
