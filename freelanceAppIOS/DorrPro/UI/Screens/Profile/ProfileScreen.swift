import PhotosUI
import SwiftUI

// freelanceApp `ui/screens/ProfileScreen.kt`.

/**
 * Completing the profile (FL-103): photo (gallery or camera), name, headline, about, years, languages, country, city,
 * residency, gender, date of birth, and the email with its code. Everything but the name is optional here; the home shows
 * what is still missing. Nothing is asked on a timer: the lists load once when the page opens and the save is one request.
 * Nothing is required to leave the page; only saving needs a name of 2+ characters.
 */
struct ProfileScreen: View {
    let onBack: () -> Void
    let onSaved: () -> Void

    @Environment(\.wa) private var wa
    @ObservedObject private var session = Session.shared

    @State private var name: String
    @State private var headline: String
    @State private var bio: String
    @State private var years: String
    @State private var languages: [String]
    @State private var countryId: Int?
    @State private var city: String
    @State private var residency: String?
    @State private var gender: String?
    @State private var dob: String

    @State private var allCountries: [CountryDto] = []
    @State private var allLanguages: [LanguageDto] = [LanguageDto(code: "ar", name: "العربية"), LanguageDto(code: "en", name: "English")]

    @State private var saving = false
    @State private var error: String?
    @State private var photoBusy = false
    @State private var photoError: String?
    @State private var galleryItem: PhotosPickerItem?
    @State private var cameraOpen = false

    @State private var email: String
    @State private var codeSent = false
    @State private var code = ""
    @State private var emailBusy = false
    @State private var emailMessage: String?
    @State private var emailError: String?

    @MainActor
    init(onBack: @escaping () -> Void, onSaved: @escaping () -> Void) {
        self.onBack = onBack
        self.onSaved = onSaved
        let initial = Session.shared.me
        _name = State(initialValue: initial?.name ?? "")
        _headline = State(initialValue: initial?.headline ?? "")
        _bio = State(initialValue: initial?.bio ?? "")
        _years = State(initialValue: initial?.yearsOfExperience.map(String.init) ?? "")
        _languages = State(initialValue: initial?.contactLanguages ?? [])
        _countryId = State(initialValue: initial?.countryId)
        _city = State(initialValue: initial?.city ?? "")
        _residency = State(initialValue: initial?.residency)
        _gender = State(initialValue: initial?.gender)
        _dob = State(initialValue: initial?.dateOfBirth ?? "")
        _email = State(initialValue: initial?.email ?? "")
    }

    private var me: FreelancerDto? { session.me }

    private var emailVerified: Bool {
        me?.emailVerified == true && (me?.email ?? "").lowercased() == email.trimmingCharacters(in: .whitespaces).lowercased()
    }

    var body: some View {
        Page(title: L("profile_title"), onBack: onBack) {
            FormIntro(icon: "person.fill", title: L("profile_intro_title"), text: L("profile_intro"))

            // Photo
            Card {
                VStack(spacing: 10) {
                    AvatarImage(url: me?.avatarUrl, size: 96)
                    Text(L("profile_photo")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                    HStack(spacing: 10) {
                        PhotosPicker(selection: $galleryItem, matching: .images) {
                            PhotoButtonLabel(icon: "photo.on.rectangle", text: L("profile_photo_gallery"), enabled: !photoBusy)
                        }
                        .buttonStyle(.plain)
                        .disabled(photoBusy)
                        Button { cameraOpen = true } label: {
                            PhotoButtonLabel(icon: "camera.fill", text: L("profile_photo_camera"), enabled: !photoBusy)
                        }
                        .buttonStyle(.plain)
                        .disabled(photoBusy)
                    }
                }
                .frame(maxWidth: .infinity)
            }

            // Basics
            FormGroup {
                DorrTextField(value: $name, label: L("profile_name"), icon: "person.fill")
                DorrTextField(value: $headline, label: L("profile_headline"), placeholder: L("profile_headline_hint"), icon: "person.text.rectangle")
                DorrTextField(value: $bio.profileTake(1500), label: L("profile_bio"), icon: "text.alignleft", singleLine: false, minLines: 4)
                DorrTextField(value: $years.profileDigits(2), label: L("profile_years"), icon: "clock", keyboard: .numberPad)
            }

            // Languages and place
            FormGroup {
                FieldLabel(text: L("profile_languages"))
                ChipRow(
                    options: allLanguages.map { (value: $0.code ?? "", label: ($0.name ?? "").trimmingCharacters(in: .whitespaces).isEmpty ? ($0.code ?? "") : ($0.name ?? "")) },
                    selected: Set(languages),
                    onToggle: { code in
                        if languages.contains(code) { languages.removeAll { $0 == code } } else if languages.count < 10 { languages.append(code) }
                    }
                )
                countryField
                DorrTextField(value: $city, label: L("profile_city"), icon: "building.2")
                FieldLabel(text: L("profile_residency"))
                ChipRow(
                    options: [(value: "citizen", label: L("residency_citizen")), (value: "resident", label: L("residency_resident"))],
                    selected: Set([residency].compactMap { $0 }),
                    onToggle: { residency = residency == $0 ? nil : $0 }
                )
                FieldLabel(text: L("profile_gender"))
                ChipRow(
                    options: [(value: "male", label: L("gender_male")), (value: "female", label: L("gender_female"))],
                    selected: Set([gender].compactMap { $0 }),
                    onToggle: { gender = gender == $0 ? nil : $0 }
                )
                DateField(value: $dob, label: L("profile_dob"))
            }

            SettingsPrimaryButton(text: L("profile_save"), enabled: name.trimmingCharacters(in: .whitespaces).count >= 2, loading: saving, action: save)

            // Email (its own code, apart from the save)
            Card {
                DorrTextField(value: $email.profileMap { codeSent = false; return $0 }, label: L("profile_email"), icon: "envelope.fill", keyboard: .emailAddress)
                if emailVerified {
                    HStack(spacing: 6) {
                        Image(systemName: "checkmark.circle.fill").font(.system(size: 16)).foregroundStyle(wa.red)
                        Text(L("profile_email_verified")).font(.dorr(13, .bold)).foregroundStyle(wa.red)
                    }
                } else if codeSent {
                    DorrTextField(value: $code.profileDigits(8), label: L("profile_email_code"), icon: "lock.fill", keyboard: .numberPad)
                    SettingsPrimaryButton(text: L("profile_email_confirm"), enabled: code.count >= 4, loading: emailBusy, action: confirmEmail)
                } else {
                    SettingsPrimaryButton(text: L("profile_email_send"), enabled: email.contains("@"), loading: emailBusy, action: sendCode)
                }
            }
        }
        .messageToast(photoError)
        .messageToast(error)
        .messageToast(emailMessage)
        .messageToast(emailError)
        // One read each when the page opens.
        .task {
            if let countries = await GeneralApi.countries().value { allCountries = countries }
            if let langs = await GeneralApi.languages().value, !langs.isEmpty { allLanguages = langs }
        }
        .onChange(of: galleryItem) { item in
            guard let item else { return }
            galleryItem = nil
            Task {
                guard let data = try? await item.loadTransferable(type: Data.self) else { return }
                upload(data)
            }
        }
        .fullScreenCover(isPresented: $cameraOpen) {
            ProfileCameraPicker { data in
                cameraOpen = false
                if let data { upload(data) }
            }
            .ignoresSafeArea()
        }
    }

    /// The country as a field that opens the list (Android's DropdownMenu under the field).
    private var countryField: some View {
        let shown = allCountries.first { $0.id == countryId }?.name ?? ""
        return Menu {
            ForEach(allCountries) { country in
                Button {
                    countryId = country.id
                } label: {
                    if country.id == countryId {
                        Label(country.name.isEmpty ? country.code : country.name, systemImage: "checkmark")
                    } else {
                        Text(verbatim: country.name.isEmpty ? country.code : country.name)
                    }
                }
            }
        } label: {
            DorrTextField(
                value: .constant(shown), label: L("profile_country"), icon: "globe",
                trailing: AnyView(Image(systemName: "chevron.down").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut)),
                onClick: {}
            )
            .allowsHitTesting(false)
        }
        .buttonStyle(.plain)
        .disabled(allCountries.isEmpty)
    }

    // ---- photo ----
    private func upload(_ data: Data) {
        photoBusy = true
        photoError = nil
        Task {
            let part = await Task.detached { avatarPart(data) }.value
            if let part {
                let result = await ProfileApi.avatar(part)
                if let me = result.value {
                    Session.shared.me = me
                    AvatarVersion.shared.bump()
                } else {
                    photoError = result.errorText(L("login_error_offline")) ?? L("profile_photo_failed")
                }
            } else {
                photoError = L("profile_photo_failed")
            }
            photoBusy = false
        }
    }

    // ---- email ----
    private func sendCode() {
        emailBusy = true
        emailError = nil
        emailMessage = nil
        Task {
            let result = await ProfileApi.sendEmailCode(email.trimmingCharacters(in: .whitespaces))
            emailBusy = false
            if result.value != nil {
                codeSent = true
                emailMessage = L("profile_email_sent")
            } else {
                emailError = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func confirmEmail() {
        emailBusy = true
        emailError = nil
        Task {
            let result = await ProfileApi.confirmEmail(code.trimmingCharacters(in: .whitespaces))
            emailBusy = false
            if let me = result.value {
                Session.shared.me = me
                codeSent = false
                code = ""
                emailMessage = nil
            } else {
                emailError = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    // ---- save ----
    private func save() {
        saving = true
        error = nil
        var body: JsonBody = [
            "name": name.trimmingCharacters(in: .whitespaces),
            "headline": headline.trimmingCharacters(in: .whitespaces),
            "bio": bio.trimmingCharacters(in: .whitespacesAndNewlines),
            "city": city.trimmingCharacters(in: .whitespaces),
            "contact_languages": languages,
            "locale": AppLanguage.shared.code,
        ]
        if let value = Int(years.trimmingCharacters(in: .whitespaces)) { body["years_of_experience"] = value }
        if let countryId { body["country_id"] = countryId }
        if let residency { body["residency"] = residency }
        if let gender { body["gender"] = gender }
        if !dob.trimmingCharacters(in: .whitespaces).isEmpty { body["date_of_birth"] = dob.trimmingCharacters(in: .whitespaces) }
        let sent = body
        Task {
            let result = await ProfileApi.update(sent)
            saving = false
            if let me = result.value {
                Session.shared.me = me
                AppToast.show(L("profile_saved"))
                onSaved()
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }
}

/// `PhotoButton`: an icon and a bold word in the brand colour, faded while disabled.
private struct PhotoButtonLabel: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let enabled: Bool

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold))
            Text(verbatim: text).font(.dorr(14, .bold))
        }
        .foregroundStyle(wa.red.opacity(enabled ? 1 : 0.4))
        .padding(.horizontal, 14)
        .padding(.vertical, 8)
        .contentShape(Rectangle())
    }
}
