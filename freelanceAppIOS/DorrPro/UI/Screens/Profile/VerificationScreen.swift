import SwiftUI
import UniformTypeIdentifiers

// freelanceApp `ui/screens/VerificationScreen.kt`.

private let maxDocBytes = 8 * 1024 * 1024

/**
 * Verifying the account by hand (FL-107 / FL-108): what is required for the freelancer's country, uploading each document
 * (front and back, from a file or the camera), and sending them for review. The state comes from one request when the page
 * opens (and when the connection returns); an upload, a delete and the submit each answer with one request. While the
 * documents are under review nothing can be changed.
 */
struct VerificationScreen: View {
    let onBack: () -> Void
    let onOpenProfile: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var overview: VerificationOverviewDto?
    @State private var error: String?
    @State private var info: String?
    @State private var busy = false
    /// nil = the list; else the type being uploaded.
    @State private var formTypeId: Int?
    @State private var shown: ProfileShownFile?

    private var typeInForm: DocTypeDto? {
        guard let id = formTypeId else { return nil }
        return overview?.required?.compactMap(\.type).first { $0.id == id }
    }

    var body: some View {
        Group {
            if let type = typeInForm {
                DocumentForm(type: type, onBack: { formTypeId = nil }, onUploaded: {
                    Task {
                        await reload()
                        formTypeId = nil
                    }
                })
                .id(formTypeId)
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) { await reload() }
    }

    private var list: some View {
        Page(title: L("verification_title"), onBack: onBack) {
            if let current = overview {
                let editable = current.step != "under_review"
                let required = current.required ?? []
                Card {
                    Text(verbatim: stepText(current.step, current.status)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    if let reason = current.lastRequest?.reason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(L("verification_reason", reason)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    }
                }

                if current.needsResidency == true {
                    Card {
                        Text(L("verification_residency_hint")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        ProfileTextLink(text: L("verification_open_profile"), action: onOpenProfile)
                    }
                }

                Text(L("verification_required_docs")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                if required.isEmpty {
                    Text(L("verification_none_required")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                }
                ForEach(Array(required.enumerated()), id: \.offset) { _, row in
                    RequiredRow(row: row, editable: editable, busy: busy, onAdd: { formTypeId = row.type?.id }, onView: view, onDelete: deleteDraft)
                }

                let others = current.others ?? []
                if !others.isEmpty {
                    Text(L("verification_other_docs")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    ForEach(others, id: \.id) { doc in
                        Card {
                            Text(verbatim: doc.type?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: docStatusText(doc)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                    }
                }

                let blockers = (current.submitBlockers ?? []).compactMap { blockerText($0, required) }
                if editable && !blockers.isEmpty {
                    Card { ForEach(blockers, id: \.self) { Text(verbatim: "• \($0)").font(.dorr(13)).foregroundStyle(wa.settingsMut) } }
                }
                if editable && current.status != "verified" {
                    SettingsPrimaryButton(text: L("verification_submit"), enabled: current.canSubmit == true, loading: busy, action: submit)
                }
            }
            if let info { Text(verbatim: info).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
        }
        .messageToast(error)
        .sheet(item: $shown) { ProfileFileViewer(url: $0.url).ignoresSafeArea() }
    }

    private func reload() async {
        let result = await VerificationApi.overview()
        if let data = result.value {
            overview = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private func submit() {
        busy = true
        error = nil
        info = nil
        Task {
            let result = await VerificationApi.submit()
            busy = false
            if let data = result.value {
                overview = data
                info = L("verification_submitted_ok")
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func deleteDraft(_ doc: VerificationDocDto) {
        busy = true
        error = nil
        Task {
            let failed = await VerificationApi.deleteDocument(doc.id ?? 0, offline: L("login_error_offline"), generic: L("login_error_generic"))
            if let failed { error = failed } else { await reload() }
            busy = false
        }
    }

    /// Downloads one of my verification files (signed in, private) into the cache and opens it.
    private func view(_ doc: VerificationDocDto, _ side: String) {
        busy = true
        Task {
            let id = doc.id ?? 0
            let data = await VerificationApi.file(id, side)
            busy = false
            if let data, let file = profileCacheFile(data, name: "verification_\(id)_\(side).\(profileSniffExtension(data))") {
                shown = file
            } else {
                error = L("login_error_generic")
            }
        }
    }
}

private struct RequiredRow: View {
    @Environment(\.wa) private var wa
    let row: RequiredDocDto
    let editable: Bool
    let busy: Bool
    let onAdd: () -> Void
    let onView: (VerificationDocDto, String) -> Void
    let onDelete: (VerificationDocDto) -> Void

    var body: some View {
        let doc = row.document
        Card {
            HStack(alignment: .top) {
                Text(verbatim: row.type?.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: doc.map(docStatusText) ?? L("doc_missing")).font(.dorr(12, .bold)).foregroundStyle(wa.red)
            }
            if let instructions = row.type?.instructions, !instructions.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: instructions).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if let doc {
                if let number = doc.number, !number.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: number).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if let reason = doc.rejectionReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(L("verification_reason", reason)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            FlowLayout(spacing: 16) {
                if editable {
                    ProfileTextLink(text: L(doc == nil ? "doc_add" : "doc_replace"), enabled: !busy, action: onAdd)
                }
                if let doc, doc.hasFile("front") {
                    ProfileTextLink(text: L("doc_view_front"), enabled: !busy) { onView(doc, "front") }
                }
                if let doc, doc.hasFile("back") {
                    ProfileTextLink(text: L("doc_view_back"), enabled: !busy) { onView(doc, "back") }
                }
                if let doc, doc.status ?? "draft" == "draft", editable {
                    ProfileTextLink(text: L("doc_delete_draft"), muted: true, enabled: !busy) { onDelete(doc) }
                }
            }
        }
    }
}

/** Uploading one document: its number and expiry when the type asks for them, and the front (and back) as a photo or a file. */
private struct DocumentForm: View {
    let type: DocTypeDto
    let onBack: () -> Void
    let onUploaded: () -> Void

    @State private var number = ""
    @State private var expires = ""
    @State private var front: FilePart?
    @State private var back: FilePart?
    /// Which side the picker or the camera that is open now is for.
    @State private var pendingSide = "front"
    @State private var picking = false
    @State private var cameraOpen = false
    @State private var busy = false
    @State private var error: String?

    private var ready: Bool {
        front != nil && (type.needsBackSide != true || back != nil)
            && (type.hasNumber != true || !number.trimmingCharacters(in: .whitespaces).isEmpty)
            && (type.hasExpiry != true || !expires.trimmingCharacters(in: .whitespaces).isEmpty)
    }

    var body: some View {
        Page(title: type.name ?? "", onBack: onBack) {
            FormIntro(icon: "person.text.rectangle", title: type.name ?? "", text: type.instructions ?? "")
            FormGroup {
                if type.hasNumber == true {
                    DorrTextField(value: $number.profileTake(64), label: L("doc_number"), icon: "person.text.rectangle", keyboard: .asciiCapable)
                }
                if type.hasExpiry == true { DateField(value: $expires, label: L("doc_expires")) }
            }
            SideCard(title: L("doc_front"), file: front, onPick: { pendingSide = "front"; picking = true }, onCamera: { pendingSide = "front"; cameraOpen = true })
            if type.needsBackSide == true {
                SideCard(title: L("doc_back"), file: back, onPick: { pendingSide = "back"; picking = true }, onCamera: { pendingSide = "back"; cameraOpen = true })
            }
            SettingsPrimaryButton(text: L("doc_upload"), enabled: ready, loading: busy, action: upload)
        }
        .messageToast(error)
        .fileImporter(isPresented: $picking, allowedContentTypes: [.jpeg, .png, .pdf]) { result in
            guard case .success(let url) = result else { return }
            let mime = profileMimeType(of: url)
            guard let data = profileReadPicked(url) else {
                error = L("doc_file_type")
                return
            }
            take(data, mime: mime ?? "application/octet-stream", side: pendingSide)
        }
        .fullScreenCover(isPresented: $cameraOpen) {
            ProfileCameraPicker { data in
                cameraOpen = false
                if let data { take(data, mime: "image/jpeg", side: pendingSide) }
            }
            .ignoresSafeArea()
        }
    }

    private func take(_ data: Data, mime: String, side: String) {
        Task {
            let file = await Task.detached { prepareDocFile(data, mimeType: mime, name: "doc_\(side)") }.value
            if let file {
                if file.data.count > maxDocBytes {
                    error = L("doc_file_large")
                } else {
                    error = nil
                    if side == "front" { front = file } else { back = file }
                }
            } else {
                error = L("doc_file_type")
            }
        }
    }

    private func upload() {
        guard let front else { return }
        busy = true
        error = nil
        var fields = ["document_type_id": String(type.id ?? 0)]
        if type.hasNumber == true { fields["number"] = number.trimmingCharacters(in: .whitespacesAndNewlines) }
        if type.hasExpiry == true { fields["expires_on"] = expires }
        let sent = fields
        let backPart = type.needsBackSide == true ? back : nil
        Task {
            let result = await VerificationApi.upload(sent, front: front, back: backPart)
            busy = false
            if result.value != nil {
                // The identity files do not stay in memory once they are on the server.
                self.front = nil
                self.back = nil
                onUploaded()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

private struct SideCard: View {
    @Environment(\.wa) private var wa
    let title: String
    let file: FilePart?
    let onPick: () -> Void
    let onCamera: () -> Void

    var body: some View {
        Card {
            FieldLabel(text: title)
            if let file { Text(verbatim: L("doc_chosen") + ": " + file.filename).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            HStack(spacing: 20) {
                ProfileTextLink(text: L("doc_choose"), action: onPick)
                ProfileTextLink(text: L("doc_camera"), action: onCamera)
            }
            .padding(.top, 2)
        }
    }
}

/// `stepText` of VerificationScreen.kt — the verification step in words.
func stepText(_ step: String?, _ status: String?) -> String {
    switch step {
    case "complete_profile": return L("step_complete_profile")
    case "submit_verification": return L("step_submit_verification")
    case "under_review": return L("step_under_review")
    case "fix_documents": return L("step_fix_documents")
    case "suspended": return L("step_suspended")
    case "blocked": return L("step_blocked")
    default: return status == "verified" ? L("status_verified") : (status ?? "")
    }
}

private func docStatusText(_ doc: VerificationDocDto) -> String {
    let status = doc.status ?? "draft"
    if doc.isExpired == true || status == "expired" { return L("doc_status_expired") }
    switch status {
    case "draft": return L("doc_status_draft")
    case "pending": return L("doc_status_pending")
    case "approved": return L("doc_status_approved")
    case "rejected": return L("doc_status_rejected")
    case "replaced": return L("doc_status_replaced")
    default: return status
    }
}

private func blockerText(_ code: String, _ required: [RequiredDocDto]) -> String? {
    func nameOf(_ typeCode: String) -> String { required.first { $0.type?.code == typeCode }?.type?.name ?? typeCode }
    if code == "not_allowed" { return L("verification_blocker_not_allowed") }
    if code == "residency" { return L("verification_blocker_residency") }
    if code.hasPrefix("missing:") { return L("verification_blocker_missing", nameOf(String(code.dropFirst("missing:".count)))) }
    if code.hasPrefix("expired:") { return L("verification_blocker_expired", nameOf(String(code.dropFirst("expired:".count)))) }
    return nil
}
