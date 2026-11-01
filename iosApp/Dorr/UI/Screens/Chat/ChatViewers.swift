import CryptoKit
import PDFKit
import SwiftUI

// androidApp `chat/ChatViewers.kt` — a PDF read page by page without leaving the chat (PDFKit in place of Android's
// PdfRenderer), and its first page under a small PDF in the chat. Other files open in the system's viewer, as on
// Android.

/// A PDF to show: where it is and what it's called.
struct PdfTarget: Identifiable, Equatable {
    let url: String
    let name: String
    var id: String { url }
}

@MainActor
final class ChatViewers: ObservableObject {
    static let shared = ChatViewers()
    @Published var pdf: PdfTarget?

    static func isPdf(_ name: String?, _ mime: String?) -> Bool {
        mime == "application/pdf" || name?.lowercased().hasSuffix(".pdf") == true
    }
}

/// The PDF on this phone, downloaded once (kept in the caches folder, which iOS may clear).
func cachedPdf(_ url: String) async -> URL? {
    let name = Insecure.SHA1.hash(data: Data(url.utf8)).map { String(format: "%02x", $0) }.joined()
    let dir = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0].appendingPathComponent("chat-docs", isDirectory: true)
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    let file = dir.appendingPathComponent("\(name).pdf")
    if let size = try? file.resourceValues(forKeys: [.fileSizeKey]).fileSize, size > 0 { return file }
    guard let remote = ApiClient.mediaUrl(url),
          let (tmp, response) = try? await URLSession.shared.download(from: remote),
          (response as? HTTPURLResponse).map({ (200..<300).contains($0.statusCode) }) ?? true else { return nil }
    try? FileManager.default.removeItem(at: file)
    guard (try? FileManager.default.moveItem(at: tmp, to: file)) != nil else { return nil }
    return file
}

/// The PDF inside the chat: pinch to zoom; «open with» hands it to the system.
struct PdfViewerView: View {
    let target: PdfTarget
    let onClose: () -> Void
    @State private var document: PDFDocument?
    @State private var failed = false
    @State private var page = 1

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                circle("xmark", action: onClose)
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: target.name).font(.dorr(15, .bold)).foregroundStyle(.white).lineLimit(1)
                    if let d = document { Text(L("ch_pdf_page", page, d.pageCount)).font(.dorr(12)).foregroundStyle(.white.opacity(0.6)) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                circle("arrow.up.right.square") { if let url = ApiClient.mediaUrl(target.url) { UIApplication.shared.open(url) } }
            }
            .padding(10)
            ZStack {
                if let d = document {
                    PdfKitView(document: d, page: $page)
                } else if failed {
                    Text(L("ch_pdf_failed")).font(.dorr(14)).foregroundStyle(.white)
                } else {
                    ProgressView().tint(.white)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .background(Color(hex: 0x1F2430).ignoresSafeArea())
        .task(id: target.url) {
            if let file = await cachedPdf(target.url), let d = PDFDocument(url: file) { document = d } else { failed = true }
        }
    }

    private func circle(_ icon: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 40, height: 40).background(Color.white.opacity(0.12), in: Circle())
        }
        .buttonStyle(.plain)
    }
}

private struct PdfKitView: UIViewRepresentable {
    let document: PDFDocument
    @Binding var page: Int

    func makeCoordinator() -> Coordinator { Coordinator(page: $page) }

    func makeUIView(context: Context) -> PDFView {
        let view = PDFView()
        view.document = document
        view.autoScales = true
        view.displayMode = .singlePageContinuous
        view.displaysPageBreaks = true
        view.backgroundColor = UIColor(red: 0x1F / 255, green: 0x24 / 255, blue: 0x30 / 255, alpha: 1)
        view.minScaleFactor = view.scaleFactorForSizeToFit
        view.maxScaleFactor = 4
        NotificationCenter.default.addObserver(context.coordinator, selector: #selector(Coordinator.changed(_:)), name: .PDFViewPageChanged, object: view)
        return view
    }

    func updateUIView(_ view: PDFView, context: Context) {}

    final class Coordinator: NSObject {
        let page: Binding<Int>
        init(page: Binding<Int>) { self.page = page }
        @objc func changed(_ note: Notification) {
            guard let view = note.object as? PDFView, let current = view.currentPage, let doc = view.document else { return }
            page.wrappedValue = doc.index(for: current) + 1
        }
    }
}

/// Under a PDF in the chat: its first page — only for files up to 5 MB, so a big PDF is never downloaded just to be
/// shown in the list.
struct PdfFirstPage: View {
    let url: String
    let size: Int64?
    @State private var image: UIImage?

    var body: some View {
        if let size, size <= 5 * 1024 * 1024 {
            Group {
                if let image {
                    Image(uiImage: image).resizable().scaledToFill()
                        .frame(height: 140, alignment: .top).frame(maxWidth: .infinity)
                        .clipped()
                        .background(Color.white)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }
            .task(id: url) {
                guard let file = await cachedPdf(url), let page = PDFDocument(url: file)?.page(at: 0) else { return }
                image = page.thumbnail(of: CGSize(width: 700, height: 990), for: .mediaBox)
            }
        }
    }
}
