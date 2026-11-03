import AVFoundation
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

/// What the wizard has gathered so far. It lives in the route ([WeRoute.wizard]), so going to fund the ad wallet and
/// coming back finds every step as it was. [edit] = editing that ad: steps 1–3 only, and it goes back to the review.
@MainActor
final class AdForm: ObservableObject {
    let edit: WeAdDto?
    @Published var step = 0
    /// +1 when the last move went forward, -1 back — the pages slide accordingly.
    @Published var direction = 1

    @Published var mediaFile: URL?
    @Published var mediaMime: String?
    @Published var mediaType: String?
    @Published var mediaSeconds: Int?
    @Published var mediaError: String?
    @Published var inspecting = false

    @Published var title: String
    @Published var body: String
    @Published var link: String

    @Published var cities: [Int]
    /// nil = everyone
    @Published var gender: String?
    /// nil = every language
    @Published var locale: String?
    @Published var people: Int?

    @Published var budgetText = ""
    @Published var startDate = weToday()
    @Published var endDate = wePlusDays(weToday(), 7)

    init(edit: WeAdDto? = nil) {
        self.edit = edit
        title = edit?.title ?? ""
        body = edit?.body ?? ""
        link = edit?.linkUrl ?? ""
        cities = edit?.audience.cities ?? []
        mediaType = edit?.media.type
        gender = edit?.audience.gender
        locale = edit?.audience.locale
    }

    var isEdit: Bool { edit != nil }
    var lastStep: Int { isEdit ? 2 : 4 }

    /// What the preview shows: the picked file, or the ad's current one when editing.
    var previewUrl: URL? { mediaFile ?? ApiClient.mediaUrl(edit?.media.url) }
    var hasPreview: Bool { previewUrl != nil }
    var previewIsVideo: Bool { mediaType == "video" }
}

private func isHttpsLink(_ text: String) -> Bool {
    let t = text.trimmingCharacters(in: .whitespaces)
    if t.isEmpty { return true }
    guard t.lowercased().hasPrefix("https://"), !t.contains(" "), let url = URL(string: t), let host = url.host, host.contains(".") else { return false }
    return true
}

/// androidApp `ui/screens/watchearn/AdWizard.kt` — creating an ad (D16), five steps on a pager with a step indicator:
/// the file, the text, the audience (with the live «reaches about N people»), the budget and dates (with the free ad
/// wallet balance, and a way to fund it), and the review — then «send for review» and a success check. Editing reuses
/// steps 1–3.
@MainActor
struct AdWizard: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm
    @State private var options: WeOptionsDto?
    @State private var optionsFailed = false
    @State private var reloadOptions = 0
    @State private var sending = false
    @State private var sent = false

    var body: some View {
        let opts = options
        let decimals = opts?.currency.decimals ?? 2
        let budgetMinor = weParseAmount(form.budgetText, decimals)
        let needMinor = max(budgetMinor ?? 0, opts?.minAdWalletMinor ?? 0)
        let walletShort = opts != nil && budgetMinor != nil && (opts?.freeMinor ?? 0) < needMinor
        Group {
            if sent {
                successPage
            } else {
                wizardPage(opts: opts, budgetMinor: budgetMinor, needMinor: needMinor, walletShort: walletShort)
            }
        }
        // The options (and the free balance) on every visit: coming back from funding shows the new balance.
        .task(id: reloadOptions) {
            optionsFailed = false
            options = try? await WatchEarnApi.options().data
            if options == nil { optionsFailed = true }
        }
    }

    private var successPage: some View {
        WaPage(title: L(form.isEdit ? "we_edit_ad" : "we_create_ad"), onBack: {}, scroll: false, showHeader: false) {
            VStack(spacing: 0) {
                Spacer(minLength: 0)
                WeSuccessCheck {
                    Task {
                        try? await Task.sleep(nanoseconds: 900_000_000)
                        if form.isEdit { host.pop() } else { host.replace(.myAds()) }
                    }
                }
                Text(L(form.isEdit ? "we_edit_sent_title" : "we_sent_title")).font(.dorr(20, .black)).foregroundStyle(wa.ink)
                    .multilineTextAlignment(.center).padding(.top, 18)
                Text(L("we_sent_text")).font(.dorr(13.5)).foregroundStyle(wa.mut).multilineTextAlignment(.center)
                    .padding(.horizontal, 30).padding(.vertical, 6)
                Spacer(minLength: 0)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private func wizardPage(opts: WeOptionsDto?, budgetMinor: Int?, needMinor: Int, walletShort: Bool) -> some View {
        let titles = form.isEdit
            ? ["we_step_file", "we_step_text", "we_step_audience"]
            : ["we_step_file", "we_step_text", "we_step_audience", "we_step_budget", "we_step_review"]
        let last = form.step == form.lastStep
        let valid = stepValid(opts, budgetMinor, walletShort)
        return WaPage(title: L(form.isEdit ? "we_edit_ad" : "we_create_ad"), onBack: { back() }, scroll: false, ctaReserve: 0, ctaDock: true, cta: {
            WeightedRow(spacing: 10) {
                if form.step > 0 {
                    WaButton(text: L("we_back"), style: .ghost) { back() }.layoutWeight(0.4)
                }
                WaButton(
                    text: L(!last ? "we_next" : (form.isEdit ? "we_resubmit" : "we_send_for_review")),
                    icon: last ? "paperplane.fill" : "arrow.forward",
                    enabled: valid && opts != nil,
                    loading: sending,
                    iconAfter: !last
                ) {
                    if !last { go(+1) } else { Task { await submit(budgetMinor) } }
                }
                .layoutWeight(1)
            }
        }) {
            StepIndicator(step: form.step, titles: titles)
                .padding(.bottom, 12)
            if let opts {
                if !opts.enabled {
                    WaEmpty(icon: "video.fill", tone: .gray, title: L("we_disabled_title"), text: L("we_disabled_text"))
                } else {
                    ZStack(alignment: .top) {
                        ScrollView {
                            stepView(form.step, opts: opts, budgetMinor: budgetMinor, needMinor: needMinor, walletShort: walletShort)
                                .padding(.bottom, 120)
                        }
                        .scrollDismissesKeyboard(.interactively)
                        .id(form.step)
                        .transition(.asymmetric(
                            insertion: .modifier(active: WeShift(fraction: form.direction > 0 ? 1 : -1, opacity: 0), identity: WeShift(fraction: 0, opacity: 1)),
                            removal: .modifier(active: WeShift(fraction: form.direction > 0 ? -1 : 1, opacity: 0), identity: WeShift(fraction: 0, opacity: 1))
                        ))
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .clipped()
                }
            } else if optionsFailed {
                WaEmpty(icon: "exclamationmark.triangle.fill", tone: .red, title: L("we_load_failed"), text: L("we_load_failed_hint")) {
                    WaButton(text: L("we_retry")) { reloadOptions += 1 }.padding(.horizontal, 40)
                }
            } else {
                VStack(spacing: 12) {
                    WaSkeleton(cornerRadius: 22).frame(height: 220)
                    WaSkeleton(cornerRadius: 16).frame(height: 60)
                }
            }
        }
    }

    @ViewBuilder
    private func stepView(_ step: Int, opts: WeOptionsDto, budgetMinor: Int?, needMinor: Int, walletShort: Bool) -> some View {
        switch step {
        case 0: FileStep(form: form, opts: opts)
        case 1: TextStep(form: form)
        case 2: AudienceStep(form: form, opts: opts)
        case 3:
            BudgetStep(form: form, opts: opts, budgetMinor: budgetMinor, needMinor: needMinor, walletShort: walletShort) {
                host.push(.adWallet(fundMinor: max(needMinor - opts.freeMinor, 1)))
            }
        default: ReviewStep(form: form, opts: opts, budgetMinor: budgetMinor)
        }
    }

    private func stepValid(_ opts: WeOptionsDto?, _ budgetMinor: Int?, _ walletShort: Bool) -> Bool {
        switch form.step {
        case 0: return !form.inspecting && form.mediaError == nil && (form.mediaFile != nil || form.isEdit)
        case 1:
            let n = form.title.trimmingCharacters(in: .whitespacesAndNewlines).count
            return (2...120).contains(n) && isHttpsLink(form.link)
        case 2: return true
        case 3:
            guard let opts, let b = budgetMinor else { return false }
            return b >= max(opts.pricePerViewMinor, 1) && form.startDate >= weToday() && form.endDate > form.startDate && !walletShort
        default: return true
        }
    }

    /// The direction is set first (the page on screen slides out the right way), then the step changes.
    private func go(_ delta: Int) {
        form.direction = delta
        DispatchQueue.main.async {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) { form.step += delta }
        }
    }

    private func back() {
        guard !sent else { return }
        if form.step > 0 { go(-1) } else { host.pop() }
    }

    private func submit(_ budgetMinor: Int?) async {
        guard options != nil else { return }
        sending = true
        do {
            let (fields, files) = try buildParts(budgetMinor)
            if let edit = form.edit {
                _ = try await WatchEarnApi.updateAd(edit.id, fields: fields, files: files)
            } else {
                _ = try await WatchEarnApi.createAd(fields: fields, files: files)
            }
            withAnimation(.easeOut(duration: 0.25)) { sent = true }
        } catch {
            let failure = error.apiFailure
            host.showToast(failure.message ?? L("we_error_generic"), error: true)
            // Take the person to the step the problem is on.
            var goTo: Int?
            switch failure.errorCode ?? "" {
            case "watch_earn_bad_file", "watch_earn_file_too_big", "watch_earn_video_too_long", "watch_earn_video_length_unknown": goTo = 0
            case "watch_earn_bad_cities": goTo = 2
            case "watch_earn_insufficient_ad_wallet", "watch_earn_budget_too_small", "watch_earn_bad_dates": goTo = form.isEdit ? nil : 3
            default: goTo = nil
            }
            if let goTo, goTo != form.step {
                form.direction = -1
                withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32)) { form.step = goTo }
            }
            if failure.errorCode == "watch_earn_insufficient_ad_wallet" { reloadOptions += 1 }
        }
        sending = false
    }

    /// The multipart body: the text, the audience, the dates and budget (creating only) and the file when one was picked.
    private func buildParts(_ budgetMinor: Int?) throws -> ([String: String], [ApiClient.FilePart]) {
        var fields: [String: String] = [
            "title": form.title.trimmingCharacters(in: .whitespacesAndNewlines),
            "body": form.body.trimmingCharacters(in: .whitespacesAndNewlines),
            "link_url": form.link.trimmingCharacters(in: .whitespacesAndNewlines),
            "target_gender": form.gender ?? "",
            "target_locale": form.locale ?? "",
        ]
        // No city = the whole country; an empty value says so on an edit too (it clears the old ones). PHP reads
        // `cities[0]`, `cities[1]`… as the same array Android's repeated `cities[]` parts make.
        if form.cities.isEmpty {
            fields["cities"] = ""
        } else {
            for (i, id) in form.cities.enumerated() { fields["cities[\(i)]"] = String(id) }
        }
        if !form.isEdit {
            fields["starts_at"] = weServerDate(form.startDate)
            fields["ends_at"] = weServerDate(form.endDate, endOfDay: true)
            fields["budget_minor"] = String(budgetMinor ?? 0)
        }
        var files: [ApiClient.FilePart] = []
        if let file = form.mediaFile, let mime = form.mediaMime {
            files.append(ApiClient.FilePart(field: "media", filename: file.lastPathComponent, mimeType: mime, data: try Data(contentsOf: file)))
            if let seconds = form.mediaSeconds { fields["media_seconds"] = String(seconds) }
        }
        return (fields, files)
    }
}

// MARK: - the step indicator

private struct StepIndicator: View {
    @Environment(\.wa) private var wa
    let step: Int
    let titles: [String]

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                ForEach(Array(titles.indices), id: \.self) { i in
                    let done = i < step
                    let now = i == step
                    ZStack {
                        Circle().fill(done || now ? wa.red : wa.line)
                        if done {
                            Image(systemName: "checkmark").font(.system(size: 12, weight: .heavy)).foregroundStyle(.white)
                        } else {
                            Text(verbatim: "\(i + 1)").font(.dorr(12, .heavy)).foregroundStyle(now ? Color.white : wa.mut)
                        }
                    }
                    .frame(width: 26, height: 26)
                    .scaleEffect(now ? 1.15 : 1)
                    .animation(.easeOut(duration: 0.3), value: step)
                    if i < titles.count - 1 {
                        GeometryReader { g in
                            ZStack(alignment: .leading) {
                                wa.line
                                wa.red.frame(width: i < step ? g.size.width : 0)
                            }
                        }
                        .frame(height: 3)
                        .clipShape(RoundedRectangle(cornerRadius: 2))
                        .padding(.horizontal, 4)
                        .animation(.easeOut(duration: 0.4), value: step)
                    }
                }
            }
            Text(verbatim: L("we_step_of", step + 1, titles.count) + " · " + L(titles[min(max(step, 0), titles.count - 1)]))
                .font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                .padding(.top, 10)
                .id(step)
                .transition(.opacity)
                .animation(.easeOut(duration: 0.22), value: step)
        }
    }
}

// MARK: - 1. the file

private enum MediaProblem { case badType, tooBig, tooLong, unreadable }

private struct MediaCheck {
    var problem: MediaProblem? = nil
    var file: URL? = nil
    var mime: String? = nil
    var video = false
    var seconds: Int? = nil
    var limitMb = 0
}

/// Type, size and length on the device, then a copy in the temporary folder to upload. A photo in a format the server
/// doesn't take (the iPhone's HEIC) goes up as JPEG — Android's picker hands JPEGs already.
private func inspectMedia(_ item: PhotosPickerItem, _ opts: WeOptionsDto) async -> MediaCheck {
    let types = item.supportedContentTypes
    let video = types.contains { $0.conforms(to: .movie) }
    let image = types.contains { $0.conforms(to: .image) }
    guard video || image else { return MediaCheck(problem: .badType) }
    guard var data = try? await item.loadTransferable(type: Data.self) else { return MediaCheck(problem: .unreadable) }
    var mime: String
    var ext: String
    if video {
        let type = types.first { $0.conforms(to: .movie) }
        mime = type?.preferredMIMEType?.lowercased() ?? "video/quicktime"
        ext = type?.preferredFilenameExtension ?? "mov"
        guard opts.media.videoMimes.contains(mime) else { return MediaCheck(problem: .badType) }
    } else {
        let type = types.first { $0.conforms(to: .image) }
        mime = type?.preferredMIMEType?.lowercased() ?? "image/jpeg"
        ext = type?.preferredFilenameExtension ?? "jpg"
        if !opts.media.imageMimes.contains(mime) {
            guard let picture = UIImage(data: data), let jpeg = picture.jpegData(compressionQuality: 0.9) else { return MediaCheck(problem: .badType) }
            data = jpeg
            mime = "image/jpeg"
            ext = "jpg"
        }
    }
    let limitMb = video ? opts.media.videoMaxMb : 10
    if data.count > limitMb * 1024 * 1024 { return MediaCheck(problem: .tooBig, limitMb: limitMb) }
    let file = FileManager.default.temporaryDirectory.appendingPathComponent("we_ad_\(Int(Date().timeIntervalSince1970 * 1000)).\(ext)")
    guard (try? data.write(to: file)) != nil else { return MediaCheck(problem: .unreadable) }
    var seconds: Int?
    if video {
        let duration = try? await AVURLAsset(url: file).load(.duration)
        let value = duration.map(CMTimeGetSeconds) ?? 0
        guard value.isFinite, value > 0 else {
            try? FileManager.default.removeItem(at: file)
            return MediaCheck(problem: .unreadable)
        }
        let s = max(Int(value.rounded(.up)), 1)
        if s > opts.media.videoMaxSeconds {
            try? FileManager.default.removeItem(at: file)
            return MediaCheck(problem: .tooLong)
        }
        seconds = s
    }
    return MediaCheck(file: file, mime: mime, video: video, seconds: seconds)
}

@MainActor
private struct FileStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm
    let opts: WeOptionsDto
    @State private var pickerOpen = false
    @State private var item: PhotosPickerItem?

    var body: some View {
        VStack(spacing: 0) {
            PhoneFrame(form: form) {
                if !form.hasPreview {
                    Button { pickerOpen = true } label: {
                        VStack(spacing: 8) {
                            Image(systemName: "icloud.and.arrow.up").font(.system(size: 34, weight: .semibold)).foregroundStyle(.white.opacity(0.85))
                            Text(L("we_pick_file")).font(.dorr(13, .bold)).foregroundStyle(.white).multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .frame(width: 210)
            WaButton(text: L(form.hasPreview ? "we_change_file" : "we_pick_file"), style: .ghost, icon: "photo", loading: form.inspecting, compact: true) {
                pickerOpen = true
            }
            .padding(.horizontal, 30)
            .padding(.top, 14)
            if let error = form.mediaError {
                Text(verbatim: error).font(.dorr(12.5)).foregroundStyle(wa.danger).multilineTextAlignment(.center).padding(.top, 10)
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
            WaNote(text: L("we_file_rules", opts.media.videoMaxSeconds, opts.media.videoMaxMb)).padding(.top, 12)
            if form.isEdit { WaNote(text: L("we_edit_note")).padding(.top, 8) }
        }
        .frame(maxWidth: .infinity)
        .animation(.easeOut(duration: 0.22), value: form.mediaError)
        .photosPicker(isPresented: $pickerOpen, selection: $item, matching: .any(of: [.images, .videos]))
        .onChange(of: item) { picked in
            guard let picked else { return }
            item = nil
            Task { await inspect(picked) }
        }
    }

    private func inspect(_ picked: PhotosPickerItem) async {
        form.inspecting = true
        // Checked on the phone first: the type, the size and a video's length (the server checks again).
        let result = await inspectMedia(picked, opts)
        form.inspecting = false
        switch result.problem {
        case .badType: form.mediaError = L("we_file_bad_type")
        case .tooBig: form.mediaError = L("we_file_too_big", result.limitMb)
        case .tooLong: form.mediaError = L("we_file_too_long", opts.media.videoMaxSeconds)
        case .unreadable: form.mediaError = L("we_file_unreadable")
        case nil:
            form.mediaError = nil
            form.mediaFile = result.file
            form.mediaMime = result.mime
            form.mediaType = result.video ? "video" : "image"
            form.mediaSeconds = result.seconds
        }
    }
}

/// A phone-shaped frame with the file (or the ad as the viewer will see it) inside.
struct PhoneFrame<Overlay: View>: View {
    @ObservedObject var form: AdForm
    @ViewBuilder var overlay: () -> Overlay

    var body: some View {
        ZStack(alignment: .top) {
            Color(hex: 0x0B0F19)
            if let url = form.previewUrl {
                if form.previewIsVideo {
                    LoopingPreview(url: url)
                } else if url.isFileURL {
                    Color.clear.overlay {
                        if let picture = UIImage(contentsOfFile: url.path) { Image(uiImage: picture).resizable().scaledToFill() }
                    }
                    .clipped()
                } else {
                    Color.clear.overlay { WeRemoteImage(url: url.absoluteString) }.clipped()
                }
            }
            // The notch.
            Capsule().fill(Color(hex: 0x1F2937)).frame(width: 54, height: 6).padding(.top, 10)
            overlay()
        }
        .aspectRatio(9 / 18.5, contentMode: .fit)
        .clipShape(RoundedRectangle(cornerRadius: 30, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 30, style: .continuous).strokeBorder(Color(hex: 0x1F2937), lineWidth: 6))
        .shadow(color: Color(hex: 0x111928).opacity(0.3), radius: 18, y: 8)
    }
}

/// The picked video, silent and looping, inside the frame.
private struct LoopingPreview: UIViewRepresentable {
    let url: URL

    final class LoopView: UIView {
        override static var layerClass: AnyClass { AVPlayerLayer.self }
        var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
        var looper: AVPlayerLooper?
        var url: URL?

        func load(_ url: URL) {
            guard url != self.url else { return }
            self.url = url
            let player = AVQueuePlayer()
            player.isMuted = true
            looper = AVPlayerLooper(player: player, templateItem: AVPlayerItem(url: url))
            playerLayer.player = player
            player.play()
        }
    }

    func makeUIView(context: Context) -> LoopView {
        let v = LoopView()
        v.playerLayer.videoGravity = .resizeAspectFill
        v.load(url)
        return v
    }

    // Only a new file restarts it.
    func updateUIView(_ v: LoopView, context: Context) { v.load(url) }

    static func dismantleUIView(_ v: LoopView, coordinator: ()) {
        v.playerLayer.player?.pause()
        v.looper = nil
        v.playerLayer.player = nil
    }
}

// MARK: - 2. the text

private struct TextStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm

    var body: some View {
        let linkBad = !form.link.trimmingCharacters(in: .whitespaces).isEmpty && !isHttpsLink(form.link)
        let titleShort = !form.title.isEmpty && form.title.trimmingCharacters(in: .whitespacesAndNewlines).count < 2
        VStack(alignment: .leading, spacing: 12) {
            WeTextField(value: $form.title, label: L("we_ad_title"), placeholder: L("we_ad_title_hint"), error: titleShort, limit: 120, capitalize: true)
            Text(verbatim: "\(form.title.count) / 120").font(.dorr(11)).foregroundStyle(wa.soft).frame(maxWidth: .infinity, alignment: .trailing).weLtr()
            WeTextField(value: $form.body, label: L("we_ad_body"), placeholder: L("we_ad_body_hint"), minLines: 3, limit: 2000, capitalize: true)
            WeTextField(
                value: Binding(get: { form.link }, set: { form.link = $0.trimmingCharacters(in: .whitespacesAndNewlines) }),
                label: L("we_ad_link"), placeholder: "https://", icon: "link", keyboard: .URL, error: linkBad, ltr: true
            )
            if linkBad {
                Text(L("we_ad_link_bad")).font(.dorr(12)).foregroundStyle(wa.danger).transition(.opacity)
            }
            if form.isEdit { WaNote(text: L("we_edit_note")) }
        }
        .animation(.easeOut(duration: 0.2), value: linkBad)
    }
}

// MARK: - 3. the audience

private struct AudienceStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm
    let opts: WeOptionsDto
    @State private var query = ""

    var body: some View {
        let names = Dictionary(opts.cities.map { ($0.id, $0.name ?? "") }, uniquingKeysWith: { a, _ in a })
        let q = query.trimmingCharacters(in: .whitespaces)
        let matches = Array(opts.cities.filter { c in !form.cities.contains(c.id) && (q.isEmpty || (c.name ?? "").localizedCaseInsensitiveContains(q)) }.prefix(8))
        VStack(alignment: .leading, spacing: 0) {
            // The live estimate on top.
            WaCard(padding: 16) {
                HStack(spacing: 12) {
                    Image(systemName: "person.3.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                        .frame(width: 42, height: 42)
                        .background(wa.red.opacity(0.13), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("we_reaches_about")).font(.dorr(12)).foregroundStyle(wa.mut)
                        if let people = form.people {
                            HStack(alignment: .lastTextBaseline, spacing: 6) {
                                WeCountUp(target: people, format: weCount, size: 22, duration: 0.6)
                                Text(L("we_people")).font(.dorr(13)).foregroundStyle(wa.mut)
                            }
                        } else {
                            WaSkeleton().frame(width: 90, height: 20).padding(.top, 4)
                        }
                    }
                    Spacer(minLength: 0)
                }
            }

            Text(L("we_cities")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 18).padding(.bottom, 8)
            // Picked cities as chips that pop in and shrink away.
            if form.cities.isEmpty {
                Text(L("we_whole_country")).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.bottom, 8)
            } else {
                WeFlow(spacing: 6) {
                    ForEach(form.cities, id: \.self) { id in
                        WeRemovableChip(text: names[id] ?? String(id)) { form.cities.removeAll { $0 == id } }
                    }
                }
                .padding(.bottom, 8)
            }
            WeTextField(value: $query, placeholder: L("we_search_city"), icon: "magnifyingglass", limit: 80)
            if !matches.isEmpty {
                WaCard {
                    ForEach(Array(matches.enumerated()), id: \.element.id) { i, c in
                        Button {
                            form.cities.append(c.id)
                            query = ""
                        } label: {
                            HStack {
                                Text(verbatim: c.name ?? "").font(.dorr(13.5)).foregroundStyle(wa.ink)
                                Spacer(minLength: 6)
                                Text(verbatim: "+").font(.dorr(18, .heavy)).foregroundStyle(wa.red)
                            }
                            .padding(.horizontal, 14)
                            .padding(.vertical, 11)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        if i < matches.count - 1 { WaDivider() }
                    }
                }
                .padding(.top, 8)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            if opts.cities.isEmpty {
                Text(L("we_no_cities")).font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 6)
            }

            Text(L("we_gender")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 18).padding(.bottom, 8)
            HStack(spacing: 8) {
                WeChip(text: L("we_all"), selected: form.gender == nil) { form.gender = nil }
                WeChip(text: L("we_gender_male"), selected: form.gender == "male") { form.gender = "male" }
                WeChip(text: L("we_gender_female"), selected: form.gender == "female") { form.gender = "female" }
            }
            Text(L("we_language")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 18).padding(.bottom, 8)
            HStack(spacing: 8) {
                WeChip(text: L("we_all"), selected: form.locale == nil) { form.locale = nil }
                WeChip(text: L("we_lang_ar"), selected: form.locale == "ar") { form.locale = "ar" }
                WeChip(text: L("we_lang_en"), selected: form.locale == "en") { form.locale = "en" }
            }
            if form.isEdit { WaNote(text: L("we_edit_note")).padding(.top, 14) }
        }
        .animation(.easeOut(duration: 0.22), value: matches.map(\.id))
        // «Reaches about N people», asked again a moment after the choice changes (not on a timer).
        .task(id: "\(form.cities)|\(form.gender ?? "")|\(form.locale ?? "")") {
            try? await Task.sleep(nanoseconds: 450_000_000)
            guard !Task.isCancelled else { return }
            let request = WeAudienceRequest(cities: form.cities.isEmpty ? nil : form.cities, gender: form.gender, locale: form.locale)
            if let people = try? await WatchEarnApi.audience(request).data?.people, !Task.isCancelled { form.people = people }
        }
    }
}

// MARK: - 4. the budget and the dates

@MainActor
private struct BudgetStep: View {
    @EnvironmentObject private var host: WeHost
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm
    let opts: WeOptionsDto
    let budgetMinor: Int?
    let needMinor: Int
    let walletShort: Bool
    let onFund: () -> Void

    var body: some View {
        let currency = opts.currency
        let views: Int? = budgetMinor.flatMap { b in opts.pricePerViewMinor > 0 ? b / opts.pricePerViewMinor : nil }
        VStack(alignment: .leading, spacing: 12) {
            WeTextField(
                value: Binding(get: { form.budgetText }, set: { form.budgetText = weAmountChars($0) }),
                label: L("we_budget"), placeholder: "0", icon: "wallet.pass.fill", keyboard: .decimalPad,
                error: !form.budgetText.trimmingCharacters(in: .whitespaces).isEmpty && budgetMinor == nil,
                trailing: currency.code, ltr: true
            )
            WaCard(padding: 14) {
                WaKeyValue(label: L("we_price_per_view"), value: weMoney(opts.pricePerViewMinor, currency), ltr: true)
                WaDivider()
                HStack {
                    Text(L("we_enough_for")).font(.dorr(13.5)).foregroundStyle(wa.mut)
                    Spacer(minLength: 8)
                    if let views {
                        WeCountUp(target: views, format: weCount, size: 14, duration: 0.5)
                    } else {
                        Text(verbatim: "—").font(.dorr(13.5)).foregroundStyle(wa.ink)
                    }
                }
                .padding(.vertical, 11)
                WaDivider()
                WaKeyValue(label: L("we_free_balance"), value: weMoney(opts.freeMinor, currency), bold: true, valueColor: walletShort ? wa.danger : We.withdrawable, ltr: true)
            }
            if walletShort {
                VStack(spacing: 10) {
                    WaNote(text: L("we_wallet_short", weMoney(needMinor, currency)), icon: "exclamationmark.triangle.fill")
                    WaButton(text: L("we_fund_ad_wallet"), style: .ghost, icon: "wallet.pass.fill", compact: true, action: onFund)
                }
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
            if opts.minAdWalletMinor > 0 {
                Text(L("we_min_ad_wallet", weMoney(opts.minAdWalletMinor, currency))).font(.dorr(11.5)).foregroundStyle(wa.soft)
            }

            Text(L("we_dates")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 6)
            HStack(spacing: 10) {
                DateBox(label: L("we_starts"), day: form.startDate) {
                    host.pickDate(WeDatePickerSpec(initial: form.startDate, min: weToday()) { day in
                        form.startDate = day
                        if form.endDate <= day { form.endDate = wePlusDays(day, 7) }
                    })
                }
                DateBox(label: L("we_ends"), day: form.endDate) {
                    host.pickDate(WeDatePickerSpec(initial: form.endDate, min: wePlusDays(form.startDate, 1)) { day in form.endDate = day })
                }
            }
            if form.endDate <= form.startDate {
                Text(L("we_dates_bad")).font(.dorr(12)).foregroundStyle(wa.danger)
            }
        }
        .animation(.easeOut(duration: 0.22), value: walletShort)
    }
}

private struct DateBox: View {
    @Environment(\.wa) private var wa
    let label: String
    let day: Date
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Image(systemName: "calendar").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(11.5)).foregroundStyle(wa.mut)
                    Text(verbatim: weDate(day: day)).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .frame(maxWidth: .infinity)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.line, lineWidth: 1))
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
    }
}

// MARK: - 5. the review

private struct ReviewStep: View {
    @Environment(\.wa) private var wa
    @ObservedObject var form: AdForm
    let opts: WeOptionsDto
    let budgetMinor: Int?

    var body: some View {
        let currency = opts.currency
        let names = Dictionary(opts.cities.map { ($0.id, $0.name ?? "") }, uniquingKeysWith: { a, _ in a })
        let me = AuthSession.shared.user?.name ?? ""
        let cities = form.cities.isEmpty ? L("we_whole_country") : form.cities.map { names[$0] ?? String($0) }.joined(separator: "، ")
        let gender = L(form.gender == "male" ? "we_gender_male" : form.gender == "female" ? "we_gender_female" : "we_all")
        let language = L(form.locale == "ar" ? "we_lang_ar" : form.locale == "en" ? "we_lang_en" : "we_all")
        VStack(spacing: 0) {
            Text(L("we_review_hint")).font(.dorr(12.5)).foregroundStyle(wa.mut).multilineTextAlignment(.center).padding(.bottom, 12)
            // The ad as a viewer will see it.
            PhoneFrame(form: form) {
                VStack(spacing: 0) {
                    HStack(spacing: 6) {
                        Text(verbatim: me.first.map(String.init) ?? "").font(.dorr(11, .bold)).foregroundStyle(.white)
                            .frame(width: 22, height: 22).background(Color.white.opacity(0.3), in: Circle())
                        Text(verbatim: me).font(.dorr(11, .bold)).foregroundStyle(.white).lineLimit(1)
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 12).padding(.top, 22).padding(.bottom, 14)
                    .background(LinearGradient(colors: [.black.opacity(0.55), .clear], startPoint: .top, endPoint: .bottom))
                    Spacer(minLength: 0)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: form.title.trimmingCharacters(in: .whitespacesAndNewlines)).font(.dorr(13, .heavy)).foregroundStyle(.white).lineLimit(2)
                        if !form.body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            Text(verbatim: form.body.trimmingCharacters(in: .whitespacesAndNewlines)).font(.dorr(10)).foregroundStyle(.white.opacity(0.88)).lineLimit(3)
                        }
                        if !form.link.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(L("we_open_link")).font(.dorr(9.5, .bold)).foregroundStyle(.black)
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background(Color.white, in: Capsule())
                                .padding(.top, 6)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 12).padding(.top, 20).padding(.bottom, 16)
                    .background(LinearGradient(colors: [.clear, .black.opacity(0.7)], startPoint: .top, endPoint: .bottom))
                }
            }
            .frame(width: 200)
            WaCard(padding: 14) {
                WaKeyValue(label: L("we_cities"), value: cities)
                WaDivider()
                WaKeyValue(label: L("we_gender"), value: gender)
                WaDivider()
                WaKeyValue(label: L("we_language"), value: language)
                WaDivider()
                if let people = form.people {
                    WaKeyValue(label: L("we_reaches_about"), value: weCount(people) + " " + L("we_people"))
                    WaDivider()
                }
                WaKeyValue(label: L("we_budget"), value: weMoney(budgetMinor ?? 0, currency), bold: true, ltr: true)
                if let budgetMinor, opts.pricePerViewMinor > 0 {
                    WaKeyValue(label: L("we_enough_for"), value: weCount(budgetMinor / opts.pricePerViewMinor) + " " + L("we_views_word"))
                }
                WaDivider()
                WaKeyValue(label: L("we_dates"), value: weDate(day: form.startDate) + " – " + weDate(day: form.endDate))
            }
            .padding(.top, 16)
            WaNote(text: L("we_review_note")).padding(.top, 12)
        }
        .frame(maxWidth: .infinity)
    }
}
