import PhotosUI
import SwiftUI
import UIKit
import UniformTypeIdentifiers

// driverApp `ui/CasesScreen.kt` — the captain's disputes and unpaid-ride reports (D-229..D-238).

/// The captain's disputes and "the rider left without paying" reports (D-229..D-238, D-285..D-292): each is a
/// case of its own — opened on a finished ride, with the system's evidence shown, photos the captain adds
/// (D-232), the conversation with the team, the decision in plain words (D-236), and one request for another
/// look where the country allows it (D-237). A report is only a report: it never becomes a debt by itself (D-230).
/// It follows the team through notifications; nothing here asks the server on a timer.
struct CasesScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var options: CaseOptionsDto?
    @State private var cases: [CaseDto]?
    @State private var openUuid: String?
    @State private var creating = false
    @State private var error: String?

    private func load() async {
        if let o = await DriverApi.caseOptions().value { options = o }
        switch await DriverApi.cases() {
        case .ok(let list, _): cases = list; error = nil
        case .failed(let e): error = e.displayMessage
        case .offline: error = L("error_offline")
        }
    }

    private func back() {
        if openUuid != nil { openUuid = nil } else if creating { creating = false } else { onBack() }
    }

    var body: some View {
        ScreenScaffold(L("cs_title"), onBack: back, actions: {
            if openUuid == nil && !creating && options?.available == true {
                RoundIconButton("plus", L("cs_new"), onClick: { creating = true })
            }
        }) {
            Group {
                if let uuid = openUuid {
                    CaseDetail(uuid: uuid).id(uuid)
                } else if creating {
                    NewCase(options: options, onCreated: { uuid in creating = false; openUuid = uuid })
                } else {
                    VStack(alignment: .leading, spacing: 0) {
                        if let error { Text(error).font(.dorr(16)).foregroundStyle(pal.danger).padding(16) }
                        if cases == nil && error == nil {
                            CircularProgress(color: pal.orange, size: 40, lineWidth: 4).frame(maxWidth: .infinity, maxHeight: .infinity)
                        } else if options?.available == false {
                            CasesHint(text: L("cs_unavailable"))
                        } else if (cases ?? []).isEmpty {
                            CasesHint(text: L("cs_empty"))
                        } else {
                            ScrollView {
                                VStack(spacing: 10) {
                                    ForEach(Array((cases ?? []).enumerated()), id: \.offset) { _, c in
                                        CaseRow(c: c) { openUuid = c.uuid ?? "" }
                                    }
                                }
                                .padding(16)
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                }
            }
        }
        // Back from a case (or from the form) shows the list as the server has it now.
        .task(id: "\(openUuid ?? "-")|\(creating)") { if openUuid == nil && !creating { await load() } }
    }
}

private struct CasesHint: View {
    let text: String
    @Environment(\.pal) private var pal

    var body: some View {
        Text(text).font(.dorr(14)).foregroundStyle(pal.ink.opacity(0.6))
            .padding(32)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

private func statusColor(_ status: String, _ pal: Pal) -> Color {
    switch status {
    case "confirmed", "partially_confirmed": return pal.success
    case "rejected": return pal.danger
    case "needs_info": return pal.warning
    default: return pal.navy
    }
}

/// The headline of a case: its kind, and the category when it says more.
private func caseHeadline(_ c: CaseDto) -> String {
    let kind = c.kindLabel ?? ""
    let cat = c.categoryLabel ?? ""
    return kind + (!cat.trimmingCharacters(in: .whitespaces).isEmpty && cat != kind ? " · \(cat)" : "")
}

private struct CaseRow: View {
    let c: CaseDto
    let onClick: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let color = statusColor(c.status ?? "submitted", pal)
        Card {
            HStack(spacing: 0) {
                DorrIcon("hammer.fill", size: 20, tint: color)
                    .frame(width: 40, height: 40)
                    .background(Circle().fill(color.opacity(0.12)))
                HSpace(12)
                VStack(alignment: .leading, spacing: 0) {
                    Text(caseHeadline(c)).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                    Text([c.number ?? "", c.ride?.number].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .trailing, spacing: 0) {
                    if let minor = c.disputedMinor { Text(money(minor, c.currency)).font(.dorr(14, .heavy)).foregroundStyle(pal.ink) }
                    // The state in words (a colour is only a help).
                    Text(c.statusLabel ?? "").font(.dorr(11, .bold)).foregroundStyle(color)
                        .padding(.horizontal, 10).padding(.vertical, 3)
                        .background(Capsule().fill(color.opacity(0.12)))
                }
            }
        }
        .contentShape(Rectangle())
        .onTapGesture(perform: onClick)
    }
}

/// Open a dispute or an unpaid report on one of the captain's finished rides.
private struct NewCase: View {
    let options: CaseOptionsDto?
    let onCreated: (String) -> Void

    @Environment(\.pal) private var pal
    @State private var rides: [DriverRideItemDto]?
    @State private var kind: String?
    @State private var reason: String?
    @State private var ride: DriverRideItemDto?
    @State private var summary = ""
    @State private var busy = false
    @State private var error: String?
    // O-63: attachments chosen with the report (photos, a dash cam's video).
    @State private var files: [PhotosPickerItem] = []
    @State private var picking = false
    @State private var picked: [PhotosPickerItem] = []

    init(options: CaseOptionsDto?, onCreated: @escaping (String) -> Void) {
        self.options = options
        self.onCreated = onCreated
        _kind = State(initialValue: options?.kinds?.first?.code)
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 14) {
                Card {
                    Text(L("cs_what")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    VSpace(8)
                    CasesFlow(spacing: 8) {
                        ForEach(Array((options?.kinds ?? []).enumerated()), id: \.offset) { _, k in
                            CasePill(label: k.label ?? "", selected: kind == k.code) { kind = k.code; reason = nil }
                        }
                    }
                    if kind == "dispute" {
                        VSpace(10)
                        Text(L("cs_which_charge")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                        VSpace(6)
                        CasesFlow(spacing: 8) {
                            ForEach(Array((options?.disputeReasons ?? []).enumerated()), id: \.offset) { _, r in
                                CasePill(label: r.label ?? "", selected: reason == r.code) { reason = r.code }
                            }
                        }
                    }
                    if kind == "unpaid_report" {
                        Text(L("cs_unpaid_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.65)).padding(.top, 8)
                    }
                }
                Card {
                    Text(L("cs_which_ride")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    VSpace(8)
                    if let list = rides {
                        if list.isEmpty {
                            Text(L("cs_no_rides")).font(.dorr(13)).foregroundStyle(pal.ink)
                        } else {
                            ForEach(Array(list.prefix(15).enumerated()), id: \.offset) { _, r in
                                let on = ride?.uuid == r.uuid
                                HStack(spacing: 0) {
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text([r.number, r.category].compactMap { $0 }.joined(separator: " · ")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
                                        Text([r.originAddress, r.destinationAddress].compactMap { $0 }.joined(separator: " → "))
                                            .font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6)).lineLimit(1)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    Text(paymentWord(r.paymentMethod)).font(.dorr(11, .semibold)).foregroundStyle(pal.ink)
                                }
                                .padding(10)
                                .background(RoundedRectangle(cornerRadius: 12).fill(on ? pal.navy.opacity(0.1) : Color.clear))
                                .contentShape(RoundedRectangle(cornerRadius: 12))
                                .onTapGesture { ride = r }
                                .padding(.vertical, 3)
                            }
                        }
                    } else {
                        CircularProgress(color: pal.orange, size: 22, lineWidth: 2)
                    }
                }
                Card {
                    DorrField(value: summary, onValueChange: { summary = $0 }, label: L("cs_summary"), minLines: 3)
                    VSpace(10)
                    HStack(spacing: 0) {
                        DorrIcon("paperclip", size: 16, tint: pal.navy)
                        Text(L("cs_attach_media", files.count)).font(.dorr(13, .semibold)).foregroundStyle(pal.navy)
                    }
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(Capsule().fill(pal.navy.opacity(0.07)))
                    .contentShape(Capsule())
                    .onTapGesture { if !busy { picking = true } }
                    ForEach(Array(files.enumerated()), id: \.offset) { _, f in
                        HStack(spacing: 0) {
                            let video = f.supportedContentTypes.contains { $0.conforms(to: .movie) }
                            Text((video ? "🎬 " : "🖼 ") + (f.itemIdentifier ?? "file")).font(.dorr(12)).foregroundStyle(pal.ink).lineLimit(1)
                                .frame(maxWidth: .infinity, alignment: .leading)
                            Text("✕").font(.dorr(16)).foregroundStyle(pal.danger).padding(6)
                                .contentShape(Rectangle())
                                .onTapGesture { files.removeAll { $0 == f } }
                        }
                        .padding(.top, 4)
                    }
                    if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger).padding(.top, 6) }
                }
                BigButton(L("cs_send"),
                          enabled: kind != nil && ride != nil && summary.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3
                              && (kind != "dispute" || reason != nil),
                          busy: busy, onClick: send)
            }
            .padding(16)
        }
        .scrollDismissesKeyboard(.interactively)
        .task { rides = await DriverApi.rideHistory().value?.items ?? [] }
        .photosPicker(isPresented: $picking, selection: $picked, maxSelectionCount: 6, matching: .any(of: [.images, .videos]))
        .onChange(of: picked) { chosen in
            guard !chosen.isEmpty else { return }
            var all = files
            for item in chosen where !all.contains(item) { all.append(item) }
            files = Array(all.prefix(6))
            picked = []
        }
    }

    private func send() {
        busy = true
        error = nil
        Task {
            var body: JsonBody = [
                "kind": kind,
                "ride_uuid": ride?.uuid,
                "summary": summary.trimmingCharacters(in: .whitespacesAndNewlines),
            ]
            if let reason { body["category"] = reason }
            switch await DriverApi.openCase(body: body) {
            case .ok(let c, _):
                // O-63: the attachments go up with the new report (one by one; a failed one is said, the report stays).
                for f in files {
                    guard let part = await filePart(f) else { continue }
                    if case .failed(let e) = await DriverApi.caseFile(uuid: c.uuid ?? "", file: part) { error = e.displayMessage }
                }
                onCreated(c.uuid ?? "")
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

private func paymentWord(_ method: String?) -> String {
    switch method {
    case "cash": return L("pf_pay_cash")
    case "wallet": return L("pf_pay_wallet")
    case "card": return L("pf_pay_card")
    default: return ""
    }
}

private struct CaseDetail: View {
    let uuid: String

    @Environment(\.pal) private var pal
    @State private var c: CaseDto?
    @State private var text = ""
    @State private var appealing = false
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?
    @State private var picking = false
    @State private var picked: PhotosPickerItem?

    private func act(_ call: @escaping () async -> ApiResult<CaseDto>) {
        busy = true
        error = nil
        Task {
            switch await call() {
            case .ok(let d, _): c = d; text = ""; note = ""; appealing = false
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        Group {
            if let d = c {
                detail(d)
            } else {
                CircularProgress(color: pal.orange, size: 40, lineWidth: 4).frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task(id: uuid) { if let d = await DriverApi.caseOf(uuid: uuid).value { c = d } }
        .photosPicker(isPresented: $picking, selection: $picked, matching: .any(of: [.images, .videos]))
        .onChange(of: picked) { item in
            guard let item else { return }
            picked = nil
            busy = true
            Task {
                if let part = await filePart(item) {
                    switch await DriverApi.caseFile(uuid: uuid, file: part) {
                    case .ok(let d, _): c = d; error = nil
                    case .failed(let e): error = e.displayMessage
                    case .offline: error = L("error_offline")
                    }
                }
                busy = false
            }
        }
    }

    @ViewBuilder
    private func detail(_ d: CaseDto) -> some View {
        let color = statusColor(d.status ?? "submitted", pal)
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Card {
                    HStack(spacing: 0) {
                        VStack(alignment: .leading, spacing: 0) {
                            Text(caseHeadline(d)).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text([d.number ?? "", d.ride?.number].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        Text(d.statusLabel ?? "").font(.dorr(12, .bold)).foregroundStyle(color)
                            .padding(.horizontal, 12).padding(.vertical, 4)
                            .background(Capsule().fill(color.opacity(0.12)))
                    }
                    if let minor = d.disputedMinor { Text(money(minor, d.currency)).font(.dorr(22, .heavy)).foregroundStyle(pal.ink).padding(.top, 8) }
                    if let s = d.summary { Text(s).font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 6) }
                }

                if let dec = d.decision {
                    Card {
                        Text(L("cs_decision")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                        Text(d.statusLabel ?? "").font(.dorr(16, .semibold)).foregroundStyle(color).padding(.top, 4)
                        if let r = dec.reason { Text(r).font(.dorr(14)).foregroundStyle(pal.ink).padding(.top, 6) }
                    }
                }
                if d.appealedAt != nil && d.decision == nil {
                    Text(L("cs_appealed")).font(.dorr(13, .semibold)).foregroundStyle(pal.warning)
                }

                if let e = d.evidence {
                    Card {
                        Text(L("cs_evidence")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                        VSpace(6)
                        ForEach(Array((e.timeline ?? []).enumerated()), id: \.offset) { _, m in
                            HStack(spacing: 0) {
                                Text(milestoneWord(m.event ?? "")).font(.dorr(13, .semibold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                                if let dist = m.distanceToPickupM {
                                    Text(L("cs_from_pickup", dist)).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.6))
                                }
                                HSpace(8)
                                Text(clockOf(m.at)).font(.dorr(12)).foregroundStyle(pal.ink)
                            }
                            .padding(.vertical, 2)
                        }
                        if let w = e.waiting {
                            Text(L("cs_waiting", (w.pickupS ?? 0) / 60, (w.freeS ?? 0) / 60)).font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 6)
                        }
                        if let r = e.route {
                            Text(L("cs_route", km(r.drivenM), km(r.plannedM))).font(.dorr(13)).foregroundStyle(pal.ink)
                        }
                        ForEach(Array((e.lines ?? []).enumerated()), id: \.offset) { _, l in
                            HStack(spacing: 0) {
                                Text(l.code ?? "").font(.dorr(12)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                                Text(money(l.amountMinor ?? 0, d.currency)).font(.dorr(12, .semibold)).foregroundStyle(pal.ink)
                            }
                            .padding(.top, 2)
                        }
                    }
                }

                Card {
                    Text(L("cs_conversation")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    let messages = d.messages ?? []
                    if messages.isEmpty {
                        Text(L("cs_no_messages")).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6)).padding(.top, 4)
                    }
                    ForEach(Array(messages.enumerated()), id: \.offset) { _, m in
                        let mine = m.from == "me"
                        VStack(alignment: mine ? .trailing : .leading, spacing: 0) {
                            Text(m.body ?? "").font(.dorr(13)).foregroundStyle(pal.ink)
                                .padding(.horizontal, 12).padding(.vertical, 8)
                                .background(RoundedRectangle(cornerRadius: 14).fill(mine ? pal.navy.opacity(0.1) : pal.orange.opacity(0.12)))
                            Text(mine ? clockOf(m.at) : L("cs_team") + " · " + clockOf(m.at)).font(.dorr(10)).foregroundStyle(pal.ink.opacity(0.5))
                        }
                        .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
                        .padding(.top, 8)
                    }
                    let files = d.files ?? []
                    if !files.isEmpty {
                        VSpace(8)
                        ForEach(Array(files.enumerated()), id: \.offset) { _, f in
                            Text("📎 \(f.name ?? "")").font(.dorr(12)).foregroundStyle(pal.navy)
                        }
                    }
                    if d.canMessage == true {
                        VSpace(10)
                        DorrField(value: text, onValueChange: { text = $0 }, label: L("cs_write"), minLines: 2)
                        HStack(spacing: 8) {
                            // D-232: photos of what the captain has (a screenshot, a receipt…).
                            HStack(spacing: 0) {
                                DorrIcon("paperclip", size: 16, tint: pal.navy)
                                Text(L("cs_attach")).font(.dorr(13, .semibold)).foregroundStyle(pal.navy)
                            }
                            .padding(.horizontal, 14).padding(.vertical, 8)
                            .background(Capsule().fill(pal.navy.opacity(0.07)))
                            .contentShape(Capsule())
                            .onTapGesture { if !busy { picking = true } }
                            Spacer(minLength: 0)
                            let can = !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !busy
                            Text(L("cs_reply")).font(.dorr(13, .bold)).foregroundStyle(.white)
                                .padding(.horizontal, 18).padding(.vertical, 8)
                                .background(Capsule().fill(can ? pal.orange : pal.orange.opacity(0.4)))
                                .contentShape(Capsule())
                                .onTapGesture {
                                    guard can else { return }
                                    let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
                                    act { await DriverApi.caseMessage(uuid: uuid, body: ["body": body]) }
                                }
                        }
                        .padding(.top, 8)
                    }
                }

                // D-237: another look — only where the country allows it, once.
                if d.canAppeal == true {
                    Card {
                        if !appealing {
                            Text(L("cs_appeal_hint")).font(.dorr(13)).foregroundStyle(pal.ink)
                            VSpace(8)
                            BigButton(L("cs_appeal"), color: pal.navy) { appealing = true }
                        } else {
                            DorrField(value: note, onValueChange: { note = $0 }, label: L("cs_appeal_why"), minLines: 3)
                            VSpace(8)
                            BigButton(L("cs_appeal_send"), color: pal.navy, enabled: note.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3, busy: busy) {
                                let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                                act { await DriverApi.appealCase(uuid: uuid, body: ["note": n]) }
                            }
                        }
                    }
                }
                if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
            }
            .padding(16)
        }
        .scrollDismissesKeyboard(.interactively)
    }
}

private func milestoneWord(_ event: String) -> String {
    switch event {
    case "accepted": return L("cs_ev_accepted")
    case "arrived": return L("cs_ev_arrived")
    case "started": return L("cs_ev_started")
    case "completed": return L("cs_ev_completed")
    case "canceled": return L("cs_ev_canceled")
    default: return L("cs_ev_no_show")
    }
}

private struct CasePill: View {
    let label: String
    let selected: Bool
    let onClick: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        Text(label).font(.dorr(13, .semibold)).foregroundStyle(selected ? Color.white : pal.navy)
            .padding(.horizontal, 14).padding(.vertical, 8)
            .background(Capsule().fill(selected ? pal.navy : pal.navy.opacity(0.07)))
            .animation(.tween(200), value: selected)
            .contentShape(Capsule())
            .onTapGesture(perform: onClick)
    }
}

/// Compose's `FlowRow`: children left to right (start to end), wrapping onto new lines.
private struct CasesFlow: Layout {
    var spacing: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { y += line + spacing; x = 0; line = 0 }
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
            if x > bounds.minX && x + size.width > bounds.maxX { y += line + spacing; x = bounds.minX; line = 0 }
            s.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}

private func km(_ m: Int?) -> String {
    guard let m else { return "—" }
    return String(format: "%.1f", Double(m) / 1000.0)
}

/// The clock time of a server time, in the phone's zone; empty if missing.
private func clockOf(_ iso: String?) -> String { instantOf(iso).map { timeOfDay($0) } ?? "" }

/// O-63: a photo or a video (a dash cam's clip), as one multipart part named `file` (`filePart`).
private func filePart(_ item: PhotosPickerItem) async -> FilePart? {
    guard var data = try? await item.loadTransferable(type: Data.self) else { return nil }
    let utType = item.supportedContentTypes.first
    var type = utType?.preferredMIMEType ?? "image/jpeg"
    let video = utType?.conforms(to: .movie) == true
    if !video && !(type.contains("png") || type.contains("webp") || type.contains("jpeg") || type.contains("pdf")) {
        // HEIC and the like go up as JPEG, as an Android gallery hands them.
        if let jpeg = UIImage(data: data)?.jpegData(compressionQuality: 0.9) { data = jpeg }
        type = "image/jpeg"
    }
    let ext: String
    if type.contains("png") { ext = "png" }
    else if type.contains("webp") { ext = "webp" }
    else if type.contains("pdf") { ext = "pdf" }
    else if type.contains("quicktime") { ext = "mov" }
    else if type.contains("3gpp") { ext = "3gp" }
    else if type.contains("webm") { ext = "webm" }
    else if type.hasPrefix("video/") { ext = "mp4" }
    else { ext = "jpg" }
    return FilePart(field: "file", filename: "evidence.\(ext)", mimeType: type, data: data)
}
