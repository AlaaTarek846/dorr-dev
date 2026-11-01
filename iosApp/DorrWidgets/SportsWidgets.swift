import SwiftUI
import WidgetKit

// androidApp `widget/SportsWidgets.kt` — «My team» (my teams' live or next match) and «Live now» (the biggest matches
// on), on the home screen. The data comes from our server every 15 minutes and right after the app asks; crests are
// kept on the phone. Tapping opens the match in the app.

@main
struct DorrWidgetsBundle: WidgetBundle {
    var body: some Widget {
        MyTeamWidget()
        LiveWidget()
    }
}

struct SportsEntry: TimelineEntry {
    let date: Date
    let data: SpWidgetData?
}

struct SportsProvider: TimelineProvider {
    func placeholder(in context: Context) -> SportsEntry { SportsEntry(date: Date(), data: nil) }

    func getSnapshot(in context: Context, completion: @escaping (SportsEntry) -> Void) {
        completion(SportsEntry(date: Date(), data: SportsWidgetStore.load()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<SportsEntry>) -> Void) {
        Task {
            let data = await SportsWidgetStore.fetch()
            completion(Timeline(entries: [SportsEntry(date: Date(), data: data)], policy: .after(Date().addingTimeInterval(15 * 60))))
        }
    }
}

// MARK: - the look

private let white = Color.white
private let dim = Color.white.opacity(0.72)
private let liveRed = Color(red: 1, green: 0x4D / 255, blue: 0x6D / 255)
private let gold = Color(red: 0xFA / 255, green: 0xCC / 255, blue: 0x15 / 255)
private let backdrop = LinearGradient(colors: [Color(red: 0x0B / 255, green: 0x12 / 255, blue: 0x20 / 255), Color(red: 0x1E / 255, green: 0x3A / 255, blue: 0x8A / 255),
                                               Color(red: 0x0F / 255, green: 0x76 / 255, blue: 0x6E / 255)], startPoint: .topLeading, endPoint: .bottomTrailing)

/// The app's strings in the app's language (the extension carries its own copy of `Localizable.strings`).
private func T(_ key: String, _ args: CVarArg...) -> String {
    let code = SportsWidgetStore.language == "en" ? "en" : "ar"
    let bundle = Bundle.main.path(forResource: code, ofType: "lproj").flatMap(Bundle.init(path:)) ?? .main
    let format = bundle.localizedString(forKey: key, value: key, table: nil)
    return args.isEmpty ? format : String(format: format, arguments: args)
}

private func link(_ matchId: String?) -> URL { URL(string: "dorr://sports/match/\(matchId ?? "")")! }

private func scoreOrTime(_ m: SpWidgetMatch) -> String {
    switch m.status {
    case "live", "break", "finished": return "\(m.homeScore ?? 0) - \(m.awayScore ?? 0)"
    default: return m.localTime ?? ""
    }
}

private func clock(_ m: SpWidgetMatch) -> String {
    switch m.status {
    case "live": return m.minute.map { "\($0)'" + (m.minuteExtra.map { "+\($0)" } ?? "") } ?? T("spw_live")
    case "break": return T("sp_ht")
    case "finished": return T("sp_ft")
    default: return m.localDate ?? ""
    }
}

private struct WidgetCrest: View {
    let team: SpWidgetTeam?
    let size: CGFloat

    var body: some View {
        ZStack {
            Circle().fill(Color.white)
            if let id = team?.id, let data = SportsWidgetStore.crest(id), let image = UIImage(data: data) {
                Image(uiImage: image).resizable().scaledToFit().frame(width: size * 0.74, height: size * 0.74)
            } else {
                Text(verbatim: (team?.code ?? team?.name.map { String($0.prefix(2)) } ?? "?").uppercased())
                    .font(.system(size: size * 0.3, weight: .bold)).foregroundStyle(Color(red: 0x0B / 255, green: 0x12 / 255, blue: 0x20 / 255))
            }
        }
        .frame(width: size, height: size)
    }
}

private extension View {
    /// The navy-into-teal card, on iOS 17's container background when there is one.
    @ViewBuilder func sportsBackground() -> some View {
        if #available(iOS 17.0, *) {
            containerBackground(for: .widget) { backdrop }
        } else {
            background(backdrop)
        }
    }
}

// MARK: - «My team»

struct MyTeamWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: SportsWidgetStore.kind.myTeam, provider: SportsProvider()) { entry in
            MyTeamView(data: entry.data)
        }
        .configurationDisplayName(T("spw_myteam"))
        .description(T("spw_myteam_desc"))
        .supportedFamilies([.systemMedium, .systemSmall])
    }
}

private struct MyTeamView: View {
    let data: SpWidgetData?

    var body: some View {
        let m = data?.mine?.first
        Group {
            if data == nil {
                Text(T("spw_loading")).font(.system(size: 13)).foregroundStyle(dim)
            } else if data?.signedOut == true {
                Text(T("spw_sign_in")).font(.system(size: 13)).foregroundStyle(dim).multilineTextAlignment(.center)
            } else if let m {
                VStack(spacing: 0) {
                    HStack {
                        Text(verbatim: m.competition?.name ?? "").font(.system(size: 11)).foregroundStyle(dim).lineLimit(1)
                        Spacer()
                        let live = m.status == "live" || m.status == "break"
                        Text(verbatim: live ? "● " + clock(m) : clock(m)).font(.system(size: 11, weight: .bold)).foregroundStyle(live ? liveRed : dim)
                    }
                    Spacer(minLength: 4)
                    HStack(spacing: 0) {
                        side(m.home)
                        Text(verbatim: scoreOrTime(m)).font(.system(size: 26, weight: .bold)).foregroundStyle(white).frame(width: 96)
                            .environment(\.layoutDirection, .leftToRight)
                        side(m.away)
                    }
                    Spacer(minLength: 4)
                    if let n = data?.mine?.dropFirst().first {
                        Text(verbatim: T("spw_then", n.home?.name ?? "", n.away?.name ?? "", [n.localDate, n.localTime].compactMap { $0 }.joined(separator: " ")))
                            .font(.system(size: 10.5)).foregroundStyle(dim).lineLimit(1)
                    }
                }
            } else {
                VStack(spacing: 4) {
                    Text(verbatim: "⚽").font(.system(size: 22))
                    Text(T("spw_pick_teams")).font(.system(size: 13)).foregroundStyle(dim).multilineTextAlignment(.center)
                }
            }
        }
        .padding(2)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .widgetURL(link(m?.id))
        .sportsBackground()
        .environment(\.layoutDirection, SportsWidgetStore.language == "en" ? .leftToRight : .rightToLeft)
    }

    private func side(_ t: SpWidgetTeam?) -> some View {
        VStack(spacing: 3) {
            WidgetCrest(team: t, size: 46)
            Text(verbatim: t?.name ?? "").font(.system(size: 12, weight: .bold)).foregroundStyle(white).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - «Live now»

struct LiveWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: SportsWidgetStore.kind.live, provider: SportsProvider()) { entry in
            LiveView(data: entry.data)
        }
        .configurationDisplayName(T("spw_live_now"))
        .description(T("spw_live_desc"))
        .supportedFamilies([.systemLarge, .systemMedium])
    }
}

private struct LiveView: View {
    @Environment(\.widgetFamily) private var family
    let data: SpWidgetData?

    var body: some View {
        let list = data?.live ?? []
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(verbatim: "● ").font(.system(size: 12, weight: .bold)).foregroundStyle(liveRed)
                Text(T("spw_live_now")).font(.system(size: 13, weight: .bold)).foregroundStyle(white)
                Spacer()
                Text(verbatim: "DORR").font(.system(size: 10, weight: .bold)).foregroundStyle(gold)
            }
            .padding(.bottom, 6)
            if data == nil {
                Text(T("spw_loading")).font(.system(size: 12)).foregroundStyle(dim)
            } else if data?.signedOut == true {
                Text(T("spw_sign_in")).font(.system(size: 12)).foregroundStyle(dim)
            } else if list.isEmpty {
                Text(T("spw_no_live")).font(.system(size: 12)).foregroundStyle(dim)
            } else {
                ForEach(list.prefix(family == .systemLarge ? 6 : 3)) { m in
                    Link(destination: link(m.id)) { row(m) }
                }
            }
            Spacer(minLength: 0)
        }
        .padding(2)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .widgetURL(link(nil))
        .sportsBackground()
        .environment(\.layoutDirection, SportsWidgetStore.language == "en" ? .leftToRight : .rightToLeft)
    }

    private func row(_ m: SpWidgetMatch) -> some View {
        HStack(spacing: 6) {
            WidgetCrest(team: m.home, size: 22)
            Text(verbatim: m.home?.code ?? m.home?.name ?? "").font(.system(size: 12, weight: .bold)).foregroundStyle(white).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: scoreOrTime(m)).font(.system(size: 14, weight: .bold)).foregroundStyle(white).frame(width: 54).environment(\.layoutDirection, .leftToRight)
            Text(verbatim: m.away?.code ?? m.away?.name ?? "").font(.system(size: 12, weight: .bold)).foregroundStyle(white).lineLimit(1).frame(maxWidth: .infinity, alignment: .trailing)
            WidgetCrest(team: m.away, size: 22)
            Text(verbatim: clock(m)).font(.system(size: 10, weight: .bold)).foregroundStyle(liveRed).frame(width: 34)
        }
        .padding(.vertical, 4)
    }
}
