import PhotosUI
import SwiftUI
import UIKit

// providerApp `ui/wallet/WaPinFlows.kt` — the DORR app's wallet PIN pages, unchanged (ported from iosApp `UI/Wallet/WaPinFlows.swift`
// through driverAppIos). Only the networking differs: this app's `WalletApi` answers an `ApiResult` instead of throwing, and the
// establishment's wallet is chosen by the `X-Establishment` header `Api` adds to every request.

/// What a failed call shows: the server's own message, or the "no connection" text.
private func waFailText<T>(_ result: ApiResult<T>) -> String {
    let network = L("wa_error_network")
    guard let text = result.errorText(network), !text.isEmpty else { return network }
    return text
}

/// How a forgotten wallet PIN gets recovered (docs/wallet-tasks.md, "PIN recovery"). One of these is
/// chosen *before* the PIN is created; `wire` is the value the API uses. (androidApp `WaPinFlows.kt`)
enum RecoveryMethodUi: String, CaseIterable {
    case password = "password"
    case birthDate = "birth_date"
    case idPhoto = "id_photo"
    case passportPhoto = "passport_photo"
    case email = "email"

    var wire: String { rawValue }

    var icon: String {
        switch self {
        case .password: return "key.fill"
        case .birthDate: return "birthday.cake.fill"
        case .idPhoto: return "person.text.rectangle.fill"
        case .passportPhoto: return "airplane"
        case .email: return "envelope.fill"
        }
    }

    var tone: Tone {
        switch self {
        case .password: return .red
        case .birthDate: return .pink
        case .idPhoto: return .blue
        case .passportPhoto: return .amber
        case .email: return .green
        }
    }

    var title: String {
        switch self {
        case .password: return L("wa_rec_m_password")
        case .birthDate: return L("wa_rec_m_birth_date")
        case .idPhoto: return L("wa_rec_m_id_photo")
        case .passportPhoto: return L("wa_rec_m_passport_photo")
        case .email: return L("wa_rec_m_email")
        }
    }

    var desc: String {
        switch self {
        case .password: return L("wa_rec_m_password_d")
        case .birthDate: return L("wa_rec_m_birth_date_d")
        case .idPhoto: return L("wa_rec_m_id_photo_d")
        case .passportPhoto: return L("wa_rec_m_passport_photo_d")
        case .email: return L("wa_rec_m_email_d")
        }
    }

    var isPhoto: Bool { self == .idPhoto || self == .passportPhoto }

    static func of(_ wire: String?) -> RecoveryMethodUi? { wire.flatMap(RecoveryMethodUi.init(rawValue:)) }
}

private func photoName(_ method: RecoveryMethodUi) -> String {
    L(method == .passportPhoto ? "wa_photo_passport" : "wa_photo_id")
}

private let waEmailShape = #"^[^@\s]+@[^@\s]+\.[^@\s]+$"#

// MARK: - Photos

/// A picture, already shrunk and turned into a JPEG the server accepts (max 5 MB).
struct WaPickedPhoto: Equatable {
    let bytes: Data
    let preview: UIImage
}

/// At most ~2000px on the long side, upright (the camera's rotation applied), re-encoded as JPEG 88 %.
/// Nil when the data is not a picture.
func waReadPhoto(_ data: Data) -> WaPickedPhoto? {
    guard let image = UIImage(data: data) else { return nil }
    return waReadPhoto(image)
}

func waReadPhoto(_ image: UIImage) -> WaPickedPhoto? {
    let longSide = max(image.size.width, image.size.height)
    var scale: CGFloat = 1
    while longSide / scale > 2000 { scale *= 2 }
    let target = CGSize(width: image.size.width / scale, height: image.size.height / scale)
    let format = UIGraphicsImageRendererFormat.default()
    format.scale = 1
    // Drawing re-applies imageOrientation, so the result is upright like Android's EXIF rotation.
    let upright = UIGraphicsImageRenderer(size: target, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: target)) }
    guard let jpeg = upright.jpegData(compressionQuality: 0.88) else { return nil }
    return WaPickedPhoto(bytes: jpeg, preview: upright)
}

/// The front / back camera for a live photo (a selfie has to be taken live, not picked from the gallery).
private struct WaCameraPicker: UIViewControllerRepresentable {
    let front: Bool
    let onPicked: (UIImage?) -> Void

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        if front, UIImagePickerController.isCameraDeviceAvailable(.front) { picker.cameraDevice = .front }
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ controller: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onPicked: onPicked) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onPicked: (UIImage?) -> Void

        init(onPicked: @escaping (UIImage?) -> Void) { self.onPicked = onPicked }

        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            onPicked(info[.originalImage] as? UIImage)
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { onPicked(nil) }
    }
}

/// The "tap to choose a photo" card; shows the picture once one is chosen.
@MainActor
private struct WaPhotoPicker: View {
    @Environment(\.wa) private var wa
    let photo: WaPickedPhoto?
    let onPicked: (WaPickedPhoto) -> Void
    let onUnreadable: () -> Void
    var useCamera = false

    @State private var galleryItem: PhotosPickerItem?
    @State private var showGallery = false
    @State private var showCamera = false

    var body: some View {
        VStack(spacing: 0) {
            Button(action: launch) {
                ZStack {
                    if let photo {
                        Image(uiImage: photo.preview).resizable().scaledToFit().frame(maxHeight: 260).padding(8)
                    } else {
                        VStack(spacing: 0) {
                            WaIconWell(icon: useCamera ? "camera.fill" : "photo.badge.plus", size: 58, iconSize: 28)
                            Text(L(useCamera ? "wa_rec_photo_take" : "wa_rec_photo_pick")).font(.dorr(15, .heavy)).foregroundStyle(wa.red).padding(.top, 10)
                        }
                        .padding(24)
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 190)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(photo == nil ? wa.red.opacity(0.35) : wa.line, lineWidth: 1.5))
            }
            .buttonStyle(.plain)
            if photo != nil {
                WaButton(text: L(useCamera ? "wa_rec_photo_retake" : "wa_rec_photo_change"), style: .quiet, action: launch).padding(.top, 6)
            }
        }
        .photosPicker(isPresented: $showGallery, selection: $galleryItem, matching: .images)
        .onChange(of: galleryItem) { item in
            guard let item else { return }
            Task {
                let data = try? await item.loadTransferable(type: Data.self)
                galleryItem = nil
                if let picked = data.flatMap(waReadPhoto) { onPicked(picked) } else { onUnreadable() }
            }
        }
        .fullScreenCover(isPresented: $showCamera) {
            WaCameraPicker(front: true) { image in
                showCamera = false
                guard let image else { return }
                if let picked = waReadPhoto(image) { onPicked(picked) } else { onUnreadable() }
            }
            .ignoresSafeArea()
        }
    }

    private func launch() {
        if useCamera {
            // No camera (the simulator): nothing to open, same as a refused permission on Android.
            if UIImagePickerController.isSourceTypeAvailable(.camera) { showCamera = true }
        } else {
            showGallery = true
        }
    }
}

// MARK: - Shared pieces

private struct WaStepHeader: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tone: Tone = .red
    let title: String
    let sub: String

    var body: some View {
        VStack(spacing: 0) {
            WaIconWell(icon: icon, tone: tone, size: 58, iconSize: 28)
            Text(title).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 10)
            if !sub.isEmpty {
                Text(sub).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineSpacing(4).padding(.top, 4)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 8)
        .padding(.bottom, 18)
    }
}

/// A scrolling form with its button pinned underneath; SwiftUI lifts it above the keyboard.
private struct WaFormStep<Content: View, Cta: View>: View {
    @ViewBuilder let cta: () -> Cta
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0, content: content).padding(.horizontal, 8)
            }
            .scrollDismissesKeyboard(.interactively)
            .frame(maxHeight: .infinity)
            VStack(spacing: 0, content: cta).padding(.horizontal, 8).padding(.vertical, 12)
        }
        .frame(maxHeight: .infinity)
    }
}

private struct WaMethodRow: View {
    @Environment(\.wa) private var wa
    let method: RecoveryMethodUi
    let index: Int
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            WaCard(padding: 14) {
                HStack(spacing: 12) {
                    WaIconWell(icon: method.icon, tone: method.tone)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(method.title).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                        Text(method.desc).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(2).multilineTextAlignment(.leading)
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.forward").font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.soft)
                }
            }
        }
        .buttonStyle(.plain)
        .waRise(index)
    }
}

/// A full-height pad inside a page (the `Box(weight(1f)) { WaPinPad(fillMaxSize) }` of every flow).
private struct WaPadArea<Pad: View>: View {
    @ViewBuilder let pad: () -> Pad

    var body: some View {
        pad().frame(maxWidth: .infinity, maxHeight: .infinity).padding(.horizontal, 8).waRise(0)
    }
}

// MARK: - Create the PIN

/// First-time PIN: choose a way to get the PIN back → fill in its details (an e-mail is confirmed with a
/// 4-digit code) → enter the PIN → confirm it. Draws its own [WaPage]. `onDone` runs after the PIN exists
/// (after a "saved" seal when `showSaved`); `onCreated` gets the new PIN first, so the wallet opens with it (this app
/// keeps the unlocked PIN for the money pages). With `existingPin` the person already has a PIN and is only
/// switching how it can be recovered — proven with that PIN, and it ends after the method (no PIN steps).
@MainActor
struct WaPinSetupPage: View {
    @Environment(\.wa) private var wa
    let onExit: () -> Void
    let onDone: () -> Void
    var title = L("wa_pin_page_title")
    var showSaved = true
    var existingPin: String?
    var onCreated: (String) -> Void = { _ in }

    @State private var step = "method"
    @State private var method = RecoveryMethodUi.password
    @State private var password = ""
    @State private var passwordAgain = ""
    @State private var day = ""
    @State private var month = ""
    @State private var year = ""
    @State private var email = ""
    @State private var photo: WaPickedPhoto?
    @State private var code = ""
    @State private var first = ""
    @State private var error: String?
    @State private var notice: String?
    @State private var busy = false
    /// Seconds until a new e-mail code may be asked for (the personal-data screens use the same 60): the phone's clock only.
    @State private var countdown = 0

    private var changing: Bool { existingPin != nil }

    var body: some View {
        WaPage(title: title, onBack: back, scroll: false) {
            switch step {
            case "method": methodStep
            case "detail": detailStep
            case "code": codeStep
            case "saved": savedStep
            default: pinStep
            }
        }
        .task(id: countdown) {
            guard countdown > 0 else { return }
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            if !Task.isCancelled { countdown -= 1 }
        }
    }

    private var methodStep: some View {
        ScrollView {
            VStack(spacing: 10) {
                WaStepHeader(icon: "shield.fill", title: L("wa_rec_choose_title"), sub: L("wa_rec_choose_sub"))
                ForEach(Array(RecoveryMethodUi.allCases.enumerated()), id: \.element) { index, item in
                    WaMethodRow(method: item, index: index) {
                        method = item
                        error = nil
                        step = "detail"
                    }
                }
            }
            .padding(.horizontal, 8)
        }
        .frame(maxHeight: .infinity)
    }

    private var detailStep: some View {
        WaFormStep {
            WaError(message: error)
            WaButton(text: L(method == .email ? "pd_send_code" : "wa_continue"), loading: busy, action: submitDetail)
        } content: {
            // The icon and the name of the method on top, the same for every method.
            WaStepHeader(icon: method.icon, tone: method.tone, title: method.title, sub: "")
            switch method {
            case .password:
                // In a card like the date-of-birth and e-mail screens: the two fields, then one line of help.
                WaCard(padding: 16) {
                    WaTextField(value: bind($password), label: L("wa_rec_password"), icon: "lock.fill", password: true)
                    WaTextField(value: bind($passwordAgain), label: L("wa_rec_password_confirm"), icon: "lock.rotation", password: true).padding(.top, 12)
                    Text(L("wa_rec_password_hint")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(3).padding(.top, 12).padding(.horizontal, 2)
                }
            case .birthDate:
                WaBirthPicker(day: bind($day), month: bind($month), year: bind($year), error: error != nil)
                WaNote(text: L("wa_rec_birth_hint")).padding(.top, 12)
            case .email:
                // The same card as the e-mail screen of the personal data: label, pill field, one line of help.
                WaCard(padding: 16) {
                    Text(L("wa_rec_email")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
                    WaPillField(value: bind($email), placeholder: "name@example.com", icon: "envelope.fill", keyboard: .emailAddress, error: error != nil)
                    Text(L("wa_rec_email_hint")).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(3).padding(.top, 10).padding(.horizontal, 2)
                }
            default:
                WaPhotoPicker(photo: photo, onPicked: { photo = $0; error = nil }, onUnreadable: { error = L("wa_rec_photo_unreadable") })
                WaNote(text: L(method == .passportPhoto ? "wa_rec_photo_passport_hint" : "wa_rec_photo_id_hint")).padding(.top, 12)
            }
        }
    }

    private var codeStep: some View {
        WaFormStep {
            WaError(message: error)
            WaButton(text: L("profile_otp_confirm"), enabled: code.count == 4, loading: busy, action: confirmCode)
        } content: {
            WaCard(padding: 18) {
                Text(L("wa_rec_code_sub", email.trimmingCharacters(in: .whitespaces)))
                    .font(.dorr(13)).foregroundStyle(wa.mut).lineSpacing(5).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                WaOtpBoxes(value: bind($code), error: error != nil).padding(.top, 18)
                Group {
                    if countdown > 0 {
                        Text(L("profile_otp_timer", countdown)).font(.dorr(13)).foregroundStyle(wa.mut)
                    } else {
                        Button(action: resendCode) {
                            Text(L("profile_otp_resend")).font(.dorr(13.5, .heavy)).foregroundStyle(wa.red).padding(.vertical, 4)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.top, 16)
                if let notice {
                    Text(notice).font(.dorr(12.5)).foregroundStyle(wa.green).frame(maxWidth: .infinity).padding(.top, 8)
                }
            }
        }
    }

    private var savedStep: some View {
        WaStatusColumn {
            WaSeal()
            WaStatusTitle(text: L(changing ? "wa_rec_changed_title" : "wa_pin_saved_title"))
            WaStatusText(text: L(changing ? "wa_rec_changed_text" : "wa_pin_saved_text"))
            WaButton(text: L("wa_done"), action: onDone).padding(.horizontal, 20).padding(.top, 12)
        }
    }

    private var pinStep: some View {
        let confirming = step == "confirm"
        return WaPadArea {
            WaPinPad(
                title: L(confirming ? "wa_pin_confirm_title" : "wa_pin_create_title_page"),
                sub: L(confirming ? "wa_pin_confirm_sub" : "wa_pin_digits_sub"),
                icon: "shield.fill"
            ) { pin in
                if !confirming {
                    first = pin
                    step = "confirm"
                    return .reset
                }
                if pin != first {
                    step = "pin"
                    return .error(L("wa_pin_mismatch"))
                }
                // The server trusts the phone that creates the PIN (X-Device-Id): no "new device" page right after.
                let result = await WalletApi.createPin(CreatePinRequest(pin: first, pinConfirmation: pin))
                if result.value == nil {
                    step = "pin"
                    return .error(waFailText(result))
                }
                onCreated(pin)
                if showSaved { step = "saved" } else { onDone() }
                return .ok
            }
        }
    }

    /// A binding that also clears the shown error, like `{ value = it; error = null }` on Android.
    private func bind(_ binding: Binding<String>) -> Binding<String> {
        Binding(get: { binding.wrappedValue }, set: { binding.wrappedValue = $0; error = nil })
    }

    private func back() {
        error = nil
        notice = nil
        switch step {
        case "method", "saved": onExit()
        case "detail": step = "method"
        case "code": step = "detail"
        case "pin": step = "method"
        case "confirm": step = "pin"
        default: break
        }
    }

    private func submitDetail() {
        switch method {
        case .password:
            error = password.count < 6 ? L("wa_rec_password_short") : (password != passwordAgain ? L("wa_rec_password_mismatch") : nil)
        case .birthDate:
            error = birthDateOrNull(day, month, year) == nil ? L("wa_rec_birth_invalid") : nil
        case .email:
            error = email.trimmingCharacters(in: .whitespaces).range(of: waEmailShape, options: .regularExpression) == nil ? L("wa_rec_email_invalid") : nil
        default:
            error = photo == nil ? L("wa_rec_photo_required") : nil
        }
        guard error == nil else { return }
        busy = true
        Task { @MainActor in
            defer { busy = false }
            let result: ApiResult<PinStatusDto>
            switch method {
            case .password:
                result = await WalletApi.setRecovery(RecoverySetupRequest(method: "password", password: password, passwordConfirmation: passwordAgain), pin: existingPin)
            case .birthDate:
                result = await WalletApi.setRecovery(RecoverySetupRequest(method: "birth_date", birthDate: birthDateOrNull(day, month, year)), pin: existingPin)
            case .email:
                result = await WalletApi.setRecovery(RecoverySetupRequest(method: "email", email: email.trimmingCharacters(in: .whitespaces)), pin: existingPin)
            default:
                result = await WalletApi.setRecoveryDocument(method: method.wire, document: photo?.bytes ?? Data(), pin: existingPin)
            }
            guard result.value != nil else {
                error = waFailText(result)
                return
            }
            if method == .email { countdown = 60 }
            step = method == .email ? "code" : (changing ? "saved" : "pin")
        }
    }

    private func confirmCode() {
        guard code.count == 4 else {
            error = L("wa_rec_code_invalid")
            return
        }
        busy = true
        Task { @MainActor in
            defer { busy = false }
            let result = await WalletApi.confirmRecoveryEmail(CodeBody(code: code))
            if result.value != nil {
                error = nil
                step = changing ? "saved" : "pin"
            } else {
                error = waFailText(result)
            }
        }
    }

    private func resendCode() {
        Task { @MainActor in
            let network = L("wa_error_network")
            if let failed = await WalletApi.done({ await WalletApi.sendRecoveryEmailCode(pending: changing ? true : nil) }, offline: network, generic: network) {
                error = failed
            } else {
                error = nil
                notice = L("wa_rec_code_sent")
                countdown = 60
            }
        }
    }
}

// MARK: - Forgot the PIN

/// "I forgot my PIN" — by the method chosen at setup. Password / birth date / e-mail code: prove it, then a
/// new PIN (twice). Photo methods: upload the photo again and wait for a person to approve it (the PIN then
/// becomes 0000). Draws its own [WaPage].
@MainActor
struct WaForgotPinPage: View {
    @Environment(\.wa) private var wa
    let status: PinStatusDto?
    let onExit: () -> Void
    let onDone: () -> Void

    @State private var request: RecoveryRequestDto?
    @State private var step = "secret"
    @State private var password = ""
    @State private var day = ""
    @State private var month = ""
    @State private var year = ""
    @State private var code = ""
    @State private var photo: WaPickedPhoto?
    @State private var first = ""
    @State private var error: String?
    @State private var busy = false
    @State private var sent = false
    @State private var started = false
    /// The e-mail code: sent ✓, or why not (a cooldown, the mail server) — never silently.
    @State private var codeNotice: String?

    private var info: RecoveryInfoDto? { status?.recovery }
    private var method: RecoveryMethodUi? { RecoveryMethodUi.of(info?.method) }

    var body: some View {
        WaPage(title: L("wa_forgot_title"), onBack: back, scroll: false) {
            if let method, info != nil {
                if method.isPhoto && request?.status == "pending" {
                    WaStatusColumn {
                        WaIconWell(icon: "hourglass", tone: .amber, size: 84, iconSize: 38)
                        WaStatusTitle(text: L("wa_forgot_pending_title"))
                        WaStatusText(text: L("wa_forgot_pending_text"))
                        WaButton(text: L("wa_done"), action: onExit).padding(.horizontal, 20).padding(.top, 12)
                    }
                } else if method.isPhoto {
                    photoStep(method)
                } else if step == "secret" {
                    secretStep(method)
                } else if step == "done" {
                    WaStatusColumn {
                        WaSeal()
                        WaStatusTitle(text: L("wa_forgot_done_title"))
                        WaStatusText(text: L("wa_forgot_done_text"))
                        WaButton(text: L("wa_done"), action: onDone).padding(.horizontal, 20).padding(.top, 12)
                    }
                } else {
                    pinStep(method)
                }
            } else {
                WaEmpty(icon: "exclamationmark.triangle.fill", title: L("wa_forgot_title"), text: L("wa_forgot_no_method"))
                    .frame(maxHeight: .infinity)
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            request = status?.request
            // E-mail: a fresh code goes out as soon as this screen opens.
            if method == .email && !sent {
                sent = true
                Task { await sendCode() }
            }
        }
    }

    private func photoStep(_ method: RecoveryMethodUi) -> some View {
        WaFormStep {
            WaError(message: error)
            WaButton(text: L("wa_forgot_send"), loading: busy, action: sendPhoto)
        } content: {
            WaStepHeader(icon: method.icon, tone: method.tone, title: L("wa_forgot_photo_title", photoName(method)), sub: L("wa_forgot_photo_sub"))
            if request?.status == "rejected" {
                let reason = request?.rejectionReason.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
                WaNote(text: [L("wa_forgot_rejected_title"), reason, L("wa_forgot_rejected_text")].compactMap { $0 }.joined(separator: " — "), icon: "exclamationmark.triangle.fill")
                    .padding(.bottom, 12)
            } else if request?.status == "approved" {
                WaNote(text: L("wa_forgot_approved_text")).padding(.bottom, 12)
            }
            WaPhotoPicker(photo: photo, onPicked: { photo = $0; error = nil }, onUnreadable: { error = L("wa_rec_photo_unreadable") })
        }
    }

    private func secretStep(_ method: RecoveryMethodUi) -> some View {
        WaFormStep {
            WaError(message: error)
            WaButton(text: L("wa_continue"), action: continueFromSecret)
        } content: {
            let sub: String = {
                switch method {
                case .password: return L("wa_forgot_password_sub")
                case .birthDate: return L("wa_forgot_birth_sub")
                default: return L("wa_forgot_email_sub", info?.email ?? "")
                }
            }()
            WaStepHeader(icon: method.icon, tone: method.tone, title: method.title, sub: sub)
            switch method {
            case .password:
                WaTextField(value: bind($password), label: L("wa_rec_password"), icon: "lock.fill", password: true, error: error != nil)
            case .birthDate:
                WaBirthPicker(day: bind($day), month: bind($month), year: bind($year), error: error != nil)
            default:
                WaOtpBoxes(value: bind($code), error: error != nil)
                if let codeNotice { WaNote(text: codeNotice).padding(.top, 10) }
                WaButton(text: L("wa_rec_code_resend"), style: .quiet) { Task { await sendCode() } }.padding(.top, 8)
            }
        }
    }

    private func pinStep(_ method: RecoveryMethodUi) -> some View {
        let confirming = step == "confirm"
        return WaPadArea {
            WaPinPad(
                title: L(confirming ? "wa_pin_confirm_title" : "wa_forgot_new_pin_title"),
                sub: L(confirming ? "wa_pin_confirm_sub" : "wa_pin_digits_sub"),
                icon: "shield.fill"
            ) { pin in
                if !confirming {
                    first = pin
                    step = "confirm"
                    return .reset
                }
                if pin != first {
                    step = "pin"
                    return .error(L("wa_pin_mismatch"))
                }
                let body: RecoverPinRequest
                switch method {
                case .password: body = RecoverPinRequest(password: password, pin: first, pinConfirmation: pin)
                case .birthDate: body = RecoverPinRequest(birthDate: birthDateOrNull(day, month, year), pin: first, pinConfirmation: pin)
                default: body = RecoverPinRequest(code: code, pin: first, pinConfirmation: pin)
                }
                let result = await WalletApi.recoverPin(body)
                if result.value == nil {
                    // A wrong password / date / code goes back to the form with the reason; the PIN was never the problem.
                    step = "secret"
                    error = waFailText(result)
                    return .reset
                }
                step = "done"
                return .ok
            }
        }
    }

    private func bind(_ binding: Binding<String>) -> Binding<String> {
        Binding(get: { binding.wrappedValue }, set: { binding.wrappedValue = $0; error = nil })
    }

    private func back() {
        error = nil
        switch step {
        case "secret", "done": onExit()
        case "pin": step = "secret"
        case "confirm": step = "pin"
        default: break
        }
    }

    private func sendCode() async {
        let network = L("wa_error_network")
        if let failed = await WalletApi.done({ await WalletApi.sendRecoveryEmailCode() }, offline: network, generic: network) {
            codeNotice = nil
            error = failed
        } else {
            codeNotice = L("wa_rec_code_sent")
            error = nil
        }
    }

    private func continueFromSecret() {
        switch method {
        case .password: error = password.isEmpty ? L("wa_rec_password_short") : nil
        case .birthDate: error = birthDateOrNull(day, month, year) == nil ? L("wa_rec_birth_invalid") : nil
        case .email: error = code.count != 4 ? L("wa_rec_code_invalid") : nil
        default: error = nil
        }
        if error == nil { step = "pin" }
    }

    private func sendPhoto() {
        guard let picked = photo else {
            error = L("wa_rec_photo_required")
            return
        }
        busy = true
        Task { @MainActor in
            defer { busy = false }
            let result = await WalletApi.recoverPinWithDocument(picked.bytes)
            if let data = result.value {
                request = data.request ?? request
                error = nil
            } else {
                error = waFailText(result)
            }
        }
    }
}

// MARK: - Change the recovery method

/// Switch how a forgotten PIN is recovered. Proves the current PIN first (the server asks for it too), then
/// the same method wizard as at setup — which ends after the method, since the PIN itself stays.
@MainActor
struct WaChangeRecoveryPage: View {
    let onExit: () -> Void
    let onDone: () -> Void
    @State private var pin: String?

    var body: some View {
        if let proven = pin {
            WaPinSetupPage(onExit: onExit, onDone: onDone, title: L("wa_rec_change_title"), existingPin: proven)
        } else {
            WaPage(title: L("wa_rec_change_title"), onBack: onExit, scroll: false) {
                WaPadArea {
                    WaPinPad(title: L("wa_pin_enter_title"), sub: L("wa_rec_change_pin_sub"), icon: "shield.fill") { entered in
                        let result = await WalletApi.verifyPin(entered)
                        if result.value == nil { return .error(waFailText(result)) }
                        pin = entered
                        return .ok
                    }
                }
            }
        }
    }
}

// MARK: - A reset PIN must be replaced

/// Shown right after the wallet was unlocked with the PIN 0000 an approved recovery request set: the person
/// picks a real one (twice) before anything else. `current` is the PIN they just typed; `onDone` opens the wallet
/// (`onChanged` gets the new PIN first, for the money pages).
@MainActor
struct WaForcePinChangePage: View {
    let current: String
    let onExit: () -> Void
    let onDone: () -> Void
    var onChanged: (String) -> Void = { _ in }
    @State private var step = "new"
    @State private var first = ""

    var body: some View {
        let confirming = step == "confirm"
        WaPage(title: L("wa_force_page_title"), onBack: onExit, scroll: false) {
            WaPadArea {
                WaPinPad(
                    title: L(confirming ? "wa_pin_confirm_title" : "wa_force_title"),
                    sub: L(confirming ? "wa_pin_confirm_sub" : "wa_force_sub"),
                    icon: "shield.fill"
                ) { pin in
                    if !confirming {
                        first = pin
                        step = "confirm"
                        return .reset
                    }
                    if pin != first {
                        step = "new"
                        return .error(L("wa_pin_mismatch"))
                    }
                    let result = await WalletApi.changePin(ChangePinRequest(currentPin: current, pin: first, pinConfirmation: pin))
                    if result.value == nil {
                        // e.g. "choose a PIN other than 0000": ask again from the first step
                        step = "new"
                        return .error(waFailText(result))
                    }
                    onChanged(pin)
                    onDone()
                    return .ok
                }
            }
        }
    }
}

// MARK: - A device seen for the first time

/// Shown after the right PIN comes from a device (`X-Device-Id`) this wallet has never opened from before
/// (wallet policy bend 3, docs/wallet-tasks.md §10.10). A code goes to the phone on file automatically as
/// this page opens; entering it proves the device, and `wallet/device/confirm` remembers it. (The phone that
/// created the PIN is already trusted.)
@MainActor
struct WaDeviceTrustPage: View {
    @Environment(\.wa) private var wa
    let pin: String
    let onExit: () -> Void
    let onDone: () -> Void
    @State private var notice: String?
    @State private var error: String?
    @State private var sentOnOpen = false

    var body: some View {
        WaPage(title: L("wa_device_trust_title"), onBack: onExit, scroll: false) {
            WaPadArea {
                WaPinPad(title: L("wa_device_trust_title"), sub: L("wa_device_trust_sub"), icon: "shield.fill") { code in
                    notice = nil
                    let network = L("wa_error_network")
                    // The confirm answers without useful data, so the `done` reading.
                    if let failed = await WalletApi.done({ await WalletApi.confirmDeviceTrust(pin: pin, CodeBody(code: code)) }, offline: network, generic: network) {
                        return .error(failed)
                    }
                    onDone()
                    return .ok
                }
            }
            WaButton(text: L("wa_rec_code_resend"), style: .quiet) {
                Task { @MainActor in
                    let network = L("wa_error_network")
                    if let failed = await WalletApi.done({ await WalletApi.sendDeviceTrustCode(pin: pin) }, offline: network, generic: network) {
                        error = failed
                    } else {
                        notice = L("wa_rec_code_sent")
                        error = nil
                    }
                }
            }
            WaError(message: error)
            if let notice, error == nil {
                Text(notice).font(.dorr(12.5)).foregroundStyle(wa.green).frame(maxWidth: .infinity).padding(.bottom, 8)
            }
        }
        .task {
            guard !sentOnOpen else { return }
            sentOnOpen = true
            _ = await WalletApi.sendDeviceTrustCode(pin: pin)
        }
    }
}

// MARK: - A permanent freeze

/// Shown instead of the PIN pad when the wallet is permanently frozen — a wrong attempt right after a
/// temporary lock (point 5, docs/wallet-tasks.md). The only way out: a selfie + an ID/passport photo,
/// reviewed by a person — whatever the owner's configured recovery method actually is.
@MainActor
struct WaFrozenPage: View {
    @Environment(\.wa) private var wa
    let status: PinStatusDto?
    let onExit: () -> Void
    let onLifted: () -> Void

    @State private var request: RecoveryRequestDto?
    @State private var idPhoto: WaPickedPhoto?
    @State private var selfie: WaPickedPhoto?
    @State private var error: String?
    @State private var busy = false
    @State private var started = false

    var body: some View {
        WaPage(title: L("wa_frozen_title"), onBack: onExit, scroll: false) {
            if request?.status == "pending" {
                WaStatusColumn {
                    WaIconWell(icon: "hourglass", tone: .amber, size: 84, iconSize: 38)
                    WaStatusTitle(text: L("wa_frozen_pending_title"))
                    WaStatusText(text: L("wa_frozen_pending_text"))
                    WaButton(text: L("wa_retry"), style: .ghost, icon: "arrow.clockwise", action: onLifted).padding(.horizontal, 40).padding(.top, 12)
                    WaButton(text: L("wa_done"), action: onExit).padding(.horizontal, 20).padding(.vertical, 8)
                }
            } else {
                WaFormStep {
                    WaError(message: error)
                    WaButton(text: L("wa_forgot_send"), loading: busy, action: submit)
                } content: {
                    WaStepHeader(icon: "exclamationmark.triangle.fill", title: L("wa_frozen_title"), sub: L("wa_frozen_sub"))
                    if request?.status == "rejected" {
                        let reason = request?.rejectionReason.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
                        WaNote(text: [L("wa_forgot_rejected_title"), reason].compactMap { $0 }.joined(separator: " — "), icon: "exclamationmark.triangle.fill")
                            .padding(.bottom, 12)
                    }
                    Text(L("wa_frozen_id_label")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 8)
                    WaPhotoPicker(photo: idPhoto, onPicked: { idPhoto = $0; error = nil }, onUnreadable: { error = L("wa_rec_photo_unreadable") })
                    Text(L("wa_frozen_selfie_label")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 18).padding(.bottom, 8)
                    WaPhotoPicker(photo: selfie, onPicked: { selfie = $0; error = nil }, onUnreadable: { error = L("wa_rec_photo_unreadable") }, useCamera: true)
                }
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            request = status?.request
        }
    }

    private func submit() {
        guard let id = idPhoto, let me = selfie else {
            error = L("wa_rec_photo_required")
            return
        }
        busy = true
        Task { @MainActor in
            defer { busy = false }
            let result = await WalletApi.unfreezePin(idDocument: id.bytes, selfie: me.bytes)
            if let data = result.value {
                request = data.request
                error = nil
            } else {
                error = waFailText(result)
            }
        }
    }
}
