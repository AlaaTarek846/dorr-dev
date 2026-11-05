import PhotosUI
import SwiftUI
import UIKit

// driverApp `ui/DocumentUpload.kt` — one required document on the sign-up (`DocumentCard`) and sending it (`DocumentSheet`):
// the camera or the gallery, made smaller on the phone, then the upload.

/// A document's state in words and colour (never colour alone).
private struct DocLook {
    let label: String
    let color: Color
    let icon: String
}

private func lookOf(_ pal: Pal, _ status: String?) -> DocLook {
    switch status {
    case "approved": return DocLook(label: "ob_status_approved", color: pal.success, icon: "checkmark.circle.fill")
    case "rejected": return DocLook(label: "ob_status_rejected", color: pal.danger, icon: "exclamationmark.circle")
    case nil: return DocLook(label: "ob_status_missing", color: pal.warning, icon: "icloud.and.arrow.up")
    default: return DocLook(label: "ob_status_pending", color: pal.info, icon: "hourglass")
    }
}

/// One required document on the sign-up: what it is, its state, the photos already sent, and the way to send it.
struct DocumentCard: View {
    @Environment(\.pal) private var pal
    let doc: RequiredDocDto
    let onOpen: () -> Void

    init(doc: RequiredDocDto, onOpen: @escaping () -> Void) {
        self.doc = doc
        self.onOpen = onOpen
    }

    var body: some View {
        let look = lookOf(pal, doc.document?.status)
        let canSend = doc.document?.status != "approved"
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 12) {
                TileIcon(doc.type?.appliesTo == "vehicle" ? "car.fill" : "person.crop.rectangle", tint: pal.accent, box: 44)
                VStack(alignment: .leading, spacing: 0) {
                    Text(doc.type?.name ?? doc.type?.code ?? "").font(.dorr(15, .bold)).foregroundStyle(pal.ink)
                    HStack(spacing: 4) {
                        DorrIcon(look.icon, size: 14, tint: look.color)
                        Text(L(look.label)).font(.dorr(12, .bold)).foregroundStyle(look.color)
                        if let until = doc.document?.expiresAt {
                            Text(" · " + L("doc_until", prettyDate(until))).font(.dorr(12)).foregroundStyle(pal.mut)
                        }
                    }
                    .padding(.top, 3)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let reason = doc.document?.rejectionReason {
                Banner(reason).padding(.top, 10)
            }
            let shots = [doc.document?.frontUrl, doc.document?.backUrl].compactMap { $0 }
            if !shots.isEmpty {
                HStack(spacing: 8) {
                    ForEach(shots, id: \.self) { url in
                        RemoteShot(url: url)
                            .frame(width: 86, height: 58)
                            .background(pal.well)
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                    }
                }
                .padding(.top, 10)
            }
            if canSend {
                Text(L(doc.document == nil ? "doc_add" : "ob_reupload"))
                    .font(.dorr(13, .bold)).foregroundStyle(pal.accent).padding(.top, 10)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(20)
        .bounceClick(enabled: canSend, onClick: onOpen)
    }
}

/// Coil's `AsyncImage(url, contentScale = Crop)`: the photo filling its box, nothing while it loads.
private struct RemoteShot: View {
    let url: String

    var body: some View {
        AsyncImage(url: Api.mediaUrl(url)) { phase in
            if let image = phase.image {
                image.resizable().scaledToFill()
            } else {
                Color.clear
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .clipped()
    }
}

/// A side picked on the phone (Android's content `Uri`): the photo, or a PDF's bytes.
enum PickedDoc: Equatable {
    case photo(UIImage)
    case pdf(Data)
}

/// Sending one document: its number and expiry when the type has them (checked before sending), the front and — if
/// needed — the back, each from the camera or the gallery with a preview, then the upload with its progress.
/// Photos are made smaller on the phone first (the server takes up to 10 MB); a vehicle document names the vehicle.
/// Shown in a `.sheet` (Android's `ModalBottomSheet`, `skipPartiallyExpanded`); it can't be swiped away while sending.
struct DocumentSheet: View {
    @Environment(\.pal) private var pal
    let doc: RequiredDocDto
    let vehicleId: Int?
    let onDismiss: () -> Void
    let onDone: (OnboardingDto) -> Void

    @State private var number: String
    @State private var expiry: String?
    @State private var front: PickedDoc? = nil
    @State private var back: PickedDoc? = nil
    @State private var busy = false
    @State private var progress: Double = 0
    @State private var error: String? = nil
    @State private var tried = false

    init(doc: RequiredDocDto, vehicleId: Int?, onDismiss: @escaping () -> Void, onDone: @escaping (OnboardingDto) -> Void) {
        self.doc = doc
        self.vehicleId = vehicleId
        self.onDismiss = onDismiss
        self.onDone = onDone
        _number = State(initialValue: doc.document?.number ?? "")
        _expiry = State(initialValue: doc.document?.expiresAt)
    }

    private var hasNumber: Bool { doc.type?.hasNumber ?? false }
    private var hasExpiry: Bool { doc.type?.hasExpiry ?? false }
    private var needsBackSide: Bool { doc.type?.needsBackSide ?? false }
    private var numberMissing: Bool { hasNumber && number.trimmingCharacters(in: .whitespaces).isEmpty }
    private var expiryMissing: Bool { hasExpiry && (expiry ?? "").trimmingCharacters(in: .whitespaces).isEmpty }
    private var backMissing: Bool { needsBackSide && back == nil }
    private var ready: Bool { front != nil && !backMissing && !numberMissing && !expiryMissing }

    private func send() {
        tried = true
        if !ready || busy { return }
        busy = true
        error = nil
        progress = 0
        var fields: [String: String] = ["document_type_id": String(doc.type?.id ?? 0)]
        if (doc.type?.appliesTo ?? "driver") == "vehicle", let vehicleId { fields["vehicle_id"] = String(vehicleId) }
        if !number.trimmingCharacters(in: .whitespaces).isEmpty { fields["number"] = number.trimmingCharacters(in: .whitespacesAndNewlines) }
        if let expiry, !expiry.trimmingCharacters(in: .whitespaces).isEmpty { fields["expires_at"] = expiry }
        let sides = [front.map { ("front", $0) }, back.map { ("back", $0) }].compactMap { $0 }
        let sent = fields
        Task {
            let parts = await Task.detached(priority: .userInitiated) {
                sides.map { name, picked -> FilePart in
                    let (data, type) = prepared(picked)
                    let ext = type.contains("pdf") ? "pdf" : "jpg"
                    return FilePart(field: name, filename: "\(name).\(ext)", mimeType: type, data: data)
                }
            }.value
            guard let frontPart = parts.first(where: { $0.field == "front" }) else { busy = false; return }
            let backPart = parts.first { $0.field == "back" }
            switch await DriverApi.uploadDocument(fields: sent, front: frontPart, back: backPart, onProgress: { share in
                Task { @MainActor in progress = share }
            }) {
            case .ok(let data, _): progress = 1; onDone(data)
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(doc.type?.name ?? doc.type?.code ?? "").font(.dorr(19, .heavy)).foregroundStyle(pal.ink)
                if let instructions = doc.type?.instructions, !instructions.trimmingCharacters(in: .whitespaces).isEmpty {
                    Banner(instructions, kind: .info)
                }

                if hasNumber {
                    DorrTextField(
                        value: number, onValueChange: { number = String($0.prefix(64)) }, label: L("ob_number"),
                        placeholder: L("doc_number_hint"), icon: "number", error: tried && numberMissing
                    )
                }
                if hasExpiry {
                    DateField(label: L("doc_expiry"), value: expiry, onPick: { expiry = $0 }, min: LocalDate.now().plusDays(1),
                              error: tried && expiryMissing)
                }

                HStack(alignment: .top, spacing: 10) {
                    PhotoTile(label: L("ob_front"), picked: front, existing: doc.document?.frontUrl, error: tried && front == nil) { front = $0 }
                        .frame(maxWidth: .infinity)
                    if needsBackSide {
                        PhotoTile(label: L("ob_back"), picked: back, existing: doc.document?.backUrl, error: tried && backMissing) { back = $0 }
                            .frame(maxWidth: .infinity)
                    }
                }
                Text(L("doc_photo_hint")).font(.dorr(12)).foregroundStyle(pal.mut).lineSpacing(3)
                    .fixedSize(horizontal: false, vertical: true)

                VStack(spacing: 0) {
                    if busy {
                        VStack(alignment: .leading, spacing: 6) {
                            ProgressLine(progress: progress, color: pal.accent)
                            Text(L("doc_uploading", Int(progress * 100))).font(.dorr(12)).foregroundStyle(pal.mut)
                        }
                        .transition(.opacity.combined(with: .move(edge: .top)))
                    }
                }
                .clipped()
                .animation(.default, value: busy)
                Banner(error)
                Banner(tried && !ready && !busy ? L("doc_complete_first") : nil, kind: .warning)
                PrimaryButton(L("doc_send"), busy: busy, icon: "icloud.and.arrow.up") { send() }
            }
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 18)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(pal.card.ignoresSafeArea())
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .interactiveDismissDisabled(busy)
    }
}

/// One side of a document: a preview, and the camera or the gallery to (re)take it.
private struct PhotoTile: View {
    @Environment(\.pal) private var pal
    let label: String
    let picked: PickedDoc?
    let existing: String?
    let error: Bool
    let onPicked: (PickedDoc) -> Void

    @State private var camera = false
    @State private var gallery: PhotosPickerItem? = nil
    @State private var galleryOpen = false

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16)
        VStack(alignment: .leading, spacing: 6) {
            Text(label).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
            ZStack {
                shape.fill(pal.fieldFill)
                switch picked {
                case .photo(let image)?:
                    Image(uiImage: image).resizable().scaledToFill()
                case .pdf?:
                    DorrIcon("doc.richtext", size: 30, tint: pal.accent)
                case nil:
                    if let existing {
                        RemoteShot(url: existing)
                    } else {
                        DorrIcon("camera.fill", size: 30, tint: pal.accent.opacity(0.7))
                    }
                }
            }
            .frame(maxWidth: .infinity)
            .aspectRatio(1.5, contentMode: .fit)
            .clipShape(shape)
            .overlay(shape.strokeBorder(error ? pal.danger : pal.fieldLine, lineWidth: error ? 1.5 : 1))
            HStack(spacing: 6) {
                SmallAction(icon: "camera.fill", label: L("doc_camera")) {
                    if UIImagePickerController.isSourceTypeAvailable(.camera) { camera = true }
                }
                SmallAction(icon: "photo.on.rectangle", label: L("doc_gallery")) { galleryOpen = true }
            }
        }
        .fullScreenCover(isPresented: $camera) {
            CameraCapture { image in
                camera = false
                if let image { onPicked(.photo(image)) }
            }
            .ignoresSafeArea()
        }
        .photosPicker(isPresented: $galleryOpen, selection: $gallery, matching: .images)
        .onChange(of: gallery) { item in
            guard let item else { return }
            Task {
                if let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data) {
                    onPicked(.photo(image))
                }
                gallery = nil
            }
        }
    }
}

private struct SmallAction: View {
    @Environment(\.pal) private var pal
    let icon: String
    let label: String
    let onClick: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon(icon, size: 16, tint: pal.accent)
            Text(" \(label)").font(.dorr(12, .bold)).foregroundStyle(pal.accent).lineLimit(1)
        }
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity)
        .background(RoundedRectangle(cornerRadius: 12).fill(pal.well))
        .bounceClick(onClick: onClick)
    }
}

/// `ActivityResultContracts.TakePicture()`: the phone's camera, one photo back (nil when cancelled).
struct CameraCapture: UIViewControllerRepresentable {
    let onDone: (UIImage?) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.cameraCaptureMode = .photo
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ controller: UIImagePickerController, context: Context) {}

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onDone: (UIImage?) -> Void
        init(onDone: @escaping (UIImage?) -> Void) { self.onDone = onDone }

        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            onDone(info[.originalImage] as? UIImage)
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { onDone(nil) }
    }
}

private let MAX_SIDE: CGFloat = 2000

/// The bytes to send and their type: a photo is turned the right way up and made at most 2000 px on its longer side as
/// a JPEG at 85 (a phone photo is often 5–12 MB, the server takes 10); a PDF goes as it is.
private func prepared(_ picked: PickedDoc) -> (Data, String) {
    switch picked {
    case .pdf(let data):
        return (data, "application/pdf")
    case .photo(let image):
        let w = image.size.width * image.scale
        let h = image.size.height * image.scale
        let longest = max(w, h)
        let scale = longest > MAX_SIDE ? MAX_SIDE / longest : 1
        let target = CGSize(width: (w * scale).rounded(.down), height: (h * scale).rounded(.down))
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true
        // Drawing the image applies its orientation (the right way up).
        let upright = UIGraphicsImageRenderer(size: target, format: format).image { _ in
            image.draw(in: CGRect(origin: .zero, size: target))
        }
        if let data = upright.jpegData(compressionQuality: 0.85) { return (data, "image/jpeg") }
        return (image.jpegData(compressionQuality: 0.85) ?? Data(), "image/jpeg")
    }
}
