import CoreImage
import CoreImage.CIFilterBuiltins
import LiveKit
import SwiftUI
import Vision

// androidApp `chat/CallEffects.kt` — camera backgrounds in video calls (spec 94): blur, or a picture behind me. Done on
// the phone — Apple's person segmentation (Vision) finds me in each frame, the rest is blurred or replaced (Android
// uses Google's on-device selfie segmentation) — and nothing of it goes anywhere but the call itself. Without an effect
// the camera works as before; with one, its frames pass through `BackgroundProcessor`.

@MainActor
final class CallEffects: ObservableObject {
    static let shared = CallEffects()

    enum Mode { case none, blur, image }

    /// For the call screen.
    @Published private(set) var mode = Mode.none
    @Published private(set) var picked: Int?

    private let processorInstance = BackgroundProcessor()

    func set(_ mode: Mode, image: UIImage? = nil, preset: Int? = nil) {
        if mode == .image, let image, let ci = CIImage(image: image) { processorInstance.background = ci }
        if mode != .image { processorInstance.background = nil }
        picked = mode == .image ? preset : nil
        self.mode = mode == .image && processorInstance.background == nil ? .none : mode
        processorInstance.blur = self.mode == .blur
    }

    /// What the camera track runs its frames through — nil without an effect.
    var processor: VideoProcessor? { mode == .none ? nil : processorInstance }

    /// Ready-made backgrounds: soft gradients in the app's colours.
    static let presets: [[UInt32]] = [
        [0x0F172A, 0x1E3A8A, 0x0D9488],
        [0x7C2D12, 0xEA580C, 0xFBBF24],
        [0x4C1D95, 0xDB2777, 0xF472B6],
        [0x064E3B, 0x16A34A, 0xA3E635],
    ]

    static func presetImage(_ index: Int) -> UIImage {
        let colors = presets[max(0, min(index, presets.count - 1))].map { UIColor(Color(hex: $0)).cgColor }
        let size = CGSize(width: 540, height: 960)
        return UIGraphicsImageRenderer(size: size).image { ctx in
            guard let g = CGGradient(colorsSpace: CGColorSpaceCreateDeviceRGB(), colors: colors as CFArray, locations: nil) else { return }
            ctx.cgContext.drawLinearGradient(g, start: .zero, end: CGPoint(x: size.width, y: size.height), options: [])
        }
    }
}

/// Each camera frame: find the person, then blur or replace everything else. Runs on LiveKit's capture queue.
final class BackgroundProcessor: VideoProcessor, @unchecked Sendable {
    var blur = false
    var background: CIImage?

    private let context = CIContext(options: [.cacheIntermediates: false])
    private let request: VNGeneratePersonSegmentationRequest = {
        let r = VNGeneratePersonSegmentationRequest()
        r.qualityLevel = .balanced
        r.outputPixelFormat = kCVPixelFormatType_OneComponent8
        return r
    }()
    private var pool: CVPixelBufferPool?
    private var poolSize = CGSize.zero

    func process(frame: VideoFrame) -> VideoFrame? {
        guard let source = (frame.buffer as? CVPixelVideoBuffer)?.pixelBuffer else { return frame }
        guard (try? VNImageRequestHandler(cvPixelBuffer: source, options: [:]).perform([request])) != nil,
              let maskBuffer = request.results?.first?.pixelBuffer else { return frame }
        let image = CIImage(cvPixelBuffer: source)
        let extent = image.extent
        var mask = CIImage(cvPixelBuffer: maskBuffer)
        mask = mask.transformed(by: CGAffineTransform(scaleX: extent.width / mask.extent.width, y: extent.height / mask.extent.height))

        let behind: CIImage
        if blur {
            behind = image.clampedToExtent().applyingGaussianBlur(sigma: 22).cropped(to: extent)
        } else if let picture = background {
            behind = fill(picture, into: extent, rotation: frame.rotation)
        } else {
            return frame
        }
        let blend = CIFilter.blendWithMask()
        blend.inputImage = image
        blend.backgroundImage = behind
        blend.maskImage = mask
        guard let out = blend.outputImage, let target = makeBuffer(extent.size) else { return frame }
        context.render(out, to: target)
        return VideoFrame(dimensions: frame.dimensions, rotation: frame.rotation, timeStampNs: frame.timeStampNs, buffer: CVPixelVideoBuffer(pixelBuffer: target))
    }

    /// The upright picture turned the way the camera's frame lies (the frame is shown after turning it by `rotation`),
    /// then scaled to cover the frame.
    private func fill(_ picture: CIImage, into extent: CGRect, rotation: VideoRotation) -> CIImage {
        let degrees: CGFloat
        switch rotation {
        case ._90: degrees = 90
        case ._180: degrees = 180
        case ._270: degrees = 270
        default: degrees = 0
        }
        var p = picture.transformed(by: CGAffineTransform(rotationAngle: degrees * .pi / 180))
        p = p.transformed(by: CGAffineTransform(translationX: -p.extent.minX, y: -p.extent.minY))
        let scale = max(extent.width / p.extent.width, extent.height / p.extent.height)
        p = p.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        p = p.transformed(by: CGAffineTransform(translationX: (extent.width - p.extent.width) / 2, y: (extent.height - p.extent.height) / 2))
        return p.cropped(to: extent)
    }

    private func makeBuffer(_ size: CGSize) -> CVPixelBuffer? {
        if pool == nil || poolSize != size {
            let attrs: [String: Any] = [
                kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
                kCVPixelBufferWidthKey as String: Int(size.width),
                kCVPixelBufferHeightKey as String: Int(size.height),
                kCVPixelBufferIOSurfacePropertiesKey as String: [:] as [String: Any],
            ]
            CVPixelBufferPoolCreate(nil, nil, attrs as CFDictionary, &pool)
            poolSize = size
        }
        guard let pool else { return nil }
        var out: CVPixelBuffer?
        CVPixelBufferPoolCreatePixelBuffer(nil, pool, &out)
        return out
    }
}
