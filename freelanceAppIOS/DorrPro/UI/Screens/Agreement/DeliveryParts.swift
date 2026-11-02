// freelanceApp `ui/screens/DeliveryParts.kt`
import SwiftUI
import UniformTypeIdentifiers

private let maxDeliveryFiles = 10

/// A file picked from the phone, kept until the delivery is sent (`PickedFile` of OfferScreens.kt, named apart for this area).
struct AgreementPickedFile: Identifiable {
    let id = UUID()
    let name: String
    let type: String
    let bytes: Data

    /// Reads a file the document picker gave (it is outside the app's sandbox until we ask for access).
    static func read(_ url: URL) -> AgreementPickedFile? {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        guard let data = try? Data(contentsOf: url) else { return nil }
        let mime = UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
        return AgreementPickedFile(name: url.lastPathComponent, type: mime, bytes: data)
    }
}

/// FL-601: the note and the files of a delivery. Sent as one new version of the part. The content of its form sheet
/// (`.formSheet(… fullHeight: true)` on the agreement page).
struct DeliverDialog: View {
    @Environment(\.wa) private var wa
    let busy: Bool
    let error: String?
    let onSend: (String, [AgreementPickedFile]) -> Void
    @State private var note = ""
    @State private var picks: [AgreementPickedFile] = []
    @State private var picking = false

    var body: some View {
        DorrTextField(value: agreementLimit($note, 3000), label: L("ag_ms_deliver_hint"), icon: "text.alignleft", singleLine: false, minLines: 4)
        VStack(alignment: .leading, spacing: 8) {
            ForEach(picks) { f in
                HStack(spacing: 8) {
                    Image(systemName: "paperclip").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18)
                    Text(verbatim: f.name).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    Button { picks.removeAll { $0.id == f.id } } label: {
                        Image(systemName: "xmark").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 28, height: 28).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                    .accessibilityLabel(L("ag_dl_remove"))
                }
            }
            if picks.count < maxDeliveryFiles {
                ActionButton(L("ag_dl_attach", picks.count, maxDeliveryFiles), "paperclip", .soft, enabled: !busy) { picking = true }
            }
            Text(L("ag_dl_kept")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(18)
        .fileImporter(isPresented: $picking, allowedContentTypes: [.item]) { result in
            if case .success(let url) = result, picks.count < maxDeliveryFiles, let f = AgreementPickedFile.read(url) { picks.append(f) }
        }
        if let error { Text(verbatim: error).font(.dorr(12, .bold)).foregroundStyle(Color(hex: 0xDC2626)) }
        ActionButton(L("ag_ms_deliver_send"), "icloud.and.arrow.up.fill", .primary, enabled: note.agreementTrimmed.count >= 5 && !busy, loading: busy) {
            onSend(note.agreementTrimmed, picks)
        }
    }
}

/// What the client will check before approving a part (FL-604), shown to me while I work on it.
struct ChecklistView: View {
    @Environment(\.wa) private var wa
    let m: MilestoneDto

    var body: some View {
        let items = m.checklist ?? []
        if !items.isEmpty {
            VStack(alignment: .leading, spacing: 6) {
                Text(L("ag_ck_title")).font(.dorr(12, .heavy)).foregroundStyle(wa.settingsInk)
                ForEach(Array(items.enumerated()), id: \.offset) { _, c in
                    HStack(spacing: 8) {
                        Image(systemName: c.checked == true ? "checkmark.circle.fill" : "circle")
                            .font(.system(size: 14)).foregroundStyle(c.checked == true ? Color(hex: 0x16A34A) : wa.settingsMut).frame(width: 16, height: 16)
                        Text(verbatim: c.text ?? "").font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                    }
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
    }
}

/// The versions I delivered for a part: the note, the files (open with the sign-in) and what the client asked to change.
struct DeliveriesBlock: View {
    @Environment(\.wa) private var wa
    let projectUuid: String
    let m: MilestoneDto
    @State private var open = false
    @State private var versions: [DeliveryDto] = []
    @State private var error: String?

    var body: some View {
        let count = m.deliveriesCount ?? 0
        if count != 0 {
            VStack(alignment: .leading, spacing: 10) {
                ActionButton(L(open ? "ag_dl_hide" : "ag_dl_show", count), "shippingbox.fill", .soft) { open.toggle() }
                if open {
                    ForEach(versions) { v in version(v) }
                }
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
            }
            // Read when opened and again when a new version or a status change arrives with the agreement.
            .task(id: "\(open)|\(count)|\(m.status ?? "")") {
                guard open else { return }
                if let list = await AgreementsApi.deliveries(projectUuid, m.uuid ?? "").value { versions = list }
            }
        }
    }

    private func version(_ v: DeliveryDto) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(verbatim: L("ag_dl_version", v.number ?? 1) + (v.deliveredAt.map { " · " + String($0.prefix(10)) } ?? ""))
                .font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            if let note = v.note, !note.agreementTrimmed.isEmpty { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            ForEach(Array((v.files ?? []).enumerated()), id: \.offset) { _, f in
                Text(verbatim: "\u{200E}\(f.name ?? "")").font(.dorr(12.5, .semibold)).foregroundStyle(wa.red)
                    .padding(.vertical, 3)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        Task { if await !openDeliveryFile(f) { error = L("ag_dl_open_failed") } }
                    }
            }
            if let reason = v.revisionReason { Text(verbatim: L("ag_ms_revision_reason", reason)).font(.dorr(12)).foregroundStyle(Color(hex: 0xD97706)) }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsMut.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// Downloads one file of a delivery with my own sign-in (never a public link) and opens it in a viewer.
@MainActor
private func openDeliveryFile(_ f: DeliveryFileDto) async -> Bool {
    guard let data = await AgreementsApi.file(Api.freelance + (f.path ?? "")) else { return false }
    let dir = FileManager.default.temporaryDirectory.appendingPathComponent("deliveries", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let safe = String((f.name ?? "").filter { $0.isLetter || $0.isNumber || "._- ".contains($0) })
    let out = dir.appendingPathComponent("\(f.id ?? 0)_\(safe.agreementTrimmed.isEmpty ? "file" : safe)")
    guard (try? data.write(to: out)) != nil else { return false }
    return openCachedFile(out)
}
