import Foundation

// androidApp `freelance/FreelanceRoute.kt` — a page of the client's freelance section. `key` is its written form
// ("show:<project>", "offer:<project>:<offer>", …): the form a tapped notification names (see `FreelanceLink`). The hub (`.home`)
// is where the section opens; the other pages open over it, and back returns to the page they were opened from.

enum FlRoute: Hashable {
    case home
    case projects
    case newProject
    case editProject(String)
    case project(String)
    case preview(String)
    case invitations(String)
    case offers(String)
    case compare(String, [String])
    case offer(String, String)
    case freelancer(String, String)
    case chat(String, String, String)
    case agreement(String)
    case dispute(String, String)
    case newDispute(String, String)
    case favorites
    case receipts
    // FL-L04: ready-made services and my orders.
    case services
    case service(String)
    case orders
    case order(String)
    // FL-L07: paid consultations.
    case consultMarket
    case consultations
    case consultation(String)
    case book(String)
    // FL-L09: special offers from freelancers I worked with, and my loyalty discounts.
    case specialOffers
    case specialOffer(String)
    case specialBook(String, String)
    case loyalty

    var key: String {
        switch self {
        case .home: return "home"
        case .projects: return "list"
        case .newProject: return "new"
        case .editProject(let p): return "edit:\(p)"
        case .project(let p): return "show:\(p)"
        case .preview(let p): return "preview:\(p)"
        case .invitations(let p): return "invites:\(p)"
        case .offers(let p): return "offers:\(p)"
        case .compare(let p, let o): return "compare:\(p):" + o.joined(separator: ",")
        case .offer(let p, let o): return "offer:\(p):\(o)"
        case .freelancer(let p, let o): return "freelancer:\(p):\(o)"
        case .chat(let p, let o, let r): return "chat:\(p):\(o):\(r)"
        case .agreement(let p): return "agreement:\(p)"
        case .dispute(let p, let d): return "dispute:\(p):\(d)"
        case .newDispute(let p, let m): return "dispute-new:\(p):\(m)"
        case .favorites: return "favorites"
        case .receipts: return "receipts"
        case .services: return "market"
        case .service(let s): return "svc:\(s)"
        case .orders: return "orders"
        case .order(let o): return "order:\(o)"
        case .consultMarket: return "cmarket"
        case .consultations: return "consults"
        case .consultation(let c): return "consult:\(c)"
        case .book(let o): return "book:\(o)"
        case .specialOffers: return "spos"
        case .specialOffer(let s): return "spo:\(s)"
        case .specialBook(let s, let o): return "spbook:\(s):\(o)"
        case .loyalty: return "loyalty"
        }
    }

    /// A page of its own (the hub, a list) stays alive under the pages opened over it, so it keeps its filter and scroll (Android
    /// keeps the same through its saveable state holder). A page of one item is drawn afresh when it is back on screen, so it
    /// reads itself again, as on Android.
    var keepsAlive: Bool {
        switch self {
        case .home, .projects, .favorites, .receipts, .services, .orders, .consultMarket, .consultations, .specialOffers, .loyalty: return true
        default: return false
        }
    }

    /// The page a written key names; the hub for anything it does not know (or a key with an id missing).
    static func parse(_ key: String) -> FlRoute {
        let p = key.components(separatedBy: ":")
        func at(_ i: Int) -> String? { i < p.count && !p[i].trimmingCharacters(in: .whitespaces).isEmpty ? p[i] : nil }
        let a = at(1), b = at(2), c = at(3)
        switch p[0] {
        case "home": return .home
        case "list": return .projects
        case "new": return .newProject
        case "favorites": return .favorites
        case "receipts": return .receipts
        case "market": return .services
        case "orders": return .orders
        case "cmarket": return .consultMarket
        case "consults": return .consultations
        case "spos": return .specialOffers
        case "loyalty": return .loyalty
        case "edit": return a.map { .editProject($0) } ?? .home
        case "show": return a.map { .project($0) } ?? .home
        case "preview": return a.map { .preview($0) } ?? .home
        case "invites": return a.map { .invitations($0) } ?? .home
        case "offers": return a.map { .offers($0) } ?? .home
        case "agreement": return a.map { .agreement($0) } ?? .home
        case "svc": return a.map { .service($0) } ?? .home
        case "order": return a.map { .order($0) } ?? .home
        case "consult": return a.map { .consultation($0) } ?? .home
        case "book": return a.map { .book($0) } ?? .home
        case "spo": return a.map { .specialOffer($0) } ?? .home
        case "compare": if let a, let b { return .compare(a, b.components(separatedBy: ",").filter { !$0.isEmpty }) }
        case "offer": if let a, let b { return .offer(a, b) }
        case "freelancer": if let a, let b { return .freelancer(a, b) }
        case "dispute": if let a, let b { return .dispute(a, b) }
        case "dispute-new": if let a, let b { return .newDispute(a, b) }
        case "spbook": if let a, let b { return .specialBook(a, b) }
        case "chat": if let a, let b, let c { return .chat(a, b, c) }
        default: break
        }
        return .home
    }
}

/// The open pages, the hub first and the page on screen last. Opening a page that is already open goes back to it (so «Browse»
/// from my orders and «My orders» from the services never pile up).
struct FlStack: Equatable {
    var routes: [FlRoute]

    static let start = FlStack(routes: [.home])

    var top: FlRoute { routes.last ?? .home }

    func open(_ route: FlRoute) -> FlStack {
        if let at = routes.firstIndex(where: { $0.key == route.key }) { return FlStack(routes: Array(routes.prefix(at + 1))) }
        return FlStack(routes: routes + [route])
    }

    /// The page on screen becomes `route` (a form that saved, a booking made): back skips the one replaced.
    func replace(_ route: FlRoute) -> FlStack {
        routes.count > 1 ? FlStack(routes: Array(routes.dropLast())).open(route) : open(route)
    }

    /// Back to the last page that `match`es, then `route` over it; just `route` over the current page when none matches.
    func after(_ match: (FlRoute) -> Bool, _ route: FlRoute) -> FlStack {
        if let at = routes.lastIndex(where: match) { return FlStack(routes: Array(routes.prefix(at + 1))).open(route) }
        return open(route)
    }

    func back() -> FlStack { routes.count > 1 ? FlStack(routes: Array(routes.dropLast())) : self }

    /// A page named by a notification (or a written list of keys joined by «|»): the hub under it, so back lands on the hub.
    static func read(_ text: String) -> FlStack {
        var seen = Set<String>()
        let routes = text.components(separatedBy: "|").filter { !$0.isEmpty }.map(FlRoute.parse)
            .filter { $0 != .home && seen.insert($0.key).inserted }
        return FlStack(routes: [.home] + routes)
    }
}
