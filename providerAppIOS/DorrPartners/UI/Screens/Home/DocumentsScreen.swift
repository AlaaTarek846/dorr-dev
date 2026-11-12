// providerApp `ui/screens/DocumentsScreen.kt`
import PhotosUI
import SwiftUI
import UIKit

/// The server takes up to 10MB a side (DocumentUploadRequest); a scaled photo is far smaller.
private let maxDocBytes = 10 * 1024 * 1024

/// An approved paper that ends within this many days shows as "expires soon".
private let expiringDays = 30

/// The orange of "expires soon" (between the amber of "missing" and the red of "expired").
private let paperOrange = Color(hex: 0xEA580C)

/// The establishment's papers (C-02, A-06): what is required for its country and services, the state of each upload as a badge, how
/// many are ready, uploading one (front and back from the camera, the photos or the files, its number and expiry when the type asks for
/// them), and sending the establishment for review. Read once when the page opens, when the connection returns and when a live notice
/// arrives (a paper reviewed); an upload and the submit each answer with one request. Only the owner uploads the establishment's
/// papers (the server checks it too). With `driverUuid`, one driver's licence (C-03) instead.
@MainActor
struct DocumentsScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    let onBack: () -> Void
    var driverUuid: String?

    @State private var docs: DocumentsDto?
    @State private var establishment: EstablishmentDto?
    @State private var error: String?
    @State private var info: String?
    @State private var busy = false
    /// nil = the list; else the type being uploaded.
    @State private var formTypeId: Int?

    var body: some View {
        ZStack {
            if let id = formTypeId, let type = docs?.required?.first(where: { $0.id == id }) {
                DocumentForm(
                    type: type,
                    subject: driverUuid != nil ? "driver" : "establishment",
                    subjectUuid: driverUuid,
                    onBack: { formTypeId = nil },
                    onUploaded: {
                        Task {
                            await reload()
                            formTypeId = nil
                        }
                    },
                    existing: docs?.documents?.first { $0.documentTypeId == type.id }
                )
            } else {
                list
            }
        }
        .task(id: network.reconnectTick) { await reload() }
        .onReceive(Realtime.shared.changed) { _ in Task { await reload() } }
    }

    private var list: some View {
        let isOwner = session.membership?.isOwner == true
        // A driver's licence (C-03): the driver themself or someone with fleet.drivers uploads it; the establishment's own
        // status and review are not part of that page.
        let forDriver = driverUuid != nil
        let status = forDriver ? nil : establishment?.status
        // Papers can change until the review starts, and again after a refusal; an approved establishment may replace one.
        let editable = forDriver ? true : isOwner && status != "under_review"
        let canSubmit = docs != nil && !forDriver && isOwner && (status == "draft" || status == "rejected")
        let missing = docs?.missing ?? []
        return PaperScaffold(
            title: L(forDriver ? "drv_docs_title" : "pd_title"),
            onBack: onBack,
            bottom: canSubmit ? AnyView(submitBar(missing: missing)) : nil
        ) {
            if let current = docs {
                let required = current.required ?? []
                if let status {
                    EstablishmentStatusCard(status: status, reason: establishment?.statusReason).paperStagger(0)
                } else {
                    FormIntro(icon: "person.text.rectangle", title: L("pd_intro_title"), text: L("drv_docs_intro")).paperStagger(0)
                }
                if !forDriver && !isOwner {
                    PaperNote(text: L("pd_owner_only"), color: Slate, icon: "info.circle.fill").paperStagger(1)
                }
                if required.isEmpty {
                    EmptyState(icon: "checkmark.seal.fill", title: L("pd_intro_title"), body: L("pd_none_required")).padding(.top, 24)
                } else {
                    PaperProgressCard(required: required, documents: current.documents ?? [], missing: missing).paperStagger(1)
                }
                ForEach(Array(required.enumerated()), id: \.element.id) { index, type in
                    PaperCard(type: type, doc: current.documents?.first { $0.documentTypeId == type.id }, missing: missing.contains(type.code ?? ""),
                              editable: editable, busy: busy) { formTypeId = type.id }
                        .paperStagger(index + 2)
                }
            } else if error == nil {
                SkeletonList(count: 4)
            }
            if let info { PaperNote(text: info, color: Green, icon: "checkmark.circle.fill") }
        }
        .messageToast(error)
    }

    private func submitBar(missing: [String]) -> some View {
        VStack(spacing: 10) {
            if !missing.isEmpty {
                Text(L("pp_submit_need")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            SettingsPrimaryButton(text: L("pd_submit"), enabled: missing.isEmpty, loading: busy, action: submit)
        }
        .animation(.easeOut(duration: 0.25), value: missing.isEmpty)
    }

    private func reload() async {
        let result = await WorkspaceApi.documents(driverUuid: driverUuid)
        if let data = result.value {
            docs = data
            error = nil
        } else {
            error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
        if let show = await WorkspaceApi.establishment().value {
            establishment = show.establishment
            Session.shared.setMembership(show.membership)
        }
    }

    private func submit() {
        busy = true
        error = nil
        info = nil
        Task {
            let result = await WorkspaceApi.submit()
            busy = false
            if let data = result.value {
                establishment = data
                info = L("pd_submitted_ok")
            } else {
                // `provider_documents_missing` comes with the server's own sentence ("complete the papers first").
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            await reload()
        }
    }
}

/// The establishment's review state on top of its papers: an icon that breathes while under review, the state and what to do next.
private struct EstablishmentStatusCard: View {
    @Environment(\.wa) private var wa
    let status: String
    let reason: String?
    @State private var breathe = false

    var body: some View {
        let color = establishmentStatusColor(status)
        HStack(spacing: 14) {
            Image(systemName: icon).font(.system(size: 24, weight: .semibold)).foregroundStyle(color)
                .frame(width: 54, height: 54)
                .background(color.opacity(0.14), in: Circle())
                .scaleEffect(breathe ? 1.08 : 1)
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: establishmentStatusLabel(status)).font(.dorr(16, .heavy)).foregroundStyle(color)
                Text(verbatim: hint).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(16)
        .frame(maxWidth: 440)
        .settingsSurface(20)
        .onAppear {
            guard status == "under_review" else { return }
            withAnimation(.easeInOut(duration: 1.1).repeatForever(autoreverses: true)) { breathe = true }
        }
    }

    private var icon: String {
        switch status {
        case "approved": return "checkmark.seal.fill"
        case "under_review": return "hourglass"
        case "rejected", "suspended": return "exclamationmark.circle.fill"
        default: return "square.and.pencil"
        }
    }

    private var hint: String {
        switch status {
        case "under_review": return L("pd_under_review")
        case "approved": return L("pp_status_approved_hint")
        case "rejected", "suspended":
            if let reason = notBlank(reason) { return L("pd_reason", reason) }
            return L("pp_status_rejected_hint")
        default: return L("pd_intro_body")
        }
    }
}

// MARK: - The papers kit (shared with the vehicle pages)

/// A paper's state, from the server's status and the `missing` list.
enum PaperState: Equatable {
    case missing, pending, approved, expiring, rejected, expired

    /// Whether it needs the provider to do something (upload or upload again).
    var needsAction: Bool { self == .missing || self == .rejected || self == .expired }
}

func paperState(_ doc: ProviderDocumentDto?, missing: Bool) -> PaperState {
    guard let doc else { return .missing }
    switch doc.status {
    case "rejected": return .rejected
    case "expired": return .expired
    case "pending": return .pending
    case "approved":
        // Approved but no longer counted (its date passed since): it has to be renewed.
        if missing { return .expired }
        return expiresSoon(doc.expiresOn) ? .expiring : .approved
    default:
        return missing ? .missing : .pending
    }
}

/// `YYYY-MM-DD` as a local midnight (the same reading as `DateField`).
private let paperDayFormatter: DateFormatter = {
    let f = DateFormatter()
    f.calendar = Calendar(identifier: .gregorian)
    f.locale = Locale(identifier: "en_US_POSIX")
    f.dateFormat = "yyyy-MM-dd"
    return f
}()

private func paperDay(_ text: String) -> Date? { paperDayFormatter.date(from: text) }

private func expiresSoon(_ date: String?) -> Bool {
    guard let date, let day = paperDay(date) else { return false }
    let today = Calendar.current.startOfDay(for: Date())
    guard let days = Calendar.current.dateComponents([.day], from: today, to: day).day else { return false }
    return days >= 0 && days <= expiringDays
}

/// `YYYY-MM-DD` strictly after today (the server's `after:today` on `expires_on`).
func isAfterToday(_ date: String?) -> Bool {
    guard let date, let day = paperDay(date) else { return false }
    return day > Calendar.current.startOfDay(for: Date())
}

/// Tomorrow at midnight: the first day an expiry may be.
func paperTomorrow() -> Date {
    Calendar.current.date(byAdding: .day, value: 1, to: Calendar.current.startOfDay(for: Date())) ?? Date()
}

/// "5 Jan 2027" in the app language (Latin digits); the raw text if it isn't a date.
func paperDate(_ date: String?) -> String {
    guard let date, let day = paperDay(date) else { return date ?? "" }
    let formatter = DateFormatter()
    formatter.locale = Locale(identifier: AppLocale.current == "ar" ? "ar@numbers=latn" : AppLocale.current)
    formatter.dateStyle = .medium
    formatter.timeStyle = .none
    return formatter.string(from: day)
}

/// A state in words, colour and icon (never colour alone).
struct PaperLook {
    let label: String
    let color: Color
    let icon: String
}

func paperLook(_ state: PaperState) -> PaperLook {
    switch state {
    case .missing: return PaperLook(label: L("pd_state_missing"), color: Amber, icon: "icloud.and.arrow.up.fill")
    case .pending: return PaperLook(label: L("pd_state_pending"), color: Blue, icon: "hourglass")
    case .approved: return PaperLook(label: L("pd_state_approved"), color: Green, icon: "checkmark.circle.fill")
    case .expiring: return PaperLook(label: L("pp_state_expiring"), color: paperOrange, icon: "clock.badge.exclamationmark.fill")
    case .rejected: return PaperLook(label: L("pd_state_rejected"), color: Red, icon: "exclamationmark.circle.fill")
    case .expired: return PaperLook(label: L("pd_state_expired"), color: Red, icon: "calendar.badge.exclamationmark")
    }
}

/// The state badge: icon + word on a soft pill of its colour; it fades to the new state when the state changes. `solid` sits on a photo.
struct PaperStateBadge: View {
    let state: PaperState
    var solid = false

    var body: some View {
        let look = paperLook(state)
        HStack(spacing: 4) {
            Image(systemName: look.icon).font(.system(size: 10, weight: .bold))
            Text(verbatim: look.label).font(.dorr(11, .bold)).lineLimit(1)
        }
        .foregroundStyle(look.color)
        .padding(.horizontal, 8)
        .padding(.vertical, 3)
        .background(solid ? Color.white.opacity(0.94) : look.color.opacity(0.12), in: Capsule())
        .id(state)
        .transition(.opacity)
        .animation(.easeOut(duration: 0.25), value: state)
    }
}

/// "3 of 5 ready": a ring and a bar that fill as papers are uploaded, and one badge per state count.
struct PaperProgressCard: View {
    @Environment(\.wa) private var wa
    let required: [DocumentTypeDto]
    let documents: [ProviderDocumentDto]
    let missing: [String]
    @State private var shown: Double = 0

    var body: some View {
        let total = required.count
        let states = required.map { type in paperState(documents.first { $0.documentTypeId == type.id }, missing: missing.contains(type.code ?? "")) }
        let ready = required.filter { !missing.contains($0.code ?? "") }.count
        let approved = states.filter { $0 == .approved || $0 == .expiring }.count
        let pending = states.filter { $0 == .pending }.count
        let action = states.filter { $0.needsAction }.count
        let done = total > 0 && ready == total
        let target = total == 0 ? 1 : Double(ready) / Double(total)
        let tint = done ? Green : wa.red
        let track = wa.night ? wa.darkWell : Color(hex: 0xF1F2F4)
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 14) {
                ZStack {
                    Circle().stroke(track, lineWidth: 5)
                    Circle().trim(from: 0, to: shown).stroke(tint, style: StrokeStyle(lineWidth: 5, lineCap: .round)).rotationEffect(.degrees(-90))
                    if done {
                        Image(systemName: "checkmark").font(.system(size: 20, weight: .heavy)).foregroundStyle(Green)
                    } else {
                        Text(verbatim: "\(ready)/\(total)").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                            .environment(\.layoutDirection, .leftToRight)
                    }
                }
                .frame(width: 58, height: 58)
                VStack(alignment: .leading, spacing: 3) {
                    Text(done ? L("pp_progress_all") : L("pp_progress_title", ready, total)).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L(done ? "pp_progress_done_hint" : "pp_progress_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            PaperBar(value: shown, color: tint, track: track)
            FlowLayout(spacing: 8) {
                if approved > 0 { CountBadge(icon: "checkmark.circle.fill", text: L("pp_count_approved", approved), color: Green) }
                if pending > 0 { CountBadge(icon: "hourglass", text: L("pp_count_pending", pending), color: Blue) }
                if action > 0 { CountBadge(icon: "icloud.and.arrow.up.fill", text: L("pp_count_action", action), color: Amber) }
            }
        }
        .padding(16)
        .frame(maxWidth: 440)
        .settingsSurface(20)
        .onAppear { withAnimation(.easeOut(duration: 0.7)) { shown = target } }
        .onChange(of: target) { value in withAnimation(.easeOut(duration: 0.7)) { shown = value } }
    }
}

/// A thin rounded progress bar.
struct PaperBar: View {
    let value: Double
    let color: Color
    let track: Color

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                Capsule().fill(track)
                Capsule().fill(color).frame(width: max(0, min(1, value)) * proxy.size.width)
            }
        }
        .frame(height: 6)
    }
}

private struct CountBadge: View {
    let icon: String
    let text: String
    let color: Color

    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 11, weight: .bold))
            Text(verbatim: text).font(.dorr(12, .bold))
        }
        .foregroundStyle(color)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(color.opacity(0.10), in: Capsule())
    }
}

/// One required paper as a card: an icon tile in its state's colour, the name and the state badge, the number and the date it is valid
/// until, the refusal reason, the sides already sent, and what tapping does (upload, upload again or replace).
struct PaperCard: View {
    @Environment(\.wa) private var wa
    let type: DocumentTypeDto
    let doc: ProviderDocumentDto?
    let missing: Bool
    let editable: Bool
    let busy: Bool
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) { card }
            .buttonStyle(PaperPressStyle())
            .disabled(!editable || busy)
    }

    private var card: some View {
        let state = paperState(doc, missing: missing)
        let look = paperLook(state)
        let ringed = state == .rejected || state == .expired
        return VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 12) {
                Image(systemName: type.kind == "photo" ? "camera.fill" : "doc.text.fill").font(.system(size: 19, weight: .semibold))
                    .foregroundStyle(look.color)
                    .frame(width: 46, height: 46)
                    .background(look.color.opacity(0.13), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: type.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2)
                    PaperStateBadge(state: state)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if editable {
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)
                }
            }
            if state == .missing, let instructions = notBlank(type.instructions) {
                Text(verbatim: instructions).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
            }
            if let doc, notBlank(doc.number) != nil || notBlank(doc.expiresOn) != nil {
                FlowLayout(spacing: 8) {
                    if let number = notBlank(doc.number) { MetaChip(icon: "person.text.rectangle", text: L("pd_number_line", number), color: wa.settingsMut) }
                    if let expires = notBlank(doc.expiresOn) {
                        MetaChip(icon: "calendar", text: L("pp_until", paperDate(expires)),
                                 color: state == .expiring || state == .expired ? look.color : wa.settingsMut)
                    }
                }
            }
            if doc?.status == "rejected", let reason = notBlank(doc?.rejectionReason) {
                PaperNote(text: L("pd_reason", reason), color: Red, icon: "exclamationmark.circle.fill")
            }
            let shots = [doc?.front, doc?.back].compactMap { notBlank($0) }
            if !shots.isEmpty {
                HStack(spacing: 8) { ForEach(shots, id: \.self) { PaperThumb(url: $0) } }
            }
            if editable {
                HStack(spacing: 6) {
                    Image(systemName: state == .missing ? "icloud.and.arrow.up.fill" : "arrow.triangle.2.circlepath").font(.system(size: 14, weight: .bold))
                    Text(L(state == .missing ? "pp_upload_now" : (state.needsAction ? "pp_reupload" : "pd_replace"))).font(.dorr(13, .bold))
                }
                .foregroundStyle(wa.red)
            }
        }
        .padding(14)
        .frame(maxWidth: 440, alignment: .leading)
        .settingsSurface(20)
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(ringed ? Red.opacity(0.45) : .clear, lineWidth: 1.5))
        .animation(.easeOut(duration: 0.3), value: state)
        .contentShape(Rectangle())
    }
}

private struct MetaChip: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let color: Color

    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 11, weight: .semibold))
            Text(verbatim: text).font(.dorr(12, .semibold))
        }
        .foregroundStyle(color)
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(wa.night ? wa.darkWell : Color(hex: 0xF5F6F8), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// A side already sent: its picture (a PDF shows its icon).
private struct PaperThumb: View {
    @Environment(\.wa) private var wa
    let url: String

    var body: some View {
        ZStack {
            wa.night ? wa.darkWell : Color(hex: 0xF1F2F4)
            if (url.split(separator: "?").first.map(String.init) ?? url).lowercased().hasSuffix(".pdf") {
                Image(systemName: "doc.richtext.fill").font(.system(size: 22)).foregroundStyle(Red)
            } else {
                Image(systemName: "doc.text").font(.system(size: 16)).foregroundStyle(wa.settingsMut)
                MediaThumb(url: url)
            }
        }
        .frame(width: 86, height: 58)
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// A soft note in a colour: an icon and a line (a hint, a warning, a refusal reason).
struct PaperNote: View {
    @Environment(\.wa) private var wa
    let text: String
    let color: Color
    let icon: String

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(color).frame(width: 20)
            Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: 440)
        .background(color.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// A section title inside a form: the app colour's small bar and the words.
struct PaperSectionTitle: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(spacing: 8) {
            RoundedRectangle(cornerRadius: 2).fill(wa.red).frame(width: 4, height: 16)
            Text(verbatim: text).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
        }
        .frame(maxWidth: 440, alignment: .leading)
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// A page of the papers screens: `Page`'s backdrop and header (no back button when `onBack` is nil), the scrolling content, and an
/// optional `bottom` bar fixed under it for the page's main action.
struct PaperScaffold<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: (() -> Void)?
    var bottom: AnyView?
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                if let onBack {
                    SubHeader(title: title, onBack: onBack)
                } else {
                    SettingsScreenTitle(title: title)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.horizontal, 14)
                        .padding(.top, 14)
                        .padding(.bottom, 12)
                }
                ScrollView {
                    VStack(spacing: 14) { content() }
                        .frame(maxWidth: .infinity)
                        .padding(.horizontal, 18)
                        .padding(.top, 2)
                        .padding(.bottom, 24)
                }
                .scrollDismissesKeyboard(.interactively)
                if let bottom {
                    bottom
                        .padding(.horizontal, 18)
                        .padding(.top, 14)
                        .padding(.bottom, 12)
                        .frame(maxWidth: .infinity)
                        .background(
                            UnevenTop(radius: 24).fill(wa.settingsCard)
                                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 12, y: -2)
                                .ignoresSafeArea(edges: .bottom)
                        )
                        .overlay(alignment: .top) { if wa.night { Rectangle().fill(wa.line).frame(height: 1) } }
                }
            }
        }
        .navigationBarHidden(true)
    }
}

/// A rectangle with its two top corners rounded (iOS 16 has no UnevenRoundedRectangle).
private struct UnevenTop: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.topLeft, .topRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

/// Rises and fades in the first time it is shown, one after another by `index` (the captain app's `staggerIn`).
private struct PaperStagger: ViewModifier {
    let index: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 22)
            .onAppear {
                guard !shown else { return }
                withAnimation(.easeOut(duration: 0.38).delay(Double(min(index, 8)) * 0.045)) { shown = true }
            }
    }
}

extension View {
    func paperStagger(_ index: Int = 0) -> some View { modifier(PaperStagger(index: index)) }
}

/// A tap that answers with a small press-in instead of a flat highlight (the captain app's `bounceClick`).
struct PaperPressStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.97 : 1)
            .animation(.spring(response: 0.3, dampingFraction: 0.7), value: configuration.isPressed)
    }
}

/// A dashed rounded outline (an empty photo slot).
struct DashedOutline: View {
    let color: Color
    let radius: CGFloat

    var body: some View {
        RoundedRectangle(cornerRadius: radius, style: .continuous)
            .strokeBorder(color, style: StrokeStyle(lineWidth: 1.5, dash: [10, 7]))
    }
}

// MARK: - Picking and uploading

/// Where a photo comes from.
enum PaperSource { case camera, gallery, files }

/// Asks the picker modifier to open a source for `key` (a side, or a type id). `delayed` waits for a closing dialog first.
struct PaperPickRequest: Equatable {
    let key: String
    let source: PaperSource
    var delayed = false
}

/// providerApp `rememberPaperPicker`: the camera, the photos and the files, each turned into a scaled JPEG (or the PDF as it is) by
/// `prepareDocFile`; `onPicked` gets the key it was asked for and the file.
@MainActor
private struct PaperPicker: ViewModifier {
    @Binding var request: PaperPickRequest?
    let allowPdf: Bool
    let onPicked: (String, FilePart) -> Void
    let onError: (String) -> Void
    @State private var key = "front"
    @State private var cameraOpen = false
    @State private var galleryOpen = false
    @State private var filesOpen = false
    @State private var galleryItem: PhotosPickerItem?

    func body(content: Content) -> some View {
        content
            .onChange(of: request) { asked in
                guard let asked else { return }
                request = nil
                key = asked.key
                DispatchQueue.main.asyncAfter(deadline: .now() + (asked.delayed ? 0.4 : 0)) {
                    switch asked.source {
                    case .camera: cameraOpen = true
                    case .gallery: galleryOpen = true
                    case .files: filesOpen = true
                    }
                }
            }
            .fullScreenCover(isPresented: $cameraOpen) {
                ProfileCameraPicker { data in
                    cameraOpen = false
                    if let data { take(data, mime: "image/jpeg") }
                }
                .ignoresSafeArea()
            }
            .photosPicker(isPresented: $galleryOpen, selection: $galleryItem, matching: .images)
            .onChange(of: galleryItem) { item in
                guard let item else { return }
                galleryItem = nil
                Task {
                    // Whatever the library holds (HEIC too) is drawn again as a JPEG by prepareDocFile.
                    if let data = try? await item.loadTransferable(type: Data.self) { take(data, mime: "image/jpeg") } else { onError(L("pd_file_type")) }
                }
            }
            .fileImporter(isPresented: $filesOpen, allowedContentTypes: allowPdf ? [.jpeg, .png, .pdf] : [.jpeg, .png]) { result in
                guard case .success(let url) = result else { return }
                guard let data = profileReadPicked(url), let mime = profileMimeType(of: url) else {
                    onError(L("pd_file_type"))
                    return
                }
                take(data, mime: mime)
            }
    }

    private func take(_ data: Data, mime: String) {
        let asked = key
        let safe = asked.filter { $0.isLetter || $0.isNumber || $0 == "_" }
        Task {
            let file = await Task.detached { prepareDocFile(data, mimeType: mime, name: "doc_\(safe)") }.value
            guard let file else {
                onError(L("pd_file_type"))
                return
            }
            if file.data.count > maxDocBytes {
                onError(L("pd_file_large"))
                return
            }
            onPicked(asked, file)
        }
    }
}

extension View {
    func paperPicker(_ request: Binding<PaperPickRequest?>, allowPdf: Bool, onPicked: @escaping (String, FilePart) -> Void,
                     onError: @escaping (String) -> Void) -> some View {
        modifier(PaperPicker(request: request, allowPdf: allowPdf, onPicked: onPicked, onError: onError))
    }
}

/// providerApp `uploadPaper`: sends one paper or photo of `subject` (`establishment`, `vehicle` / `driver` with `subjectUuid`), telling
/// `onProgress` (on the main thread) how much of the files has gone.
@MainActor
func uploadPaper(subject: String, subjectUuid: String?, type: DocumentTypeDto, number: String?, expires: String?, front: FilePart,
                 back: FilePart?, onProgress: @escaping @MainActor (Double) -> Void) async -> ApiResult<ProviderDocumentDto> {
    var fields = ["subject": subject, "document_type_id": String(type.id)]
    if let subjectUuid { fields["subject_uuid"] = subjectUuid }
    if type.hasNumber == true, let number = trimmedOrNil(number ?? "") { fields["number"] = number }
    if type.hasExpiry == true, let expires = trimmedOrNil(expires ?? "") { fields["expires_on"] = expires }
    return await WorkspaceApi.upload(fields, front: front, back: back, progress: { value in
        Task { @MainActor in onProgress(value) }
    })
}

// MARK: - Uploading one paper

/// Uploading one paper or photo of `subject` (`establishment`, `vehicle` with `subjectUuid`, `driver` with `subjectUuid`): a large
/// preview of each side picked (tap to change, X to remove) from the camera, the photos or the files, then its number and expiry when the
/// type asks for them (the expiry only after today, as the server says), and a fixed upload button with the upload's progress.
/// `existing` is the current upload of that type, when there is one (its state and refusal reason are shown; its number is kept).
@MainActor
struct DocumentForm: View {
    @Environment(\.wa) private var wa
    let type: DocumentTypeDto
    let subject: String
    let subjectUuid: String?
    let onBack: () -> Void
    let onUploaded: () -> Void
    let existing: ProviderDocumentDto?

    @State private var number: String
    @State private var expires = ""
    @State private var front: FilePart?
    @State private var back: FilePart?
    @State private var tried = false
    @State private var busy = false
    @State private var progress: Double = 0
    @State private var error: String?
    /// Which side the source dialog is open for.
    @State private var sourceFor: String?
    @State private var pick: PaperPickRequest?

    init(type: DocumentTypeDto, subject: String, subjectUuid: String?, onBack: @escaping () -> Void, onUploaded: @escaping () -> Void,
         existing: ProviderDocumentDto? = nil) {
        self.type = type
        self.subject = subject
        self.subjectUuid = subjectUuid
        self.onBack = onBack
        self.onUploaded = onUploaded
        self.existing = existing
        _number = State(initialValue: existing?.number ?? "")
    }

    private var photo: Bool { type.kind == "photo" }
    private var numberMissing: Bool { type.hasNumber == true && number.trimmingCharacters(in: .whitespaces).isEmpty }
    private var expiryBad: Bool { type.hasExpiry == true && !isAfterToday(expires) }
    private var backMissing: Bool { type.needsBackSide == true && back == nil }
    private var ready: Bool { front != nil && !backMissing && !numberMissing && !expiryBad }

    var body: some View {
        PaperScaffold(title: type.name ?? "", onBack: { if !busy { onBack() } }, bottom: AnyView(bottomBar)) {
            FormIntro(icon: photo ? "camera.fill" : "doc.text.fill", title: type.name ?? "",
                      text: notBlank(type.instructions) ?? L(photo ? "vh_photo_hint" : "pp_form_intro"))
                .paperStagger(0)
            if let existing {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 8) {
                        Text(L("pp_current_state")).font(.dorr(12)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                        PaperStateBadge(state: paperState(existing, missing: false))
                    }
                    if existing.status == "rejected", let reason = notBlank(existing.rejectionReason) {
                        PaperNote(text: L("pd_reason", reason), color: Red, icon: "exclamationmark.circle.fill")
                    }
                    Text(L("pp_replace_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .padding(14)
                .frame(maxWidth: 440)
                .settingsSurface(18)
                .paperStagger(1)
            }

            PaperSectionTitle(text: L(photo ? "vh_photo_side" : "pp_form_photos")).paperStagger(2)
            SideSlot(label: L(photo ? "vh_photo_side" : "pd_front"), file: front, error: tried && front == nil, photo: photo,
                     onPick: { pick = PaperPickRequest(key: "front", source: $0) }, onChange: { sourceFor = "front" }, onRemove: { front = nil })
                .paperStagger(3)
            if type.needsBackSide == true {
                SideSlot(label: L("pd_back"), file: back, error: tried && backMissing, photo: photo,
                         onPick: { pick = PaperPickRequest(key: "back", source: $0) }, onChange: { sourceFor = "back" }, onRemove: { back = nil })
                    .paperStagger(4)
            }
            Text(L(photo ? "vh_photo_hint" : "pp_camera_hint_doc")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                .frame(maxWidth: 440, alignment: .leading)
                .frame(maxWidth: .infinity, alignment: .leading)

            if type.hasNumber == true || type.hasExpiry == true {
                PaperSectionTitle(text: L("pp_form_details")).paperStagger(5)
                VStack(alignment: .leading, spacing: 14) {
                    if type.hasNumber == true {
                        DorrTextField(value: $number.profileTake(64), label: L("pd_number"), icon: "person.text.rectangle", keyboard: .asciiCapable,
                                      error: tried && numberMissing)
                    }
                    if type.hasExpiry == true {
                        VStack(alignment: .leading, spacing: 6) {
                            DateField(value: $expires, label: L("pd_expires"), minDate: paperTomorrow(), error: tried && expiryBad)
                            Text(L("pp_expiry_hint")).font(.dorr(12)).foregroundStyle(tried && expiryBad ? Red : wa.settingsMut).padding(.horizontal, 6)
                        }
                    }
                }
                .padding(16)
                .frame(maxWidth: 440)
                .settingsSurface(20)
                .paperStagger(6)
            }
        }
        .messageToast(error)
        .paperPicker($pick, allowPdf: !photo, onPicked: { key, file in
            error = nil
            withAnimation(.easeOut(duration: 0.25)) { if key == "back" { back = file } else { front = file } }
        }, onError: { error = $0 })
        .confirmationDialog(L(sourceFor == "back" ? "pd_back" : (photo ? "vh_photo_side" : "pd_front")),
                            isPresented: Binding(get: { sourceFor != nil }, set: { if !$0 { sourceFor = nil } }), titleVisibility: .visible) {
            let side = sourceFor ?? "front"
            Button(L("pd_camera")) { pick = PaperPickRequest(key: side, source: .camera, delayed: true) }
            Button(L("pp_source_gallery")) { pick = PaperPickRequest(key: side, source: .gallery, delayed: true) }
            if !photo { Button(L("pp_source_files")) { pick = PaperPickRequest(key: side, source: .files, delayed: true) } }
            Button(L("common_cancel"), role: .cancel) {}
        }
    }

    private var bottomBar: some View {
        VStack(spacing: 10) {
            if busy {
                VStack(spacing: 6) {
                    PaperBar(value: progress, color: wa.red, track: wa.night ? wa.darkWell : Color(hex: 0xF1F2F4))
                    HStack {
                        Text(L("pp_uploading")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        Text(verbatim: "\(Int(progress * 100))%").font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk)
                    }
                }
                .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            if tried && !ready && !busy {
                PaperNote(text: L("pp_complete_first"), color: Amber, icon: "exclamationmark.triangle.fill")
                    .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            SettingsPrimaryButton(text: L(photo ? "pp_send_photo" : "pd_send"), loading: busy, action: upload)
        }
        .animation(.easeOut(duration: 0.25), value: busy)
        .animation(.easeOut(duration: 0.25), value: tried && !ready)
    }

    private func upload() {
        tried = true
        guard ready, !busy, let front else { return }
        busy = true
        error = nil
        progress = 0
        let backPart = type.needsBackSide == true ? back : nil
        Task {
            let result = await uploadPaper(subject: subject, subjectUuid: subjectUuid, type: type, number: number, expires: expires,
                                           front: front, back: backPart) { value in withAnimation(.linear(duration: 0.2)) { progress = value } }
            busy = false
            if result.value != nil {
                progress = 1
                // The papers do not stay in memory once they are on the server.
                self.front = nil
                self.back = nil
                onUploaded()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// One side of a paper: empty, a dashed box with the camera, the photos (and the files for a paper); picked, the picture large (a PDF
/// shows its icon) with a tick, an X to remove it and "tap to change" (which opens the source dialog).
private struct SideSlot: View {
    @Environment(\.wa) private var wa
    let label: String
    let file: FilePart?
    let error: Bool
    let photo: Bool
    let onPick: (PaperSource) -> Void
    let onChange: () -> Void
    let onRemove: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
            ZStack {
                (wa.night ? wa.darkWell : wa.red.opacity(0.05))
                if let file {
                    filled(file).transition(.opacity.combined(with: .scale(scale: 0.96)))
                } else {
                    empty.transition(.opacity)
                }
            }
            .aspectRatio(1.6, contentMode: .fit)
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .animation(.easeOut(duration: 0.25), value: file?.data.count)
        }
        .frame(maxWidth: 440)
    }

    private var empty: some View {
        ZStack {
            DashedOutline(color: error ? Red : wa.red.opacity(0.45), radius: 20)
            VStack(spacing: 0) {
                Image(systemName: "camera.badge.ellipsis").font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 54, height: 54)
                    .background(wa.red.opacity(0.12), in: Circle())
                Text(L("pp_tap_add")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 8).padding(.bottom, 10)
                HStack(spacing: 8) {
                    SlotAction(icon: "camera.fill", label: L("pd_camera")) { onPick(.camera) }
                    SlotAction(icon: "photo.on.rectangle", label: L("pp_source_gallery")) { onPick(.gallery) }
                    if !photo { SlotAction(icon: "folder.fill", label: L("pp_source_files")) { onPick(.files) } }
                }
            }
            .padding(14)
        }
    }

    private func filled(_ file: FilePart) -> some View {
        Button(action: onChange) {
            ZStack {
                if file.mimeType == "application/pdf" {
                    VStack(spacing: 6) {
                        Image(systemName: "doc.richtext.fill").font(.system(size: 40)).foregroundStyle(Red)
                        Text(L("pp_pdf_chosen")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                    }
                } else if let image = UIImage(data: file.data) {
                    Color.clear.overlay(Image(uiImage: image).resizable().scaledToFill()).clipped()
                }
                VStack {
                    HStack {
                        Image(systemName: "checkmark").font(.system(size: 13, weight: .heavy)).foregroundStyle(.white)
                            .frame(width: 28, height: 28).background(Green, in: Circle())
                        Spacer()
                    }
                    Spacer()
                    HStack(spacing: 6) {
                        Image(systemName: "arrow.triangle.2.circlepath").font(.system(size: 13, weight: .bold))
                        Text(L("pp_tap_change")).font(.dorr(12, .bold))
                        Spacer()
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .background(LinearGradient(colors: [.clear, .black.opacity(0.55)], startPoint: .top, endPoint: .bottom))
                    .padding(.horizontal, -10)
                    .padding(.bottom, -10)
                }
                .padding(10)
            }
        }
        .buttonStyle(.plain)
        .overlay(alignment: .topTrailing) {
            Button(action: onRemove) {
                Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 32, height: 32).background(Color.black.opacity(0.55), in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("pp_remove"))
            .padding(10)
        }
    }
}

private struct SlotAction: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
                Text(verbatim: label).font(.dorr(12, .bold)).lineLimit(1)
            }
            .foregroundStyle(wa.red)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.red.opacity(0.25), lineWidth: 1))
        }
        .buttonStyle(PaperPressStyle())
    }
}
