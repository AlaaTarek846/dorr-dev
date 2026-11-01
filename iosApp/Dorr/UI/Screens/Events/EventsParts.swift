import PhotosUI
import SwiftUI
import UIKit

// androidApp `events/EventsParts.kt`, `EventsPlaces.kt`, `EventCover.kt`.

let evPurple = Color(hex: 0x7C3AED)
let evHeroGradient = LinearGradient(colors: [Color(hex: 0x7C3AED), Color(hex: 0xDB2777), Color(hex: 0xF59E0B)], startPoint: .topLeading, endPoint: .bottomTrailing)

func evZone() -> String { TimeZone.current.identifier }

/// java.time with the app's language but Western digits (the default DecimalStyle).
private func evFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.calendar = Calendar(identifier: .gregorian)
    f.dateFormat = pattern
    return f
}

/// "Thu 5 Nov".
func dayText(_ d: Date) -> String { evFormatter("EEE d MMM").string(from: d) }

func evLocalDate(_ iso: String?) -> Date? {
    guard let iso else { return nil }
    let parts = iso.prefix(10).split(separator: "-").compactMap { Int($0) }
    guard parts.count == 3 else { return nil }
    return Calendar(identifier: .gregorian).date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2], hour: 12))
}

/// "2026-11-05 20:00" → that local moment (no zone).
private func evLocalDateTime(_ text: String?) -> Date? {
    guard let text else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = Calendar(identifier: .gregorian)
    for pattern in ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd HH:mm", "yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd'T'HH:mm"] {
        f.dateFormat = pattern
        if let d = f.date(from: text) { return d }
    }
    return nil
}

func evCategoryColor(_ c: DiscCategoryDto?) -> Color { momentColor(c?.color, evPurple) }

func evCategoryGradient(_ c: DiscCategoryDto?) -> LinearGradient {
    let base = evCategoryColor(c)
    return LinearGradient(colors: [base, base.scaled(0.6)], startPoint: .topLeading, endPoint: .bottomTrailing)
}

func evStatusLabel(_ status: String) -> String? {
    switch status {
    case "postponed": return L("ev_st_postponed")
    case "cancelled": return L("ev_st_cancelled")
    case "sold_out": return L("ev_st_sold_out")
    case "ended": return L("ev_st_ended")
    default: return nil
    }
}

func evStatusColor(_ status: String) -> Color {
    switch status {
    case "postponed": return Color(hex: 0xD97706)
    case "cancelled": return Color(hex: 0xDC2626)
    case "sold_out": return Color(hex: 0x2563EB)
    case "ended": return Color(hex: 0x6B7280)
    default: return Color(hex: 0x16A34A)
    }
}

/// "Thu 5 Nov · 20:00" in the event's own place.
func evWhenText(_ e: DiscEventDto) -> String {
    [evLocalDate(e.localDate).map(dayText), e.localTime].compactMap { $0 }.joined(separator: " · ")
}

/// "21:00 your time" when I'm in another zone.
func evMyTimeText(_ e: DiscEventDto) -> String? {
    evLocalDateTime(e.myTime).map { L("ev_my_time", evFormatter("EEE d MMM · HH:mm").string(from: $0)) }
}

func evPriceText(_ e: DiscEventDto) -> String {
    if e.isFree { return L("ev_free") }
    if let display = e.priceDisplay?.trimmingCharacters(in: .whitespaces), !display.isEmpty { return display }
    let raw = (e.priceText ?? "").trimmingCharacters(in: .whitespaces)
    let symbol = (e.currency?.symbol ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? e.currency?.code : e.currency?.symbol
    let compact = raw.replacingOccurrences(of: " ", with: "").replacingOccurrences(of: ",", with: "")
    if !compact.isEmpty, compact.allSatisfy({ $0.isNumber || $0 == "." }), let symbol, !symbol.isEmpty { return "\(compact) \(symbol)" }
    return raw.isEmpty ? L("ev_paid") : raw
}

func evDistanceText(_ km: Double) -> String {
    if km < 1 { return L("ev_m", max(1, Int((km * 1000).rounded()))) }
    if km < 10 { return L("ev_km", String(format: "%.1f", km)) }
    return L("ev_km", String(Int(km.rounded())))
}

/// Hide a description that is only the booking or official URL — those become buttons.
private func visibleDescription(_ e: DiscEventDto) -> String? {
    let text = (e.description ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    guard !text.isEmpty else { return nil }
    let links = [e.bookingUrl, e.sourceUrl].compactMap { $0?.trimmingCharacters(in: .whitespaces).trimmingCharacters(in: CharacterSet(charactersIn: "/")) }
    let compact = text.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
    if links.contains(where: { $0.caseInsensitiveCompare(compact) == .orderedSame }) { return nil }
    let onlyUrl = (text.hasPrefix("http://") || text.hasPrefix("https://")) && !text.contains(" ") && !text.contains("\n")
    if onlyUrl && !links.isEmpty { return nil }
    return e.description
}

private func monthShort(_ d: Date) -> String { evFormatter("MMM").string(from: d) }

private func dayOfMonth(_ d: Date) -> String { String(Calendar(identifier: .gregorian).component(.day, from: d)) }

/// Saving / unsaving an event, shared by every heart.
@MainActor
func evToggleInterest(_ e: DiscEventDto) async -> DiscEventDto? {
    do {
        if e.interested {
            _ = try await EventsApi.uninterest(e.id)
            var next = e
            next.interested = false
            next.notify = false
            next.interestedCount = max(e.interestedCount - 1, 0)
            await EventsStore.shared.changed()
            return next
        } else {
            let r = try await EventsApi.interest(e.id, notify: true, timezone: evZone()).data
            var next = e
            next.interested = true
            next.notify = r?.notify ?? true
            next.interestedCount = r?.interestedCount ?? e.interestedCount + 1
            await EventsStore.shared.changed()
            return next
        }
    } catch {
        AppToast.show(error.apiFailure.message ?? L("wa_error_generic"), long: true)
        return nil
    }
}

private func canSave(_ e: DiscEventDto) -> Bool {
    e.status != "cancelled" && e.status != "ended" && (e.reviewStatus == nil || e.reviewStatus == "approved")
}

// MARK: - Cards

/// A home-row card: a small cover, title and place, price and a save heart at the bottom.
struct EventCard: View {
    @Environment(\.wa) private var wa
    let e: DiscEventDto
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                ZStack {
                    evCategoryGradient(e.category)
                    if let url = ApiClient.mediaUrl(e.cover) {
                        AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }
                    } else {
                        Text(verbatim: e.category?.emoji ?? "🎟️").font(.system(size: 32))
                    }
                }
                .frame(height: 96)
                .frame(maxWidth: .infinity)
                .clipped()
                .overlay(alignment: .topLeading) { DateBadge(e: e, compact: true).padding(8) }
                .overlay(alignment: .topTrailing) {
                    if let label = evStatusLabel(e.status) {
                        Text(verbatim: label).font(.dorr(10, .heavy)).foregroundStyle(.white)
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(evStatusColor(e.status), in: Capsule())
                            .padding(8)
                    }
                }
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 4) {
                        Text(verbatim: e.title).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        Spacer(minLength: 0)
                        if e.verified { Image(systemName: "checkmark.seal.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0x2563EB)).accessibilityLabel(L("ev_verified")) }
                    }
                    Text(verbatim: [e.localTime, e.venue, e.city?.name].compactMap { $0 }.joined(separator: " · "))
                        .font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1).padding(.top, 3)
                    HStack(spacing: 6) {
                        Text(verbatim: evPriceText(e)).font(.dorr(11, .heavy)).foregroundStyle(wa.green)
                            .padding(.horizontal, 8).padding(.vertical, 3)
                            .background(wa.green.opacity(0.14), in: Capsule())
                        if let km = e.distanceKm { Text(verbatim: evDistanceText(km)).font(.dorr(11)).foregroundStyle(wa.soft).lineLimit(1) }
                        Spacer(minLength: 0)
                        SaveHeart(e: e, compact: true)
                    }
                    .padding(.top, 8)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 9)
            }
            .background(wa.surface)
            .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
    }
}

private struct DateBadge: View {
    let e: DiscEventDto
    var compact = false

    var body: some View {
        if let d = evLocalDate(e.localDate) {
            VStack(spacing: 0) {
                Text(verbatim: dayOfMonth(d)).font(.dorr(compact ? 15 : 17, .heavy)).foregroundStyle(Color(hex: 0x111928))
                Text(verbatim: monthShort(d)).font(.dorr(compact ? 9 : 10.5, .heavy)).foregroundStyle(evCategoryColor(e.category))
            }
            .padding(.horizontal, compact ? 7 : 10)
            .padding(.vertical, compact ? 3 : 5)
            .background(Color.white, in: RoundedRectangle(cornerRadius: compact ? 11 : 14, style: .continuous))
        }
    }
}

/// The heart that saves an event (in my calendar, told when it changes).
struct SaveHeart: View {
    @Environment(\.wa) private var wa
    let e: DiscEventDto
    var compact = false
    var onChanged: ((DiscEventDto) -> Void)?
    @State private var ev: DiscEventDto?
    @State private var busy = false

    var body: some View {
        let cur = ev ?? e
        let size: CGFloat = compact ? 32 : 40
        Button {
            guard !busy else { return }
            busy = true
            Task {
                if let next = await evToggleInterest(cur) {
                    ev = next
                    onChanged?(next)
                }
                busy = false
            }
        } label: {
            Image(systemName: cur.interested ? "heart.fill" : "heart").font(.system(size: compact ? 14 : 17, weight: .semibold))
                .foregroundStyle(canSave(cur) ? wa.red : wa.soft)
                .frame(width: size, height: size)
                .background(cur.interested ? wa.red.opacity(0.16) : wa.field, in: Circle())
        }
        .buttonStyle(.plain)
        .allowsHitTesting(canSave(cur) && !busy)
        .accessibilityLabel(L(cur.interested ? "ev_interested_on" : "ev_interested"))
        .onChange(of: e) { ev = $0 }
    }
}

/// One event in a list: the date block, title and place, then a save heart with the price under it.
struct EventRow: View {
    @Environment(\.wa) private var wa
    let e: DiscEventDto
    var review: String?
    var onInterest: ((DiscEventDto) -> Void)?
    let onClick: () -> Void

    var body: some View {
        let color = evCategoryColor(e.category)
        let d = evLocalDate(e.localDate)
        let place = [e.venue, e.city?.name].compactMap { $0 }.joined(separator: " · ")
        var tags: [(String, Color)] = []
        if let s = evStatusLabel(e.status) { tags.append((s, evStatusColor(e.status))) }
        if let r = review, r != "approved" { tags.append((L(r == "rejected" ? "ev_rv_rejected" : "ev_rv_pending"), r == "rejected" ? wa.danger : Color(hex: 0xD97706))) }
        return Button(action: onClick) {
            HStack(spacing: 0) {
                ZStack {
                    evCategoryGradient(e.category)
                    if let url = ApiClient.mediaUrl(e.cover) { AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() } }
                    VStack(spacing: 0) {
                        Text(verbatim: d.map(dayOfMonth) ?? "—").font(.dorr(18, .heavy)).foregroundStyle(Color(hex: 0x111928))
                        Text(verbatim: d.map(monthShort) ?? "").font(.dorr(11, .heavy)).foregroundStyle(color)
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(Color.white.opacity(0.92), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .frame(width: 56, height: 56)
                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                Spacer().frame(width: 12)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 4) {
                        Text(verbatim: e.title).font(.dorr(15, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        if e.verified { Image(systemName: "checkmark.seal.fill").font(.system(size: 13)).foregroundStyle(Color(hex: 0x2563EB)).accessibilityLabel(L("ev_verified")) }
                    }
                    HStack(spacing: 3) {
                        if let t = e.localTime { Text(verbatim: t).font(.dorr(12, .medium)).foregroundStyle(wa.mut).lineLimit(1) }
                        if !place.isEmpty {
                            if e.localTime != nil { Text(verbatim: " · ").font(.dorr(12)).foregroundStyle(wa.mut) }
                            Image(systemName: "mappin.and.ellipse").font(.system(size: 10)).foregroundStyle(wa.mut)
                            Text(verbatim: place).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                        }
                        if let km = e.distanceKm { Text(verbatim: " · " + evDistanceText(km)).font(.dorr(11, .medium)).foregroundStyle(wa.soft).lineLimit(1) }
                    }
                    .padding(.top, 3)
                    if !tags.isEmpty {
                        HStack(spacing: 6) { ForEach(tags, id: \.0) { MiniTag(text: $0.0, color: $0.1) } }.padding(.top, 5)
                    }
                }
                Spacer(minLength: 8)
                VStack(spacing: 6) {
                    SaveHeart(e: e, onChanged: onInterest)
                    Text(verbatim: evPriceText(e)).font(.dorr(11, .heavy)).foregroundStyle(wa.green).lineLimit(1).multilineTextAlignment(.center)
                        .padding(.horizontal, 8).padding(.vertical, 4)
                        .background(wa.green.opacity(0.14), in: Capsule())
                }
                .frame(minWidth: 58, maxWidth: 96)
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct MiniTag: View {
    let text: String
    let color: Color
    var body: some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
            .padding(.horizontal, 7).padding(.vertical, 2)
            .background(color.opacity(0.12), in: Capsule())
    }
}

struct CategoryTile: View {
    let c: DiscCategoryDto
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Text(verbatim: c.emoji ?? "🎟️").font(.system(size: 22))
                Text(verbatim: c.name ?? "").font(.dorr(11.5, .heavy)).foregroundStyle(.white).lineLimit(1).multilineTextAlignment(.center)
            }
            .padding(.horizontal, 6)
            .padding(.vertical, 8)
            .frame(width: 80, height: 78)
            .background(evCategoryGradient(c), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

struct EvChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var icon: String?
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            Text(verbatim: [icon, text].compactMap { $0 }.joined(separator: " ")).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink).lineLimit(1)
                .padding(.horizontal, 13).padding(.vertical, 8)
                .background(selected ? wa.red : wa.surface, in: Capsule())
                .overlay(Capsule().stroke(selected ? .clear : wa.line, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// Compose's FlowRow: children left to right (start to end), wrapping onto new lines.
struct FlowLayout: Layout {
    var spacing: CGFloat = 6
    var lineSpacing: CGFloat = 6

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { y += line + lineSpacing; x = 0; line = 0 }
            x += size.width + spacing
            line = max(line, size.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + line)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, line: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > bounds.minX && x + size.width > bounds.maxX { y += line + lineSpacing; x = bounds.minX; line = 0 }
            s.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}

// MARK: - Home banner

/// "What's on around you" on the home page — hidden when Discover is off here.
@MainActor
struct EventsBanner: View {
    @ObservedObject private var store = EventsStore.shared
    let onOpen: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            if !store.off {
                let next = store.home?.sections.first?.items.first
                Button(action: onOpen) {
                    HStack(spacing: 14) {
                        Text(verbatim: "🎟️").font(.system(size: 26))
                            .frame(width: 52, height: 52)
                            .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("ev_banner_title")).font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(1)
                            Text(verbatim: next.map { "\($0.category?.emoji ?? "✨")  \($0.title) · \(evWhenText($0))" } ?? L("ev_banner_sub"))
                                .font(.dorr(13)).foregroundStyle(.white.opacity(0.9)).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        Image(systemName: "safari.fill").font(.system(size: 21)).foregroundStyle(.white)
                    }
                    .padding(16)
                    .background(evHeroGradient, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                }
                .buttonStyle(WaPressStyle(scale: 0.98))
            }
        }
        .task { if store.home == nil { await store.refresh() } }
    }
}

// MARK: - The event

@MainActor
struct EventDetail: View {
    @Environment(\.wa) private var wa
    let id: String
    let onBack: () -> Void
    @State private var e: DiscEventDto?
    @State private var missing = false
    @State private var busy = false
    @State private var statusOpen = false
    @State private var shareOpen = false
    @State private var roomOpen = false
    @State private var uploading = false
    @State private var coverItem: PhotosPickerItem?
    @State private var coverPicking = false

    var body: some View {
        let ctaEvent = e.flatMap { canSave($0) ? $0 : nil }
        EvFrame(title: e?.title ?? L("ev_title"), onBack: onBack, settingsHeader: true, hasCta: ctaEvent != nil, cta: {
            if let ev = ctaEvent {
                WaButton(text: L(ev.interested ? "ev_interested_on" : "ev_interested"), style: ev.interested ? .ghost : .primary,
                         icon: ev.interested ? "heart.fill" : "heart", loading: busy) {
                    busy = true
                    Task {
                        if let next = await evToggleInterest(ev) { e = next }
                        busy = false
                    }
                }
            }
        }) {
            if missing {
                WaCard { WaEmpty(icon: "safari", tone: .gray, title: L("ev_gone_title"), text: L("ev_gone_text")) }
            } else if let ev = e {
                DetailBody(ev: ev, uploading: uploading, onCover: { coverPicking = true }, onStatus: { statusOpen = true }, onShare: { shareOpen = true }, onRoom: { roomOpen = true }, onNotify: { on in
                    e?.notify = on
                    Task { _ = try? await EventsApi.interest(ev.id, notify: on, timezone: evZone()) }
                })
            } else {
                WaSkeleton(cornerRadius: 26).frame(height: 200)
                WaSkeleton(cornerRadius: 22).frame(height: 140).padding(.top, 12)
            }
            Spacer().frame(height: 24)
        }
        .task(id: id) {
            do {
                e = try await EventsApi.event(id, timezone: evZone()).data
                missing = e == nil
            } catch {
                missing = true
            }
        }
        .photosPicker(isPresented: $coverPicking, selection: $coverItem, matching: .images)
        .onChange(of: coverItem) { item in
            guard let item, let ev = e else { return }
            coverItem = nil
            uploading = true
            Task {
                if let data = await evCoverData(item) {
                    do {
                        let r = try await EventsApi.updateEvent(ev.id, fields: [:], cover: data, timezone: evZone()).data
                        if let r { e = r }
                        await EventsStore.shared.changed()
                        // Not a verified organizer: the event waits for review again with its new photo.
                        AppToast.show(L(r?.reviewStatus == "pending" ? "ev_cover_review" : "ev_cover_saved"), long: true)
                    } catch {
                        AppToast.show(error.apiFailure.message ?? L("wa_error_generic"), long: true)
                    }
                }
                uploading = false
            }
        }
        .sheet(isPresented: $statusOpen) {
            if let ev = e {
                StatusSheet(ev: ev, onDismiss: { statusOpen = false }) { e = $0; statusOpen = false }.dorrThemed()
            }
        }
        .sheet(isPresented: $shareOpen) {
            if let ev = e { EventShareSheet(ev: ev) { shareOpen = false }.dorrThemed() }
        }
        .sheet(isPresented: $roomOpen) {
            PeoplePickerSheet(initial: [], onDismiss: { roomOpen = false }) { ids in
                roomOpen = false
                guard let ev = e else { return }
                Task {
                    do {
                        let r = try await EventsApi.room(ev.id, members: ids).data ?? nil
                        AppToast.show(L("ev_room_made"))
                        if let conv = r?["conversation"]?["id"]?.str {
                            EventsLink.shared.close()
                            ChatPush.shared.open(.conversation(conv))
                        }
                    } catch {
                        if let m = error.apiFailure.message { AppToast.show(m, long: true) }
                    }
                }
            }
            .chatAppearance()
        }
    }
}

/// An event's cover from the organizer's phone: shrunk to 1600 px (JPEG 85) so it always fits the server's 5 MB.
func evCoverData(_ item: PhotosPickerItem) async -> Data? {
    guard let raw = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: raw) else { return nil }
    let maxSide: CGFloat = 1600
    let scale = min(1, maxSide / max(image.size.width, image.size.height))
    guard scale < 1 else { return image.jpegData(compressionQuality: 0.85) }
    let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
    let resized = UIGraphicsImageRenderer(size: size).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
    return resized.jpegData(compressionQuality: 0.85)
}

private struct DetailBody: View {
    @Environment(\.wa) private var wa
    let ev: DiscEventDto
    let uploading: Bool
    let onCover: () -> Void
    let onStatus: () -> Void
    var onShare: () -> Void = {}
    var onRoom: () -> Void = {}
    let onNotify: (Bool) -> Void

    var body: some View {
        ZStack {
            evCategoryGradient(ev.category)
            if let url = ApiClient.mediaUrl(ev.cover) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }
            } else {
                Text(verbatim: ev.category?.emoji ?? "🎟️").font(.system(size: 48))
            }
        }
        .frame(height: 168)
        .frame(maxWidth: .infinity)
        .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(alignment: .topLeading) {
            HStack(spacing: 6) {
                if let name = ev.category?.name {
                    Text(verbatim: [ev.category?.emoji, name].compactMap { $0 }.joined(separator: " ")).font(.dorr(11, .bold)).foregroundStyle(wa.ink)
                        .padding(.horizontal, 9).padding(.vertical, 4).background(wa.surface, in: Capsule())
                }
                if ev.familyFriendly {
                    Text(verbatim: "👨‍👩‍👧").font(.system(size: 12)).padding(.horizontal, 7).padding(.vertical, 4).background(wa.surface, in: Capsule())
                }
            }
            .padding(10)
        }
        .overlay(alignment: .bottomTrailing) {
            if ev.mine {
                Button(action: onCover) {
                    HStack(spacing: 6) {
                        Image(systemName: "photo.badge.plus").font(.system(size: 13))
                        Text(L(uploading ? "ev_cover_uploading" : "ev_cover_change")).font(.dorr(12.5, .bold))
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 7)
                    .background(Color.black.opacity(0.55), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
                .allowsHitTesting(!uploading)
                .padding(10)
            }
        }
        .waRise(0)

        HStack(spacing: 6) {
            if ev.verified {
                Image(systemName: "checkmark").font(.system(size: 8, weight: .heavy)).foregroundStyle(.white)
                    .frame(width: 15, height: 15).background(wa.red, in: Circle())
            } else {
                Image(systemName: "circle").font(.system(size: 14)).foregroundStyle(wa.soft)
            }
            Text(verbatim: ev.source == "admin" ? L("ev_from_official")
                 : (ev.verified ? L("ev_from_verified", ev.organizer?.name ?? "") : L("ev_from_unverified", ev.organizer?.name ?? "")))
                .font(.dorr(12.5, .semibold)).foregroundStyle(wa.mut)
        }
        .padding(.top, 10)
        .waRise(1)

        if let label = evStatusLabel(ev.status) {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: label).font(.dorr(14, .heavy)).foregroundStyle(evStatusColor(ev.status))
                if let note = ev.statusNote, !note.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: note).font(.dorr(13)).foregroundStyle(wa.ink)
                }
                if let old = parseInstant(ev.oldStartsAt) {
                    let f = evFormatter("EEE d MMM · HH:mm")
                    let _ = f.timeZone = TimeZone(identifier: ev.timezone ?? "UTC")
                    Text(verbatim: L("ev_was_on", f.string(from: old))).font(.dorr(12)).foregroundStyle(wa.mut)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(evStatusColor(ev.status).opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.top, 10)
        }
        if ev.mine, let r = ev.reviewStatus, r != "approved" {
            WaNote(text: r == "rejected" ? L("ev_rv_rejected_note", ev.reviewNote ?? "") : L("ev_rv_pending_note")).padding(.top, 10)
        }

        facts.padding(.top, 10).waRise(2)

        if ev.interested {
            WaCard(padding: 6) { ToggleLine(title: L("ev_notify"), on: ev.notify, sub: L("ev_notify_sub"), onChange: onNotify) }.padding(.top, 8)
        }

        if ev.bookingUrl != nil || ev.sourceUrl != nil {
            HStack(spacing: 8) {
                if let url = ev.sourceUrl { WaButton(text: L("ev_official_page"), style: .ghost, icon: "globe") { open(url) } }
                if let url = ev.bookingUrl { WaButton(text: L("ev_book"), icon: "arrow.up.right.square") { open(url) } }
            }
            .padding(.top, 12)
            .waRise(3)
            Text(L("ev_links_note")).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 6)
        }

        HStack(spacing: 8) {
            if ev.reviewStatus == nil || ev.reviewStatus == "approved" {
                ActionTile(icon: "square.and.arrow.up", label: L("ev_share"), action: onShare)
                if ev.status != "cancelled" && ev.status != "ended" { ActionTile(icon: "person.3.fill", label: L("ev_go_together"), action: onRoom) }
            }
            if ev.mine { ActionTile(icon: "clock.arrow.circlepath", label: L("ev_change_status"), action: onStatus) }
        }
        .padding(.top, 12)
        .waRise(4)
    }

    private var facts: some View {
        let endText = evLocalDateTime(ev.localEnd).map { d -> String in
            let f = DateFormatter()
            f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
            f.timeStyle = .short
            return L("ev_ends_at", f.string(from: d))
        }
        let place = [ev.venue, ev.address, ev.city?.name].compactMap { $0 }.joined(separator: " · ")
        let checked = parseInstant(ev.lastVerifiedAt).map(dayText)
        var rows: [(String, String, String?, (() -> Void)?)] = []
        rows.append(("calendar", evWhenText(ev) + (ev.city?.name.map { "  ·  " + L("ev_local_of", $0) } ?? ""), evMyTimeText(ev), nil))
        if let endText { rows.append(("flag.fill", endText, nil, nil)) }
        if !place.trimmingCharacters(in: .whitespaces).isEmpty { rows.append(("mappin.and.ellipse", place, L("ev_open_map"), openMap)) }
        rows.append(("banknote", evPriceText(ev), nil, nil))
        if ev.interestedCount > 0 { rows.append(("person.3.fill", L("ev_interested_n", ev.interestedCount), nil, nil)) }
        if let checked { rows.append(("checkmark.seal.fill", L("ev_checked_on", checked), nil, nil)) }
        if let desc = visibleDescription(ev) { rows.append(("text.alignleft", desc, nil, nil)) }
        return WaCard(padding: 6) {
            ForEach(Array(rows.enumerated()), id: \.offset) { i, row in
                if i > 0 { wa.line.frame(height: 1).padding(.leading, 46) }
                InfoLine(icon: row.0, text: row.1, sub: row.2, onClick: row.3)
            }
        }
    }

    private func open(_ link: String) {
        if let url = URL(string: link) { UIApplication.shared.open(url) }
    }

    /// Apple Maps at the event's point, or a search for its place.
    private func openMap() {
        let q = [ev.venue, ev.address, ev.city?.name].compactMap { $0 }.joined(separator: ", ").addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        let link = ev.lat != nil && ev.lng != nil ? "http://maps.apple.com/?ll=\(ev.lat!),\(ev.lng!)&q=\(q)" : "http://maps.apple.com/?q=\(q)"
        open(link)
    }
}

private struct InfoLine: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    var sub: String?
    var onClick: (() -> Void)?

    var body: some View {
        let content = HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 32, height: 32)
                .background(wa.field, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: text).font(.dorr(13.5, .semibold)).foregroundStyle(wa.ink).lineSpacing(2)
                if let sub { Text(verbatim: sub).font(.dorr(12, onClick != nil ? .bold : .regular)).foregroundStyle(onClick != nil ? wa.red : wa.mut) }
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 9)
        .contentShape(Rectangle())
        if let onClick {
            Button(action: onClick) { content }.buttonStyle(.plain)
        } else {
            content
        }
    }
}

private struct ActionTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 36, height: 36)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                Text(verbatim: label).font(.dorr(12, .bold)).foregroundStyle(wa.ink).lineLimit(1)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

struct ToggleLine: View {
    @Environment(\.wa) private var wa
    let title: String
    let on: Bool
    var sub: String?
    let onChange: (Bool) -> Void
    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(wa.ink)
                if let sub { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.mut) }
            }
            Spacer(minLength: 8)
            RedToggle(on: on) { onChange(!on) }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
    }
}

/// The organizer keeps it up to date: the interested who asked are told.
@MainActor
private struct StatusSheet: View {
    @Environment(\.wa) private var wa
    let ev: DiscEventDto
    let onDismiss: () -> Void
    let onSaved: (DiscEventDto) -> Void
    @State private var status = ""
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("ev_change_status")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
                ForEach([("confirmed", "ev_st_confirmed"), ("postponed", "ev_st_postponed"), ("sold_out", "ev_st_sold_out"), ("cancelled", "ev_st_cancelled")], id: \.0) { key, label in
                    let on = (status.isEmpty ? ev.status : status) == key
                    Button { status = key } label: {
                        HStack(spacing: 10) {
                            Circle().fill(evStatusColor(key)).frame(width: 10, height: 10)
                            Text(L(label)).font(.dorr(15, .bold)).foregroundStyle(wa.ink)
                            Spacer()
                            Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 20)).foregroundStyle(on ? wa.red : wa.soft)
                        }
                        .padding(.vertical, 10).padding(.horizontal, 6)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(300)) }), placeholder: L("ev_status_note"), singleLine: true)
                    .padding(.top, 6)
                WaNote(text: L("ev_status_tells", ev.interestedCount)).padding(.top, 10)
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(wa.danger).padding(.top, 8) }
                let chosen = status.isEmpty ? ev.status : status
                WaButton(text: L("ev_save"), enabled: chosen != ev.status || !note.trimmingCharacters(in: .whitespaces).isEmpty, loading: busy) {
                    busy = true
                    Task {
                        var body = ["status": chosen]
                        if !note.trimmingCharacters(in: .whitespaces).isEmpty { body["note"] = note.trimmingCharacters(in: .whitespaces) }
                        do {
                            if let saved = try await EventsApi.organizerStatus(ev.id, body, timezone: evZone()).data { onSaved(saved) }
                            await EventsStore.shared.changed()
                        } catch {
                            self.error = error.apiFailure.message
                        }
                        busy = false
                    }
                }
                .padding(.top, 12)
            }
            .padding(.horizontal, 20)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .background(wa.surface.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
    }
}

/// Every city Discover covers, searchable.
@MainActor
struct CityPickerSheet: View {
    @Environment(\.wa) private var wa
    let onDismiss: () -> Void
    let onPick: (DiscCityDto) -> Void
    @State private var cities: [DiscCityDto]?
    @State private var query = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(L("ev_pick_city")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(60)) }), placeholder: L("ev_search_city"), icon: "magnifyingglass", singleLine: true)
            let q = query.trimmingCharacters(in: .whitespaces)
            let shown = (cities ?? []).filter { q.isEmpty || ($0.name ?? "").localizedCaseInsensitiveContains(q) }
            if cities == nil {
                WaSkeleton(cornerRadius: 16).frame(height: 100)
            }
            ScrollView {
                LazyVStack(spacing: 0) {
                    ForEach(shown) { c in
                        Button { onPick(c) } label: {
                            HStack(spacing: 12) {
                                WalletFlag(code: c.country)
                                Text(verbatim: c.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.ink)
                                Spacer(minLength: 8)
                                Text(verbatim: zoneCity(c.timezone)).font(.dorr(11.5)).foregroundStyle(wa.soft)
                            }
                            .padding(.vertical, 11).padding(.horizontal, 4)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 24)
        .background(wa.surface.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task { cities = await EventsStore.shared.allCities() }
    }
}

// MARK: - Places

/// The place a browse is narrowed to; nil = not chosen (the country defaults to where I am).
struct EvPlace: Equatable {
    var countryId: Int?
    var countryName: String?
    var regionId: Int?
    var regionName: String?
    var cityId: Int?
    var cityName: String?

    var isSet: Bool { countryId != nil || regionId != nil || cityId != nil }
    var count: Int { [countryId, regionId, cityId].compactMap { $0 }.count }
}

enum EvWhen { case any, today, weekend, week, month }

/// What the browse page is narrowed to besides the place: the kind and the time.
struct EvFilter: Equatable {
    var place = EvPlace()
    var categoryId: Int?
    var whenPick = EvWhen.any
    var free = false
    var family = false
    var popular = false

    var count: Int { place.count + [categoryId != nil, whenPick != .any, free, family, popular].filter { $0 }.count }
}

/// Countries don't change while Discover is open: loaded once.
@MainActor
enum EvPlaces {
    private static var countriesCache: [DiscCountryDto]?

    static func countries() async -> [DiscCountryDto] {
        if let countriesCache { return countriesCache }
        let list = (try? await EventsApi.countries().data) ?? []
        if !list.isEmpty { countriesCache = list }
        return list
    }

    /// All of a country's dashboard regions.
    static func regions(_ countryId: Int, withEvents: Bool = false) async -> [DiscRegionDto] {
        var all: [DiscRegionDto] = []
        var page = 1
        while page <= 10 {
            guard let r = try? await EventsApi.regions(countryId: countryId, page: page, perPage: 50, withEvents: withEvents ? 1 : 0) else { break }
            all += r.data ?? []
            if r.pagination?.hasMorePages != true { break }
            page += 1
        }
        return all
    }

    static func cities(_ regionId: Int, withEvents: Bool = false) async -> [DiscCityDto] {
        (try? await EventsApi.cities(regionId: regionId, withEvents: withEvents ? 1 : 0).data) ?? []
    }
}

/// The home's regions: the first batch comes with the home, more load as the strip nears its end.
@MainActor
struct RegionsStrip: View {
    @Environment(\.wa) private var wa
    let first: DiscRegionsDto
    let rise: Int
    let onOpen: (DiscRegionDto) -> Void
    @State private var items: [DiscRegionDto] = []
    @State private var page = 1
    @State private var more = false
    @State private var loading = false
    @State private var started = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if !first.items.isEmpty {
                WaSectionTitle(title: L("ev_regions")).waRise(rise)
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 8) {
                        ForEach(Array(items.enumerated()), id: \.element.id) { i, r in
                            Button { onOpen(r) } label: {
                                HStack(spacing: 5) {
                                    Text(verbatim: "📍").font(.system(size: 13))
                                    Text(verbatim: r.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                    Text(verbatim: String(r.eventsCount)).font(.dorr(11, .heavy)).foregroundStyle(wa.red)
                                        .padding(.horizontal, 7).padding(.vertical, 2)
                                        .background(wa.red.opacity(0.1), in: Capsule())
                                        .padding(.leading, 1)
                                }
                                .padding(.leading, 12).padding(.trailing, 6).padding(.vertical, 6)
                                .background(wa.surface, in: Capsule())
                                .overlay(Capsule().stroke(wa.line, lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                            .onAppear { if i >= items.count - 3 { Task { await loadMore() } } }
                        }
                        if loading { ProgressView().tint(wa.red).frame(width: 44, height: 38) }
                    }
                }
                .waRise(rise)
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            items = first.items
            page = first.meta.currentPage
            more = first.meta.hasMorePages
        }
    }

    private func loadMore() async {
        guard more, !loading else { return }
        loading = true
        if let r = try? await EventsApi.regions(page: page + 1) {
            let known = Set(items.map(\.id))
            items += (r.data ?? []).filter { !known.contains($0.id) }
            page += 1
            more = r.pagination?.hasMorePages == true
        } else {
            more = false
        }
        loading = false
    }
}

/// What the list is narrowed to, under the search — one row, each chip drops that part with ✕.
struct EvFilterChips: View {
    @Environment(\.wa) private var wa
    let filter: EvFilter
    let kind: DiscCategoryDto?
    let onChange: (EvFilter) -> Void

    var body: some View {
        let place = filter.place
        var chips: [(String, String, () -> Void)] = []
        if let n = place.countryName { chips.append(("globe", n, { onChange(update { $0.place = EvPlace() }) })) }
        if place.cityId == nil, let n = place.regionName {
            chips.append(("map.fill", n, { onChange(update { $0.place.regionId = nil; $0.place.regionName = nil; $0.place.cityId = nil; $0.place.cityName = nil }) }))
        }
        if let n = place.cityName { chips.append(("building.2.fill", n, { onChange(update { $0.place.cityId = nil; $0.place.cityName = nil }) })) }
        if let c = kind { chips.append(("square.grid.2x2.fill", [c.emoji, c.name].compactMap { $0 }.joined(separator: " "), { onChange(update { $0.categoryId = nil }) })) }
        if filter.whenPick != .any {
            let label: String = {
                switch filter.whenPick {
                case .today: return L("ev_when_today")
                case .weekend: return L("ev_when_weekend")
                case .week: return L("ev_when_week")
                default: return L("ev_when_month")
                }
            }()
            chips.append(("clock", label, { onChange(update { $0.whenPick = .any }) }))
        }
        if filter.free { chips.append(("ticket.fill", L("ev_free"), { onChange(update { $0.free = false }) })) }
        if filter.family { chips.append(("figure.2.and.child.holdinghands", L("ev_family"), { onChange(update { $0.family = false }) })) }
        if filter.popular { chips.append(("flame.fill", L("ev_popular"), { onChange(update { $0.popular = false }) })) }
        return Group {
            if !chips.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(chips.enumerated()), id: \.offset) { _, chip in
                            HStack(spacing: 6) {
                                Image(systemName: chip.0).font(.system(size: 12, weight: .semibold))
                                Text(verbatim: chip.1).font(.dorr(12, .heavy)).lineLimit(1).frame(maxWidth: 148)
                                Button(action: chip.2) {
                                    Image(systemName: "xmark").font(.system(size: 11, weight: .bold)).frame(width: 24, height: 24)
                                }
                                .buttonStyle(.plain)
                                .accessibilityLabel(L("ev_place_clear"))
                            }
                            .foregroundStyle(wa.red)
                            .padding(.leading, 10).padding(.trailing, 4)
                            .frame(height: 34)
                            .background(wa.red.opacity(0.12), in: Capsule())
                            .overlay(Capsule().stroke(wa.red.opacity(0.28), lineWidth: 1))
                        }
                    }
                }
                .padding(.top, 10)
            }
        }
    }

    private func update(_ change: (inout EvFilter) -> Void) -> EvFilter {
        var copy = filter
        change(&copy)
        return copy
    }
}

/// A select with a label above; locked (dimmed) until `enabled`; searchable; a ✓ on the chosen one.
struct EvSelect: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String
    let value: String
    let options: [(String, () -> Void)]
    let selected: String?
    var enabled = true
    @State private var open = false
    @State private var query = ""

    var body: some View {
        let q = query.trimmingCharacters(in: .whitespaces)
        let shown = options.enumerated().filter { q.isEmpty || $0.element.0.localizedCaseInsensitiveContains(q) }
        VStack(alignment: .leading, spacing: 0) {
            if !label.isEmpty { Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8) }
            Button { if enabled { query = ""; open = true } } label: {
                HStack(spacing: 8) {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red.opacity(0.8))
                    Text(verbatim: value).font(.dorr(15)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut)
                }
                .padding(.horizontal, 14)
                .frame(height: 46)
                .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
                .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
            }
            .buttonStyle(.plain)
            .popover(isPresented: $open, arrowEdge: .top) {
                VStack(spacing: 0) {
                    DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(60)) }), placeholder: L("ev_filter_search"),
                                 icon: "magnifyingglass", singleLine: true)
                        .padding(.horizontal, 10).padding(.vertical, 6)
                    ScrollView {
                        LazyVStack(spacing: 0) {
                            if shown.isEmpty {
                                Text(L("ev_place_none")).font(.dorr(14)).foregroundStyle(wa.mut).frame(maxWidth: .infinity, alignment: .leading).padding(16)
                            }
                            ForEach(shown, id: \.offset) { _, option in
                                let on = option.0 == selected
                                Button {
                                    option.1()
                                    open = false
                                } label: {
                                    HStack {
                                        Text(verbatim: option.0).font(.dorr(15, on ? .bold : .medium)).foregroundStyle(on ? wa.red : wa.ink)
                                        Spacer(minLength: 8)
                                        if on { Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red) }
                                    }
                                    .padding(.horizontal, 16).padding(.vertical, 12)
                                    .contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
                .frame(minWidth: 260, maxHeight: 360)
                .background(wa.night ? wa.darkCard : Color.white)
                .compactPopover()
            }
        }
        .opacity(enabled ? 1 : 0.5)
    }
}

/// The browse filter: the quick switches, then kind, time and country → region → city (changing one clears what
/// is under it). "Apply" narrows the list; "Clear" goes back to everything where I am.
@MainActor
struct EvFilterSheet: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let current: EvFilter
    let categories: [DiscCategoryDto]
    let myCountryCode: String?
    let onApply: (EvFilter) -> Void
    let onClose: () -> Void
    @State private var filter = EvFilter()
    @State private var countries: [DiscCountryDto]?
    @State private var regions: [DiscRegionDto]?
    @State private var cities: [DiscCityDto]?
    @State private var started = false

    var body: some View {
        let place = filter.place
        let all = L("ev_all_kinds")
        let kindName: (DiscCategoryDto) -> String = { [$0.emoji, $0.name].compactMap { $0 }.joined(separator: " ") }
        let whens: [(EvWhen, String)] = [(.any, L("ev_when_any")), (.today, L("ev_when_today")), (.weekend, L("ev_when_weekend")), (.week, L("ev_when_week")), (.month, L("ev_when_month"))]
        VStack(alignment: .leading, spacing: 0) {
            Text(L("ev_filter_title")).font(.dorr(19, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    FlowLayout {
                        EvChip(text: L("ev_free"), selected: filter.free, icon: "🎟️") { filter.free.toggle() }
                        EvChip(text: L("ev_family"), selected: filter.family, icon: "👨‍👩‍👧") { filter.family.toggle() }
                        EvChip(text: L("ev_popular"), selected: filter.popular, icon: "🔥") { filter.popular.toggle() }
                    }
                    let kind = categories.first { $0.id == filter.categoryId }
                    EvSelect(label: L("ev_f_category"), icon: "square.grid.2x2.fill", value: kind.map(kindName) ?? all,
                             options: [(all, { filter.categoryId = nil })] + categories.map { c in (kindName(c), { filter.categoryId = c.id }) },
                             selected: kind.map(kindName))
                    let whenLabel = whens.first { $0.0 == filter.whenPick }?.1 ?? ""
                    EvSelect(label: L("ev_when"), icon: "clock", value: whenLabel, options: whens.map { w in (w.1, { filter.whenPick = w.0 }) }, selected: whenLabel)
                    EvSelect(label: L("ev_country"), icon: "globe", value: place.countryName ?? L("ev_country"),
                             options: (countries ?? []).map { c in (countryName(c), { setPlace(EvPlace(countryId: c.id, countryName: countryName(c))) }) },
                             selected: place.countryName)
                    EvSelect(label: L("ev_region"), icon: "map.fill", value: place.regionName ?? L("ev_all_regions"),
                             options: [(L("ev_all_regions"), { setPlace(EvPlace(countryId: place.countryId, countryName: place.countryName)) })]
                                + (regions ?? []).map { r in (r.name ?? "", { setPlace(EvPlace(countryId: place.countryId, countryName: place.countryName, regionId: r.id, regionName: r.name)) }) },
                             selected: place.regionName, enabled: place.countryId != nil)
                    EvSelect(label: L("ev_city"), icon: "building.2.fill", value: place.cityName ?? L("ev_all_cities"),
                             options: [(L("ev_all_cities"), { var p = place; p.cityId = nil; p.cityName = nil; setPlace(p) })]
                                + (cities ?? []).map { c in (c.name ?? "", { var p = place; p.cityId = c.id; p.cityName = c.name; setPlace(p) }) },
                             selected: place.cityName, enabled: place.regionId != nil)
                }
            }
            HStack(spacing: 10) {
                WaButton(text: L("ev_place_clear"), style: .ghost) { onApply(EvFilter()) }
                WaButton(text: L("ev_place_apply")) { onApply(filter) }
            }
            .padding(.top, 16)
        }
        .padding(.horizontal, 18)
        .padding(.top, 24)
        .padding(.bottom, 22)
        .background(wa.bg.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task {
            guard !started else { return }
            started = true
            filter = current
            let mine = session.user?.country?.code ?? myCountryCode
            let list = await EvPlaces.countries()
            let sorted = list.sorted { a, b in
                let am = a.code?.lowercased() == mine?.lowercased(), bm = b.code?.lowercased() == mine?.lowercased()
                return am != bm ? am : (a.name ?? "") < (b.name ?? "")
            }
            countries = sorted
            // Nothing chosen yet: the country I signed up with, else where I am.
            if filter.place.countryId == nil, let start = sorted.first(where: { $0.code?.lowercased() == mine?.lowercased() }) ?? sorted.first {
                var p = filter.place
                p.countryId = start.id
                p.countryName = countryName(start)
                filter.place = p
            }
        }
        .task(id: filter.place.countryId) {
            regions = nil
            if let id = filter.place.countryId { regions = await EvPlaces.regions(id) }
        }
        .task(id: filter.place.regionId) {
            cities = nil
            if let id = filter.place.regionId { cities = await EvPlaces.cities(id) }
        }
    }

    private func setPlace(_ next: EvPlace) { filter.place = next }
}

func countryName(_ c: DiscCountryDto) -> String {
    let n = (c.name ?? "").trimmingCharacters(in: .whitespaces)
    return n.isEmpty ? (c.code ?? "") : n
}

/// Pick a region to follow: the country first (mine is chosen), then its regions, as rows.
@MainActor
struct RegionPickerSheet: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let myCountryCode: String?
    let followed: Set<Int>
    let onPick: (DiscRegionDto) -> Void
    let onClose: () -> Void
    @State private var countries: [DiscCountryDto]?
    @State private var countryId: Int?
    @State private var regions: [DiscRegionDto]?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("ev_pick_region")).font(.dorr(19, .heavy)).foregroundStyle(wa.ink)
            HStack(spacing: 8) {
                Image(systemName: "globe").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 28, height: 28).background(wa.red.opacity(0.12), in: Circle())
                Text(L("ev_country")).font(.dorr(15, .bold)).foregroundStyle(wa.ink)
            }
            .padding(.top, 14).padding(.bottom, 8)
            if let list = countries {
                FlowLayout {
                    ForEach(list) { c in EvChip(text: countryName(c), selected: c.id == countryId) { countryId = c.id } }
                }
            } else {
                HStack(spacing: 6) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 17).frame(width: 78, height: 34) } }
            }
            ScrollView {
                VStack(spacing: 8) {
                    if let list = regions {
                        if list.isEmpty {
                            Text(L("ev_place_none")).font(.dorr(12.5)).foregroundStyle(wa.mut).frame(maxWidth: .infinity, alignment: .leading)
                        }
                        ForEach(list) { r in
                            let done = followed.contains(r.id)
                            Button { onPick(r) } label: {
                                HStack(spacing: 10) {
                                    Image(systemName: "map.fill").font(.system(size: 17)).foregroundStyle(wa.red)
                                    Text(verbatim: r.name ?? "").font(.dorr(14.5, .bold)).foregroundStyle(wa.ink)
                                    Spacer()
                                    Text(verbatim: String(r.eventsCount)).font(.dorr(12)).foregroundStyle(wa.mut)
                                    if done { Image(systemName: "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(wa.red).padding(.leading, 8) }
                                }
                                .padding(.horizontal, 14).padding(.vertical, 12)
                                .background(wa.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .allowsHitTesting(!done)
                        }
                    } else {
                        ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 46) }
                    }
                }
                .padding(.top, 10)
            }
        }
        .padding(.horizontal, 18)
        .padding(.top, 24)
        .padding(.bottom, 22)
        .background(wa.bg.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .task {
            let list = await EvPlaces.countries()
            countries = list
            let mine = session.user?.country?.code ?? myCountryCode
            countryId = (list.first { $0.code?.lowercased() == mine?.lowercased() } ?? list.first)?.id
        }
        .task(id: countryId) {
            regions = nil
            if let id = countryId { regions = await EvPlaces.regions(id) }
        }
    }
}

/// Share in a chat as a card, maybe with «who's going?» (178).
@MainActor
private struct EventShareSheet: View {
    @Environment(\.wa) private var wa
    let ev: DiscEventDto
    let onDismiss: () -> Void
    @State private var chats: [ConversationDto]?
    @State private var picked: String?
    @State private var poll = true
    @State private var comment = ""
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("ev_share_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            Text(verbatim: ev.title).font(.dorr(13)).foregroundStyle(wa.mut).lineLimit(1).padding(.bottom, 10)
            if let list = chats {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(list) { c in
                            let on = picked == c.id
                            Button { picked = c.id } label: {
                                HStack(spacing: 10) {
                                    ChAvatar(url: c.avatar, name: c.title, key: c.peer?.key ?? c.id, size: 40, isGroup: c.isGroup)
                                    Text(verbatim: c.title ?? "").font(.dorr(14.5, .bold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                    Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(on ? wa.red : wa.soft)
                                }
                                .padding(.vertical, 8).padding(.horizontal, 4).contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .frame(maxHeight: 320)
            } else {
                WaSkeleton(cornerRadius: 16).frame(height: 120)
            }
            ChField(placeholder: L("ev_share_comment"), text: $comment, lines: 1...4, limit: 1000).padding(.top, 8)
            ToggleLine(title: L("ev_share_poll"), on: poll, sub: L("ev_share_poll_sub")) { poll = $0 }
            WaButton(text: L("ev_share_send"), icon: "paperplane.fill", enabled: picked != nil, loading: busy, action: send).padding(.top, 10)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 24)
        .task { chats = ((try? await ChatApi.conversations(perPage: 60).data) ?? []).filter { $0.canSend != false } }
    }

    private func send() {
        guard let target = picked else { return }
        busy = true
        Task {
            var body: [String: JSONValue] = ["conversation_id": .string(target), "poll": .bool(poll)]
            let note = comment.trimmingCharacters(in: .whitespacesAndNewlines)
            if !note.isEmpty { body["comment"] = .string(note) }
            do {
                _ = try await EventsApi.share(ev.id, body)
                AppToast.show(L("ev_shared"))
                onDismiss()
            } catch {
                if let m = error.apiFailure.message { AppToast.show(m, long: true) }
            }
            busy = false
        }
    }
}
