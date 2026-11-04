import SwiftUI

// driverApp `ui/kit/Segmented.kt`.

/// A row of choices where exactly one is on (dark / light / like the phone; Arabic / English). Value → label.
struct Segmented: View {
    @Environment(\.pal) private var pal
    let options: [(String, String)]
    let selected: String
    let onSelect: (String) -> Void

    init(options: [(String, String)], selected: String, onSelect: @escaping (String) -> Void) {
        self.options = options
        self.selected = selected
        self.onSelect = onSelect
    }

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 14)
        HStack(spacing: 4) {
            ForEach(options.indices, id: \.self) { i in
                let (value, label) = options[i]
                let on = value == selected
                Text(label)
                    .font(.dorr(13, on ? .bold : .medium))
                    .foregroundStyle(on ? Color.white : pal.mut)
                    .lineLimit(1)
                    .frame(maxWidth: .infinity)
                    .frame(height: 38)
                    .background(RoundedRectangle(cornerRadius: 10).fill(on ? pal.accent : .clear))
                    .animation(.tween(200), value: on)
                    .bounceClick { onSelect(value) }
            }
        }
        .padding(4)
        .frame(maxWidth: .infinity)
        .background(shape.fill(pal.fieldFill))
        .overlay(shape.strokeBorder(pal.fieldLine, lineWidth: 1))
    }
}
