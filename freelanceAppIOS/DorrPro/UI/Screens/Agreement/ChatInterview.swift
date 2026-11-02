// freelanceApp `ui/screens/ChatInterview.kt`
import SwiftUI

private let red = Color(hex: 0xDC2626)

private func formatted(_ date: Date, _ pattern: String) -> String {
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateFormat = pattern
    return f.string(from: date)
}

private func whenText(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return iso ?? "" }
    return formatted(date, "EEE d MMM, HH:mm")
}

/// A short interview with the client (FL-310), arranged in the room: the open one (confirm / decline what the client proposed, or
/// cancel), and a picker to propose a time. Read with the room - when it opens, on a notice, on reconnect.
struct InterviewPanel: View {
    @Environment(\.wa) private var wa
    let roomUuid: String
    let canWrite: Bool
    let items: [InterviewDto]
    let onChanged: () -> Void
    let onError: (String) -> Void
    @State private var busy = false
    @State private var picking = false

    var body: some View {
        let open = items.first { $0.status == "proposed" || $0.status == "confirmed" }
        if open != nil || canWrite {
            VStack(alignment: .leading, spacing: 8) {
                if let open {
                    let mineToAnswer = open.status == "proposed" && open.proposedBy != "freelancer"
                    let uuid = open.uuid ?? ""
                    Text(L("iv_title")).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: whenText(open.startsAt) + " · " + L("iv_minutes", open.durationMinutes ?? 15))
                        .font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                    if let note = open.note, !note.agreementTrimmed.isEmpty { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    Text(L(open.status == "confirmed" ? "iv_confirmed" : mineToAnswer ? "iv_asks_you" : "iv_waiting"))
                        .font(.dorr(12, .semibold)).foregroundStyle(wa.red)
                    HStack(spacing: 16) {
                        if mineToAnswer {
                            action(L("iv_confirm"), false) { run { await ChatApi.answerInterview(roomUuid, uuid, "confirm") } }
                            action(L("iv_decline"), true) { run { await ChatApi.answerInterview(roomUuid, uuid, "decline") } }
                        } else {
                            action(L("iv_cancel"), true) { run { await ChatApi.answerInterview(roomUuid, uuid, "cancel") } }
                        }
                        if canWrite { action(L("iv_other_time"), false) { picking.toggle() } }
                    }
                } else {
                    action(L("iv_schedule"), false) { picking.toggle() }
                }
                if picking && canWrite {
                    InterviewPicker(busy: busy) { startsAt, minutes, note in
                        run { await ChatApi.proposeInterview(roomUuid, InterviewProposeRequest(startsAt: startsAt, durationMinutes: minutes, note: note)) }
                    }
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(16)
            .padding(.horizontal, 12)
            .padding(.vertical, 4)
        }
    }

    private func run(_ block: @escaping () async -> ApiResult<[InterviewDto]>) {
        busy = true
        Task {
            let r = await block()
            if case .ok = r {
                picking = false
                onChanged()
            } else {
                onError(r.errorText(L("login_error_offline")) ?? L("login_error_generic"))
            }
            busy = false
        }
    }

    private func action(_ text: String, _ danger: Bool, _ onClick: @escaping () -> Void) -> some View {
        Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(danger ? red : wa.red)
            .padding(.vertical, 4)
            .contentShape(Rectangle())
            .onTapGesture(perform: onClick)
    }
}

private struct InterviewPicker: View {
    let busy: Bool
    let onSend: (String, Int, String?) -> Void
    private let today = Calendar.current.startOfDay(for: Date())
    @State private var day: Date = Calendar.current.date(byAdding: .day, value: 1, to: Calendar.current.startOfDay(for: Date()))!
    /// Minutes after midnight (10:00).
    @State private var time = 10 * 60
    @State private var minutes = 15
    private let slots: [Int] = (8...22).flatMap { h in [h * 60, h * 60 + 30] }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(0...13, id: \.self) { n in
                        let d = Calendar.current.date(byAdding: .day, value: n, to: today)!
                        pill(formatted(d, "EEE d MMM"), d == day) { day = d }
                    }
                }
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(slots, id: \.self) { t in pill(String(format: "%02d:%02d", t / 60, t % 60), t == time) { time = t } }
                }
            }
            HStack(spacing: 6) {
                ForEach([10, 15, 30, 45, 60], id: \.self) { m in pill(L("iv_minutes", m), m == minutes) { minutes = m } }
            }
            SettingsPrimaryButton(text: L("iv_send"), loading: busy) {
                let at = Calendar.current.date(bySettingHour: time / 60, minute: time % 60, second: 0, of: day) ?? day
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                f.formatOptions = [.withInternetDateTime]
                onSend(f.string(from: at), minutes, nil)
            }
        }
    }

    private func pill(_ text: String, _ selected: Bool, _ action: @escaping () -> Void) -> some View {
        AgreementPill(text: text, on: selected, size: 12, horizontal: 12, vertical: 6, action: action)
    }
}

/// The line the server writes in the room when an interview is proposed, answered or cancelled.
struct SystemLine: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 4)
    }
}
