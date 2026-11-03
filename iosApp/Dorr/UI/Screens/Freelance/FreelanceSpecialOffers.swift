import SwiftUI

// FL-L09, the client's side — the twin of Android's `ui/screens/freelance/FreelanceSpecialOffers.kt`: special offers from freelancers I
// finished work with. A banner on the freelance home while some wait, the inbox (waiting / accepted / past), and one offer with what it is,
// the price before → after, the time left by the phone's clock, and Accept (custom work and a service start at once; a consultation goes
// to the booking page at its discounted prices) / Decline (with a reason, and "stop offers from this freelancer"). Read on open, on a live
// notice and when the connection returns — never on a timer.

private let spcGreen = Color(hex: 0x16A34A)
private let spcAmber = Color(hex: 0xD97706)
private let spcRed = Color(hex: 0xDC2626)
private let spcSlate = Color(hex: 0x64748B)

/// "Ends in 2 days" / "5 h" / "12 min" by the phone's clock, or nil past the end.
private func spcLeft(_ iso: String?) -> String? {
    guard let end = parseInstant(iso) else { return nil }
    let left = end.timeIntervalSinceNow
    guard left > 0 else { return nil }
    if left >= 86_400 { return L("spc_left_days", Int(left / 86_400)) }
    if left >= 3_600 { return L("spc_left_hours", Int(left / 3_600)) }
    return L("spc_left_minutes", max(1, Int(left / 60)))
}

private func spcKindIcon(_ kind: String?) -> String {
    switch kind {
    case "service": return "storefront.fill"
    case "consultation": return "video.bubble.left.fill"
    default: return "briefcase.fill"
    }
}

private func spcKindLabel(_ kind: String?) -> String {
    switch kind {
    case "service": return L("spc_kind_service")
    case "consultation": return L("spc_kind_consultation")
    default: return L("spc_kind_custom")
    }
}

private func spcStatus(_ status: String?) -> (String, Color) {
    switch status {
    case "accepted": return (L("spc_status_accepted"), spcGreen)
    case "declined": return (L("spc_status_declined"), spcRed)
    case "withdrawn": return (L("spc_status_withdrawn"), spcSlate)
    case "expired": return (L("spc_status_expired"), spcSlate)
    default: return (L("spc_status_sent"), spcAmber)
    }
}

/// The headline price: the offered price, or "25% off" on a consultation.
private func spcPrice(_ o: ClientSpecialOfferDto) -> String {
    if o.kind == "consultation" { return L("spc_off", (o.discountBp ?? 0) / 100) }
    return o.priceMinor.map { mkMoney($0, o.decimals, o.currency) } ?? ""
}

// MARK: - the door on the freelance home

/// Shown on the freelance hub only while offers wait: a soft-glowing card with how many, opening the inbox.
struct SpecialOffersBanner: View {
    let count: Int
    let onOpen: () -> Void
    @State private var glow = false

    var body: some View {
        if count > 0 {
            Button(action: onOpen) {
                HStack(spacing: 12) {
                    Image(systemName: "tag.fill").font(.system(size: 20)).foregroundStyle(.white)
                        .frame(width: 46, height: 46)
                        .background(Color.white.opacity(0.22), in: RoundedRectangle(cornerRadius: 15, style: .continuous))
                        .scaleEffect(glow ? 1.12 : 1)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(Lp("spc_banner_title", count, count)).font(.dorr(15, .heavy)).foregroundStyle(.white)
                        Text(L("spc_banner_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9)).lineSpacing(3)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Image(systemName: "chevron.forward").font(.system(size: 16, weight: .bold)).foregroundStyle(.white)
                }
                .padding(16)
                .background(LinearGradient(colors: [Color(hex: 0x16A34A), Color(hex: 0x0EA5E9)], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            }
            .buttonStyle(.plain)
            .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { glow = true } }
        }
    }
}

/// On the booking page: the deal the freelancer made me, its discount and their word.
struct SpecialDealBanner: View {
    @Environment(\.wa) private var wa
    let offer: ClientSpecialOfferDto

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                Image(systemName: "tag.fill").font(.system(size: 15)).foregroundStyle(spcGreen)
                Text(L("spc_deal_title", (offer.discountBp ?? 0) / 100)).font(.dorr(14, .heavy)).foregroundStyle(spcGreen).frame(maxWidth: .infinity, alignment: .leading)
                if let left = spcLeft(offer.expiresAt) { Text(verbatim: left).font(.dorr(11, .bold)).foregroundStyle(spcAmber) }
            }
            if let m = offer.message, !m.isEmpty { Text(verbatim: "“\(m)”").font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3) }
            Text(L("spc_deal_instant")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(spcGreen.opacity(wa.night ? 0.2 : 0.10), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(spcGreen.opacity(0.35), lineWidth: 1))
    }
}

// MARK: - the inbox

private let spcTabs = ["open", "accepted", "past"]

@MainActor
struct SpecialOffersPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpen: (String) -> Void
    /// Inside «My activity» the head is the hub's and the statuses are chips.
    var embedded = false
    @State private var tab = 0
    @StateObject private var paged = PagedRows<ClientSpecialOfferDto>(key: { $0.uuid })

    var body: some View {
        VStack(spacing: 0) {
            if !embedded { FlPageHeader(title: L("spc_title"), onBack: onBack) }
            FlPartTabs(tabs: [FlTab(icon: "tray.fill", label: L("spc_tab_open")), FlTab(icon: "checkmark.seal.fill", label: L("spc_tab_accepted")),
                          FlTab(icon: "clock.arrow.circlepath", label: L("spc_tab_past"))], selected: tab, compact: embedded) { tab = $0 }
            if !paged.loaded && paged.error == nil {
                FlSkeletonList()
            } else {
                ScrollView {
                    LazyVStack(spacing: 10) {
                        if paged.rows.isEmpty && paged.error == nil {
                            WaEmpty(icon: "tag.fill", tone: .green, title: L(tab == 0 ? "spc_empty_open" : "spc_empty_other"), text: L("spc_empty_body"))
                        }
                        ForEach(Array(paged.rows.enumerated()), id: \.element.uuid) { i, o in
                            SpcCard(o: o) { onOpen(o.uuid) }.onAppear { paged.appeared(i) }
                        }
                        PagedSpinner(paged: paged)
                        PagedError(paged: paged)
                    }
                    .padding(.horizontal, 16).padding(.vertical, 10)
                }
            }
        }
        .task(id: tab) {
            let t = spcTabs[tab]
            paged.reset { page in try await FreelanceSpecialOffersApi.list(tab: t, page: page) }
        }
        .task(id: "\(tab)|\(network.reconnectTick)") { await paged.reload() }
    }
}

private struct SpcCard: View {
    @Environment(\.wa) private var wa
    let o: ClientSpecialOfferDto
    let onClick: () -> Void

    var body: some View {
        let (label, color) = spcStatus(o.status)
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 10) {
                    MkAvatar(url: o.freelancer?.avatarUrl, name: o.freelancer?.name, size: 40)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 4) {
                            Text(verbatim: o.freelancer?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            if o.freelancer?.verified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 12)).foregroundStyle(Color(hex: 0x0EA5E9)) }
                        }
                        HStack(spacing: 4) {
                            Image(systemName: spcKindIcon(o.kind)).font(.system(size: 11)).foregroundStyle(wa.accent)
                            Text(verbatim: spcKindLabel(o.kind)).font(.dorr(11.5, .bold)).foregroundStyle(wa.accent)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    FlPill(text: label, color: color)
                }
                Text(verbatim: o.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.leading)
                HStack(spacing: 8) {
                    Image(systemName: "tag.fill").font(.system(size: 13)).foregroundStyle(spcGreen)
                    Text(verbatim: spcPrice(o)).font(.dorr(15, .heavy)).foregroundStyle(spcGreen)
                    if let list = o.listPriceMinor { Text(verbatim: mkMoney(list, o.decimals, o.currency)).font(.dorr(12)).strikethrough().foregroundStyle(wa.settingsMut) }
                    Spacer(minLength: 0)
                    if o.status == "sent", let left = spcLeft(o.expiresAt) { Text(verbatim: left).font(.dorr(11.5, .bold)).foregroundStyle(spcAmber) }
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .background(spcGreen.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(20)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - one offer

@MainActor
struct SpecialOfferPage: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let uuid: String
    let onBack: () -> Void
    let onOpenWork: (String) -> Void
    let onOpenOrder: (String) -> Void
    let onOpenConsultation: (String) -> Void
    let onBook: (_ special: String, _ consultationOffer: String) -> Void
    @State private var offer: ClientSpecialOfferDto?
    @State private var error: String?
    @State private var busy = false
    @State private var accepting = false
    @State private var declining = false
    @State private var reason = ""
    @State private var mute = false
    @State private var actionError: String?
    // One id for this acceptance: a second tap (or a retry on a weak network) is the same one.
    @State private var requestId = UUID().uuidString

    var body: some View {
        VStack(spacing: 0) {
            FlPageHeader(title: L("spc_detail_title"), onBack: onBack)
            if let o = offer {
                ScrollView { content(o).padding(.horizontal, 16).padding(.vertical, 6) }.scrollDismissesKeyboard(.interactively)
                bottomBar(o)
            } else {
                FlLoading(error: error)
            }
        }
        .task(id: network.reconnectTick) {
            do {
                offer = try await FreelanceSpecialOffersApi.show(uuid).data
                error = nil
            } catch {
                self.error = flError(error)
            }
        }
        .dorrDialog(isPresented: Binding(get: { accepting }, set: { if !$0 && !busy { accepting = false } }), bottom: true) {
            if let o = offer { acceptSheet(o) }
        }
        .dorrDialog(isPresented: Binding(get: { declining }, set: { if !$0 && !busy { declining = false } }), bottom: true) {
            if let o = offer { declineSheet(o) }
        }
    }

    private func act(_ done: String, _ block: @escaping () async throws -> ClientSpecialOfferDto?, after: @escaping (ClientSpecialOfferDto) -> Void = { _ in }) {
        busy = true
        actionError = nil
        Task {
            do {
                let o = try await block()
                if let o { offer = o }
                accepting = false
                declining = false
                AppToast.show(L(done))
                if let o { after(o) }
            } catch {
                actionError = error.apiFailure.message ?? L("fl_error_generic")
            }
            busy = false
        }
    }

    @ViewBuilder
    private func content(_ o: ClientSpecialOfferDto) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            SpcHero(o: o)
            if let m = o.message, !m.isEmpty {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "quote.opening").font(.system(size: 18)).foregroundStyle(wa.accent)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: m).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(4)
                        Text(verbatim: "— " + (o.freelancer?.name ?? "")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(14).settingsSurface(20)
            }
            SpcWhat(o: o)
            if let m = o.money {
                SpcSection(title: L("spc_money"), icon: "banknote.fill") {
                    SpcLine(label: L("spc_price"), value: mkMoney(m.priceMinor, o.decimals, o.currency))
                    if let saving = o.savingMinor, saving > 0 { SpcLine(label: L("spc_you_save"), value: "− " + mkMoney(saving, o.decimals, o.currency), color: spcGreen) }
                    if (m.clientFeeMinor ?? 0) > 0 { SpcLine(label: L("mk_fee"), value: mkMoney(m.clientFeeMinor, o.decimals, o.currency)) }
                    Rectangle().fill(wa.settingsMut.opacity(0.15)).frame(height: 1)
                    SpcLine(label: L("mk_total"), value: mkMoney(m.totalMinor, o.decimals, o.currency), strong: true)
                }
            }
            if o.status == "sent" { FlNotice(text: L(o.kind == "consultation" ? "spc_how_consult" : "spc_how_work"), icon: "checkmark.circle.fill", color: spcGreen) }
            if o.muted == true { FlNotice(text: L("spc_muted_note"), icon: "bell.slash.fill", color: spcSlate) }
        }
    }

    /// The bar at the bottom: what can be done now.
    private func bottomBar(_ o: ClientSpecialOfferDto) -> some View {
        VStack(spacing: 8) {
            if o.can?.accept == true {
                SettingsPrimaryButton(text: L(o.kind == "consultation" ? "spc_pick_time" : "spc_accept")) {
                    if o.kind == "consultation", let c = o.consultation?.uuid { onBook(o.uuid, c) } else { accepting = true }
                }
                FlAction(text: L("spc_decline"), icon: "xmark", tone: .danger) { declining = true }
            } else if o.status == "accepted", let c = o.result?.consultation {
                SettingsPrimaryButton(text: L("spc_open_consultation")) { onOpenConsultation(c) }
            } else if o.status == "accepted", let s = o.result?.serviceOrder {
                SettingsPrimaryButton(text: L("spc_open_order")) { onOpenOrder(s) }
            } else if o.status == "accepted", let p = o.result?.project {
                SettingsPrimaryButton(text: L("spc_open_work")) { onOpenWork(p) }
            } else if let f = o.freelancer?.uuid {
                let muted = o.muted == true
                FlAction(text: L(muted ? "spc_unmute" : "spc_mute"), icon: "bell.slash.fill", tone: .soft, loading: busy) {
                    act(muted ? "spc_unmuted" : "spc_muted", {
                        _ = try await FreelanceSpecialOffersApi.mute(freelancer: f, on: !muted)
                        var next = o
                        next.muted = !muted
                        return next
                    })
                }
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 12)
        .background(wa.night ? wa.darkCard : .white, in: UnevenRoundedRectangleCompat(topRadius: 22))
    }

    private func acceptSheet(_ o: ClientSpecialOfferDto) -> some View {
        FlSheet(icon: "checkmark.seal.fill", title: L("spc_accept_title"), text: L("spc_accept_body", o.freelancer?.name ?? ""), footer: AnyView(VStack(spacing: 10) {
            if let actionError { FlNotice(text: actionError, icon: "xmark", color: spcRed) }
            SettingsPrimaryButton(text: L("spc_accept_cta"), loading: busy) {
                act("spc_accepted", { try await FreelanceSpecialOffersApi.accept(o.uuid, requestId: requestId).data }) { done in
                    if let s = done.result?.serviceOrder { onOpenOrder(s) } else if let p = done.result?.project { onOpenWork(p) }
                }
            }
        })) {
            VStack(alignment: .leading, spacing: 9) {
                Text(verbatim: o.title ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                if let m = o.money {
                    SpcLine(label: L("spc_price"), value: mkMoney(m.priceMinor, o.decimals, o.currency))
                    if (m.clientFeeMinor ?? 0) > 0 { SpcLine(label: L("mk_fee"), value: mkMoney(m.clientFeeMinor, o.decimals, o.currency)) }
                    SpcLine(label: L("mk_total"), value: mkMoney(m.totalMinor, o.decimals, o.currency), strong: true)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            FlNotice(text: L("spc_accept_safe"), icon: "checkmark.circle.fill", color: spcGreen)
        }
    }

    private func declineSheet(_ o: ClientSpecialOfferDto) -> some View {
        FlSheet(icon: "nosign", title: L("spc_decline_title"), text: L("spc_decline_body"), footer: AnyView(VStack(spacing: 10) {
            if let actionError { FlNotice(text: actionError, icon: "xmark", color: spcRed) }
            FlAction(text: L("spc_decline_cta"), icon: "xmark", tone: .danger, loading: busy) {
                let r = reason.trimmingCharacters(in: .whitespacesAndNewlines)
                act("spc_declined", { try await FreelanceSpecialOffersApi.decline(o.uuid, reason: r.isEmpty ? nil : r, mute: mute).data })
            }
        })) {
            DorrTextArea(value: Binding(get: { reason }, set: { reason = String($0.prefix(300)) }), placeholder: L("spc_decline_reason"), minHeight: 90)
            Button { mute.toggle() } label: {
                HStack(spacing: 10) {
                    Image(systemName: mute ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(mute ? wa.accent : wa.settingsMut)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("spc_mute_check", o.freelancer?.name ?? "")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("spc_mute_hint")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            .buttonStyle(.plain)
        }
    }
}

/// The head: the freelancer, the badge, the title, the big price with the old one struck through, and the time left.
private struct SpcHero: View {
    @Environment(\.wa) private var wa
    let o: ClientSpecialOfferDto

    var body: some View {
        let (label, color) = spcStatus(o.status)
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                MkAvatar(url: o.freelancer?.avatarUrl, name: o.freelancer?.name, size: 48)
                    .overlay(Circle().stroke(Color.white.opacity(0.7), lineWidth: 2))
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) {
                        Text(verbatim: o.freelancer?.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(.white).lineLimit(1)
                        if o.freelancer?.verified == true { Image(systemName: "checkmark.seal.fill").font(.system(size: 13)).foregroundStyle(.white) }
                    }
                    if let h = o.freelancer?.headline, !h.isEmpty { Text(verbatim: h).font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).lineLimit(1) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: label).font(.dorr(11, .heavy)).foregroundStyle(o.status == "sent" ? wa.accent : .white)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(o.status == "sent" ? Color.white : color.opacity(0.9), in: Capsule())
            }
            HStack(spacing: 6) {
                Image(systemName: "tag.fill").font(.system(size: 12)).foregroundStyle(.white)
                Text(L("spc_badge", spcKindLabel(o.kind))).font(.dorr(12, .bold)).foregroundStyle(.white)
            }
            Text(verbatim: o.title ?? "").font(.dorr(20, .heavy)).foregroundStyle(.white).lineSpacing(4)
            HStack(alignment: .lastTextBaseline, spacing: 10) {
                Text(verbatim: spcPrice(o)).font(.dorr(26, .heavy)).foregroundStyle(.white)
                if let list = o.listPriceMinor { Text(verbatim: mkMoney(list, o.decimals, o.currency)).font(.dorr(14)).strikethrough().foregroundStyle(.white.opacity(0.7)) }
            }
            if o.status == "sent", let left = spcLeft(o.expiresAt) {
                HStack(spacing: 6) {
                    Image(systemName: "hourglass").font(.system(size: 13)).foregroundStyle(.white)
                    Text(verbatim: left).font(.dorr(12, .bold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 10).padding(.vertical, 7)
                .background(Color.white.opacity(0.18), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(LinearGradient(colors: [wa.accent, wa.accent.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
    }
}

/// What is on offer: the work, the service level with what it includes, or the consultation's lengths before → after.
private struct SpcWhat: View {
    @Environment(\.wa) private var wa
    let o: ClientSpecialOfferDto

    var body: some View {
        SpcSection(title: L("spc_what"), icon: spcKindIcon(o.kind)) {
            switch o.kind {
            case "service":
                let s = o.service
                Text(verbatim: s?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: [s?.levelLabel, s?.tierName].compactMap { $0?.isEmpty == false ? $0 : nil }.joined(separator: " · ")).font(.dorr(12.5, .bold)).foregroundStyle(wa.accent)
                ForEach(s?.features ?? [], id: \.self) { f in
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(spcGreen)
                        Text(verbatim: f).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                    }
                }
            case "consultation":
                Text(verbatim: o.consultation?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                ForEach(o.consultation?.lengths ?? [], id: \.minutes) { l in
                    HStack(spacing: 8) {
                        Image(systemName: "clock").font(.system(size: 14)).foregroundStyle(wa.accent)
                        Text(L("cs_minutes", l.minutes)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                        Text(verbatim: mkMoney(l.listPriceMinor, o.decimals, o.currency)).font(.dorr(12)).strikethrough().foregroundStyle(wa.settingsMut)
                        Text(verbatim: mkMoney(l.priceMinor, o.decimals, o.currency)).font(.dorr(13, .heavy)).foregroundStyle(spcGreen)
                    }
                }
            default:
                if let d = o.description { Text(verbatim: d).font(.dorr(14)).foregroundStyle(wa.settingsInk).lineSpacing(4) }
                if let n = o.specialty?.name { FlPill(text: n, color: wa.accent) }
            }
            if o.kind != "consultation" {
                HStack(spacing: 8) {
                    FlStat(icon: "clock", label: L("spc_days_label"), value: L("mk_days", o.deliveryDays ?? 0))
                    FlStat(icon: "arrow.counterclockwise", label: L("spc_revisions_label"), value: "\(o.revisions ?? 0)")
                }
            }
        }
    }
}

private struct SpcSection<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let icon: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 15)).foregroundStyle(wa.accent)
                Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
            }
            content()
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

private struct SpcLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var strong = false
    var color: Color?

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(strong ? 14 : 13, strong ? .heavy : .medium)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: "\u{200E}" + value).font(.dorr(strong ? 16 : 13, strong ? .heavy : .semibold)).foregroundStyle(color ?? (strong ? wa.accent : wa.settingsInk))
        }
    }
}
