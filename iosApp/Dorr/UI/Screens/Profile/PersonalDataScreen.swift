import PhotosUI
import SwiftUI
import UIKit

// androidApp `profile/PersonalDataScreen.kt`.

private enum PdSub { case none, name, phone, email }

// Matches the server's auth_flow.otp_length (phone and e-mail change codes alike).
private let pdOtpLength = 4
private let pdOtpMarker = "\u{200B}"
private let fieldFill = Color(hex: 0xFBF7F8)
private let fieldBorder = Color(hex: 0xF3D5DB)
private let verifiedGreen = Color(hex: 0x16A34A)
private let chevronDark = Color(hex: 0x8B93A0)

// MARK: - Local per-account storage (gender fallback, the picked photo)

private func profileKey(_ userId: Int, _ name: String) -> String { "dorr_profile_\(userId).\(name)" }

@MainActor private func loadGender() -> String {
    guard let id = AuthSession.shared.user?.id else { return "" }
    return UserDefaults.standard.string(forKey: profileKey(id, "gender")) ?? ""
}

@MainActor private func saveGender(_ gender: String) {
    guard let id = AuthSession.shared.user?.id else { return }
    UserDefaults.standard.set(gender, forKey: profileKey(id, "gender"))
}

/// The cached photo file is namespaced per account, so a second account never sees the first one's picture.
private func photoFile(_ userId: Int) -> URL {
    FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("profile_photo_\(userId).jpg")
}

private func loadPhoto(_ userId: Int) -> URL? {
    let file = photoFile(userId)
    guard FileManager.default.fileExists(atPath: file.path),
          UserDefaults.standard.string(forKey: profileKey(userId, "photo")) == file.path else { return nil }
    return file
}

private func saveProfilePhoto(_ data: Data, _ userId: Int) -> URL? {
    let dest = photoFile(userId)
    try? FileManager.default.createDirectory(at: dest.deletingLastPathComponent(), withIntermediateDirectories: true)
    guard (try? data.write(to: dest)) != nil else { return nil }
    UserDefaults.standard.set(dest.path, forKey: profileKey(userId, "photo"))
    return dest
}

private func deleteProfilePhoto(_ userId: Int) {
    try? FileManager.default.removeItem(at: photoFile(userId))
    UserDefaults.standard.removeObject(forKey: profileKey(userId, "photo"))
}

private func formatDial(_ value: String) -> String {
    var digits = value.trimmingCharacters(in: .whitespaces)
    if digits.hasPrefix("+") { digits.removeFirst() }
    return digits.isEmpty ? "" : "+" + digits
}

// MARK: - Screen

@MainActor
struct PersonalDataScreen: View {
    let onBack: () -> Void
    @State private var sub = PdSub.none
    @State private var forward = true

    var body: some View {
        ZStack {
            page
                .id(sub)
                .transition(.asymmetric(
                    insertion: .modifier(active: PdSlide(fraction: forward ? 1 : -1), identity: PdSlide(fraction: 0)),
                    removal: .modifier(active: PdSlide(fraction: forward ? -1 : 1), identity: PdSlide(fraction: 0))
                ))
        }
    }

    @ViewBuilder private var page: some View {
        switch sub {
        case .name: EditNameScreen(onBack: { go(.none) }, onSaved: { AppToast.show($0) })
        case .phone: EditPhoneScreen(onBack: { go(.none) }, onSaved: { AppToast.show($0) })
        case .email: EditEmailScreen(onBack: { go(.none) }, onSaved: { AppToast.show($0) })
        case .none: PdHub(onBack: onBack, onOpen: { go($0) })
        }
    }

    private func go(_ next: PdSub) {
        forward = next != .none
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.34)) { sub = next }
    }
}

/// A fifth of the width plus a fade (mirrored in Arabic by SwiftUI).
private struct PdSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in
            content.offset(x: proxy.size.width / 5 * fraction).opacity(fraction == 0 ? 1 : 0)
        }
    }
}

// MARK: - Hub

@MainActor
private struct PdHub: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void
    let onOpen: (PdSub) -> Void

    @State private var uploadingAvatar = false
    @State private var deletingAvatar = false
    @State private var confirmDelete = false
    @State private var photoPath: URL?
    @State private var pickerItem: PhotosPickerItem?
    @State private var serverAvatarFailed = false
    @State private var shown = false

    var body: some View {
        let user = session.user
        let notAdded = L("pd_not_added")
        PdScreen(title: L("personal_data_title"), onBack: onBack) {
            ScrollView {
                VStack(spacing: 8) {
                    avatar(user)
                        .padding(.top, 6)
                        .padding(.bottom, 14)
                        .offset(y: shown ? 0 : -30)
                        .opacity(shown ? 1 : 0)
                        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.4).delay(0.06), value: shown)
                    let fields: [(String, String, String, Bool, Bool, PdSub, String?)] = [
                        ("person.fill", L("pd_name"), nonBlank(user?.name) ?? notAdded, false, false, .name, L("pd_name_more")),
                        ("phone.fill", L("pd_phone"), nonBlank(user?.phone) ?? notAdded, user?.phoneVerifiedAt != nil, true, .phone, nil),
                        ("envelope.fill", L("pd_email"), nonBlank(user?.email) ?? notAdded, user?.emailVerifiedAt != nil, true, .email, nil),
                    ]
                    ForEach(fields.indices, id: \.self) { index in
                        let field = fields[index]
                        PdFieldRow(icon: field.0, label: field.1, value: field.2, verified: field.3, ltr: field.4, note: field.6) { onOpen(field.5) }
                            .offset(y: shown ? 0 : 24)
                            .opacity(shown ? 1 : 0)
                            .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.38).delay(0.12 + Double(index) * 0.06), value: shown)
                    }
                    Spacer().frame(height: 12)
                }
                .padding(.horizontal, 14)
            }
        }
        .onAppear {
            photoPath = user.flatMap { loadPhoto($0.id) }
            shown = true
        }
        .onChange(of: session.sessionVersion) { _ in
            if photoPath == nil { photoPath = session.user.flatMap { loadPhoto($0.id) } }
        }
        .onChange(of: pickerItem) { item in
            guard let item else { return }
            pickerItem = nil
            Task { await picked(item) }
        }
        .dorrDialog(isPresented: $confirmDelete) {
            // Same confirmation card as deleting an address.
            DeleteAddressDialog(title: L("pd_delete_photo_ask"), name: L("pd_delete_photo_confirm"),
                                onDismiss: { confirmDelete = false }, onConfirm: { confirmDelete = false; deleteAvatar() })
        }
    }

    private func avatar(_ user: UserDto?) -> some View {
        // Server avatar first (with updated_at as a cache key — the server keeps the same file name), then the
        // locally kept photo, then the placeholder icon.
        let serverAvatar = ApiClient.mediaUrl(user?.avatar).flatMap { URL(string: $0.absoluteString + "?v=" + (user?.updatedAt ?? "0")) }
        let model = (serverAvatarFailed ? nil : serverAvatar) ?? photoPath
        return ZStack {
            PhotosPicker(selection: $pickerItem, matching: .images) {
                ZStack {
                    Circle().fill(wa.settingsCard)
                        .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.12), radius: 8, y: 3)
                    if let model {
                        AsyncImage(url: model) { phase in
                            switch phase {
                            case .success(let image): image.resizable().scaledToFill()
                            case .failure:
                                Color.clear.onAppear { if model == serverAvatar { serverAvatarFailed = true } }
                            default: Color.clear
                            }
                        }
                        .clipShape(Circle())
                    } else {
                        Image(systemName: "person.fill").font(.system(size: 38)).foregroundStyle(wa.red)
                    }
                    if uploadingAvatar || deletingAvatar {
                        Circle().fill(Color.black.opacity(0.35))
                        ProgressView().tint(.white)
                    }
                }
                .frame(width: 92, height: 92)
                .accessibilityLabel(L("pd_change_photo"))
            }
            .buttonStyle(.plain)
            Image(systemName: "pencil").font(.system(size: 12, weight: .bold)).foregroundStyle(.white)
                .frame(width: 28, height: 28)
                .background(wa.red, in: Circle())
                .shadow(color: Color(hex: 0x001B53).opacity(0.25), radius: 4, y: 2)
                .frame(width: 92, height: 92, alignment: .bottomTrailing)
                .allowsHitTesting(false)
            // Only when there is a photo to remove (server avatar or the local copy).
            if (user?.avatar != nil || photoPath != nil) && !uploadingAvatar && !deletingAvatar {
                Button { confirmDelete = true } label: {
                    Image(systemName: "trash.fill").font(.system(size: 13)).foregroundStyle(wa.red)
                        .frame(width: 28, height: 28)
                        .background(wa.settingsCard, in: Circle())
                        .shadow(color: .black.opacity(0.15), radius: 4, y: 2)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("pd_delete_photo"))
                .frame(width: 92, height: 92, alignment: .bottomLeading)
            }
        }
        .frame(maxWidth: .infinity)
    }

    private func picked(_ item: PhotosPickerItem) async {
        guard let userId = session.user?.id,
              let raw = try? await item.loadTransferable(type: Data.self),
              let jpeg = UIImage(data: raw)?.jpegData(compressionQuality: 0.9),
              let saved = saveProfilePhoto(jpeg, userId) else { return }
        photoPath = saved
        serverAvatarFailed = false
        uploadingAvatar = true
        do {
            let envelope = try await ProfileApi.updateAvatar(jpeg)
            if let user = envelope.data { session.user = user }
            AppToast.show(nonBlank(envelope.message) ?? L("toast_profile_updated"))
        } catch {
            AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
        }
        uploadingAvatar = false
    }

    private func deleteAvatar() {
        Task {
            deletingAvatar = true
            do {
                let envelope = try await ProfileApi.deleteAvatar()
                if let user = envelope.data { session.user = user }
                if let id = session.user?.id { deleteProfilePhoto(id) }
                photoPath = nil
                AppToast.show(nonBlank(envelope.message) ?? L("toast_profile_updated"))
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
            deletingAvatar = false
        }
    }
}

private func nonBlank(_ text: String?) -> String? {
    guard let text, !text.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
    return text
}

private struct PdFieldRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String
    let verified: Bool
    var ltr = false
    var note: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                    HStack(spacing: 6) {
                        // The phone and e-mail read left to right even in Arabic ("+966 5…", not "5… 966+").
                        Text(verbatim: ltr ? "\u{2066}\(value)\u{2069}" : value).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        if verified {
                            Image(systemName: "checkmark").font(.system(size: 7, weight: .heavy)).foregroundStyle(.white)
                                .frame(width: 16, height: 16)
                                .background(verifiedGreen, in: Circle())
                        }
                    }
                    if let note, !note.isEmpty {
                        Text(verbatim: note).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 2)
                    }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                    .foregroundStyle(wa.night ? chevronDark : Color(hex: 0xF8BCA9))
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .settingsSurface(14)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Edit name

@MainActor
private struct EditNameScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void
    let onSaved: (String) -> Void
    @State private var name = AuthSession.shared.user?.name ?? ""
    // Server value first, locally picked gender as fallback; the API requires one of male / female.
    @State private var gender: String = {
        let server = AuthSession.shared.user?.gender
        return server == "male" || server == "female" ? server! : loadGender()
    }()
    @State private var saving = false

    var body: some View {
        PdScreen(title: L("edit_name_title"), onBack: onBack) {
            FormColumn {
                PdFormCard {
                    IconField(value: $name, label: L("edit_name_label"), icon: "person.fill")
                    PdHint(text: L("edit_name_hint"))
                    Text(L("pd_gender")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 14)
                    GenderOption(label: L("gender_male"), icon: "figure.stand", selected: gender == "male") { gender = "male" }.padding(.top, 8)
                    GenderOption(label: L("gender_female"), icon: "figure.stand.dress", selected: gender == "female") { gender = "female" }.padding(.top, 8)
                }
                PdSaveButton(label: L("common_save"), enabled: !saving, action: save)
            }
        }
    }

    private func save() {
        let cleanName = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard cleanName.count >= 2 else { AppToast.show(L("toast_enter_name")); return }
        guard gender == "male" || gender == "female" else { AppToast.show(L("toast_pick_gender")); return }
        Task {
            saving = true
            do {
                let envelope = try await ProfileApi.updateIdentity(UpdateIdentityBody(name: cleanName, gender: gender))
                if let user = envelope.data { session.user = user }
                saveGender(gender)
                onSaved(nonBlank(envelope.message) ?? L("toast_profile_updated"))
                onBack()
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
            saving = false
        }
    }
}

private struct GenderOption: View {
    @Environment(\.wa) private var wa
    let label: String
    let icon: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(selected ? wa.red : wa.settingsMut)
                    .frame(width: 22)
                Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(selected ? wa.red : wa.settingsInk)
                Spacer(minLength: 0)
                ZStack {
                    Circle().stroke(selected ? wa.red : (wa.night ? chevronDark : Color(hex: 0xF8BCA9)), lineWidth: 2)
                    if selected { Circle().fill(wa.red).frame(width: 10, height: 10) }
                }
                .frame(width: 18, height: 18)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 14)
            .background(selected ? (wa.night ? wa.darkWell : wa.red.opacity(0.14)) : (wa.night ? wa.bg : fieldFill),
                        in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(selected ? wa.red : .clear, lineWidth: 1.5))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Edit phone

/// Changing the phone number goes through the guarded flow on the server: a code to the *new* number, behind
/// the wallet PIN once one exists. "form" → ("pin" only if the account has a PIN) → "otp" → done.
@MainActor
private struct EditPhoneScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void
    let onSaved: (String) -> Void

    @State private var dial = "+966"
    @State private var flag = "sa"
    @State private var phone = ""
    @State private var phoneLength = 9
    @State private var phoneStartsWith = "5"
    @State private var step = "form"
    @State private var serverError: String?
    @State private var busy = false
    /// The PIN that let the change start — resending the code has to pass it again.
    @State private var confirmedPin: String?

    private var isPhoneValid: Bool { phone.count == phoneLength && (phoneStartsWith.isEmpty || phone.hasPrefix(phoneStartsWith)) }

    private var phoneError: String? {
        if phone.isEmpty { return nil }
        if phone.count != phoneLength { return L("login_error_phone_invalid_length", phoneLength) }
        if !phoneStartsWith.isEmpty && !phone.hasPrefix(phoneStartsWith) { return L("login_error_phone_invalid_start", phoneStartsWith) }
        return nil
    }

    var body: some View {
        Group {
            switch step {
            case "pin":
                PdScreen(title: L("edit_phone_title"), onBack: { step = "form" }) {
                    WaPinPad(title: L("wa_pin_enter_title"), sub: L("edit_phone_title")) { pin in
                        do {
                            _ = try await MobileAuthApi.startPhoneChange(OtpRequest(dialCode: dial, phone: phone), pin: pin)
                            confirmedPin = pin
                            step = "otp"
                            return .ok
                        } catch {
                            let failure = error.apiFailure
                            if failure.errorCode == "wallet_pin_locked", let until = parseInstant(failure.lockedUntil) { return .locked(until) }
                            if let code = failure.errorCode, pinErrorCodes.contains(code) { return .error(failure.message ?? L("wa_error_generic")) }
                            step = "form"
                            serverError = failure.httpStatus == nil ? L("wa_error_network") : failure.message ?? L("wa_error_generic")
                            return .ok
                        }
                    }
                }
            case "otp":
                PdOtpStep(
                    title: L("edit_phone_title"),
                    target: "\(dial) \(phone)",
                    initialCooldown: 60,
                    onVerify: { code in
                        _ = try await MobileAuthApi.confirmPhoneChange(ConfirmCodeBody(code: code))
                        return try await MobileAuthApi.me()
                    },
                    onResend: {
                        _ = try await MobileAuthApi.startPhoneChange(OtpRequest(dialCode: dial, phone: phone), pin: confirmedPin)
                        return nil
                    },
                    onVerified: { user, _ in
                        session.user = user
                        onSaved(L("toast_phone_updated"))
                        onBack()
                    },
                    onBack: { step = "form" }
                )
            default:
                PdScreen(title: L("edit_phone_title"), onBack: onBack) {
                    FormColumn {
                        PdFormCard {
                            Text(L("edit_phone_label")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
                            ProfilePhoneField(flag: flag, dial: dial, phone: phone, phoneLength: phoneLength, phoneStartsWith: phoneStartsWith,
                                              isError: phoneError != nil || serverError != nil) {
                                phone = String($0.filter(\.isNumber).prefix(phoneLength))
                                serverError = nil
                            }
                            if let message = phoneError ?? serverError {
                                HStack(spacing: 6) {
                                    Image(systemName: "exclamationmark.circle").font(.system(size: 14)).foregroundStyle(AppColors.danger)
                                    Text(verbatim: message).font(.dorr(12, .medium)).foregroundStyle(AppColors.danger).multilineTextAlignment(.center)
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.horizontal, 12)
                                .padding(.vertical, 7)
                                .background(Color(hex: 0xFEE2E2).opacity(0.85), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                                .padding(.top, 8)
                                .transition(.opacity.combined(with: .move(edge: .top)))
                            }
                            PdHint(text: L("edit_phone_hint"))
                        }
                        .animation(.easeInOut(duration: 0.2), value: phoneError ?? serverError)
                        PdSaveButton(label: L("pd_send_code"), enabled: isPhoneValid && !busy) {
                            if isPhoneValid && !busy { start() }
                        }
                    }
                }
            }
        }
        .onAppear(perform: loadCountry)
    }

    /// The account's country (auth/me) first; else the one its number starts with, else the default.
    private func loadCountry() {
        if let uc = session.user?.country {
            dial = nonBlank(formatDial(uc.dialCode ?? "")) ?? dial
            let candidate = uc.flag?.code?.lowercased() ?? uc.code.map { String($0.lowercased().prefix(2)) }
            if let candidate, candidate.count == 2 { flag = candidate }
            phoneLength = uc.phoneLength ?? phoneLength
            phoneStartsWith = uc.phoneStartsWith ?? ""
        } else if let picked = CountryCache.resolveForPhone(session.user?.phone) ?? CountryCache.pickDefault() {
            dial = nonBlank(formatDial(picked.dialCode)) ?? dial
            let candidate = picked.flag?.code.lowercased()
            flag = (candidate?.count == 2 ? candidate : String(picked.code.lowercased().prefix(2))) ?? flag
            phoneLength = picked.phoneLength ?? phoneLength
            phoneStartsWith = picked.phoneStartsWith ?? ""
        }
    }

    // The server decides whether a PIN is needed (only once the wallet has one).
    private func start() {
        Task {
            busy = true
            serverError = nil
            do {
                _ = try await MobileAuthApi.startPhoneChange(OtpRequest(dialCode: dial, phone: phone))
                step = "otp"
            } catch {
                let failure = error.apiFailure
                if failure.errorCode == "wallet_pin_required" {
                    step = "pin"
                } else {
                    step = "form"
                    serverError = failure.httpStatus == nil ? L("wa_error_network") : failure.message ?? L("wa_error_generic")
                }
            }
            busy = false
        }
    }
}

// MARK: - Edit e-mail

@MainActor
private struct EditEmailScreen: View {
    @EnvironmentObject private var session: AuthSession
    let onBack: () -> Void
    let onSaved: (String) -> Void
    @State private var email = ""
    @State private var stepOtp = false
    @State private var requesting = false
    @State private var maskedEmail: String?
    @State private var cooldownSeconds = 60

    var body: some View {
        if stepOtp {
            PdOtpStep(
                title: L("edit_email_title"),
                target: maskedEmail ?? email.trimmingCharacters(in: .whitespaces),
                initialCooldown: cooldownSeconds,
                onVerify: { code in try await ProfileApi.confirmEmailChange(ConfirmCodeBody(code: code)) },
                onResend: { try await requestCode() },
                onVerified: { user, message in
                    session.user = user
                    onSaved(nonBlank(message) ?? L("toast_email_updated"))
                    onBack()
                },
                onBack: { stepOtp = false }
            )
        } else {
            PdScreen(title: L("edit_email_title"), onBack: onBack) {
                FormColumn {
                    PdFormCard {
                        IconField(value: $email, label: L("edit_email_label"), icon: "envelope.fill", placeholder: "name@example.com", ltr: true, keyboard: .emailAddress)
                        PdHint(text: L("edit_email_hint"))
                    }
                    PdSaveButton(label: L("pd_send_code"), enabled: !requesting, action: submit)
                }
            }
        }
    }

    private func requestCode() async throws -> ChannelOtpDto? {
        try await ProfileApi.requestEmailChange(EmailChangeRequest(email: email.trimmingCharacters(in: .whitespaces))).data
    }

    private func submit() {
        let value = email.trimmingCharacters(in: .whitespaces)
        guard value.contains("@") && value.contains(".") else { AppToast.show(L("toast_valid_email")); return }
        guard !requesting else { return }
        Task {
            requesting = true
            do {
                let dto = try await requestCode()
                maskedEmail = dto?.maskedEmail ?? value
                cooldownSeconds = dto?.resendCooldownSeconds ?? 60
                stepOtp = true
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
            requesting = false
        }
    }
}

// MARK: - OTP step

/// The shared OTP step for phone and e-mail changes: `onVerify(code)` confirms, `onResend()` asks for a fresh
/// code and reports the new cooldown.
@MainActor
private struct PdOtpStep: View {
    @Environment(\.wa) private var wa
    let title: String
    let target: String
    let initialCooldown: Int
    let onVerify: (String) async throws -> ApiEnvelope<UserDto>
    let onResend: () async throws -> ChannelOtpDto?
    let onVerified: (UserDto, String) -> Void
    let onBack: () -> Void

    @State private var digits = Array(repeating: "", count: pdOtpLength)
    @State private var countdown = 0
    @State private var hasError = false
    @State private var busy = false
    @FocusState private var focus: Int?

    var body: some View {
        let hintColor = wa.night ? wa.settingsMut : AppColors.textSecondary
        PdScreen(title: title, onBack: onBack) {
            FormColumn {
                PdFormCard {
                    Text(verbatim: L("profile_otp_hint", target)).font(.dorr(12)).lineSpacing(5).foregroundStyle(hintColor)
                        .multilineTextAlignment(.center).frame(maxWidth: .infinity)
                    HStack(spacing: 8) {
                        ForEach(0..<pdOtpLength, id: \.self) { index in
                            OtpDigit(value: digits[index], hasError: hasError, focused: focus == index, focus: $focus, index: index,
                                     onValue: { d in setDigit(index, d) },
                                     onBackspaceOnEmpty: {
                                         if index > 0 {
                                             focus = index - 1
                                             digits[index - 1] = ""
                                         }
                                     })
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .environment(\.layoutDirection, .leftToRight)
                    .padding(.top, 14)
                    Group {
                        if countdown > 0 || busy {
                            Text(verbatim: L("profile_otp_timer", max(countdown, 0))).font(.dorr(13)).foregroundStyle(hintColor)
                        } else {
                            Button(action: resend) {
                                Text(L("profile_otp_resend")).font(.dorr(13, .bold)).foregroundStyle(wa.red).frame(maxWidth: .infinity)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 14)
                }
                let code = digits.joined()
                PdSaveButton(label: L("profile_otp_confirm"), enabled: code.count == pdOtpLength && !busy) { verify(code) }
            }
        }
        .onAppear {
            countdown = max(initialCooldown, 0)
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.35) { focus = 0 }
        }
        // The countdown runs on the device clock — one tick a second while it is above zero.
        .task(id: countdown) {
            guard countdown > 0 else { return }
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            guard !Task.isCancelled else { return }
            countdown -= 1
        }
    }

    private func setDigit(_ index: Int, _ d: String) {
        digits[index] = d
        hasError = false
        guard !d.isEmpty else { return }
        if index < pdOtpLength - 1 { focus = index + 1 }
        let code = digits.joined()
        if code.count == pdOtpLength { verify(code) }
    }

    private func verify(_ code: String) {
        guard code.count == pdOtpLength, !busy else { return }
        Task {
            busy = true
            do {
                let envelope = try await onVerify(code)
                if let user = envelope.data {
                    onVerified(user, envelope.message ?? "")
                } else {
                    hasError = true
                    AppToast.show(nonBlank(envelope.message) ?? L("toast_wrong_code"))
                }
            } catch {
                hasError = true
                digits = Array(repeating: "", count: pdOtpLength)
                focus = 0
                AppToast.show(error.apiFailure.message ?? L("toast_wrong_code"))
            }
            busy = false
        }
    }

    private func resend() {
        guard !busy else { return }
        Task {
            busy = true
            do {
                let dto = try await onResend()
                digits = Array(repeating: "", count: pdOtpLength)
                hasError = false
                countdown = dto?.resendCooldownSeconds ?? 60
                AppToast.show(L("toast_code_sent"))
                focus = 0
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
            busy = false
        }
    }
}

private struct OtpDigit: View {
    @Environment(\.wa) private var wa
    let value: String
    let hasError: Bool
    let focused: Bool
    var focus: FocusState<Int?>.Binding
    let index: Int
    let onValue: (String) -> Void
    let onBackspaceOnEmpty: () -> Void

    var body: some View {
        let border = hasError ? AppColors.danger : (focused ? wa.red : Color(hex: 0xFBD2C4))
        ZStack {
            RoundedRectangle(cornerRadius: 14, style: .continuous).fill(wa.settingsCard)
            RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(border, lineWidth: 1.5)
            Text(verbatim: value).font(.dorr(20, .semibold)).foregroundStyle(wa.settingsInk)
            // A soft keyboard sends nothing for Backspace on an empty field, so the box always holds an
            // invisible marker: deleting it is how an "empty" box learns Backspace was pressed.
            TextField("", text: Binding(
                get: { pdOtpMarker + value },
                set: { typed in
                    guard typed.hasPrefix(pdOtpMarker) else {
                        if value.isEmpty { onBackspaceOnEmpty() } else { onValue("") }
                        return
                    }
                    onValue(String(asciiDigits(typed).suffix(1)))
                }
            ))
            .keyboardType(.numberPad)
            .textContentType(.oneTimeCode)
            .focused(focus, equals: index)
            .foregroundStyle(.clear)
            .tint(wa.red)
            .multilineTextAlignment(.center)
        }
        .frame(width: 40, height: 48)
    }
}

// MARK: - Form pieces

private struct ProfilePhoneField: View {
    @Environment(\.wa) private var wa
    let flag: String
    let dial: String
    let phone: String
    let phoneLength: Int
    var phoneStartsWith = ""
    var isError = false
    let onPhone: (String) -> Void
    @FocusState private var focused: Bool

    var body: some View {
        let border = isError ? AppColors.danger : (focused ? wa.red : fieldBorder)
        let placeholder = phoneStartsWith.isEmpty
            ? String(repeating: "0", count: max(phoneLength, 1))
            : phoneStartsWith + String(repeating: "*", count: max(phoneLength - phoneStartsWith.count, 0))
        HStack(spacing: 0) {
            Image(systemName: "phone.fill").font(.system(size: 15)).foregroundStyle(Color(hex: 0x9CA3AF))
            PhoneSep()
            HStack(spacing: 6) {
                FlagImage(code: flag)
                Text(verbatim: dial).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            }
            PhoneSep()
            ZStack(alignment: .leading) {
                if phone.isEmpty {
                    Text(verbatim: placeholder).font(.dorr(14, .medium)).kerning(0.5).foregroundStyle(Color(hex: 0xC5CAD3))
                }
                TextField("", text: Binding(get: { phone }, set: onPhone))
                    .font(.dorr(14, .medium)).kerning(0.5)
                    .foregroundStyle(wa.settingsInk)
                    .keyboardType(.phonePad)
                    .tint(wa.red)
                    .focused($focused)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 44)
        .background(wa.night ? wa.bg : (focused ? .white : fieldFill), in: Capsule())
        .overlay(Capsule().stroke(border, lineWidth: 1))
        .environment(\.layoutDirection, .leftToRight)
    }
}

private struct PhoneSep: View {
    @Environment(\.wa) private var wa
    var body: some View {
        (wa.night ? wa.line : Color(hex: 0xE5E7EB)).frame(width: 1, height: 18).padding(.horizontal, 8)
    }
}

private struct FormColumn<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) { content() }
                .padding(.horizontal, 14)
                .padding(.top, 6)
                .padding(.bottom, 16)
        }
        .scrollDismissesKeyboard(.interactively)
    }
}

private struct PdFormCard<Content: View>: View {
    @ViewBuilder let content: () -> Content
    @State private var shown = false
    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .settingsSurface(18, elevation: 8)
            .offset(y: shown ? 0 : 30)
            .opacity(shown ? 1 : 0)
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.4).delay(0.08)) { shown = true } }
    }
}

private struct IconField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    let label: String
    let icon: String
    var placeholder = ""
    var ltr = false
    var keyboard: UIKeyboardType = .default
    @Environment(\.layoutDirection) private var direction
    @FocusState private var focused: Bool

    var body: some View {
        Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
        HStack(spacing: 8) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
            ZStack(alignment: .leading) {
                if value.isEmpty && !placeholder.isEmpty {
                    Text(verbatim: placeholder).font(.dorr(15, .medium)).foregroundStyle(Color(hex: 0xC5CAD3))
                }
                TextField("", text: $value)
                    .font(.dorr(15))
                    .foregroundStyle(wa.settingsInk)
                    .keyboardType(keyboard)
                    .textInputAutocapitalization(keyboard == .emailAddress ? .never : .sentences)
                    .autocorrectionDisabled(keyboard == .emailAddress)
                    .tint(wa.red)
                    .focused($focused)
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 44)
        .background(wa.night ? wa.bg : (focused ? .white : fieldFill), in: Capsule())
        .overlay(Capsule().stroke(focused ? wa.red : (wa.night ? wa.line : fieldBorder), lineWidth: 1))
        .environment(\.layoutDirection, ltr ? .leftToRight : direction)
    }
}

private struct PdHint: View {
    @Environment(\.wa) private var wa
    let text: String
    var body: some View {
        Text(verbatim: text).font(.dorr(12)).lineSpacing(5)
            .foregroundStyle(wa.night ? wa.settingsMut : AppColors.textSecondary)
            .padding(.top, 10).padding(.horizontal, 2)
    }
}

private struct PdSaveButton: View {
    @Environment(\.wa) private var wa
    let label: String
    var enabled = true
    let action: () -> Void
    @State private var shown = false

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(16, .bold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity, minHeight: 48)
                .background(wa.red, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
                .opacity(enabled ? 1 : 0.5)
        }
        .buttonStyle(.plain)
        .allowsHitTesting(enabled)
        .padding(.top, 16)
        .offset(y: shown ? 0 : 24)
        .opacity(shown ? 1 : 0)
        .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.42).delay(0.16)) { shown = true } }
    }
}

private struct PdScreen<Content: View>: View {
    let title: String
    let onBack: () -> Void
    @ViewBuilder let content: () -> Content

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: title, onBack: onBack)
                content().frame(maxHeight: .infinity, alignment: .top)
            }
        }
    }
}
