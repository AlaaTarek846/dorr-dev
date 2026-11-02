import StoreKit
import SwiftUI
import UIKit

// freelanceApp `ui/screens/RateAppScreen.kt` — rate the app: quarter stars and a short comment. One rating per freelancer
// (`GET ratings/mine`, `POST ratings` creates or updates it). The first time it becomes 4–5 stars the store's own review
// prompt is offered (Play In-App Review on Android, SKStoreReviewController here — the system decides whether it shows).

@MainActor
struct RateAppScreen: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    /// Quarter-star steps: 1, 1.25, 1.5 … 5 (0 = not chosen yet).
    @State private var stars: Double = 0
    @State private var comment = ""
    @State private var alreadyRated = false
    @State private var offeredStoreReview = false
    @State private var sending = false

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: L("rate_title"), onBack: onBack)
                ScrollView {
                    VStack(spacing: 0) {
                        Text(L("rate_headline")).font(.dorr(22, .heavy)).foregroundStyle(wa.settingsInk)
                            .multilineTextAlignment(.center).lineSpacing(4)
                            .frame(maxWidth: .infinity).padding(.top, 8).padding(.bottom, 6)
                        Text(L("rate_body")).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4)
                            .frame(maxWidth: .infinity).padding(.horizontal, 8).padding(.vertical, 4)
                        StarPicker(value: stars, activeColor: wa.red, idleColor: wa.settingsMut.opacity(0.45)) { stars = $0 }
                            .padding(.top, 18)
                        Text(verbatim: starsLabel).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 10).padding(.bottom, 18)
                        if alreadyRated {
                            Text(L("rate_already")).font(.dorr(14, .semibold)).foregroundStyle(wa.red).multilineTextAlignment(.center)
                                .frame(maxWidth: .infinity).padding(.bottom, 10)
                        }
                        DorrTextField(value: Binding(get: { comment }, set: { if $0.count <= 300 { comment = $0 } }),
                                      label: L("rate_comment_label"), placeholder: L("rate_comment_hint"), icon: "text.alignleft",
                                      singleLine: false, minLines: 4, minHeight: 120)
                        Text(verbatim: "\(comment.trimmingCharacters(in: .whitespacesAndNewlines).count) / 300")
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.top, 6).padding(.bottom, 4)
                        SettingsPrimaryButton(text: L(alreadyRated ? "rate_update" : "rate_submit"), loading: sending, action: submit)
                            .padding(.top, 14)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, 24)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .task {
            guard let mine = try? await SupportClient.myRating().data, mine.rated == true, let rating = mine.rating else { return }
            stars = rating.stars ?? 0
            comment = rating.comment ?? ""
            alreadyRated = true
            if (rating.stars ?? 0) >= 4 { offeredStoreReview = true }
        }
    }

    private var starsLabel: String {
        let label: String
        switch stars {
        case ...0: label = L("rate_hint")
        case ..<1.5: label = L("rate_label_1")
        case ..<2.5: label = L("rate_label_2")
        case ..<3.5: label = L("rate_label_3")
        case ..<4.5: label = L("rate_label_4")
        default: label = L("rate_label_5")
        }
        return stars > 0 ? "\(label)  ·  \(formatStars(stars))" : label
    }

    private func formatStars(_ value: Double) -> String {
        if value.truncatingRemainder(dividingBy: 1) == 0 { return String(Int(value)) }
        var text = String(value)
        while text.hasSuffix("0") { text.removeLast() }
        return text
    }

    private func submit() {
        guard !sending else { return }
        guard stars >= 1 else {
            AppToast.show(L("rate_pick_stars"))
            return
        }
        let trimmed = comment.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty && !(5...300).contains(trimmed.count) {
            AppToast.show(L("rate_comment_length"))
            return
        }
        UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
        sending = true
        let wasRated = alreadyRated
        Task {
            do {
                let envelope = try await SupportClient.submitRating(SubmitRatingRequest(stars: stars, comment: trimmed))
                alreadyRated = true
                comment = envelope.data?.comment ?? ""
                AppToast.show(L(wasRated ? "rate_updated" : "rate_thanks"))
                // The store's review prompt only the first time this rating becomes 4–5 stars.
                if envelope.data?.promptStoreReview == true && !offeredStoreReview {
                    offeredStoreReview = true
                    if let scene = UIApplication.shared.connectedScenes.first(where: { $0.activationState == .foregroundActive }) as? UIWindowScene {
                        SKStoreReviewController.requestReview(in: scene)
                    }
                }
            } catch {
                AppToast.show(error.apiFailure().message ?? L("rate_failed"))
            }
            sending = false
        }
    }
}

/// Five stars that can be tapped or dragged to any quarter (1, 1.25, 1.5 … 5). Each star is drawn partly filled, and the
/// fill follows the reading direction (right to left in Arabic).
private struct StarPicker: View {
    let value: Double
    let activeColor: Color
    let idleColor: Color
    let onChange: (Double) -> Void

    private let star: CGFloat = 40
    private let gap: CGFloat = 6

    var body: some View {
        let total = star * 5 + gap * 4
        HStack(spacing: gap) {
            ForEach(1...5, id: \.self) { n in
                let fill = CGFloat(min(max(value - Double(n - 1), 0), 1))
                ZStack {
                    Image(systemName: "star").resizable().scaledToFit().foregroundStyle(idleColor)
                    if fill > 0 {
                        // `.leading` follows the layout direction, so in Arabic the fill grows from the right edge.
                        Image(systemName: "star.fill").resizable().scaledToFit().foregroundStyle(activeColor)
                            .mask(alignment: .leading) { Rectangle().frame(width: star * fill) }
                    }
                }
                .frame(width: star, height: star)
            }
        }
        .frame(width: total)
        .contentShape(Rectangle())
        .gesture(DragGesture(minimumDistance: 0).onChanged { onChange(valueAt($0.location.x, total: total)) })
    }

    private func valueAt(_ x: CGFloat, total: CGFloat) -> Double {
        // In RTL SwiftUI already reports x from the leading (right) edge, so no flip is needed here.
        let pos = min(max(x, 0), total)
        let stride = star + gap
        let index = min(Int(pos / stride), 4)
        let within = min(max((pos - CGFloat(index) * stride) / star, 0), 1)
        return min(max(Double(index) + ceil(within * 4) / 4, 1), 5)
    }
}
