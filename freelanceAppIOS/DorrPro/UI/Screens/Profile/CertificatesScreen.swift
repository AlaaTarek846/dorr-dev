import SwiftUI
import UniformTypeIdentifiers

// freelanceApp `ui/screens/CertificatesScreen.kt`.

private let maxCertificates = 20
private let maxProofBytes = 5 * 1024 * 1024

/** The freelancer's certificates: the list, and a form with an optional proof (image or PDF, private to the owner). Up to 20. */
struct CertificatesScreen: View {
    let onBack: () -> Void

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor

    @State private var items: [CertificateDto] = []
    @State private var loaded = false
    @State private var error: String?
    /// nil = the list; 0 = a new one; else the id being edited.
    @State private var formId: Int?

    private var editing: CertificateDto? {
        guard let id = formId else { return nil }
        return id == 0 ? CertificateDto(id: 0) : items.first { $0.id == id }
    }

    var body: some View {
        Group {
            if let editing {
                CertificateForm(initial: editing, onBack: { formId = nil }, onChanged: changed)
                    .id(formId)
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) {
            let result = await ListsApi.certificates()
            if let list = result.value {
                items = list
                loaded = true
                error = nil
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private var list: some View {
        Page(title: L("certificates_title"), onBack: onBack) {
            if loaded {
                if items.count < maxCertificates { SettingsPrimaryButton(text: L("certificates_add")) { formId = 0 } }
                if items.isEmpty { Text(L("common_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                ForEach(items, id: \.id) { c in
                    Card {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(verbatim: c.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: c.issuer ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut)
                            let dates = [c.issuedOn, c.expiresOn].compactMap { $0 }.joined(separator: " → ")
                            if !dates.isEmpty {
                                Text(verbatim: dates + (c.isExpired == true ? " · " + L("certificate_expired") : ""))
                                    .font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .contentShape(Rectangle())
                        .onTapGesture { formId = c.id }
                    }
                }
            }
        }
        .messageToast(error)
    }

    private func changed(_ saved: CertificateDto?, _ removedId: Int?) {
        if let removedId { items.removeAll { $0.id == removedId } }
        if let saved {
            if let at = items.firstIndex(where: { $0.id == saved.id }) { items[at] = saved } else { items.insert(saved, at: 0) }
        }
        formId = nil
    }
}

private struct CertificateForm: View {
    let initial: CertificateDto
    let onBack: () -> Void
    let onChanged: (CertificateDto?, Int?) -> Void

    @Environment(\.wa) private var wa
    @State private var title: String
    @State private var issuer: String
    @State private var issued: String
    @State private var expires: String
    @State private var url: String
    /// The chosen proof, ready to upload.
    @State private var proofFile: FilePart?
    @State private var proofName: String?
    @State private var removeProof = false
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var picking = false
    @State private var shown: ProfileShownFile?

    init(initial: CertificateDto, onBack: @escaping () -> Void, onChanged: @escaping (CertificateDto?, Int?) -> Void) {
        self.initial = initial
        self.onBack = onBack
        self.onChanged = onChanged
        _title = State(initialValue: initial.title ?? "")
        _issuer = State(initialValue: initial.issuer ?? "")
        _issued = State(initialValue: initial.issuedOn ?? "")
        _expires = State(initialValue: initial.expiresOn ?? "")
        _url = State(initialValue: initial.credentialUrl ?? "")
        _proofName = State(initialValue: initial.proof?.name)
    }

    private var isNew: Bool { (initial.id ?? 0) == 0 }
    private var hasName: Bool { !(proofName ?? "").isEmpty && !removeProof }

    var body: some View {
        Page(title: L(isNew ? "certificates_add" : "common_edit"), onBack: onBack) {
            FormIntro(icon: "graduationcap.fill", title: L("certificate_intro_title"), text: L("certificate_intro"))
            FormGroup {
                DorrTextField(value: $title, label: L("certificate_title"), icon: "graduationcap.fill")
                DorrTextField(value: $issuer, label: L("certificate_issuer"), icon: "building.2.fill")
                DateField(value: $issued, label: L("certificate_issued"))
                DateField(value: $expires, label: L("certificate_expires"))
                DorrTextField(value: $url, label: L("certificate_url"), placeholder: "https://", icon: "link", keyboard: .URL)
                    .environment(\.layoutDirection, .leftToRight)
                FieldLabel(text: L("certificate_proof"))
                if hasName { Text(verbatim: proofName ?? "").font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                HStack(spacing: 16) {
                    ProfileTextLink(text: L("certificate_proof_pick")) { picking = true }
                    if initial.proof != nil && proofFile == nil && !removeProof {
                        ProfileTextLink(text: L("certificate_proof_view"), enabled: !busy, action: viewProof)
                    }
                    if hasName {
                        ProfileTextLink(text: L("certificate_proof_remove"), muted: true) {
                            proofFile = nil
                            proofName = nil
                            removeProof = initial.proof != nil
                        }
                    }
                }
            }
            SettingsPrimaryButton(
                text: L("profile_save"),
                enabled: title.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && issuer.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2,
                loading: busy, action: save
            )
            if !isNew {
                ProfileTextLink(text: L("common_delete")) { confirmDelete = true }.padding(8)
            }
        }
        .messageToast(error)
        .profileDeleteConfirm(isPresented: $confirmDelete, onConfirm: delete)
        .fileImporter(isPresented: $picking, allowedContentTypes: [.jpeg, .png, .pdf]) { result in
            guard case .success(let picked) = result else { return }
            guard let file = copyProof(picked) else {
                error = L("certificate_proof_type")
                return
            }
            if file.data.count > maxProofBytes {
                error = L("certificate_proof_large")
            } else {
                error = nil
                proofFile = file
                proofName = file.filename
                removeProof = false
            }
        }
        .sheet(item: $shown) { ProfileFileViewer(url: $0.url).ignoresSafeArea() }
    }

    private func save() {
        busy = true
        error = nil
        var fields: [String: String] = [
            "title": title.trimmingCharacters(in: .whitespacesAndNewlines),
            "issuer": issuer.trimmingCharacters(in: .whitespacesAndNewlines),
            "issued_on": issued,
            "expires_on": expires,
            "credential_url": url.trimmingCharacters(in: .whitespacesAndNewlines),
        ]
        if removeProof { fields["remove_proof"] = "1" }
        let part = proofFile
        let sent = fields
        Task {
            let result = isNew ? await ListsApi.addCertificate(sent, part) : await ListsApi.updateCertificate(initial.id ?? 0, sent, part)
            busy = false
            if let saved = result.value { onChanged(saved, nil) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        }
    }

    private func delete() {
        busy = true
        Task {
            let failed = await ListsApi.deleteCertificate(initial.id ?? 0, offline: L("login_error_offline"), generic: L("login_error_generic"))
            busy = false
            if let failed { error = failed } else { onChanged(nil, initial.id) }
        }
    }

    /// Downloads the proof (signed in) into the cache and opens it in the viewer.
    private func viewProof() {
        busy = true
        Task {
            let id = initial.id ?? 0
            let data = await ListsApi.certificateProof(id)
            busy = false
            let ext = initial.proof?.name.flatMap { name -> String? in
                guard let dot = name.lastIndex(of: ".") else { return nil }
                let e = String(name[name.index(after: dot)...])
                return (2...4).contains(e.count) ? e : nil
            } ?? "pdf"
            if let data, let file = profileCacheFile(data, name: "proof_\(id).\(ext)") { shown = file } else { error = L("login_error_generic") }
        }
    }
}

/** The chosen document as the `proof` part; nil when it is not a JPG, PNG or PDF. */
private func copyProof(_ url: URL) -> FilePart? {
    guard let mime = profileMimeType(of: url), let data = profileReadPicked(url) else { return nil }
    let ext = mime == "application/pdf" ? "pdf" : mime == "image/png" ? "png" : "jpg"
    return FilePart(field: "proof", filename: "proof_upload.\(ext)", mimeType: mime, data: data)
}
