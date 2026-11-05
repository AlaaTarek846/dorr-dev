import CoreLocation
import SwiftUI
import UIKit

// androidApp `chat/ChatLiveLocation.kt` — live location, as in WhatsApp but in Dorr's colours: the sharer's photo is
// the pin (in the app's red ring, a red pulse around it while live), and a tap on the bubble opens «View live
// location» — a big map that follows them. Every move arrives with the `chat.location.moved` event
// (ConversationState updates the message): nothing here asks the server.

/// The sharer's photo in the app's red ring, with a red pulse around it while the location is live.
struct LiveAvatarPin: View {
    @ObservedObject private var ch = Ch.shared
    let sender: ProfileDto?
    let active: Bool
    var size: CGFloat = 46
    @State private var t: CGFloat = 0

    var body: some View {
        let color = active ? ch.red : ch.mut
        ZStack {
            if active {
                Circle().fill(color.opacity(0.2)).frame(width: size + 12, height: size + 12)
                Circle().fill(color.opacity(0.4 * (1 - t))).frame(width: size + 52 * t, height: size + 52 * t)
            }
            ChAvatar(url: sender?.avatar, name: sender?.name, key: sender?.key, size: size - 6)
                .overlay(Circle().stroke(Color.white, lineWidth: 2))
                .padding(3)
                .background(color, in: Circle())
        }
        .frame(width: size, height: size)
        .onAppear { withAnimation(.linear(duration: 1.8).repeatForever(autoreverses: false)) { t = 1 } }
    }
}

/// «View live location»: the map follows the sharer's photo pin (until I move the map; 🎯 brings it back), with a red
/// halo for how precise the fix is. Under it, in a card: who, «Live · N min left» (or ended), when it last moved,
/// «Directions» (the phone's maps app) and — mine, still live — «Stop sharing».
struct LiveLocationPage: View {
    @ObservedObject private var ch = Ch.shared
    @ObservedObject var state: ConversationState
    let messageId: String
    let onDismiss: () -> Void
    @StateObject private var camera: DorrCamera
    @State private var following = true
    @State private var photo: UIImage?
    @State private var photoLoaded = false

    init(state: ConversationState, messageId: String, onDismiss: @escaping () -> Void) {
        self.state = state
        self.messageId = messageId
        self.onDismiss = onDismiss
        let meta = state.messages.first(where: { $0.id == messageId })?.dto.meta
        let start = CLLocationCoordinate2D(latitude: meta?.at("latitude")?.dbl ?? 0, longitude: meta?.at("longitude")?.dbl ?? 0)
        _camera = StateObject(wrappedValue: DorrCamera(start: start, zoom: 16.5))
    }

    var body: some View {
        // The message as it moves (the event updates it in the conversation's list).
        let m = state.messages.first(where: { $0.id == messageId })
        let meta = m?.dto.meta
        let at = CLLocationCoordinate2D(latitude: meta?.at("latitude")?.dbl ?? 0, longitude: meta?.at("longitude")?.dbl ?? 0)
        let accuracy = meta?.at("accuracy")?.dbl
        let sender = m?.dto.sender
        // The labels come from the device clock (redrawn every 15 s) — no server calls.
        TimelineView(.periodic(from: .now, by: 15)) { ctx in
            let until = parseRideTime(m?.dto.liveLocation?.liveUntil)
            let active = m?.dto.liveLocation?.active == true && (until.map { $0 > ctx.date } ?? false)
            ZStack(alignment: .bottom) {
                DorrMap(
                    camera: camera,
                    pins: [MapPin(at: at, kind: .stop, id: "live:\(photoLoaded)", image: photo)],
                    zones: accuracy.map { $0 > 5 ? [MapZone(center: at, radiusM: min($0, 300), color: ch.red.opacity(0.14))] : [] } ?? [],
                    showMe: true,
                    padding: EdgeInsets(top: 0, leading: 0, bottom: 190, trailing: 0)
                )
                .ignoresSafeArea()
                VStack(spacing: 0) {
                    HStack {
                        roundButton("chevron.backward", L("ch_close_sheet"), onDismiss)
                        Spacer()
                    }
                    .padding(12)
                    Spacer()
                    HStack {
                        Spacer()
                        roundButton("location.fill", L("ch_live_recenter")) {
                            following = true
                            camera.animate(to: at, zoom: 16.5)
                        }
                    }
                    .padding(.horizontal, 14).padding(.bottom, 14)
                    card(m: m, sender: sender, at: at, active: active, until: until, now: ctx.date)
                }
            }
            .onChange(of: "\(at.latitude),\(at.longitude)") { _ in if following { camera.animate(to: at, zoom: max(camera.zoom, 15)) } }
        }
        .background(ch.bg.ignoresSafeArea())
        // My finger moved the map: stop following until 🎯.
        .onChange(of: camera.isMoving) { moving in if moving && camera.byGesture { following = false } }
        // The pin: the initial on red at once, the photo once it has loaded.
        .task(id: sender?.avatar) {
            let name = state.messages.first(where: { $0.id == messageId })?.dto.sender?.name
            if photo == nil { photo = Self.pinImage(nil, initial: name, ring: UIColor(ch.red)) }
            guard let url = ApiClient.mediaUrl(sender?.avatar), let result = try? await URLSession.shared.data(from: url), let image = UIImage(data: result.0) else { return }
            photo = Self.pinImage(image, initial: name, ring: UIColor(ch.red))
            photoLoaded = true
        }
    }

    private func card(m: UiMessage?, sender: ProfileDto?, at: CLLocationCoordinate2D, active: Bool, until: Date?, now: Date) -> some View {
        let mine = m?.isMine == true
        let updated = parseRideTime(m?.dto.liveLocation?.updatedAt)
        return VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 14) {
                LiveAvatarPin(sender: sender, active: active, size: 48)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: mine ? L("ch_live_you") : (sender?.name ?? "")).font(.dorr(16, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                    HStack(spacing: 6) {
                        Circle().fill(active ? ch.red : ch.mut).frame(width: 7, height: 7)
                        Text(verbatim: active && until != nil ? Self.left(until!, now) : L("ch_live_ended_long"))
                            .font(.dorr(13, .bold)).foregroundStyle(active ? ch.red : ch.mut).lineLimit(1)
                    }
                    if let updated {
                        let minutes = Int(now.timeIntervalSince(updated) / 60)
                        Text(verbatim: minutes < 1 ? L("ch_live_updated_now") : L("ch_live_updated_ago", minutes)).font(.dorr(12)).foregroundStyle(ch.mut).lineLimit(1)
                    }
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 10) {
                actionButton("arrow.triangle.turn.up.right.diamond.fill", L("ch_live_directions"), filled: true) {
                    if let url = URL(string: "http://maps.apple.com/?daddr=\(at.latitude),\(at.longitude)") { UIApplication.shared.open(url) }
                }
                if mine && active, let m {
                    actionButton("stop.circle.fill", L("ch_live_stop"), filled: false) { state.stopLive(m) }
                }
            }
        }
        .padding(.horizontal, 18).padding(.top, 16).padding(.bottom, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(ch.surface, in: UnevenRoundedRectangleCompat(topRadius: 26))
        .shadow(color: .black.opacity(0.15), radius: 12, y: -2)
        // The card's colour carries on under the home indicator.
        .background(alignment: .bottom) { ch.surface.frame(height: 40).offset(y: 40).ignoresSafeArea(edges: .bottom) }
    }

    private func roundButton(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(ch.ink)
                .frame(width: 46, height: 46).background(ch.surface, in: Circle()).shadow(color: .black.opacity(0.2), radius: 5, y: 2)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    /// «Directions» filled in the app's red, «Stop sharing» quiet with red text.
    private func actionButton(_ icon: String, _ label: String, filled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 7) {
                Image(systemName: icon).font(.system(size: 16))
                Text(verbatim: label).font(.dorr(14.5, .heavy)).lineLimit(1)
            }
            .foregroundStyle(filled ? .white : ch.red)
            .frame(maxWidth: .infinity).padding(.vertical, 13)
            .background(filled ? ch.red : ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }

    private static func left(_ until: Date, _ now: Date) -> String {
        let minutes = max(Int(until.timeIntervalSince(now) / 60), 1)
        if minutes < 60 { return L("ch_live_minutes_left", minutes) }
        let f = DateFormatter()
        f.dateFormat = "h:mm a"
        return L("ch_live_until", f.string(from: until))
    }

    /// The photo as a map pin: a circle in the app's red ring with a white edge — the initial on red without a photo.
    static func pinImage(_ photo: UIImage?, initial: String?, ring: UIColor) -> UIImage {
        let side: CGFloat = 52
        return UIGraphicsImageRenderer(size: CGSize(width: side, height: side)).image { _ in
            let full = CGRect(x: 0, y: 0, width: side, height: side)
            ring.setFill()
            UIBezierPath(ovalIn: full).fill()
            UIColor.white.setFill()
            UIBezierPath(ovalIn: full.insetBy(dx: 3.6, dy: 3.6)).fill()
            let inner = full.insetBy(dx: 6, dy: 6)
            if let photo {
                UIBezierPath(ovalIn: inner).addClip()
                let s = min(photo.size.width, photo.size.height)
                let scale = inner.width / max(s, 1)
                let w = photo.size.width * scale, h = photo.size.height * scale
                photo.draw(in: CGRect(x: inner.midX - w / 2, y: inner.midY - h / 2, width: w, height: h))
            } else {
                ring.setFill()
                UIBezierPath(ovalIn: inner).fill()
                let letter = String((initial ?? "").trimmingCharacters(in: .whitespaces).prefix(1)).uppercased()
                let attrs: [NSAttributedString.Key: Any] = [.font: UIFont.boldSystemFont(ofSize: 18), .foregroundColor: UIColor.white]
                let size = (letter as NSString).size(withAttributes: attrs)
                (letter as NSString).draw(at: CGPoint(x: inner.midX - size.width / 2, y: inner.midY - size.height / 2), withAttributes: attrs)
            }
        }
    }
}
