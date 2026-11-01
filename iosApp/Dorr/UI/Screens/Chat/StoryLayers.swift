import SwiftUI

// androidApp `chat/StoryLayers.kt` — stickers, GIFs, text and drawing on a status (backlog 79–80). They stay layers —
// sent as `style[layers]` — so a GIF keeps moving for whoever watches. Positions are fractions of the frame (the photo /
// video's own shape, or the whole screen for a text status), so every phone puts them in the same spot.

/// Pen and text colours.
private let layerColors = ["#FFFFFF", "#000000", "#EF4444", "#F97316", "#FACC15", "#22C55E", "#3B82F6", "#A855F7", "#EC4899"]

private let penWidth = 0.012

private func layerColor(_ hex: String?) -> Color {
    let clean = (hex ?? "#FFFFFF").replacingOccurrences(of: "#", with: "")
    guard clean.count == 6, let v = UInt32(clean, radix: 16) else { return .white }
    return Color(hex: v)
}

private func isLight(_ hex: String?) -> Bool { ["#FFFFFF", "#FACC15"].contains((hex ?? "").uppercased()) }

/// Missing position / size / turn read as Android's defaults (centre, 1×, upright).
extension StoryLayerDto {
    var px: Double { x ?? 0.5 }
    var py: Double { y ?? 0.5 }
    var ps: Double { s ?? 1 }
    var pr: Double { r ?? 0 }
}

// MARK: - showing them

/// What sits on top of a status, as it's watched. Fills its frame.
struct StoryLayersView: View {
    let layers: [StoryLayerDto]?

    var body: some View {
        if let layers, !layers.isEmpty {
            GeometryReader { geo in
                ZStack {
                    Strokes(strokes: layers.filter { $0.k == "d" }, live: [], liveColor: "#FFFFFF")
                    ForEach(Array(layers.enumerated()).filter { $0.element.k == "t" || $0.element.k == "s" }, id: \.offset) { _, l in
                        LayerBody(layer: l, frameW: geo.size.width)
                            .position(x: l.px * geo.size.width, y: l.py * geo.size.height)
                    }
                }
            }
            .allowsHitTesting(false)
        }
    }
}

/// A text or a sticker, sized from the frame's width so it looks the same on every phone.
private struct LayerBody: View {
    let layer: StoryLayerDto
    let frameW: CGFloat
    var selected = false

    var body: some View {
        content
            .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color.white.opacity(selected ? 0.85 : 0), lineWidth: 1.5))
            .scaleEffect(layer.ps)
            .rotationEffect(.degrees(layer.pr))
    }

    @ViewBuilder
    private var content: some View {
        if layer.k == "s" {
            StickerImage(url: layer.url).frame(width: frameW * 0.32, height: frameW * 0.32 / max(layer.a ?? 1, 0.01))
        } else {
            let size = frameW * 0.07
            let pill = layer.bg == 1
            Text(verbatim: layer.text ?? "")
                .font(StoryLook.font(layer.f, size: size))
                .foregroundStyle(pill ? (isLight(layer.c) ? Color.black : .white) : layerColor(layer.c))
                .multilineTextAlignment(.center)
                .shadow(color: pill ? .clear : .black.opacity(0.45), radius: 3, y: 2)
                .padding(.horizontal, pill ? 10 : 0).padding(.vertical, pill ? 4 : 0)
                .background(pill ? layerColor(layer.c) : .clear, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                .frame(maxWidth: frameW * 0.9)
                .fixedSize(horizontal: false, vertical: true)
        }
    }
}

/// The pen lines, plus the one being drawn right now.
private struct Strokes: View {
    let strokes: [StoryLayerDto]
    let live: [CGPoint]
    let liveColor: String

    var body: some View {
        Canvas { gc, size in
            for d in strokes {
                let raw = d.p ?? []
                let pts = stride(from: 0, to: raw.count - 1, by: 2).map { CGPoint(x: raw[$0] * size.width, y: raw[$0 + 1] * size.height) }
                draw(&gc, pts, layerColor(d.c), (d.w ?? penWidth) * size.width)
            }
            draw(&gc, live.map { CGPoint(x: $0.x * size.width, y: $0.y * size.height) }, layerColor(liveColor), penWidth * size.width)
        }
        .allowsHitTesting(false)
    }

    private func draw(_ gc: inout GraphicsContext, _ pts: [CGPoint], _ color: Color, _ width: CGFloat) {
        guard let first = pts.first else { return }
        if pts.count == 1 {
            gc.fill(Path(ellipseIn: CGRect(x: first.x - width / 2, y: first.y - width / 2, width: width, height: width)), with: .color(color))
            return
        }
        var path = Path()
        path.move(to: first)
        for i in 1..<pts.count {
            let a = pts[i - 1], b = pts[i]
            path.addQuadCurve(to: CGPoint(x: (a.x + b.x) / 2, y: (a.y + b.y) / 2), control: a)
        }
        path.addLine(to: pts[pts.count - 1])
        gc.stroke(path, with: .color(color), style: StrokeStyle(lineWidth: width, lineCap: .round, lineJoin: .round))
    }
}

// MARK: - editing them

struct EditLayer: Identifiable, Equatable {
    let id: Int
    var dto: StoryLayerDto
}

/// The layers being put on a status, and what the editor is busy with.
@MainActor
final class StoryLayerEditor: ObservableObject {
    @Published var layers: [EditLayer] = []
    @Published var drawing = false
    @Published var pen = "#FFFFFF"
    @Published var selected: Int?
    /// The text being written: id 0 = a new one.
    @Published var writing: EditLayer?
    @Published var picking = false
    private var nextId = 1

    var busy: Bool { drawing || writing != nil }

    func add(_ dto: StoryLayerDto) {
        let id = nextId
        nextId += 1
        layers.append(EditLayer(id: id, dto: dto))
        selected = id
    }

    func update(_ id: Int, _ change: (StoryLayerDto) -> StoryLayerDto) {
        if let i = layers.firstIndex(where: { $0.id == id }) { layers[i].dto = change(layers[i].dto) }
    }

    func remove(_ id: Int) {
        layers.removeAll { $0.id == id }
        if selected == id { selected = nil }
    }

    func addStroke(_ dto: StoryLayerDto) {
        let id = nextId
        nextId += 1
        layers.append(EditLayer(id: id, dto: dto))
    }

    func undoStroke() {
        if let i = layers.lastIndex(where: { $0.dto.k == "d" }) { layers.remove(at: i) }
    }

    /// The form field the server keeps (nothing when the status is plain).
    func fields() -> [String: String] {
        guard !layers.isEmpty else { return [:] }
        func r3(_ v: Double?) -> Double? { v.map { ($0 * 1000).rounded() / 1000 } }
        let rounded = layers.map { l -> StoryLayerDto in
            var d = l.dto
            d.x = r3(d.px); d.y = r3(d.py); d.s = r3(d.ps); d.r = r3(d.pr)
            d.p = d.p?.map { ($0 * 1000).rounded() / 1000 }
            return d
        }
        guard let data = try? JSONEncoder().encode(rounded), let json = String(data: data, encoding: .utf8) else { return [:] }
        return ["style[layers]": json]
    }
}

/// The layers, live: drag / pinch / turn a sticker or text, tap it to pick it (tap a picked text to rewrite it), and
/// in pen mode draw with a finger.
struct StoryLayersEditable: View {
    @ObservedObject var ed: StoryLayerEditor
    var deselectOnTap = true
    @State private var live: [CGPoint] = []
    /// The layer as it was when the current gesture began.
    @State private var gestureStart: (id: Int, dto: StoryLayerDto)?

    var body: some View {
        GeometryReader { geo in
            let w = max(geo.size.width, 1), h = max(geo.size.height, 1)
            ZStack {
                if deselectOnTap && !ed.drawing {
                    Color.clear.contentShape(Rectangle()).onTapGesture { ed.selected = nil }
                }
                Strokes(strokes: ed.layers.map(\.dto).filter { $0.k == "d" }, live: live, liveColor: ed.pen)
                ForEach(ed.layers.filter { $0.dto.k != "d" }) { item in
                    LayerBody(layer: item.dto, frameW: w, selected: ed.selected == item.id)
                        .position(x: item.dto.px * w, y: item.dto.py * h)
                        .onTapGesture {
                            if ed.selected == item.id && item.dto.k == "t" { ed.writing = item } else { ed.selected = item.id }
                        }
                        .gesture(transform(item, w: w, h: h))
                }
                // Pen mode: the whole frame takes the finger.
                if ed.drawing {
                    Color.clear.contentShape(Rectangle()).gesture(
                        DragGesture(minimumDistance: 0)
                            .onChanged { v in
                                let p = CGPoint(x: min(max(v.location.x / w, 0), 1), y: min(max(v.location.y / h, 0), 1))
                                if let last = live.last, hypot(p.x - last.x, p.y - last.y) <= 0.003 { return }
                                live.append(p)
                            }
                            .onEnded { _ in
                                if !live.isEmpty {
                                    ed.addStroke(StoryLayerDto(k: "d", c: ed.pen, w: penWidth, p: live.flatMap { [Double($0.x), Double($0.y)] }))
                                }
                                live = []
                            }
                    )
                }
            }
        }
    }

    private func transform(_ item: EditLayer, w: CGFloat, h: CGFloat) -> some Gesture {
        func begin() -> StoryLayerDto {
            if let s = gestureStart, s.id == item.id { return s.dto }
            gestureStart = (item.id, item.dto)
            ed.selected = item.id
            return item.dto
        }
        let drag = DragGesture().onChanged { v in
            let d0 = begin()
            ed.update(item.id) { d in
                var n = d
                n.x = min(max(d0.px + v.translation.width / w, 0), 1)
                n.y = min(max(d0.py + v.translation.height / h, 0), 1)
                return n
            }
        }
        let pinch = MagnificationGesture().onChanged { z in
            let d0 = begin()
            ed.update(item.id) { d in
                var n = d
                n.s = min(max(d0.ps * z, 0.2), 6)
                return n
            }
        }
        let turn = RotationGesture().onChanged { a in
            let d0 = begin()
            ed.update(item.id) { d in
                var n = d
                n.r = d0.pr + a.degrees
                return n
            }
        }
        return SimultaneousGesture(SimultaneousGesture(drag, pinch), turn).onEnded { _ in gestureStart = nil }
    }
}

/// Top tools: delete the picked one, stickers / GIF, text, pen.
struct LayerTools: View {
    @ObservedObject var ed: StoryLayerEditor
    var withText = true

    var body: some View {
        HStack(spacing: 8) {
            if let id = ed.selected { LayerTool(icon: "trash") { ed.remove(id) } }
            LayerTool(icon: "face.smiling") { ed.selected = nil; ed.picking = true }
            if withText {
                LayerTool(icon: "textformat") {
                    ed.selected = nil
                    ed.writing = EditLayer(id: 0, dto: StoryLayerDto(k: "t", y: 0.4, c: "#FFFFFF", f: "bold"))
                }
            }
            LayerTool(icon: "paintbrush.pointed.fill") { ed.selected = nil; ed.drawing = true }
        }
    }
}

/// Pen mode's bar: undo · colours · done.
struct DrawingBar: View {
    @ObservedObject var ed: StoryLayerEditor

    var body: some View {
        HStack {
            LayerTool(icon: "arrow.uturn.backward") { ed.undoStroke() }
            ColorDots(current: ed.pen) { ed.pen = $0 }.frame(maxWidth: .infinity)
            LayerTool(icon: "checkmark") { ed.drawing = false }
        }
        .padding(12)
    }
}

private struct ColorDots: View {
    let current: String
    let onPick: (String) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(layerColors, id: \.self) { hex in
                    let on = hex == current
                    Circle().fill(layerColor(hex))
                        .overlay(Circle().stroke(Color.white, lineWidth: on ? 3 : 1.5))
                        .frame(width: on ? 28 : 22, height: on ? 28 : 22)
                        .onTapGesture { onPick(hex) }
                }
            }
            .padding(.horizontal, 8)
            .frame(minHeight: 30)
        }
        .fixedSize(horizontal: false, vertical: true)
    }
}

struct LayerTool: View {
    var icon: String?
    var label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if let icon { Image(systemName: icon).font(.system(size: 18, weight: .semibold)) }
                if let label { Text(verbatim: label).font(.dorr(16, .heavy)) }
            }
            .foregroundStyle(.white)
            .frame(width: 42, height: 42)
            .background(Color.black.opacity(0.3), in: Circle())
            .overlay(Circle().stroke(Color.white.opacity(0.35), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// Writing (or rewriting) a text: font, a coloured pill behind it, colour. A tap outside = done.
struct LayerTextWriter: View {
    @ObservedObject var ed: StoryLayerEditor
    @State private var text = ""
    @State private var color = "#FFFFFF"
    @State private var font = "bold"
    @State private var pill = false
    @FocusState private var focused: Bool

    var body: some View {
        if let start = ed.writing {
            ZStack {
                Color.black.opacity(0.55).ignoresSafeArea().onTapGesture { done(start) }
                VStack {
                    HStack(spacing: 8) {
                        LayerTool(label: "Aa") { font = StoryLook.fonts[((StoryLook.fonts.firstIndex(of: font) ?? 0) + 1) % StoryLook.fonts.count] }
                        LayerTool(icon: "paintbrush.fill") { pill.toggle() }
                        Spacer()
                        LayerTool(icon: "checkmark") { done(start) }
                    }
                    .padding(12)
                    Spacer()
                    TextField("", text: Binding(get: { text }, set: { text = String($0.prefix(200)) }), axis: .vertical)
                        .font(StoryLook.font(font, size: 30))
                        .foregroundStyle(pill ? (isLight(color) ? Color.black : .white) : layerColor(color))
                        .tint(pill && isLight(color) ? .black : .white)
                        .multilineTextAlignment(.center)
                        .focused($focused)
                        .padding(.horizontal, pill ? 12 : 0).padding(.vertical, pill ? 6 : 0)
                        .background(pill ? layerColor(color) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .fixedSize(horizontal: false, vertical: true)
                        .padding(.horizontal, 24)
                    Spacer()
                    ColorDots(current: color) { color = $0 }.padding(.bottom, 16)
                }
            }
            .onAppear {
                text = start.dto.text ?? ""
                color = start.dto.c ?? "#FFFFFF"
                font = start.dto.f ?? "bold"
                pill = start.dto.bg == 1
                focused = true
            }
        }
    }

    private func done(_ start: EditLayer) {
        let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
        var dto = start.dto
        dto.text = t
        dto.c = color
        dto.f = font
        dto.bg = pill ? 1 : 0
        if start.id == 0 {
            if !t.isEmpty { ed.add(dto) }
        } else if t.isEmpty {
            ed.remove(start.id)
        } else {
            ed.update(start.id) { _ in dto }
        }
        ed.writing = nil
    }
}

/// The emoji / sticker / GIF sheet: an emoji lands as big text, a sticker or GIF as a picture.
struct LayerStickerPicker: ViewModifier {
    @ObservedObject var ed: StoryLayerEditor

    func body(content: Content) -> some View {
        content.dorrDialog(isPresented: $ed.picking, bottom: true) {
            ExpressionPanel(
                onDismiss: { ed.picking = false },
                onEmoji: { emoji in
                    ed.add(StoryLayerDto(k: "t", y: 0.4, s: 1.8, text: emoji, c: "#FFFFFF"))
                    ed.picking = false
                },
                onPick: { pick in
                    let url = (pick.extra["webp"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? pick.extra["url"] as? String
                    guard let url, !url.isEmpty else { return }
                    let w = (pick.extra["width"] as? Int).map(Double.init) ?? 0
                    let h = (pick.extra["height"] as? Int).map(Double.init) ?? 0
                    ed.add(StoryLayerDto(k: "s", y: 0.4, url: url, a: w > 0 && h > 0 ? min(max(w / h, 0.2), 5) : 1))
                }
            )
        }
    }
}
