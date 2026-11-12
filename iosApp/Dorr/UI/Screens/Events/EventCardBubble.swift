import SwiftUI

// androidApp `events/EventsParts.kt` EventCardBubble — the card in a chat (178).

/// An event shared in a chat: a snapshot card; "View" opens the live event in Discover.
struct EventCardBubble<Footer: View>: View {
    @Environment(\.wa) private var wa
    let dto: MessageDto
    let mine: Bool
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        let ev = dto.meta?["event"]
        let s: (String) -> String? = { ev?[$0]?.str }
        let id = s("id")
        let color = momentColor(s("color"), evPurple)
        let status = s("status") ?? "confirmed"
        VStack(spacing: 0) {
            Button { if let id { EventsLink.shared.show(id) } } label: {
                VStack(alignment: .leading, spacing: 0) {
                    ZStack {
                        LinearGradient(colors: [color, color.blended()], startPoint: .topLeading, endPoint: .bottomTrailing)
                        if let cover = s("cover") {
                            ChatImage(url: ApiClient.mediaUrl(cover))
                        } else {
                            Text(verbatim: s("emoji") ?? "🎟️").font(.system(size: 40))
                        }
                    }
                    .frame(height: 112)
                    .clipped()
                    .overlay(alignment: .topLeading) {
                        if let d = s("local_date").flatMap(dayOf) {
                            VStack(spacing: 0) {
                                Text(verbatim: "\(Calendar.current.component(.day, from: d))").font(.dorr(15, .heavy)).foregroundStyle(Color(hex: 0x111928))
                                Text(verbatim: monthShort(d)).font(.dorr(10, .heavy)).foregroundStyle(color)
                            }
                            .padding(.horizontal, 9).padding(.vertical, 4)
                            .background(Color.white, in: RoundedRectangle(cornerRadius: 12))
                            .padding(8)
                        }
                    }
                    .overlay(alignment: .topTrailing) {
                        if let label = evStatusLabel(status) {
                            Text(verbatim: label).font(.dorr(10.5, .heavy)).foregroundStyle(.white)
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background(evStatusColor(status), in: Capsule())
                                .padding(8)
                        }
                    }
                    VStack(alignment: .leading, spacing: 0) {
                        if let category = s("category") {
                            Text(verbatim: (s("emoji").map { "\($0) " } ?? "") + category).font(.dorr(11.5, .heavy)).foregroundStyle(color)
                        }
                        HStack(spacing: 4) {
                            Text(verbatim: s("title") ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.ink).lineLimit(2)
                            if ev?["verified"]?.bool == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(Color(hex: 0x2563EB)) }
                        }
                        Text(verbatim: [s("local_time"), s("venue"), s("city")].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(1)
                        if let body = dto.body, !body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            Text(verbatim: body).font(.dorr(14)).foregroundStyle(wa.ink).padding(.top, 6)
                        }
                        Text(L("ev_view")).font(.dorr(13, .heavy)).foregroundStyle(wa.red)
                            .frame(maxWidth: .infinity).padding(.vertical, 8)
                            .background(wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 12))
                            .padding(.top, 8)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(12)
                }
                .background(wa.surface)
                .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(id == nil)
            HStack { Spacer(); footer() }.padding(.horizontal, 6)
        }
        .frame(width: 262)
    }

    private func dayOf(_ iso: String) -> Date? {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f.date(from: iso)
    }

    private func monthShort(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateFormat = "MMM"
        return f.string(from: d)
    }
}

private extension Color {
    /// Android darkens the second stop to 60 % of each channel.
    func blended() -> Color {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return Color(red: r * 0.6, green: g * 0.6, blue: b * 0.6)
    }
}
