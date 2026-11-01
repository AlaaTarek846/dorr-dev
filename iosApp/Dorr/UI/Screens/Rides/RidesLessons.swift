import SwiftUI

// androidApp `rides/RidesLessons.kt` — driving lessons (docs/rides_tasks.md O-26b): trainers' lesson packs near me
// (automatic or manual, how many lessons, how long, the price per lesson), buy one, then book each lesson at a time
// that suits me and where to be picked up; a ring fills as lessons are done. Read on open and on reconnect (Android
// also reloads on each `lesson.*` notification — Chat section).

private let plum = Color(hex: 0x6A1B9A)
private let lilac = Color(hex: 0xBA68C8)

private enum LessonPage: Equatable {
    case browse, mine
    case pack(LessonPackDto)
    case enrollment(LessonEnrollmentDto, fresh: Bool)
}

struct LessonsHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var page = LessonPage.browse

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            switch page {
            case .browse: LessonBrowse(rides: state, onBack: onClose, onMine: { page = .mine }, onPick: { page = .pack($0) }).transition(.opacity)
            case .pack(let p): LessonPackPage(p: p, onBack: { page = .browse }, onEnrolled: { page = .enrollment($0, fresh: true) }).transition(.move(edge: .trailing))
            case .mine: LessonMine(onBack: { page = .browse }, onOpen: { page = .enrollment($0, fresh: false) }).transition(.move(edge: .trailing))
            case .enrollment(let e, let fresh): LessonEnrollmentPage(rides: state, initial: e, fresh: fresh, onBack: { page = .mine }).transition(.move(edge: .trailing))
            }
        }
        .animation(.easeInOut(duration: 0.3), value: page)
        .task {
            // Opened from a notification: that pack of mine (or my packs).
            guard ServiceLink.shared.pending?.kind == "lesson" else { return }
            let uuid = ServiceLink.shared.take("lesson")
            if let uuid, let e = try? await LessonsApi.enrollment(uuid).data { page = .enrollment(e, fresh: false) } else { page = .mine }
        }
    }
}

private struct LessonBrowse: View {
    @Environment(\.wa) private var wa
    @ObservedObject var rides: RidesState
    let onBack: () -> Void
    let onMine: () -> Void
    let onPick: (LessonPackDto) -> Void
    @State private var transmission: String?
    @State private var list: [LessonPackDto]?
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                LessonHero(onBack: onBack, onMine: onMine)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach([(nil, "ls_all"), ("automatic", "ls_automatic"), ("manual", "ls_manual")], id: \.1) { v, label in
                            SvcChip(text: L(label), on: transmission == v, tint: plum) { transmission = v }
                        }
                    }
                    .padding(16)
                }
                if let rows = list {
                    if let error, rows.isEmpty {
                        Text(verbatim: error).font(.dorr(14)).foregroundStyle(AppColors.danger).padding(.horizontal, 20)
                    } else if rows.isEmpty {
                        Text(L("ls_none")).font(.dorr(15)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(36)
                    } else {
                        VStack(spacing: 12) { ForEach(Array(rows.enumerated()), id: \.offset) { i, p in PackCard(p: p) { onPick(p) }.staggerIn(i) } }
                            .padding(.horizontal, 16)
                    }
                } else {
                    ProgressView().tint(plum).frame(maxWidth: .infinity).padding(36)
                }
                Spacer(minLength: 24)
            }
        }
        .ignoresSafeArea(edges: .top)
        .task(id: transmission) {
            list = nil
            guard let near = rides.me ?? rides.pickup?.latLng else { list = []; return }
            do {
                list = try await LessonsApi.packs(lat: near.latitude, lng: near.longitude, transmission: transmission).data ?? []
                error = nil
            } catch {
                self.error = error.apiFailure.message
                list = []
            }
        }
    }
}

/// The hero: a little car driving a dashed road, the «L» plate bobbing.
private struct LessonHero: View {
    let onBack: () -> Void
    let onMine: () -> Void
    @State private var bob = false

    var body: some View {
        ZStack(alignment: .topLeading) {
            TimelineView(.animation) { context in
                Canvas { gc, size in
                    let dash = (context.date.timeIntervalSinceReferenceDate / 0.9).truncatingRemainder(dividingBy: 1) * 20
                    let y = size.height * 0.82
                    var x = -dash
                    while x < size.width {
                        var p = Path()
                        p.move(to: CGPoint(x: x, y: y))
                        p.addLine(to: CGPoint(x: x + 10, y: y))
                        gc.stroke(p, with: .color(.white.opacity(0.45)), style: StrokeStyle(lineWidth: 2, lineCap: .round))
                        x += 20
                    }
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    SvcRound(icon: "arrow.backward", action: onBack)
                    Spacer()
                    SvcRound(icon: "graduationcap.fill", action: onMine)
                }
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("ls_title")).font(.dorr(26, .black)).foregroundStyle(.white)
                        Text(L("ls_subtitle")).font(.dorr(13)).foregroundStyle(.white.opacity(0.88))
                    }
                    Spacer(minLength: 0)
                    ZStack(alignment: .topTrailing) {
                        Image(systemName: "car.fill").font(.system(size: 50)).foregroundStyle(.white).offset(y: bob ? 4 : -4)
                        Text(verbatim: "L").font(.dorr(13, .black)).foregroundStyle(Color(hex: 0xD32F2F))
                            .padding(.horizontal, 5).padding(.vertical, 1).background(Color.white, in: RoundedRectangle(cornerRadius: 4))
                            .rotationEffect(.degrees(bob ? 10 : -10))
                    }
                    .onAppear { withAnimation(.easeInOut(duration: 1.3).repeatForever()) { bob = true } }
                }
            }
            .padding(.horizontal, 18).padding(.top, 56).padding(.bottom, 34)
        }
        .background(LinearGradient(colors: [plum, lilac], startPoint: .topLeading, endPoint: .bottomTrailing))
        .clipShape(BottomRoundedRectangle(radius: 34))
    }
}

private struct PackCard: View {
    @Environment(\.wa) private var wa
    let p: LessonPackDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Text(verbatim: String((p.trainer?.name ?? "?").prefix(1))).font(.dorr(18, .black)).foregroundStyle(.white)
                        .frame(width: 46, height: 46).background(LinearGradient(colors: [plum, lilac], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: p.title).font(.dorr(16, .black)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        Text(verbatim: L("ls_trainer", p.trainer?.name ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    VStack(alignment: .trailing, spacing: 0) {
                        Text(verbatim: rideMoney(p.priceMinor, p.currency)).font(.dorr(17, .black)).foregroundStyle(plum)
                        Text(verbatim: L("ls_per_lesson", rideMoney(p.perLessonMinor, p.currency))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                }
                HStack(spacing: 6) {
                    badge(L("ls_lessons_n", p.lessons ?? 1, p.minutesPerLesson ?? 60))
                    badge(L(p.transmission == "manual" ? "ls_manual" : "ls_automatic"))
                    if p.ownCar != false { badge(L("ls_own_car")) }
                }
                .padding(.top, 10)
                HStack(spacing: 2) {
                    Image(systemName: "mappin").font(.system(size: 12)).foregroundStyle(plum)
                    Text(verbatim: " " + (p.area.address ?? "")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .padding(.top, 8)
            }
            .padding(16)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
            .shadow(color: plum.opacity(0.18), radius: 10, y: 3)
        }
        .buttonStyle(WaPressStyle())
    }

    private func badge(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(plum).padding(.horizontal, 10).padding(.vertical, 4).background(plum.opacity(0.1), in: Capsule())
    }
}

private struct LessonInfo: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(plum).frame(width: 30, height: 30).background(plum.opacity(0.1), in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                if !value.isEmpty { Text(verbatim: value).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk) }
            }
        }
        .padding(.vertical, 5)
    }
}

private struct LessonPackPage: View {
    @Environment(\.wa) private var wa
    let p: LessonPackDto
    let onBack: () -> Void
    let onEnrolled: (LessonEnrollmentDto) -> Void
    @State private var busy = false
    @State private var error: String?
    @State private var key = RidesApi.key()

    var body: some View {
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    VStack(alignment: .leading, spacing: 4) {
                        SvcRound(icon: "arrow.backward", action: onBack).padding(.bottom, 8)
                        Text(verbatim: p.title).font(.dorr(24, .black)).foregroundStyle(.white)
                        Text(verbatim: L("ls_trainer", p.trainer?.name ?? "")).font(.dorr(13)).foregroundStyle(.white.opacity(0.88))
                    }
                    .padding(18).padding(.top, 40)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(LinearGradient(colors: [plum, lilac], startPoint: .topLeading, endPoint: .bottomTrailing))
                    .clipShape(BottomRoundedRectangle(radius: 30))
                    VStack(spacing: 12) {
                        SvcCard {
                            LessonInfo(icon: "graduationcap.fill", label: L("ls_what"), value: L("ls_lessons_n", p.lessons ?? 1, p.minutesPerLesson ?? 60))
                            LessonInfo(icon: "car.fill", label: L("ls_car"), value: L(p.transmission == "manual" ? "ls_manual" : "ls_automatic") + (p.ownCar != false ? " · " + L("ls_own_car") : ""))
                            LessonInfo(icon: "mappin", label: L("ls_area"), value: p.area.address ?? "")
                            if let d = p.description, !d.isEmpty { Text(verbatim: d).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 6) }
                        }
                        SvcCard {
                            ForEach(Array(["ls_how_1", "ls_how_2", "ls_how_3"].enumerated()), id: \.offset) { i, s in
                                HStack(spacing: 8) {
                                    Text(verbatim: "\(i + 1)").font(.dorr(12, .black)).foregroundStyle(plum).frame(width: 24, height: 24).background(plum.opacity(0.12), in: Circle())
                                    Text(L(s)).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                                }
                                .padding(.vertical, 4)
                            }
                        }
                        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                    }
                    .padding(16)
                }
            }
            .ignoresSafeArea(edges: .top)
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("ls_pack_price")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: rideMoney(p.priceMinor, p.currency)).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                }
                Spacer()
                Button {
                    busy = true
                    error = nil
                    Task {
                        do { if let e = try await LessonsApi.enroll(p.uuid, key: key).data { onEnrolled(e) } } catch { self.error = error.apiFailure.message }
                        busy = false
                    }
                } label: {
                    ZStack {
                        if busy { ProgressView().tint(.white) } else { Text(L("ls_buy")).font(.dorr(15, .bold)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 26).padding(.vertical, 14)
                    .background(LinearGradient(colors: [plum, lilac], startPoint: .leading, endPoint: .trailing), in: Capsule())
                }
                .buttonStyle(WaPressStyle())
                .disabled(busy)
            }
            .padding(16)
            .background(wa.settingsCard.ignoresSafeArea(edges: .bottom).shadow(color: .black.opacity(0.12), radius: 18, y: -2))
        }
    }
}

/// A ring that fills with the lessons done.
private struct ProgressRing: View {
    let done: Int
    let total: Int
    let size: CGFloat
    @State private var sweep: Double = 0

    var body: some View {
        let w = size * 0.1
        ZStack {
            Circle().stroke(plum.opacity(0.12), lineWidth: w).padding(w / 2)
            Circle().trim(from: 0, to: sweep)
                .stroke(AngularGradient(colors: [plum, lilac, plum], center: .center), style: StrokeStyle(lineWidth: w, lineCap: .round))
                .rotationEffect(.degrees(-90)).padding(w / 2)
            if done >= total && total > 0 {
                Image(systemName: "star.fill").font(.system(size: size / 3)).foregroundStyle(plum)
            } else {
                Text(verbatim: "\(done)/\(total)").font(.dorr(size / 5, .black)).foregroundStyle(plum)
            }
        }
        .frame(width: size, height: size)
        .onAppear { withAnimation(.easeOut(duration: 0.9)) { sweep = total <= 0 ? 0 : Double(done) / Double(total) } }
        .onChange(of: done) { _ in withAnimation(.easeOut(duration: 0.9)) { sweep = total <= 0 ? 0 : Double(done) / Double(total) } }
    }
}

private struct LessonMine: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (LessonEnrollmentDto) -> Void
    @State private var list: [LessonEnrollmentDto]?

    var body: some View {
        VStack(spacing: 0) {
            SvcBar(title: L("ls_mine"), colors: [plum, lilac], onBack: onBack)
            if let rows = list {
                if rows.isEmpty {
                    Text(L("ls_no_mine")).font(.dorr(15)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(36)
                    Spacer()
                } else {
                    ScrollView {
                        VStack(spacing: 10) {
                            ForEach(Array(rows.enumerated()), id: \.offset) { i, e in
                                Button { onOpen(e) } label: {
                                    HStack(spacing: 12) {
                                        ProgressRing(done: e.lessonsDone ?? 0, total: e.lessonsTotal ?? 0, size: 48)
                                        VStack(alignment: .leading, spacing: 0) {
                                            Text(verbatim: e.pack.title).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                            Text(verbatim: L("ls_done_of", e.lessonsDone ?? 0, e.lessonsTotal ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                        }
                                        Spacer(minLength: 0)
                                    }
                                    .padding(14)
                                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                                }
                                .buttonStyle(WaPressStyle())
                                .staggerIn(i)
                            }
                        }
                        .padding(16)
                    }
                }
            } else {
                ProgressView().tint(plum).padding(40)
                Spacer()
            }
        }
        .task { list = (try? await LessonsApi.mine().data) ?? [] }
    }
}

private func sessionStatus(_ s: String) -> String {
    switch s {
    case "confirmed": return L("ls_st_confirmed")
    case "done": return L("ls_st_done")
    case "declined": return L("ls_st_declined")
    case "canceled": return L("ls_st_canceled")
    default: return L("ls_st_requested")
    }
}

private struct LessonEnrollmentPage: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject var rides: RidesState
    let initial: LessonEnrollmentDto
    let fresh: Bool
    let onBack: () -> Void
    @State private var e: LessonEnrollmentDto?
    @State private var busy = false
    @State private var error: String?
    @State private var booking = false

    private func reload() async { if let fresh = try? await LessonsApi.enrollment(initial.uuid).data { e = fresh } }

    private func act(_ call: @escaping () async throws -> LessonEnrollmentDto?) {
        busy = true
        error = nil
        Task {
            do { if let x = try await call() { e = x } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }

    var body: some View {
        let en = e ?? initial
        VStack(spacing: 0) {
            SvcBar(title: en.pack.title, colors: [plum, lilac], onBack: onBack)
            ScrollView {
                VStack(spacing: 12) {
                    // O-67: held for its payment — the one payment screen (coupon, top-up), then the lessons.
                    if en.status == "unpaid" {
                        ServicePayCard(purpose: ServicePay.lessons, uuid: en.uuid, expiresAt: en.paymentExpiresAt, autoOpen: fresh) { Task { await reload() } }
                    }
                    ProgressRing(done: en.lessonsDone ?? 0, total: en.lessonsTotal ?? 0, size: 130)
                    Text(verbatim: L("ls_done_of", en.lessonsDone ?? 0, en.lessonsTotal ?? 0)).font(.dorr(18, .black)).foregroundStyle(wa.settingsInk)
                    if let phone = en.trainerPhone, let url = URL(string: "tel:\(phone)") {
                        Button { openURL(url) } label: {
                            Text(verbatim: "📞 " + L("ls_call_trainer")).font(.dorr(13, .bold)).foregroundStyle(AppColors.success)
                                .padding(.horizontal, 14).padding(.vertical, 7).background(AppColors.success.opacity(0.12), in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                    if en.status == "active" && (en.lessonsLeft ?? 0) > 0 {
                        Button { booking = true } label: {
                            Text(verbatim: L("ls_book_lesson", en.lessonsLeft ?? 0)).font(.dorr(15, .bold)).foregroundStyle(.white)
                                .frame(maxWidth: .infinity).padding(14)
                                .background(LinearGradient(colors: [plum, lilac], startPoint: .leading, endPoint: .trailing), in: Capsule())
                        }
                        .buttonStyle(WaPressStyle())
                    }
                    ForEach(en.sessions ?? []) { s in
                        SvcCard {
                            HStack(spacing: 10) {
                                ZStack {
                                    Circle().fill(s.status == "done" ? AppColors.success : plum.opacity(0.12))
                                    if s.status == "done" {
                                        Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                                    } else {
                                        Text(verbatim: "\(s.number)").font(.dorr(15, .black)).foregroundStyle(plum)
                                    }
                                }
                                .frame(width: 36, height: 36)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: L("ls_lesson_n", s.number) + " · " + serviceDayTime(s.startsAt)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                                    Text(verbatim: sessionStatus(s.status)).font(.dorr(12)).foregroundStyle(["declined", "canceled"].contains(s.status) ? AppColors.danger : wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                                if s.status == "requested" || s.status == "confirmed" {
                                    Button { act { try await LessonsApi.cancelSession(s.uuid).data } } label: {
                                        Text(L("ls_cancel")).font(.dorr(12, .bold)).foregroundStyle(AppColors.danger).padding(8)
                                    }
                                    .buttonStyle(.plain)
                                    .disabled(busy)
                                }
                            }
                        }
                    }
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                    if en.status == "active" {
                        Button { act { try await LessonsApi.quit(en.uuid).data } } label: {
                            Text(L("ls_quit")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity).padding(12)
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                    if let r = en.refundedMinor, r > 0 { Text(verbatim: L("ls_refunded", rideMoney(r, en.pack.currency))).font(.dorr(13)).foregroundStyle(AppColors.success) }
                }
                .padding(16)
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        // Each move arrives as an event: one reload (nothing on a timer).
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("lesson.") { Task { await reload() } } }
        .fullScreenCover(isPresented: $booking) {
            BookLessonSheet(rides: rides, onClose: { booking = false }) { at, place in
                booking = false
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                act { try await LessonsApi.book(en.uuid, ["starts_at": .string(f.string(from: at)), "pickup": .object(place.json)]).data }
            }
            .dorrThemed()
        }
    }
}

/// Book a lesson: the day, the hour, and where the trainer picks me up.
private struct BookLessonSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var rides: RidesState
    let onClose: () -> Void
    let onBook: (Date, RidePoint) -> Void
    @State private var day = Calendar.current.date(byAdding: .day, value: 1, to: Calendar.current.startOfDay(for: Date()))!
    @State private var hour: Int?
    @State private var place: RidePoint?
    @State private var query = ""
    @State private var results: [RidePoint] = []

    var body: some View {
        let cal = Calendar.current
        let dayF = DateFormatter()
        dayF.locale = Locale(identifier: AppLocale.current)
        dayF.dateFormat = "EEE d"
        let hourF = DateFormatter()
        hourF.locale = Locale(identifier: AppLocale.current)
        hourF.dateFormat = "h a"
        let at = hour.flatMap { cal.date(bySettingHour: $0, minute: 0, second: 0, of: day) }
        return VStack(spacing: 0) {
            SvcBar(title: L("ls_book_title"), colors: [plum, lilac], onBack: onClose)
            ScrollView {
                VStack(spacing: 12) {
                    SvcCard {
                        LessonInfo(icon: "clock", label: L("ls_when"), value: "")
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(0..<21, id: \.self) { n in
                                    let d = cal.date(byAdding: .day, value: n, to: cal.startOfDay(for: Date()))!
                                    SvcChip(text: dayF.string(from: d), on: d == day, tint: plum) { day = d; hour = nil }
                                }
                            }
                        }
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(7...22, id: \.self) { h in
                                    let t = cal.date(bySettingHour: h, minute: 0, second: 0, of: day) ?? day
                                    SvcChip(text: hourF.string(from: t), on: hour == h, enabled: t > Date(), tint: plum) { hour = h }
                                }
                            }
                        }
                        .padding(.top, 8)
                    }
                    SvcCard {
                        LessonInfo(icon: "mappin", label: L("ls_pickup"), value: place?.address ?? "—")
                        DorrTextArea(value: $query, placeholder: L("rd_search_hint"), icon: "magnifyingglass", singleLine: true)
                        ForEach(Array(results.prefix(5).enumerated()), id: \.offset) { _, r in
                            Button { place = r; query = ""; results = [] } label: {
                                Text(verbatim: r.address).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading).padding(10)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .padding(16)
            }
            Button { if let at, let place { onBook(at, place) } } label: {
                Text(L("ls_send_request")).font(.dorr(15, .bold)).foregroundStyle(.white).frame(maxWidth: .infinity).padding(14)
                    .background(LinearGradient(colors: at != nil && place != nil ? [plum, lilac] : [wa.settingsMut, wa.settingsMut], startPoint: .leading, endPoint: .trailing), in: Capsule())
            }
            .buttonStyle(WaPressStyle())
            .disabled(at == nil || place == nil)
            .padding(16)
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .onAppear { place = place ?? rides.pickup }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            results = await searchPlaces(query, near: rides.me ?? rides.pickup?.latLng)
        }
    }
}
