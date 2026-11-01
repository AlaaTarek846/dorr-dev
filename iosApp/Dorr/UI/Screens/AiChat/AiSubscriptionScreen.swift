import SwiftUI

// androidApp `aichat/AiSubscriptionScreen.kt` — DORR AI plans paid from the wallet: the current subscription (status,
// days left, grace period, auto-renew), the plans (featured, badge, discount, features), and subscribe / switch
// behind the wallet PIN like every other money-moving screen.

private enum AiPinAction: Equatable {
    case subscribe(AiPlanDto)
    case change(AiPlanDto)

    var plan: AiPlanDto {
        switch self {
        case .subscribe(let p), .change(let p): return p
        }
    }
}

/// Plan prices arrive as plain decimals ("249.00"): a thousands separator only.
private func planPrice(_ raw: String) -> String {
    guard let v = Double(raw) else { return raw }
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US")
    f.numberStyle = .decimal
    f.maximumFractionDigits = 2
    return f.string(from: NSNumber(value: v)) ?? raw
}

struct AiSubscriptionScreen: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    @State private var loading = true
    @State private var current: AiSubscriptionDto?
    @State private var plans: [AiPlanDto] = []
    @State private var togglingAutoRenew = false
    @State private var pinAction: AiPinAction?
    @State private var noPin = false

    private func load() async {
        loading = true
        if let s = try? await AiChatApi.currentSubscription().data { current = s }
        if let p = try? await AiChatApi.subscriptionPlans().data { plans = p }
        loading = false
    }

    private func request(_ action: AiPinAction) {
        Task {
            let status = try? await WalletApi.pinStatus().data
            if status?.hasPin == false { noPin = true } else { pinAction = action }
        }
    }

    var body: some View {
        let c = wa.ai
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                AiRoundIcon(icon: "chevron.left", tint: c.ink, size: 17, action: onExit)
                Text(L("ai_subscription_title")).font(.dorr(16, .heavy)).foregroundStyle(c.ink)
                Spacer()
            }
            .padding(.horizontal, 8).padding(.vertical, 8)
            if loading {
                ProgressView().tint(c.red).frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        currentCard.padding(.bottom, 8)
                        Text(L("ai_subscription_available_plans")).font(.dorr(14, .bold)).foregroundStyle(c.ink)
                        if plans.isEmpty { Text(L("ai_subscription_empty_plans")).font(.dorr(13)).foregroundStyle(c.mut) }
                        ForEach(plans) { plan in
                            let isCurrent = current?.status == "active" && current?.plan?.id == plan.id
                            AiPlanCard(plan: plan, isCurrent: isCurrent) {
                                let billable = current?.status == "active" && current?.currentPlanPrice != nil
                                request(billable ? .change(plan) : .subscribe(plan))
                            }
                        }
                    }
                    .padding(.horizontal, 16).padding(.bottom, 32)
                }
            }
        }
        .background(c.bg.ignoresSafeArea())
        .task { await load() }
        .dorrDialog(isPresented: $noPin) {
            VStack(alignment: .leading, spacing: 16) {
                Text(L("ai_subscription_no_pin_set")).font(.dorr(14)).foregroundStyle(c.ink)
                Button { noPin = false } label: {
                    Text(L("ai_ok")).font(.dorr(13, .semibold)).foregroundStyle(.white).frame(maxWidth: .infinity).padding(.vertical, 10)
                        .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
            .padding(20)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .padding(.horizontal, 28)
        }
        .dorrDialog(isPresented: Binding(get: { pinAction != nil }, set: { if !$0 { pinAction = nil } })) {
            if let action = pinAction {
                let isSubscribe: Bool = { if case .subscribe = action { return true } else { return false } }()
                WaPinPad(title: L("ai_subscription_pin_title"), sub: L(isSubscribe ? "ai_subscription_pin_subtitle_subscribe" : "ai_subscription_pin_subtitle_change")) { pin in
                    do {
                        let updated = isSubscribe
                            ? try await AiChatApi.subscribe(planId: action.plan.id, pin: pin).data
                            : try await AiChatApi.changePlan(planId: action.plan.id, pin: pin).data
                        current = updated
                        pinAction = nil
                        return .ok
                    } catch {
                        return padFailure(error, networkError: L("ai_send_failed"))
                    }
                }
                .padding(.vertical, 24)
                .background(c.surface, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                .padding(.horizontal, 20)
                .contentShape(Rectangle())
                .onTapGesture {}
            }
        }
    }

    private var currentCard: some View {
        let c = wa.ai
        return VStack(alignment: .leading, spacing: 0) {
            Text(L("ai_subscription_current")).font(.dorr(12, .semibold)).foregroundStyle(c.mut).padding(.bottom, 6)
            if let s = current, s.status == "active" {
                Text(verbatim: s.plan?.name ?? "-").font(.dorr(18, .heavy)).foregroundStyle(c.ink)
                if let days = s.daysRemaining {
                    Text(verbatim: days <= 0 ? L("ai_subscription_ends_today") : L("ai_subscription_days_remaining", days)).font(.dorr(12.5)).foregroundStyle(c.mut).padding(.top, 4)
                }
                if s.inGracePeriod == true, let grace = parseRideTime(s.graceEndsAt) {
                    let f: DateFormatter = { let f = DateFormatter(); f.dateFormat = "d/M"; return f }()
                    Text(verbatim: L("ai_subscription_grace_warning", f.string(from: grace))).font(.dorr(12.5)).foregroundStyle(c.red).padding(.top, 8)
                }
                HStack {
                    Text(L("ai_subscription_auto_renew")).font(.dorr(13)).foregroundStyle(c.ink)
                    Spacer()
                    let on = s.autoRenew == true
                    Button {
                        Task {
                            togglingAutoRenew = true
                            if let u = try? await AiChatApi.updateAutoRenew(!on).data { current = u }
                            togglingAutoRenew = false
                        }
                    } label: {
                        Text(verbatim: on ? "On" : "Off").font(.dorr(12, .semibold)).foregroundStyle(on ? .white : c.mut)
                            .padding(.horizontal, 14).padding(.vertical, 6)
                            .background(on ? c.red : c.line, in: Capsule())
                    }
                    .buttonStyle(.plain)
                    .disabled(togglingAutoRenew)
                }
                .padding(.top, 12)
            } else {
                Text(L("ai_subscription_no_plan")).font(.dorr(14)).foregroundStyle(c.ink)
                if current?.status == "suspended" {
                    Text(L("ai_subscription_suspended_banner")).font(.dorr(12.5)).foregroundStyle(c.red).padding(.top, 8)
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

private struct AiPlanCard: View {
    @Environment(\.wa) private var wa
    let plan: AiPlanDto
    let isCurrent: Bool
    let onAction: () -> Void

    var body: some View {
        let c = wa.ai
        let featured = plan.isFeatured == true
        let discount = (plan.discountPercent ?? 0) > 0 && plan.originalPrice?.isEmpty == false
        VStack(alignment: .leading, spacing: 0) {
            if featured || plan.badge?.isEmpty == false {
                HStack(spacing: 4) {
                    if featured { Image(systemName: "star.fill").font(.system(size: 11)) }
                    if let b = plan.badge, !b.isEmpty { Text(verbatim: b).font(.dorr(11, .bold)) }
                }
                .foregroundStyle(c.red)
                .padding(.bottom, 6)
            }
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 6) {
                        Text(verbatim: plan.name).font(.dorr(15, .bold)).foregroundStyle(c.ink)
                        if isCurrent { Image(systemName: "checkmark.circle.fill").font(.system(size: 15)).foregroundStyle(c.red) }
                    }
                    if let d = plan.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(c.mut).padding(.top, 4) }
                    if let features = plan.features, !features.isEmpty {
                        VStack(alignment: .leading, spacing: 2) {
                            ForEach(features, id: \.self) { f in
                                HStack(spacing: 6) {
                                    Image(systemName: "checkmark.circle.fill").font(.system(size: 11))
                                    Text(verbatim: f).font(.dorr(12))
                                }
                                .foregroundStyle(c.mut)
                            }
                        }
                        .padding(.top, 8)
                    }
                }
                Spacer(minLength: 8)
                VStack(alignment: .trailing, spacing: 2) {
                    if discount {
                        Text(verbatim: L("ai_subscription_discount_badge", plan.discountPercent ?? 0)).font(.dorr(11, .bold)).foregroundStyle(c.red)
                            .padding(.horizontal, 8).padding(.vertical, 3)
                            .background(c.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 6, style: .continuous))
                        Text(verbatim: "\(planPrice(plan.originalPrice ?? "")) \(plan.currency)").font(.dorr(11)).foregroundStyle(c.mut).strikethrough().padding(.top, 2)
                    }
                    Text(verbatim: "\(planPrice(plan.price)) \(plan.currency)").font(.dorr(16, .heavy)).foregroundStyle(discount ? c.red : c.ink)
                    Text(verbatim: L("ai_subscription_per_cycle", plan.durationDays ?? 0)).font(.dorr(11)).foregroundStyle(c.mut)
                }
            }
            if isCurrent {
                Text(L("ai_subscription_current_badge")).font(.dorr(13, .semibold)).foregroundStyle(c.mut)
                    .frame(maxWidth: .infinity).padding(.vertical, 10)
                    .background(c.line, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .padding(.top, 12)
            } else {
                Button(action: onAction) {
                    Text(L(plan.isTrial == true ? "ai_subscription_subscribe" : "ai_subscription_switch_plan")).font(.dorr(13, .semibold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 10)
                        .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(AiPressStyle())
                .padding(.top, 12)
            }
        }
        .padding(16)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay { if featured { RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(c.red, lineWidth: 1.5) } }
    }
}
