import SwiftUI

// androidApp `chat/ChatShieldUi.kt` — this phone's privacy: quick privacy mode (spec 111) with its duration, the daily
// schedule (112), smart quiet (115), Safe View (105), recent apps (106), blurred media (109), hiding again (110), and
// "while you were private" once a timed privacy mode has ended (113).

/// While the chat is on screen and "hide in recent apps" is on, the app switcher shows nothing of it (spec 106): iOS
/// takes its snapshot as the app leaves the screen, so a plain cover goes over it then.
struct HideFromRecents: ViewModifier {
    @Environment(\.scenePhase) private var phase
    @ObservedObject private var shield = ChatShield.shared

    func body(content: Content) -> some View {
        content.overlay {
            if shield.hideInRecents && phase != .active {
                ZStack {
                    Color(hex: 0x001B53)
                    Image(systemName: "lock.fill").font(.system(size: 34)).foregroundStyle(.white.opacity(0.7))
                }
                .ignoresSafeArea()
            }
        }
    }
}

/// The rows of the privacy page; their sheets are drawn by the page (`PrivacyShieldSheet`).
struct PrivacyShieldCards: View {
    @ObservedObject private var shield = ChatShield.shared
    let privacy: PrivacyDto
    let onSheet: (String) -> Void
    let call: (@escaping () async throws -> Void) -> Void
    let onChanged: (PrivacyDto) -> Void
    let onSummary: (PrivacySummaryDto) -> Void

    var body: some View {
        let mode = privacy.privacyMode
        let subtitle: String = {
            if let until = mode?.until { return L("ch_privacy_mode_until", scheduleLabel(until)) }
            if mode?.on == true { return L("ch_privacy_mode_by_schedule") }
            return L("ch_privacy_mode_sub")
        }()
        PrivacySettingsRow(icon: "shield.fill", title: L("ch_privacy_mode"), subtitle: subtitle, checked: mode?.on == true) { on in
            if on { onSheet("shield.durations") } else {
                call {
                    guard let off = try await ChatApi.privacyModeOff().data else { return }
                    shield.privacyStartedAt = 0
                    shield.setSafeView(false)
                    onChanged(off.settings)
                    onSummary(off.summary)
                }
            }
        }
        PrivacySettingsRow(icon: "moon.fill", title: L("ch_privacy_schedule"), value: range(mode?.schedule) ?? L("ch_off"), onTap: { onSheet("shield.schedule") })
        PrivacySettingsRow(icon: "moon.fill", title: L("ch_quiet"), value: range(privacy.quiet?.schedule) ?? L("ch_off"), onTap: { onSheet("shield.quiet") })
        PrivacySettingsRow(icon: "lock.fill", title: L("ch_safe_view"), subtitle: L("ch_safe_view_sub"), checked: shield.safeView) { shield.setSafeView($0) }
        PrivacySettingsRow(icon: "lock.fill", title: L("ch_hide_recents"), subtitle: L("ch_hide_recents_sub"), checked: shield.hideInRecents) { shield.setHideInRecents($0) }
        PrivacySettingsRow(icon: "lock.fill", title: L("ch_blur_media"), subtitle: L("ch_blur_media_sub"), checked: shield.blurMedia) { shield.setBlurMedia($0) }
        PrivacySettingsRow(icon: "lock.fill", title: L("ch_rehide"), value: rehideLabel(shield.rehideSeconds), last: true, onTap: { onSheet("shield.rehide") })
    }

    private func range(_ s: PrivacyScheduleDto?) -> String? {
        guard let s, let from = s.from, let to = s.to else { return nil }
        return "\(from) – \(to)"
    }
}

func rehideLabel(_ seconds: Int) -> String { seconds == 0 ? L("ch_rehide_leave") : slowModeLabel(seconds) }

private func shieldDurationLabel(_ minutes: Int) -> String { minutes < 60 ? L("ch_minutes_n", minutes) : L("ch_hours_n", minutes / 60) }

/// The sheet behind one of the rows above.
struct PrivacyShieldSheet: View {
    @ObservedObject private var shield = ChatShield.shared
    let key: String
    let privacy: PrivacyDto?
    let onDismiss: () -> Void
    let call: (@escaping () async throws -> Void) -> Void
    let onChanged: (PrivacyDto) -> Void

    var body: some View {
        switch key {
        case "shield.durations":
            ChoiceSheet(title: L("ch_privacy_mode"), subtitle: L("ch_privacy_mode_sub"), options: [30, 60, 240, 480, 1440].map { minutes in
                (shieldDurationLabel(minutes), {
                    call {
                        if let fresh = try await ChatApi.privacyModeOn(minutes: minutes).data { onChanged(fresh) }
                        // Safe View goes on with it; the summary is offered when it ends.
                        shield.setSafeView(true)
                        if shield.privacyStartedAt == 0 { shield.privacyStartedAt = Date().timeIntervalSince1970 * 1000 }
                    }
                })
            }, onDismiss: onDismiss)
        case "shield.rehide":
            let choices = [0, 30, 60, 300]
            ChoiceSheet(title: L("ch_rehide"), options: choices.map { s in (rehideLabel(s), { shield.setRehideSeconds(s) }) }, radios: true,
                        selected: choices.firstIndex(of: shield.rehideSeconds) ?? 0, onDismiss: onDismiss)
        default:
            if let privacy {
                PrivacyScheduleSheet(privacy: privacy, quiet: key == "shield.quiet", onDismiss: onDismiss) { body in
                    call { if let fresh = try await ChatApi.updatePrivacy(body).data { onChanged(fresh) } }
                }
            }
        }
    }
}

/// Privacy mode every day between two times, on the days I pick (spec 112); the same sheet sets smart quiet (115).
private struct PrivacyScheduleSheet: View {
    @ObservedObject private var ch = Ch.shared
    let privacy: PrivacyDto
    let quiet: Bool
    let onDismiss: () -> Void
    let onSave: ([String: Any?]) -> Void
    @State private var groupsOnly = false
    @State private var from = "22:00"
    @State private var to = "07:00"
    @State private var days: Set<Int> = Set(0...6)
    @State private var picking: Bool?
    @State private var loaded = false

    private var current: PrivacyScheduleDto? { quiet ? privacy.quiet?.schedule : privacy.privacyMode?.schedule }
    private var key: String { quiet ? "quiet_schedule" : "privacy_schedule" }

    var body: some View {
        ChSheetCard {
            if let isFrom = picking {
                BusinessTimeSheet(initial: isFrom ? from : to) { t in
                    if isFrom { from = t } else { to = t }
                    picking = nil
                }
            } else {
                form
            }
        }
        .onAppear {
            guard !loaded else { return }
            loaded = true
            groupsOnly = privacy.quiet?.scope == "groups"
            if let c = current {
                from = c.from ?? from
                to = c.to ?? to
                if let d = c.days, !d.isEmpty { days = Set(d) }
            }
        }
    }

    @ViewBuilder private var form: some View {
        Text(L(quiet ? "ch_quiet" : "ch_privacy_schedule")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
        Text(L(quiet ? "ch_quiet_sub" : "ch_privacy_schedule_sub")).font(.dorr(13)).foregroundStyle(ch.mut)
        section("clock.fill", L("ch_schedule_time")).padding(.top, 14)
        HStack(spacing: 10) {
            timeBox(L("ch_from"), from) { picking = true }
            timeBox(L("ch_to"), to) { picking = false }
        }
        section("calendar", L("ch_weekdays")).padding(.top, 16)
        Text(L("ch_weekdays_pick")).font(.dorr(12)).foregroundStyle(ch.mut).padding(.bottom, 8)
        let names: [String] = {
            var cal = Calendar(identifier: .gregorian)
            cal.locale = AppLocale.locale
            return cal.weekdaySymbols
        }()
        VStack(spacing: 6) {
            ForEach([[0, 1, 2], [3, 4, 5], [6]], id: \.self) { row in
                HStack(spacing: 6) {
                    ForEach(row, id: \.self) { d in
                        let on = days.contains(d)
                        Button {
                            if on { if days.count > 1 { days.remove(d) } } else { days.insert(d) }
                        } label: {
                            HStack {
                                Text(verbatim: names[d]).font(.dorr(13.5, .bold)).foregroundStyle(ch.ink).lineLimit(1)
                                Spacer(minLength: 4)
                                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(on ? ch.red : ch.soft)
                            }
                            .padding(.horizontal, 12).padding(.vertical, 11)
                            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 14))
                        }
                        .buttonStyle(.plain)
                        .frame(maxWidth: .infinity)
                    }
                    ForEach(0..<(3 - row.count), id: \.self) { _ in Color.clear.frame(maxWidth: .infinity, maxHeight: 1) }
                }
            }
        }
        if quiet {
            PrivacySettingsRow(icon: "moon.fill", title: L("ch_quiet_groups_only"), last: true, checked: groupsOnly) { groupsOnly = $0 }.padding(.top, 8)
        }
        ChPrimaryButton(text: L("ch_save"), enabled: !days.isEmpty) {
            var body: [String: Any?] = [key: ["from": from, "to": to, "days": days.sorted(), "timezone": TimeZone.current.identifier] as [String: Any?]]
            if quiet { body["quiet_scope"] = groupsOnly ? "groups" : "all" }
            onSave(body)
            onDismiss()
        }
        .padding(.top, 18)
        if current != nil {
            Button {
                onSave([key: nil])
                onDismiss()
            } label: {
                Text(L("ch_privacy_schedule_off")).font(.dorr(14, .bold)).foregroundStyle(ch.danger).frame(maxWidth: .infinity).padding(12)
            }
            .buttonStyle(.plain)
            .padding(.top, 6)
        }
    }

    private func section(_ icon: String, _ title: String) -> some View {
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 16)).foregroundStyle(ch.red)
            Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(ch.ink)
        }
        .padding(.bottom, 8)
    }

    private func timeBox(_ label: String, _ time: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 4) {
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(ch.mut)
                Image(systemName: "clock").font(.system(size: 14)).foregroundStyle(ch.red).padding(.leading, 2)
                Text(verbatim: " : ").font(.dorr(13, .bold)).foregroundStyle(ch.mut)
                Text(verbatim: time).font(.dorr(15, .heavy)).foregroundStyle(ch.ink).lineLimit(1)
                Spacer(minLength: 0)
            }
            .padding(10)
            .background(ch.surfaceMuted, in: RoundedRectangle(cornerRadius: 12))
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}

/// "While you were private: 5 messages in 2 chats" (spec 113).
struct PrivacySummaryDialog: View {
    @ObservedObject private var ch = Ch.shared
    let summary: PrivacySummaryDto
    let onDismiss: () -> Void

    var body: some View {
        ChSheetCard {
            VStack(spacing: 10) {
                Image(systemName: "shield.fill").font(.system(size: 26)).foregroundStyle(ch.red)
                Text(L("ch_privacy_summary_title")).font(.dorr(18, .heavy)).foregroundStyle(ch.ink)
                Text((summary.messages ?? 0) == 0 ? L("ch_privacy_summary_none") : L("ch_privacy_summary", summary.messages ?? 0, summary.conversations ?? 0))
                    .font(.dorr(14)).foregroundStyle(ch.mut).multilineTextAlignment(.center)
                ChPrimaryButton(text: L("ch_ok"), action: onDismiss).padding(.top, 6)
            }
            .frame(maxWidth: .infinity)
        }
    }
}
