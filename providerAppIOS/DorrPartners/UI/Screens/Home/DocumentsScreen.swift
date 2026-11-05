// providerApp `ui/screens/DocumentsScreen.kt`
import SwiftUI

/// The server takes up to 10MB a side (DocumentUploadRequest); a scaled photo is far smaller.
private let maxDocBytes = 10 * 1024 * 1024

/// The establishment's papers (C-02, A-06): what is required for its country and services, the state of each upload, uploading
/// one (front and back from the camera or the files, its number and expiry when the type asks for them), and sending the
/// establishment for review. Read once when the page opens, when the connection returns and when a live notice arrives (a paper
/// reviewed); an upload and the submit each answer with one request. Only the owner uploads the establishment's papers (the
/// server checks it too). With `driverUuid`, one driver's licence (C-03) instead.
@MainActor
struct DocumentsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    let onBack: () -> Void
    var driverUuid: String?

    @State private var docs: DocumentsDto?
    @State private var establishment: EstablishmentDto?
    @State private var error: String?
    @State private var info: String?
    @State private var busy = false
    /// nil = the list; else the type being uploaded.
    @State private var formTypeId: Int?

    var body: some View {
        ZStack {
            if let id = formTypeId, let type = docs?.required?.first(where: { $0.id == id }) {
                DocumentForm(
                    type: type,
                    subject: driverUuid != nil ? "driver" : "establishment",
                    subjectUuid: driverUuid,
                    onBack: { formTypeId = nil },
                    onUploaded: {
                        Task {
                            await reload()
                            formTypeId = nil
                        }
                    }
                )
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    private var list: some View {
        let isOwner = session.membership?.isOwner == true
        // A driver's licence (C-03): the driver themself or someone with fleet.drivers uploads it; the establishment's own
        // status and review are not part of that page.
        let forDriver = driverUuid != nil
        let status = forDriver ? nil : establishment?.status
        // Papers can change until the review starts, and again after a refusal; an approved establishment may replace one.
        let editable = forDriver ? true : isOwner && status != "under_review"
        return Page(title: L(forDriver ? "drv_docs_title" : "pd_title"), onBack: onBack) {
            if let current = docs {
                let required = current.required ?? []
                let missing = current.missing ?? []
                FormIntro(icon: "doc.text.fill", title: L("pd_intro_title"),
                          text: L(forDriver ? "drv_docs_intro" : (isOwner ? "pd_intro_body" : "pd_owner_only")))
                if let status {
                    Card {
                        Text(verbatim: establishmentStatusLabel(status)).font(.dorr(15, .heavy)).foregroundStyle(establishmentStatusColor(status))
                        if status == "rejected" || status == "suspended",
                           let reason = establishment?.statusReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                            Text(L("pd_reason", reason)).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        }
                        if status == "under_review" { Text(L("pd_under_review")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                    }
                }

                if required.isEmpty {
                    Text(L("pd_none_required")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                }
                ForEach(required) { type in
                    let doc = current.documents?.first { $0.documentTypeId == type.id }
                    RequiredRow(type: type, doc: doc, missing: missing.contains(type.code ?? ""), editable: editable, busy: busy) { formTypeId = type.id }
                }

                if !forDriver && isOwner && (status == "draft" || status == "rejected") {
                    if !missing.isEmpty {
                        Text(L("pd_missing_count", missing.count)).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    SettingsPrimaryButton(text: L("pd_submit"), enabled: missing.isEmpty, loading: busy, action: submit)
                }
            } else if error == nil {
                ProgressView().tint(wa.red).frame(width: 28, height: 28).padding(.top, 40)
            }
            if let info { Text(verbatim: info).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
        }
        .messageToast(error)
    }

    private func reload() async {
        let result = await WorkspaceApi.documents(driverUuid: driverUuid)
        if let data = result.value {
            docs = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        if let show = await WorkspaceApi.establishment().value {
            establishment = show.establishment
            Session.shared.setMembership(show.membership)
        }
    }

    private func submit() {
        busy = true
        error = nil
        info = nil
        Task {
            let result = await WorkspaceApi.submit()
            busy = false
            if let data = result.value {
                establishment = data
                info = L("pd_submitted_ok")
            } else {
                // `provider_documents_missing` comes with the server's own sentence ("complete the papers first").
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            await reload()
        }
    }
}

struct RequiredRow: View {
    @Environment(\.wa) private var wa
    let type: DocumentTypeDto
    let doc: ProviderDocumentDto?
    let missing: Bool
    let editable: Bool
    let busy: Bool
    let onAdd: () -> Void

    var body: some View {
        let state = docState(doc, missing: missing, mut: wa.settingsMut)
        Card {
            HStack(alignment: .top) {
                Text(verbatim: type.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: state.0).font(.dorr(12, .bold)).foregroundStyle(state.1)
            }
            if let instructions = type.instructions, !instructions.trimmingCharacters(in: .whitespaces).isEmpty {
                Text(verbatim: instructions).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if let doc {
                if let number = doc.number, !number.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(L("pd_number_line", number)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if let expires = doc.expiresOn, !expires.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(L("pd_expires_line", expires)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if doc.status == "rejected", let reason = doc.rejectionReason, !reason.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(L("pd_reason", reason)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
            if editable {
                ProfileTextLink(text: L(doc == nil ? "pd_upload" : "pd_replace"), enabled: !busy, action: onAdd)
            }
        }
    }
}

/// What to say about one required paper: missing, waiting, approved, refused or expired.
func docState(_ doc: ProviderDocumentDto?, missing: Bool, mut: Color) -> (String, Color) {
    guard let doc else { return (L("pd_state_missing"), Color(hex: 0xDC2626)) }
    switch doc.status {
    case "approved": return (L("pd_state_approved"), Color(hex: 0x16A34A))
    case "pending": return (L("pd_state_pending"), Color(hex: 0xD97706))
    case "rejected": return (L("pd_state_rejected"), Color(hex: 0xDC2626))
    default:
        if doc.status == "expired" || missing { return (L("pd_state_expired"), Color(hex: 0xDC2626)) }
        return (doc.status ?? "", mut)
    }
}

/// Uploading one paper or photo of `subject` (`establishment`, `vehicle` with `subjectUuid`, `driver` with `subjectUuid`): its
/// number and expiry when the type asks for them, and the front (and back). A photo (`kind = photo`, e.g. the vehicle showing its
/// plate) is an image only; a paper may also be a PDF.
struct DocumentForm: View {
    let type: DocumentTypeDto
    let subject: String
    let subjectUuid: String?
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

    private var photo: Bool { type.kind == "photo" }

    private var ready: Bool {
        front != nil && (type.needsBackSide != true || back != nil)
            && (type.hasNumber != true || !number.trimmingCharacters(in: .whitespaces).isEmpty)
            && (type.hasExpiry != true || !expires.trimmingCharacters(in: .whitespaces).isEmpty)
    }

    var body: some View {
        let intro = (type.instructions ?? "").trimmingCharacters(in: .whitespaces)
        Page(title: type.name ?? "", onBack: onBack) {
            FormIntro(icon: "person.text.rectangle", title: type.name ?? "", text: !intro.isEmpty ? intro : (photo ? L("vh_photo_hint") : ""))
            FormGroup {
                if type.hasNumber == true {
                    DorrTextField(value: $number.profileTake(64), label: L("pd_number"), icon: "person.text.rectangle", keyboard: .asciiCapable)
                }
                if type.hasExpiry == true { DateField(value: $expires, label: L("pd_expires")) }
            }
            SideCard(title: L(photo ? "vh_photo_side" : "pd_front"), file: front, onPick: { pendingSide = "front"; picking = true },
                     onCamera: { pendingSide = "front"; cameraOpen = true })
            if type.needsBackSide == true {
                SideCard(title: L("pd_back"), file: back, onPick: { pendingSide = "back"; picking = true }, onCamera: { pendingSide = "back"; cameraOpen = true })
            }
            SettingsPrimaryButton(text: L("pd_send"), enabled: ready, loading: busy, action: upload)
        }
        .messageToast(error)
        // A photo is an image only; a paper may also be a PDF.
        .fileImporter(isPresented: $picking, allowedContentTypes: photo ? [.jpeg, .png] : [.jpeg, .png, .pdf]) { result in
            guard case .success(let url) = result else { return }
            let mime = profileMimeType(of: url)
            guard let data = profileReadPicked(url) else {
                error = L("pd_file_type")
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
                    error = L("pd_file_large")
                } else {
                    error = nil
                    if side == "front" { front = file } else { back = file }
                }
            } else {
                error = L("pd_file_type")
            }
        }
    }

    private func upload() {
        guard let front else { return }
        busy = true
        error = nil
        var fields = ["subject": subject, "document_type_id": String(type.id)]
        if let subjectUuid { fields["subject_uuid"] = subjectUuid }
        if type.hasNumber == true { fields["number"] = number.trimmingCharacters(in: .whitespacesAndNewlines) }
        if type.hasExpiry == true { fields["expires_on"] = expires }
        let sent = fields
        let backPart = type.needsBackSide == true ? back : nil
        Task {
            let result = await WorkspaceApi.upload(sent, front: front, back: backPart)
            busy = false
            if result.value != nil {
                // The papers do not stay in memory once they are on the server.
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
    let title: String
    let file: FilePart?
    let onPick: () -> Void
    let onCamera: () -> Void

    var body: some View {
        Card {
            FieldLabel(text: title)
            if file != nil { Text(L("pd_chosen")).font(.dorr(12, .semibold)).foregroundStyle(Color(hex: 0x16A34A)) }
            HStack(spacing: 20) {
                ProfileTextLink(text: L("pd_camera"), action: onCamera)
                ProfileTextLink(text: L("pd_gallery"), action: onPick)
            }
            .padding(.top, 2)
        }
    }
}
