import QuickLook
import SwiftUI
import UIKit
import UniformTypeIdentifiers

// freelanceApp `ui/screens/ImageUtil.kt` — preparing a picked or photographed image (or document) for upload — plus the iOS
// pieces the profile pages share: the camera, the file viewer (Android's ACTION_VIEW) and an image read signed in.

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
 * Turns a picked or photographed image into the avatar upload part: scaled down to 1600px on its longest side and saved
 * as JPEG (the server accepts up to 5MB; a camera photo is far bigger). Nil when it can't be read. Run it off the main thread.
 */
func avatarPart(_ data: Data) -> FilePart? {
    guard let image = UIImage(data: data), let jpeg = scaledJpeg(image, maxSide: 1600) else { return nil }
    return FilePart(field: "avatar", filename: "avatar.jpg", mimeType: "image/jpeg", data: jpeg)
}

/**
 * FS-209: a team's logo picked from the gallery, as the upload part (`logo`): scaled down to 1600px on its longest side and saved as
 * JPEG like the avatar. Nil when it can't be read. Run it off the main thread.
 */
func logoPart(_ data: Data) -> FilePart? {
    guard let image = UIImage(data: data), let jpeg = scaledJpeg(image, maxSide: 1600) else { return nil }
    return FilePart(field: "logo", filename: "logo.jpg", mimeType: "image/jpeg", data: jpeg)
}

/**
 * A verification document picked from the files or photographed: a PDF is kept as it is, an image is scaled to 2000px on its
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

/**
 * A portfolio image picked from the gallery or photographed: any image the phone can read (JPG, PNG, HEIC, WebP…) scaled to
 * 2000px on its longest side and saved as JPEG `name.jpg` (the server takes JPG / PNG / WebP up to 5MB). Nil when it can't be read.
 */
func preparePortfolioImage(_ data: Data, name: String) -> FilePart? {
    guard let image = UIImage(data: data), let jpeg = scaledJpeg(image, maxSide: 2000) else { return nil }
    return FilePart(field: "image", filename: "\(name).jpg", mimeType: "image/jpeg", data: jpeg)
}

extension FilePart {
    /// The same file under another form field (`front` / `back`, `proof`…).
    func asField(_ field: String) -> FilePart { FilePart(field: field, filename: filename, mimeType: mimeType, data: data) }
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

// MARK: - Viewing a file

/// A downloaded file written to the cache to be shown (Android: the cache file opened with ACTION_VIEW).
struct ProfileShownFile: Identifiable {
    let id = UUID()
    let url: URL
}

/// Writes `data` to `caches/proofs/name` for the viewer; nil when it can't.
func profileCacheFile(_ data: Data, name: String) -> ProfileShownFile? {
    guard let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first else { return nil }
    let dir = caches.appendingPathComponent("proofs", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let url = dir.appendingPathComponent(name)
    do { try data.write(to: url, options: .atomic) } catch { return nil }
    return ProfileShownFile(url: url)
}

/// The extension of downloaded bytes by their signature: pdf, png, else jpg (Android reads it from the Content-Type).
func profileSniffExtension(_ data: Data) -> String {
    if data.starts(with: [0x25, 0x50, 0x44, 0x46]) { return "pdf" }
    if data.starts(with: [0x89, 0x50, 0x4E, 0x47]) { return "png" }
    return "jpg"
}

/// The system viewer (Quick Look) for a file in the cache — `.sheet(item:) { ProfileFileViewer(url: $0.url) }`.
struct ProfileFileViewer: UIViewControllerRepresentable {
    let url: URL

    func makeCoordinator() -> Coordinator { Coordinator(url: url) }

    func makeUIViewController(context: Context) -> UINavigationController {
        let preview = QLPreviewController()
        preview.dataSource = context.coordinator
        return UINavigationController(rootViewController: preview)
    }

    func updateUIViewController(_ controller: UINavigationController, context: Context) {}

    final class Coordinator: NSObject, QLPreviewControllerDataSource {
        let url: URL
        init(url: URL) { self.url = url }
        func numberOfPreviewItems(in controller: QLPreviewController) -> Int { 1 }
        func previewController(_ controller: QLPreviewController, previewItemAt index: Int) -> QLPreviewItem { url as NSURL }
    }
}

// MARK: - Images read signed in

/// My own image read signed in from a path of the freelancer API (`ApiClient.url(path)` through Coil with the token on
/// Android), cropped to fill. `placeholder` shows while it comes or when it can't be read.
struct ProfileAuthedImage<Placeholder: View>: View {
    let path: String?
    @ViewBuilder var placeholder: () -> Placeholder
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            if let image {
                Image(uiImage: image).resizable().scaledToFill()
            } else {
                placeholder()
            }
        }
        .task(id: path) {
            guard let path, !path.isEmpty else { return }
            if let cached = ProfileImageCache.shared.object(forKey: path as NSString) {
                image = cached
                return
            }
            if let data = await Api.bytes(Api.freelance + path), let read = UIImage(data: data) {
                ProfileImageCache.shared.setObject(read, forKey: path as NSString)
                image = read
            }
        }
    }
}

/// The images read signed in, kept in memory while the app runs (Coil's memory cache).
enum ProfileImageCache {
    static let shared = NSCache<NSString, UIImage>()
}
