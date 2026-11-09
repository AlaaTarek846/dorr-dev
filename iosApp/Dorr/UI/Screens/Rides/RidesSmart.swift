import AVFoundation
import Speech
import SwiftUI

// androidApp `rides/RidesSmart.kt` — Block 06, DORR AI in the rider's rides: «دُرر يفهم مقصدك» (the trip in my own
// words, text or voice), the category I usually take (AI-09), «راحتي», and the captain finishing nearby / a booking
// that may be late.

private let aiGradient = [Color(hex: 0x6D5BFF), Color(hex: 0xB54BFF), Color(hex: 0xFF7A59)]

/// The entry under "Where to?": a glowing pill that opens the assistant — locked with the plan when needed (a locked
/// tap offers the DORR AI subscription).
struct SmartDestinationEntry: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onOpen: () -> Void
    @State private var status: RideAiStatusDto?
    @State private var spin = false

    var body: some View {
        Group {
            if state.feature("ai_booking"), let s = status, s.available == true {
                let allowed = s.allowed == true
                Button { if allowed { onOpen() } else { AiPlanGate.shared.offer(L("rd_ai_plan_needed")) } } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "sparkles").font(.system(size: 16, weight: .semibold)).foregroundStyle(.white)
                            .rotationEffect(.degrees(allowed && spin ? 360 : 0))
                            .frame(width: 34, height: 34)
                            .background(LinearGradient(colors: aiGradient, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("rd_ai_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(L("rd_ai_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        if !allowed {
                            HStack(spacing: 4) {
                                Image(systemName: "lock.fill").font(.system(size: 11))
                                Text(L("rd_ai_locked")).font(.dorr(11, .bold))
                            }
                            .foregroundStyle(AppColors.brandOrange)
                            .padding(.horizontal, 8).padding(.vertical, 4)
                            .background(wa.settingsCard, in: Capsule())
                        } else {
                            Image(systemName: "mic.fill").font(.system(size: 18)).foregroundStyle(aiGradient[0])
                        }
                    }
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .background(LinearGradient(colors: aiGradient.map { $0.opacity(0.14) }, startPoint: .leading, endPoint: .trailing),
                                in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous)
                        .stroke(LinearGradient(colors: aiGradient.map { $0.opacity(0.5) }, startPoint: .leading, endPoint: .trailing), lineWidth: 1))
                }
                .buttonStyle(WaPressStyle())
                .padding(.top, 10)
                .onAppear { withAnimation(.linear(duration: 4).repeatForever(autoreverses: false)) { spin = true } }
            }
        }
        .task(id: state.pickup?.lat) {
            guard state.feature("ai_booking") else { return }
            status = try? await RidesApi.aiStatus(lat: state.pickup?.lat ?? state.me?.latitude, lng: state.pickup?.lng ?? state.me?.longitude).data
        }
    }
}

/// The phone's own speech recogniser turns the words into text (nothing is recorded or kept here).
@MainActor
final class SpeechListener: ObservableObject {
    @Published var listening = false
    private let engine = AVAudioEngine()
    private var task: SFSpeechRecognitionTask?
    private var request: SFSpeechAudioBufferRecognitionRequest?

    func start(onText: @escaping (String, Bool) -> Void, onFail: @escaping () -> Void) {
        SFSpeechRecognizer.requestAuthorization { auth in
            Task { @MainActor in
                guard auth == .authorized,
                      let recognizer = SFSpeechRecognizer(locale: Locale(identifier: AppLocale.current == "ar" ? "ar-SA" : AppLocale.current)),
                      recognizer.isAvailable else { onFail(); return }
                do {
                    try AVAudioSession.sharedInstance().setCategory(.record, mode: .measurement, options: .duckOthers)
                    try AVAudioSession.sharedInstance().setActive(true, options: .notifyOthersOnDeactivation)
                    let request = SFSpeechAudioBufferRecognitionRequest()
                    request.shouldReportPartialResults = true
                    self.request = request
                    let input = self.engine.inputNode
                    input.removeTap(onBus: 0)
                    input.installTap(onBus: 0, bufferSize: 1024, format: input.outputFormat(forBus: 0)) { buffer, _ in request.append(buffer) }
                    self.engine.prepare()
                    try self.engine.start()
                    self.listening = true
                    self.task = recognizer.recognitionTask(with: request) { result, error in
                        Task { @MainActor in
                            if let result { onText(result.bestTranscription.formattedString, result.isFinal) }
                            if error != nil || result?.isFinal == true { self.stop() }
                        }
                    }
                } catch {
                    self.stop()
                    onFail()
                }
            }
        }
    }

    func stop() {
        if engine.isRunning {
            engine.stop()
            engine.inputNode.removeTap(onBus: 0)
        }
        request?.endAudio()
        task?.cancel()
        task = nil
        request = nil
        listening = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }
}

/// The assistant: say or write the trip, see what DORR AI understood, pick the place — then it is shown on the map to
/// confirm or move (nothing is booked here).
struct SmartDestinationSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let camera: DorrCamera
    let onClose: () -> Void
    @StateObject private var speech = SpeechListener()
    @State private var text = ""
    @State private var busy = false
    @State private var answer: RideAiUnderstandDto?
    @State private var error: String?
    @State private var glow = false

    private func ask(_ words: String) {
        let clean = words.trimmingCharacters(in: .whitespacesAndNewlines)
        guard clean.count >= 2, !busy else { return }
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["text": .string(clean)]
            if let at = state.pickup?.latLng ?? state.me {
                body["lat"] = .double(at.latitude)
                body["lng"] = .double(at.longitude)
            }
            do { answer = try await RidesApi.aiUnderstand(body).data } catch { self.error = error.apiFailure.message ?? L("rd_error") }
            busy = false
        }
    }

    private func pick(_ place: RideAiPlaceDto) {
        let u = answer?.understood
        // What else was said fills the booking sheet; the rider still sees and changes all of it.
        if let p = u?.passengers { state.passengers = p }
        if let pay = u?.payment, state.feature(pay) { state.payment = pay }
        if let note = u?.note { state.pickupNote = note }
        if let cat = u?.category { state.preferCategory = cat }
        if let when = u?.when, state.feature("scheduled"), let at = parseRideTime(when) { state.scheduledAt = at }
        state.editing = "destination"
        state.set(RidePoint(lat: place.lat, lng: place.lng, address: (place.address ?? "").isEmpty ? (place.name ?? "") : place.address!, name: place.name))
        // Confirm (or move) it on the map before anything is priced or booked.
        state.step = .pickOnMap
        camera.animate(to: .init(latitude: place.lat, longitude: place.lng), zoom: 16)
        onClose()
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 12) {
                    Image(systemName: "sparkles").font(.system(size: 20)).foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                        .background(LinearGradient(colors: aiGradient, startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("rd_ai_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("rd_ai_sheet_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(500)) }), placeholder: L("rd_ai_hint"), minHeight: 70).padding(.top, 14)
                HStack(spacing: 10) {
                    Button {
                        if speech.listening {
                            speech.stop()
                            ask(text)
                        } else {
                            speech.start(onText: { heard, final in
                                text = heard
                                if final { ask(heard) }
                            }, onFail: { error = L("rd_ai_no_voice") })
                        }
                    } label: {
                        Image(systemName: speech.listening ? "waveform" : "mic.fill").font(.system(size: 22)).foregroundStyle(aiGradient[0])
                            .frame(width: 54, height: 54)
                            .background(aiGradient[0].opacity(glow ? 0.38 : 0.18), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(WaPressStyle())
                    .accessibilityLabel(L("rd_ai_listening"))
                    .onAppear { withAnimation(.easeInOut(duration: 1.2).repeatForever()) { glow = true } }
                    AccentButton(text: L("rd_ai_send"), enabled: text.trimmingCharacters(in: .whitespaces).count >= 2, busy: busy, icon: "paperplane.fill") { ask(text) }
                }
                .padding(.top, 10)
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 8) }
                if let a = answer { understood(a) }
            }
            .padding(18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .onDisappear { speech.stop() }
    }

    @ViewBuilder
    private func understood(_ a: RideAiUnderstandDto) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            if let q = a.clarify {
                HStack(spacing: 8) {
                    Image(systemName: "sparkles").foregroundStyle(aiGradient[0])
                    Text(verbatim: q).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(aiGradient[0].opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            let u = a.understood ?? RideAiUnderstoodDto()
            let payment: String? = u.payment.map { $0 == "wallet" ? L("rd_ai_pay_wallet") : ($0 == "card" ? L("rd_ai_pay_card") : L("rd_ai_pay_cash")) }
            let chips: [(String, String)] = [
                u.categoryName.map { ("car.fill", $0) },
                u.when.flatMap(parseRideTime).map { ("clock", rideWhenText($0)) },
                u.passengers.map { ("person.2.fill", L("rd_ai_chip_passengers", $0)) },
                payment.map { ("banknote", $0) },
                u.note.map { ("note.text", $0) },
            ].compactMap { $0 }
            if !chips.isEmpty {
                Text(L("rd_ai_understood")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 10).padding(.bottom, 6)
                FlowLayout(spacing: 6) {
                    ForEach(Array(chips.enumerated()), id: \.offset) { _, chip in
                        HStack(spacing: 5) {
                            Image(systemName: chip.0).font(.system(size: 12)).foregroundStyle(wa.accent)
                            Text(verbatim: chip.1).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        }
                        .padding(.horizontal, 10).padding(.vertical, 6)
                        .background(wa.settingsBackground, in: Capsule())
                    }
                }
            }
            if let places = a.places, !places.isEmpty {
                Text(L("rd_ai_pick_place")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 14).padding(.bottom, 4)
                ForEach(Array(places.enumerated()), id: \.offset) { i, place in
                    Button { pick(place) } label: {
                        HStack(spacing: 10) {
                            Image(systemName: place.source == "saved" ? "bookmark.fill" : "mappin").font(.system(size: 15)).foregroundStyle(wa.accent)
                                .frame(width: 38, height: 38).background(wa.accent.opacity(0.1), in: Circle())
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: place.name ?? place.address ?? "").font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                if let name = place.name, name != place.address, let address = place.address {
                                    Text(verbatim: address).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                                }
                            }
                            Spacer(minLength: 0)
                            Text(L(place.source == "saved" ? "rd_ai_saved" : "rd_ai_on_map")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        }
                        .padding(.vertical, 10)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(WaPressStyle())
                    .staggerIn(i)
                }
            }
        }
        .padding(.top, 16)
        .transition(.opacity)
    }
}

extension RidesState {
    /// AI-09: the category this rider usually takes for a trip like this — asked once per list of prices.
    func loadSuggestion() async {
        suggestion = nil
        let list = quotes
        guard list.count >= 2, feature("ai_booking") else { return }
        var body: [String: JSONValue] = ["passengers": .int(passengers), "categories": .array(list.compactMap { $0.category?.slug }.map { .string($0) })]
        if let d = list.first?.route?.distanceM { body["distance_m"] = .int(d) }
        if let s = try? await RidesApi.aiSuggest(body).data, s.allowed == true, s.category != nil { suggestion = s }
    }
}

/// "✨ Suggested for you" under a category's name, with the reason under the list.
struct SuggestedTag: View {
    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: "sparkles").font(.system(size: 9))
            Text(L("rd_ai_suggested")).font(.dorr(10, .bold))
        }
        .foregroundStyle(aiGradient[1])
        .padding(.horizontal, 8).padding(.vertical, 2)
        .background(LinearGradient(colors: aiGradient.map { $0.opacity(0.16) }, startPoint: .leading, endPoint: .trailing), in: Capsule())
        .padding(.top, 3)
    }
}

// MARK: - «راحتي»

private func comfortSummary(_ p: RideComfortPrefsDto?, _ s: RideComfortScreenDto) -> String {
    let opts = s.options
    var parts: [String] = []
    if p?.quiet == true { parts.append("🤫") }
    if let ac = p?.ac, ac != "any" { parts.append("❄️ " + ((opts?.ac ?? []).first { $0.value == ac }?.label ?? ac)) }
    if let m = p?.music, m != "any" { parts.append("🎵 " + ((opts?.music ?? []).first { $0.value == m }?.label ?? m)) }
    if let notes = p?.notes, !notes.isEmpty {
        parts.append(notes.compactMap { n in (opts?.notes ?? []).first { $0.value == n }?.label }.joined(separator: "، "))
    }
    return parts.joined(separator: " · ")
}

/// On the booking sheet: a quiet ride for this trip (one switch), then «راحتي» — what I like, in one line, tap to change.
struct ComfortRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    @State private var open = false
    @State private var screen: RideComfortScreenDto?

    var body: some View {
        Group {
            if let s = screen, s.quietEnabled == true {
                QuietRideRow(state: state, saved: s.prefs?.quiet ?? false)
            }
            if state.feature("comfort"), let s = screen, s.enabled == true {
                let summary = comfortSummary(s.prefs, s)
                Button { open = true } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "leaf.fill").font(.system(size: 14)).foregroundStyle(AppColors.success)
                            .frame(width: 30, height: 30).background(AppColors.success.opacity(0.12), in: Circle())
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("rd_comfort_title")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: summary.isEmpty ? L("rd_comfort_none") : summary).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        Text(L("rd_change")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                    }
                    .padding(.vertical, 6)
                    .contentShape(Rectangle())
                }
                .buttonStyle(WaPressStyle())
                .sheet(isPresented: $open) {
                    ComfortSheet(screen: s, onClose: { open = false }) { screen = $0; open = false }.dorrThemed().presentationDetents([.large])
                }
            }
        }
        .task {
            guard state.feature("comfort") || state.feature("quiet_ride") else { return }
            screen = try? await RidesApi.comfort(lat: state.pickup?.lat, lng: state.pickup?.lng).data
        }
    }
}

/// A quiet ride, like the big ride apps: the captain is told the rider prefers no small talk.
private struct QuietRideRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let saved: Bool

    var body: some View {
        let on = Binding(get: { state.quietRide ?? saved }, set: { state.quietRide = $0 })
        HStack(spacing: 10) {
            Image(systemName: "speaker.slash.fill").font(.system(size: 14)).foregroundStyle(wa.accent)
                .frame(width: 30, height: 30).background(wa.accent.opacity(0.12), in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(L("rd_quiet_title")).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                Text(L("rd_quiet_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
            Toggle("", isOn: on).labelsHidden().tint(wa.accent)
        }
        .padding(.vertical, 6)
        .contentShape(Rectangle())
        .onTapGesture { on.wrappedValue.toggle() }
    }
}

private struct ComfortSheet: View {
    @Environment(\.wa) private var wa
    let screen: RideComfortScreenDto
    let onClose: () -> Void
    let onSaved: (RideComfortScreenDto) -> Void
    @State private var quiet = false
    @State private var ac = "any"
    @State private var music = "any"
    @State private var notes: Set<String> = []
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 12) {
                    Image(systemName: "leaf.fill").font(.system(size: 20)).foregroundStyle(AppColors.success)
                        .frame(width: 44, height: 44).background(AppColors.success.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("rd_comfort_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("rd_comfort_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                HStack(spacing: 10) {
                    Image(systemName: "speaker.slash.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("rd_comfort_quiet")).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                        Text(L("rd_comfort_quiet_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    Toggle("", isOn: $quiet).labelsHidden().tint(wa.accent)
                }
                .padding(.top, 16)
                group("snowflake", L("rd_comfort_ac"), screen.options?.ac ?? [], value: ac) { ac = $0 }
                group("music.note", L("rd_comfort_music"), screen.options?.music ?? [], value: music) { music = $0 }
                Text(L("rd_comfort_notes")).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk).padding(.top, 14).padding(.bottom, 8)
                FlowLayout(spacing: 8) {
                    ForEach(screen.options?.notes ?? [], id: \.value) { n in
                        let on = notes.contains(n.value)
                        pill(n.label ?? n.value, on) { if on { notes.remove(n.value) } else { notes.insert(n.value) } }
                    }
                }
                if let d = screen.disclaimer {
                    HStack(alignment: .top, spacing: 6) {
                        Image(systemName: "info.circle").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer(minLength: 0)
                    }
                    .padding(10)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.top, 14)
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 8) }
                AccentButton(text: L("rd_comfort_save"), busy: busy) { save() }.padding(.top, 14)
            }
            .padding(18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .onAppear {
            quiet = screen.prefs?.quiet ?? false
            ac = screen.prefs?.ac ?? "any"
            music = screen.prefs?.music ?? "any"
            notes = Set(screen.prefs?.notes ?? [])
        }
    }

    private func save() {
        busy = true
        Task {
            do {
                if let s = try await RidesApi.saveComfort(["quiet": .bool(quiet), "ac": .string(ac), "music": .string(music), "notes": .array(notes.map { .string($0) })]).data {
                    onSaved(s)
                }
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }

    @ViewBuilder
    private func group(_ icon: String, _ title: String, _ choices: [RideChoiceDto], value: String, pick: @escaping (String) -> Void) -> some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(wa.accent)
            Text(verbatim: title).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
        }
        .padding(.top, 14).padding(.bottom, 8)
        FlowLayout(spacing: 8) {
            ForEach(choices, id: \.value) { c in pill(c.label ?? c.value, c.value == value) { pick(c.value) } }
        }
    }

    private func pill(_ label: String, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, selected ? .bold : .regular)).foregroundStyle(selected ? wa.accent : wa.settingsInk)
                .padding(.horizontal, 14).padding(.vertical, 8)
                .background(selected ? wa.accent.opacity(0.08) : .clear, in: Capsule())
                .overlay(Capsule().stroke(selected ? wa.accent : wa.settingsInk.opacity(0.12), lineWidth: selected ? 2 : 1))
        }
        .buttonStyle(WaPressStyle())
    }
}

/// On the ride: what the rider asked for, and anything the captain said they can't do (with why).
struct RideComfortCard: View {
    @Environment(\.wa) private var wa
    let ride: RideDto

    var body: some View {
        if let comfort = ride.comfort {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 6) {
                    Image(systemName: "leaf.fill").font(.system(size: 15)).foregroundStyle(AppColors.success)
                    Text(L("rd_comfort_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                }
                FlowLayout(spacing: 6) {
                    ForEach(comfort.items ?? [], id: \.item) { item in
                        let refused = (comfort.unable ?? []).contains { $0.item == item.item }
                        Text(verbatim: item.label ?? item.item).font(.dorr(12)).strikethrough(refused).foregroundStyle(refused ? wa.settingsMut : wa.settingsInk)
                            .padding(.horizontal, 10).padding(.vertical, 5).background(wa.settingsCard, in: Capsule())
                    }
                }
                ForEach(Array((comfort.unable ?? []).enumerated()), id: \.offset) { _, u in
                    Text(verbatim: L("rd_comfort_unable", u.label ?? "", u.reasonLabel ?? "")).font(.dorr(12, .semibold)).foregroundStyle(AppColors.warning)
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(AppColors.success.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .padding(.top, 10)
        }
    }
}

/// The captain finishing a ride nearby, or a booking that may be late — said early and plainly, never a promise.
struct RideAiBanners: View {
    @Environment(\.wa) private var wa
    let ride: RideDto

    var body: some View {
        if let f = ride.driverFinishing, f.finishing == true {
            banner("car.fill", L("rd_finishing", minutesText(f.etaS, L("rd_minutes"), L("rd_hours"))), wa.accent)
        }
        if let r = ride.lateRisk {
            banner("clock", L("rd_late_risk", r.minutes ?? 0, r.reasonLabel ?? ""), AppColors.warning)
        }
    }

    private func banner(_ icon: String, _ text: String, _ color: Color) -> some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 17)).foregroundStyle(color)
            Text(verbatim: text).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(color.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.top, 10)
    }
}
