import SwiftUI
import UniformTypeIdentifiers

// freelanceApp `ui/screens/TeamVerificationScreen.kt`.

private let maxPaperBytes = 8 * 1024 * 1024
private let maxPapers = 10
private let tvRed = Color(hex: 0xDC2626)

/// FS-209c: verifying a team (FS-202b) — optional, and the team's Owners only. The Owners add the team's papers (a title and a JPG / PNG /
/// PDF up to 8 MB, up to ten), send them for review, and read the staff's answer; while the papers are with the staff nothing can change.
/// It reads when it opens, when a notice arrives (the decision) and when the connection returns; an upload, a removal and the sending each
/// answer with the new state.
struct TeamVerificationScreen: View {
    let teamUuid: String
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var state: TeamVerificationDto?
    @State private var error: String?
    @State private var info: String?
    @State private var busy = false
    @State private var adding = false
    /// Kept here (not in the sheet) so the sheet stays open while a paper goes up.
    @State private var addBusy = false
    @State private var shown: ProfileShownFile?

    var body: some View {
        Page(title: L("tv_title"), onBack: onBack) {
            if let v = state {
                content(v)
            } else if error == nil {
                SkeletonDetail()
            }
            if let info { Text(verbatim: info).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading) }
        }
        .messageToast(error)
        .task(id: "\(teamUuid)|\(network.reconnectTick)") { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .sheet(item: $shown) { ProfileFileViewer(url: $0.url).ignoresSafeArea() }
        .cmSheet(isPresented: $adding, locked: addBusy) {
            AddPaperSheet(teamUuid: teamUuid, busy: $addBusy, onUploaded: {
                adding = false
                Task { await load() }
            })
        }
    }

    @ViewBuilder
    private func content(_ v: TeamVerificationDto) -> some View {
        let status = v.status ?? "none"
        let documents = v.documents ?? []
        StatusCard(v: v)
        Text(L("tv_intro")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)

        Text(L("tv_docs_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
        if documents.isEmpty {
            Text(L("tv_docs_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
        }
        ForEach(documents) { d in
            PaperRow(d: d, canEdit: v.canEdit == true, busy: busy, onView: { view(d) }, onRemove: { remove(d) })
        }

        if v.canEdit == true {
            if documents.count < maxPapers {
                SettingsPrimaryButton(text: L("tv_add"), enabled: !busy) { adding = true }
            } else {
                Text(L("tv_limit")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            }
        } else if status == "submitted" {
            Text(L("tv_locked")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3).frame(maxWidth: .infinity, alignment: .leading)
        }
        if v.canEdit == true {
            SettingsPrimaryButton(text: L("tv_submit"), enabled: v.canSubmit == true && !busy, loading: busy, action: submit)
            if v.canSubmit != true && documents.isEmpty {
                Text(L("tv_submit_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            }
        }

        if let last = v.lastRequest {
            Text(L("tv_last_title")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            Card {
                let lastStatus = last.status ?? "submitted"
                HStack(spacing: 8) { StatusPill(text: tvStatusText(lastStatus), tint: tvTint(lastStatus)) }
                if let at = last.submittedAt { Text(L("tv_req_submitted", teamDate(at) ?? at)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                if let at = last.decidedAt { Text(L("tv_req_decided", teamDate(at) ?? at)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                if let reason = last.reason, !reason.agreementTrimmed.isEmpty {
                    Text(L("tv_reason", reason)).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                }
                ForEach(last.documents ?? []) { d in
                    PaperRow(d: d, canEdit: false, busy: busy, onView: { view(d) }, onRemove: {})
                }
            }
        }
    }

    private func load() async {
        let result = await TeamsApi.verification(teamUuid)
        if let data = result.value {
            state = data
            error = nil
        } else if state == nil {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    /// Downloads one of the team's papers (private, signed in) into the cache and opens it.
    private func view(_ doc: TeamVerificationDocDto) {
        busy = true
        error = nil
        Task {
            let uuid = doc.uuid ?? ""
            let data = await TeamsApi.verificationFile(teamUuid, uuid)
            busy = false
            if let data, let file = profileCacheFile(data, name: "team_verification_\(uuid).\(profileSniffExtension(data))") {
                shown = file
            } else {
                error = L("login_error_generic")
            }
        }
    }

    private func remove(_ doc: TeamVerificationDocDto) {
        busy = true
        error = nil
        info = nil
        Task {
            let failed = await TeamsApi.deleteVerificationDocument(teamUuid, doc.uuid ?? "", offline: L("login_error_offline"), generic: L("login_error_generic"))
            if let failed { error = failed } else { await load() }
            busy = false
        }
    }

    private func submit() {
        busy = true
        error = nil
        info = nil
        Task {
            let result = await TeamsApi.submitVerification(teamUuid)
            if let data = result.value {
                state = data
                info = L("tv_submitted_ok")
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
        }
    }
}

private func tvStatusText(_ status: String) -> String {
    switch status {
    case "verified", "approved": return L("tv_status_verified")
    case "rejected": return L("tv_status_rejected")
    case "submitted": return L("tv_status_submitted")
    default: return L("tv_status_none")
    }
}

private func tvTint(_ status: String) -> Color {
    switch status {
    case "verified", "approved": return Tints.blue
    case "rejected": return Tints.rose
    default: return Tints.amber
    }
}

/// Where the team stands: not verified, with the staff, turned down (with why), or verified.
private struct StatusCard: View {
    @Environment(\.wa) private var wa
    let v: TeamVerificationDto

    var body: some View {
        let status = v.status ?? "none"
        let tint = tvTint(status)
        HStack(spacing: 12) {
            Image(systemName: "checkmark.seal.fill").font(.system(size: 22, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 44, height: 44)
                .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: Corners.small, style: .continuous))
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: tvStatusText(status)).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                switch status {
                case "verified":
                    if let at = v.verifiedAt {
                        Text(L("tv_verified_at", teamDate(at) ?? at)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
                    }
                case "rejected":
                    Text(L("tv_rejected_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    if let reason = v.lastRequest?.reason, !reason.agreementTrimmed.isEmpty {
                        Text(L("tv_reason", reason)).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    }
                case "submitted":
                    Text(L("tv_submitted_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                default:
                    Text(L("tv_none_hint")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

/// One paper: its title and file, the way to open it, and (for a draft of an Owner) to remove it.
private struct PaperRow: View {
    @Environment(\.wa) private var wa
    let d: TeamVerificationDocDto
    let canEdit: Bool
    let busy: Bool
    let onView: () -> Void
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: "doc.text.fill").font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red).frame(width: 22, height: 22)
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: d.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                if let name = d.file?.name, !name.agreementTrimmed.isEmpty {
                    Text(verbatim: name).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if d.file != nil {
                Button(action: onView) {
                    Text(L("tv_view")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(4).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
            if canEdit {
                Button(action: onRemove) {
                    Text(L("tv_remove")).font(.dorr(13, .bold)).foregroundStyle(tvRed).padding(4).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(busy)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.card)
    }
}

/// Adding one paper: a title and the file (JPG, PNG or PDF, up to 8 MB). Shown with `.cmSheet`, which closes itself — `onUploaded` is where
/// the page reads again.
private struct AddPaperSheet: View {
    @Environment(\.wa) private var wa
    let teamUuid: String
    @Binding var busy: Bool
    let onUploaded: () -> Void

    @State private var title = ""
    @State private var picked: FilePart?
    @State private var picking = false
    @State private var error: String?

    private func take(_ url: URL) {
        let mime = profileMimeType(of: url)
        guard let mime, let data = profileReadPicked(url) else {
            picked = nil
            error = L("tv_file_type")
            return
        }
        if data.count > maxPaperBytes {
            picked = nil
            error = L("tv_file_large")
            return
        }
        picked = FilePart(field: "file", filename: url.lastPathComponent.isEmpty ? "file" : url.lastPathComponent, mimeType: mime, data: data)
        error = nil
    }

    private func upload() {
        guard let file = picked else { return }
        busy = true
        error = nil
        Task {
            let result = await TeamsApi.addVerificationDocument(teamUuid, title: title.agreementTrimmed, file)
            busy = false
            if result.value != nil {
                onUploaded()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text(L("tv_add_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                DorrTextField(value: agreementLimit($title, 120), label: L("tv_doc_title_label"), enabled: !busy)
                VStack(alignment: .leading, spacing: 4) {
                    FieldLabel(text: L("tv_file_label"))
                    if let picked { Text(L("tv_file_chosen", picked.filename)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    Button { picking = true } label: {
                        Text(L("tv_choose")).font(.dorr(14, .bold)).foregroundStyle(wa.red).padding(.vertical, 4).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                    Text(L("tv_file_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
                SettingsPrimaryButton(text: L("tv_upload"), enabled: title.agreementTrimmed.count >= 2 && picked != nil, loading: busy, action: upload)
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(tvRed).frame(maxWidth: .infinity, alignment: .leading) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 24)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(wa.settingsCard.ignoresSafeArea())
        .fileImporter(isPresented: $picking, allowedContentTypes: [.jpeg, .png, .pdf]) { result in
            if case .success(let url) = result { take(url) }
        }
    }
}
