import AVFoundation
import CoreImage
import Photos
import PhotosUI
import SwiftUI

// androidApp `WalletQr.kt`.

/// The wallet's QR as a module matrix (medium error correction, no quiet zone — we draw our own).
struct QrMatrix {
    let size: Int
    let bits: [Bool]

    func isSet(_ x: Int, _ y: Int) -> Bool { bits[y * size + x] }

    init?(text: String) {
        guard let filter = CIFilter(name: "CIQRCodeGenerator") else { return nil }
        filter.setValue(Data(text.utf8), forKey: "inputMessage")
        filter.setValue("M", forKey: "inputCorrectionLevel")
        guard let output = filter.outputImage,
              let cg = CIContext().createCGImage(output, from: output.extent) else { return nil }
        let width = cg.width, height = cg.height
        var pixels = [UInt8](repeating: 0, count: width * height)
        guard let context = CGContext(data: &pixels, width: width, height: height, bitsPerComponent: 8, bytesPerRow: width,
                                      space: CGColorSpaceCreateDeviceGray(), bitmapInfo: CGImageAlphaInfo.none.rawValue) else { return nil }
        context.draw(cg, in: CGRect(x: 0, y: 0, width: width, height: height))
        // CoreImage adds its own white border: keep only the modules between the first and last dark ones.
        let dark = pixels.map { $0 < 128 }
        let rows = (0..<height).filter { y in (0..<width).contains { dark[y * width + $0] } }
        let cols = (0..<width).filter { x in (0..<height).contains { dark[$0 * width + x] } }
        guard let top = rows.first, let bottom = rows.last, let left = cols.first, let right = cols.last else { return nil }
        let side = max(bottom - top, right - left) + 1
        var bits = [Bool](repeating: false, count: side * side)
        for y in 0..<side {
            for x in 0..<side where top + y < height && left + x < width {
                bits[y * side + x] = dark[(top + y) * width + left + x]
            }
        }
        size = side
        self.bits = bits
    }

    /// A square PNG-ready image of the code (1024 px, 2-module white margin) for saving to Photos.
    func image(pixels: CGFloat = 1024) -> UIImage {
        let quiet = 2
        let modules = size + quiet * 2
        let cell = floor(pixels / CGFloat(modules))
        let side = cell * CGFloat(modules)
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1
        return UIGraphicsImageRenderer(size: CGSize(width: side, height: side), format: format).image { context in
            UIColor.white.setFill()
            context.fill(CGRect(x: 0, y: 0, width: side, height: side))
            UIColor(red: 0x11 / 255, green: 0x19 / 255, blue: 0x28 / 255, alpha: 1).setFill()
            for y in 0..<size {
                for x in 0..<size where isSet(x, y) {
                    context.fill(CGRect(x: CGFloat(x + quiet) * cell, y: CGFloat(y + quiet) * cell, width: cell, height: cell))
                }
            }
        }
    }
}

/// "My QR": the wallet's number as a code others scan to send money here.
@MainActor
struct WalletMyQr: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa

    var body: some View {
        let balance = host.balance
        let payload = balance?.qrPayload
        let number = balance?.walletNumberFormatted ?? groupWalletNumber(balance?.walletNumber ?? "")
        let matrix = payload.flatMap { QrMatrix(text: $0) }
        let country = countryName(balance?.countryCode)
        let currency = balance?.currencyCode ?? ""

        WaPage(title: L("wa_my_qr_title"), onBack: { host.pop() }) {
            VStack(spacing: 0) {
                HStack(spacing: 0) {
                    Image(systemName: "wallet.pass.fill").font(.system(size: 12, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 28, height: 28).background(Color.white.opacity(0.16), in: Circle())
                    VStack(alignment: .leading, spacing: 1) {
                        Text(L("wa_qr_digital_title")).font(.dorr(10, .medium)).foregroundStyle(.white.opacity(0.7))
                        Text(L("wa_qr_digital_sub")).font(.dorr(12.5, .bold)).foregroundStyle(.white)
                    }
                    .padding(.horizontal, 8)
                    Spacer(minLength: 0)
                    HStack(spacing: 4) {
                        Image(systemName: "checkmark.seal.fill").font(.system(size: 10))
                        Text(L("wa_qr_verified")).font(.dorr(10, .bold))
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(Color.white.opacity(0.16), in: Capsule())
                }
                .padding(.horizontal, 14)

                ZStack {
                    if let matrix {
                        QrCanvas(matrix: matrix)
                    } else {
                        Text(L("wa_qr_failed")).font(.dorr(13)).foregroundStyle(wa.mut)
                    }
                }
                .padding(10)
                // fillMaxWidth(0.56) of the card (screen minus the page gutters), at most 196, square.
                .frame(width: min((UIScreen.main.bounds.width - 32) * 0.56, 196), height: min((UIScreen.main.bounds.width - 32) * 0.56, 196))
                .background(Color.white, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .padding(.top, 14)

                Text(L("wa_wallet_number")).font(.dorr(11)).foregroundStyle(.white.opacity(0.62)).padding(.top, 12)
                if !number.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(number).font(.dorr(16, .heavy)).foregroundStyle(.white).padding(.top, 4).environment(\.layoutDirection, .leftToRight)
                }

                // The perforation: a dashed line with a notch cut on each side.
                ZStack {
                    Line().stroke(Color.white.opacity(0.32), style: StrokeStyle(lineWidth: 1.5, dash: [5 / UIScreen.main.scale, 7 / UIScreen.main.scale]))
                        .frame(height: 1)
                        .padding(.horizontal, 16)
                    HStack {
                        Circle().fill(wa.bg).frame(width: 18, height: 18).offset(x: -9)
                        Spacer()
                        Circle().fill(wa.bg).frame(width: 18, height: 18).offset(x: 9)
                    }
                    .environment(\.layoutDirection, .leftToRight)
                }
                .frame(height: 18)
                .padding(.vertical, 12)

                HStack(spacing: 10) {
                    QrMetaPill(icon: "globe", value: country.isEmpty ? "—" : country)
                    QrMetaPill(icon: "wallet.pass.fill", value: currency.isEmpty ? "—" : currency)
                }
                .padding(.horizontal, 14)
            }
            .padding(.top, 12)
            .padding(.bottom, 16)
            .frame(maxWidth: .infinity)
            .background(wa.heroGradient, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
            .shadow(color: wa.red.opacity(0.36), radius: 16, y: 6)
            .waRise(0)

            HStack(spacing: 0) {
                Image(systemName: "qrcode.viewfinder").font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 36, height: 36).background(wa.red.opacity(0.12), in: Circle())
                    .padding(.trailing, 10)
                Text(L("wa_my_qr_hint")).font(.dorr(12.5)).foregroundStyle(wa.ink).lineSpacing(4)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.line, lineWidth: 1))
            .padding(.top, 14)
            .waRise(1)

            QrPillButton(text: L("wa_copy_number"), icon: "doc.on.doc", filled: true) {
                copyToClipboard(number)
                host.showToast(L("wa_number_copied"))
            }
            .padding(.top, 14)
            .waRise(2)
            HStack(spacing: 10) {
                QrPillButton(text: L("wa_save_download"), icon: "arrow.down.to.line", filled: false, enabled: matrix != nil) {
                    guard let matrix else { return }
                    save(matrix.image())
                }
                QrPillButton(text: L("wa_share"), icon: "square.and.arrow.up", filled: false) {
                    shareText(L("wa_qr_share_text", number))
                }
            }
            .padding(.top, 10)
            .waRise(3)
        }
    }

    private func save(_ image: UIImage) {
        PHPhotoLibrary.requestAuthorization(for: .addOnly) { status in
            guard status == .authorized || status == .limited else { return }
            PHPhotoLibrary.shared().performChanges({
                PHAssetChangeRequest.creationRequestForAsset(from: image)
            }) { ok, _ in
                if ok { Task { @MainActor in host.showToast(L("wa_qr_saved")) } }
            }
        }
    }
}

private struct Line: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        path.move(to: CGPoint(x: rect.minX, y: rect.midY))
        path.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
        return path
    }
}

struct QrCanvas: View {
    let matrix: QrMatrix

    var body: some View {
        Canvas { gc, size in
            let quiet = 2
            let modules = matrix.size + quiet * 2
            let cell = size.width / CGFloat(modules)
            gc.fill(Path(CGRect(origin: .zero, size: size)), with: .color(.white))
            var path = Path()
            for y in 0..<matrix.size {
                for x in 0..<matrix.size where matrix.isSet(x, y) {
                    path.addRect(CGRect(x: CGFloat(x + quiet) * cell, y: CGFloat(y + quiet) * cell, width: cell + 0.5, height: cell + 0.5))
                }
            }
            gc.fill(path, with: .color(Color(hex: 0x111928)))
        }
        .environment(\.layoutDirection, .leftToRight)
    }
}

private struct QrMetaPill: View {
    let icon: String
    let value: String

    var body: some View {
        HStack(spacing: 7) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(.white.opacity(0.9))
            Text(value).font(.dorr(13, .bold)).foregroundStyle(.white).lineLimit(1)
        }
        .padding(12)
        .frame(maxWidth: .infinity)
        .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

private struct QrPillButton: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String
    let filled: Bool
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Text(text).font(.dorr(14, .heavy))
                Image(systemName: icon).font(.system(size: 15, weight: .semibold))
            }
            .foregroundStyle(filled ? .white : wa.ink)
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 52)
            .background {
                if filled {
                    Capsule().fill(wa.heroGradient)
                } else {
                    Capsule().fill(wa.surface).overlay(Capsule().stroke(wa.ink.opacity(0.18), lineWidth: 1))
                }
            }
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

// MARK: - Scanner

/// Decodes a QR from a picture (gallery).
func decodeQr(_ data: Data) -> String? {
    guard let image = CIImage(data: data) else { return nil }
    let detector = CIDetector(ofType: CIDetectorTypeQRCode, context: nil, options: [CIDetectorAccuracy: CIDetectorAccuracyHigh])
    return detector?.features(in: image).compactMap { ($0 as? CIQRCodeFeature)?.messageString }.first
}

/// Scan someone's wallet QR (camera) or read one from a picture. A code goes through the same server lookup
/// as typing the number, so it ends on the same "is this who you mean?" page.
@MainActor
struct WalletScanner: View {
    @EnvironmentObject private var host: WalletHost
    @Environment(\.wa) private var wa
    @State private var busy = false
    @State private var error: String?
    @State private var showCamera = false
    @State private var showGallery = false
    @State private var galleryItem: PhotosPickerItem?

    var body: some View {
        WaPage(title: L("wa_scan_title"), onBack: { host.pop() }) {
            // The sentence that used to sit under "Scan a QR code" on the transfer form.
            Text(L("wa_scan_cta_text")).font(.dorr(14, .bold)).foregroundStyle(wa.ink).multilineTextAlignment(.center).lineSpacing(4)
                .frame(maxWidth: .infinity).padding(.bottom, 14).waRise(0)
            Viewfinder(busy: busy).waRise(0)
            Text(L(busy ? "wa_scan_checking" : "wa_scan_hint")).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center)
                .frame(maxWidth: .infinity).padding(.top, 14).padding(.bottom, 12)
            WaError(message: error)
            VStack(spacing: 10) {
                WaButton(text: L("wa_scan_open_camera"), icon: "camera.fill", enabled: !busy) { showCamera = true }
                WaButton(text: L("wa_scan_gallery"), style: .ghost, icon: "photo", enabled: !busy) { showGallery = true }
            }
            .waRise(1)
        }
        .photosPicker(isPresented: $showGallery, selection: $galleryItem, matching: .images)
        .onChange(of: galleryItem) { item in
            guard let item else { return }
            Task {
                let data = try? await item.loadTransferable(type: Data.self)
                galleryItem = nil
                if let text = data.flatMap(decodeQr) { lookup(text) } else { error = L("wa_scan_no_code") }
            }
        }
        .fullScreenCover(isPresented: $showCamera) {
            QrCameraScreen { code in
                showCamera = false
                if let code { lookup(code) }
            }
        }
    }

    private func lookup(_ text: String) {
        guard !busy else { return }
        busy = true
        error = nil
        Task {
            defer { busy = false }
            do {
                if let who = try await WalletApi.transferLookup(TransferLookupRequest(mode: "qr", qr: text)).data {
                    host.pop()
                    host.push(.transferConfirm(who))
                } else {
                    error = L("wa_error_network")
                }
            } catch {
                let failure = error.apiFailure
                self.error = failure.httpStatus == nil ? L("wa_error_network") : failure.message ?? L("wa_error_network")
            }
        }
    }
}

/// The dark viewfinder frame with white corner brackets and a brand line sweeping up and down.
private struct Viewfinder: View {
    @Environment(\.wa) private var wa
    let busy: Bool
    @State private var sweep = false

    var body: some View {
        let side = min(UIScreen.main.bounds.width * 0.78, 300)
        ZStack(alignment: .top) {
            RadialGradient(colors: [Color(hex: 0x2A3140), Color(hex: 0x0F141C)], center: .center, startRadius: 0, endRadius: side * 0.7)
            Image(systemName: "qrcode").font(.system(size: 50)).foregroundStyle(.white.opacity(0.7)).frame(maxWidth: .infinity, maxHeight: .infinity)
            Canvas { gc, size in
                let arm: CGFloat = 34, pad: CGFloat = 16
                let corners: [(CGPoint, CGFloat, CGFloat)] = [
                    (CGPoint(x: pad, y: pad), 1, 1), (CGPoint(x: size.width - pad, y: pad), -1, 1),
                    (CGPoint(x: pad, y: size.height - pad), 1, -1), (CGPoint(x: size.width - pad, y: size.height - pad), -1, -1),
                ]
                var path = Path()
                for (o, dx, dy) in corners {
                    path.move(to: CGPoint(x: o.x + dx * arm, y: o.y))
                    path.addLine(to: o)
                    path.addLine(to: CGPoint(x: o.x, y: o.y + dy * arm))
                }
                gc.stroke(path, with: .color(.white), style: StrokeStyle(lineWidth: 4, lineCap: .round, lineJoin: .round))
            }
            if !busy {
                LinearGradient(colors: [.clear, wa.red, .clear], startPoint: .leading, endPoint: .trailing)
                    .frame(height: 3)
                    .clipShape(Capsule())
                    .padding(.horizontal, 26)
                    .offset(y: (sweep ? 0.78 : 0.2) * 300 * 0.78)
                    .onAppear { withAnimation(.easeInOut(duration: 2.2).repeatForever(autoreverses: true)) { sweep = true } }
            }
        }
        .frame(width: side, height: side)
        .clipShape(RoundedRectangle(cornerRadius: 30, style: .continuous))
        .shadow(color: Color(hex: 0x111928).opacity(0.28), radius: 18, y: 8)
        .frame(maxWidth: .infinity)
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// The live camera scanner (ZXing's capture screen on Android): QR codes only, a short prompt, a close button.
struct QrCameraScreen: View {
    /// The line over the camera (Android's `setPrompt`).
    var prompt = "wa_scan_prompt"
    let onResult: (String?) -> Void
    @State private var denied = false

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            if denied {
                Text(L(prompt)).font(.dorr(15, .bold)).foregroundStyle(.white).padding(.top, 120)
            } else {
                QrCameraView(onCode: { onResult($0) }, onDenied: { denied = true }).ignoresSafeArea()
            }
            HStack {
                Text(L(prompt)).font(.dorr(14, .bold)).foregroundStyle(.white)
                Spacer()
                Button { onResult(nil) } label: {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 38, height: 38).background(Color.white.opacity(0.18), in: Circle())
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 12)
        }
    }
}

private struct QrCameraView: UIViewControllerRepresentable {
    let onCode: (String) -> Void
    let onDenied: () -> Void

    func makeUIViewController(context: Context) -> ScannerController {
        let controller = ScannerController()
        controller.onCode = onCode
        controller.onDenied = onDenied
        return controller
    }

    func updateUIViewController(_ controller: ScannerController, context: Context) {}

    final class ScannerController: UIViewController, AVCaptureMetadataOutputObjectsDelegate {
        var onCode: ((String) -> Void)?
        var onDenied: (() -> Void)?
        private let session = AVCaptureSession()
        private var delivered = false

        override func viewDidLoad() {
            super.viewDidLoad()
            view.backgroundColor = .black
            AVCaptureDevice.requestAccess(for: .video) { granted in
                DispatchQueue.main.async { granted ? self.start() : self.onDenied?() }
            }
        }

        private func start() {
            guard let device = AVCaptureDevice.default(for: .video), let input = try? AVCaptureDeviceInput(device: device),
                  session.canAddInput(input) else {
                onDenied?()
                return
            }
            session.addInput(input)
            let output = AVCaptureMetadataOutput()
            guard session.canAddOutput(output) else { return }
            session.addOutput(output)
            output.setMetadataObjectsDelegate(self, queue: .main)
            output.metadataObjectTypes = [.qr]
            let preview = AVCaptureVideoPreviewLayer(session: session)
            preview.videoGravity = .resizeAspectFill
            preview.frame = view.bounds
            view.layer.addSublayer(preview)
            DispatchQueue.global(qos: .userInitiated).async { self.session.startRunning() }
        }

        override func viewDidLayoutSubviews() {
            super.viewDidLayoutSubviews()
            view.layer.sublayers?.forEach { $0.frame = view.bounds }
        }

        override func viewWillDisappear(_ animated: Bool) {
            super.viewWillDisappear(animated)
            if session.isRunning { session.stopRunning() }
        }

        func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput objects: [AVMetadataObject], from connection: AVCaptureConnection) {
            guard !delivered, let code = (objects.first as? AVMetadataMachineReadableCodeObject)?.stringValue else { return }
            delivered = true
            session.stopRunning()
            onCode?(code)
        }
    }
}
