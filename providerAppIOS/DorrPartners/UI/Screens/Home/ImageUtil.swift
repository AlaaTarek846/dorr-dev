import SwiftUI
import UIKit
import UniformTypeIdentifiers

// providerApp `ui/screens/ImageUtil.kt` — preparing a picked or photographed paper for upload — plus the iOS pieces the
// papers pages share: the file picker's type and bytes, and the camera.

/// An image drawn at most `maxSide` on its longest side (the photo's orientation applied), as JPEG at 88 %.
private func scaledJpeg(_ image: UIImage, maxSide: CGFloat) -> Data? {
    let longest = max(image.size.width, image.size.height)
    guard longest > 0 else { return nil }
    let ratio = min(1, maxSide / longest)
    let size = CGSize(width: (image.size.width * ratio).rounded(), height: (image.size.height * ratio).rounded())
    let format = UIGraphicsImageRendererFormat.default()
    format.scale = 1
    format.opaque = true
    let drawn = UIGraphicsImageRenderer(size: size, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
    return drawn.jpegData(compressionQuality: 0.88)
}

/**
 * A paper picked from the files or photographed: a PDF is kept as it is, an image is scaled to 2000px on its
 * longest side and saved as JPEG (the server takes up to 8MB). Named `name` (`name.pdf` / `name.jpg`, the part's field is
 * `name` too — set the real field with `asField`); nil when the file is neither a JPG, PNG nor PDF or can't be read.
 * `mimeType` is the picked file's type (nil = a JPEG, like Android's `getType() ?: "image/jpeg"`).
 */
func prepareDocFile(_ data: Data, mimeType: String?, name: String) -> FilePart? {
    let type = mimeType ?? "image/jpeg"
    if type == "application/pdf" {
        return data.isEmpty ? nil : FilePart(field: name, filename: "\(name).pdf", mimeType: "application/pdf", data: data)
    }
    guard type == "image/jpeg" || type == "image/png" else { return nil }
    guard let image = UIImage(data: data), let jpeg = scaledJpeg(image, maxSide: 2000) else { return nil }
    return FilePart(field: name, filename: "\(name).jpg", mimeType: "image/jpeg", data: jpeg)
}

/// The MIME type of a file picked with `.fileImporter` (the three types the pickers allow), nil for anything else.
func profileMimeType(of url: URL) -> String? {
    let type = (try? url.resourceValues(forKeys: [.contentTypeKey]))?.contentType ?? UTType(filenameExtension: url.pathExtension)
    guard let type else { return nil }
    if type.conforms(to: .pdf) { return "application/pdf" }
    if type.conforms(to: .png) { return "image/png" }
    if type.conforms(to: .jpeg) { return "image/jpeg" }
    return nil
}

/// The bytes of a file picked with `.fileImporter` (a security-scoped URL).
func profileReadPicked(_ url: URL) -> Data? {
    let scoped = url.startAccessingSecurityScopedResource()
    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
    return try? Data(contentsOf: url)
}

// MARK: - Camera

/// Android's `TakePicture` with the camera permission: the system camera; `onTaken` gets the photo as JPEG (nil when
/// cancelled). Present it with `.fullScreenCover`; on a phone without a camera it closes at once.
struct ProfileCameraPicker: UIViewControllerRepresentable {
    let onTaken: (Data?) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onTaken: onTaken) }

    func makeUIViewController(context: Context) -> UIViewController {
        guard UIImagePickerController.isSourceTypeAvailable(.camera) else {
            let empty = UIViewController()
            DispatchQueue.main.async { onTaken(nil) }
            return empty
        }
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ controller: UIViewController, context: Context) {}

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onTaken: (Data?) -> Void
        init(onTaken: @escaping (Data?) -> Void) { self.onTaken = onTaken }

        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            onTaken((info[.originalImage] as? UIImage)?.jpegData(compressionQuality: 0.95))
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { onTaken(nil) }
    }
}
