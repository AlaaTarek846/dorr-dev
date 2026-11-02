import Foundation

/// androidApp `network/WalletApi.kt` — wallet endpoints under /api/mobile/v1/wallet. Money is always an
/// integer in minor units (`*_minor`); the app never does float maths on it — see [formatMinor].
///
/// Endpoints that move money take the wallet PIN in `X-Wallet-Pin` and a client-generated
/// `Idempotency-Key`, so a retry after a dropped connection can never charge twice.
enum WalletApi {
    static func balance() async throws -> ApiEnvelope<WalletBalanceDto> {
        try await ApiClient.get("mobile/v1/wallet", auth: true)
    }

    /// Statement of the request country's wallet, newest first. `direction`: credit/debit; `bucket`: withdrawable/spend_only.
    static func transactions(page: Int, perPage: Int = 15, direction: String? = nil, bucket: String? = nil, type: String? = nil) async throws -> ApiEnvelope<[WalletTransactionDto]> {
        try await ApiClient.get("mobile/v1/wallet/transactions", query: [
            "page": String(page), "per_page": String(perPage), "direction": direction, "bucket": bucket, "type": type,
        ], auth: true)
    }

    /// Step 1 of a transfer: who is behind this phone / wallet number? No PIN, moves nothing; returns a masked name + a short-lived token.
    static func transferLookup(_ body: TransferLookupRequest) async throws -> ApiEnvelope<TransferRecipientDto> {
        try await ApiClient.post("mobile/v1/wallet/transfers/lookup", body: body, auth: true)
    }

    /// Step 2: pays the recipient the lookup token names. Needs the PIN and a client-generated idempotency key; the recipient always gets spend-only money.
    static func transfer(pin: String, idempotencyKey: String, _ body: TransferRequest) async throws -> ApiEnvelope<WalletTransactionDto> {
        try await ApiClient.post("mobile/v1/wallet/transfers", body: body, auth: true, headers: ["X-Wallet-Pin": pin, "Idempotency-Key": idempotencyKey])
    }

    static func paymentMethods() async throws -> ApiEnvelope<[PaymentMethodDto]> {
        try await ApiClient.get("mobile/v1/wallet/payment-methods", auth: true)
    }

    static func pinStatus() async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.get("mobile/v1/wallet/pin", auth: true)
    }

    static func createPin(_ body: CreatePinRequest) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.post("mobile/v1/wallet/pin", body: body, auth: true)
    }

    static func changePin(_ body: ChangePinRequest) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.put("mobile/v1/wallet/pin", body: body)
    }

    /// How the PIN can be got back — chosen before the PIN exists. JSON for password / birth date / e-mail;
    /// the photo methods use [setRecoveryDocument]. Changing it once a PIN exists needs `X-Wallet-Pin`.
    static func setRecovery(_ body: RecoverySetupRequest, pin: String? = nil) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.post("mobile/v1/wallet/pin/recovery", body: body, auth: true, headers: pinHeader(pin))
    }

    static func setRecoveryDocument(method: String, document: Data, pin: String? = nil) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.upload("mobile/v1/wallet/pin/recovery", fields: ["method": method], files: [photoPart("document", document)], headers: pinHeader(pin))
    }

    /// Sends a 4-digit code to the e-mail given at setup; `pending` = the new address of a method switch still to be confirmed.
    static func sendRecoveryEmailCode(pending: Bool? = nil) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.send("mobile/v1/wallet/pin/recovery/email-code", method: "POST", query: ["pending": pending.map { $0 ? "true" : "false" }], body: Optional<Empty>.none, auth: true)
    }

    static func confirmRecoveryEmail(_ body: RecoveryCodeRequest) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.post("mobile/v1/wallet/pin/recovery/confirm-email", body: body, auth: true)
    }

    /// "I forgot my PIN": proves the recovery secret and sets a new PIN in one call (password / birth date / e-mail code).
    static func recoverPin(_ body: RecoverPinRequest) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.post("mobile/v1/wallet/pin/recover", body: body, auth: true)
    }

    /// Photo methods: uploads the new photo for a person to compare with the one from setup (201).
    static func recoverPinWithDocument(_ document: Data) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.upload("mobile/v1/wallet/pin/recover", files: [photoPart("document", document)])
    }

    /// The only way out of a permanent freeze: a selfie + an ID/passport photo, reviewed by a person —
    /// whatever the recovery method on file actually is.
    static func unfreezePin(idDocument: Data, selfie: Data) async throws -> ApiEnvelope<PinStatusDto> {
        try await ApiClient.upload("mobile/v1/wallet/pin/unfreeze", files: [photoPart("id_document", idDocument), photoPart("selfie", selfie)])
    }

    /// Succeeds (200) only if the PIN in `X-Wallet-Pin` is right — used to unlock the wallet screens.
    /// `X-Device-Id` (added to every request by ApiClient) decides `device_trusted` in the answer.
    static func verifyPin(_ pin: String) async throws -> ApiEnvelope<PinVerifyDto> {
        try await ApiClient.send("mobile/v1/wallet/pin/verify", method: "POST", body: Optional<Empty>.none, auth: true, headers: ["X-Wallet-Pin": pin])
    }

    /// A device that unlocked with the right PIN but was never seen before still has to prove the
    /// phone on file is reachable from it — a code goes there (dev-fixed, same as everywhere else).
    static func sendDeviceTrustCode(pin: String) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.send("mobile/v1/wallet/device/verify-code", method: "POST", body: Optional<Empty>.none, auth: true, headers: ["X-Wallet-Pin": pin])
    }

    static func confirmDeviceTrust(pin: String, _ body: RecoveryCodeRequest) async throws -> ApiEnvelope<Ignored> {
        try await ApiClient.post("mobile/v1/wallet/device/confirm", body: body, auth: true, headers: ["X-Wallet-Pin": pin])
    }

    static func quote(_ body: TopupRequest) async throws -> ApiEnvelope<TopupQuoteDto> {
        try await ApiClient.post("mobile/v1/wallet/topups/quote", body: body, auth: true)
    }

    static func startTopup(pin: String, idempotencyKey: String, _ body: TopupRequest) async throws -> ApiEnvelope<TopupPaymentDto> {
        try await ApiClient.post("mobile/v1/wallet/topups", body: body, auth: true, headers: ["X-Wallet-Pin": pin, "Idempotency-Key": idempotencyKey])
    }

    static func topup(uuid: String) async throws -> ApiEnvelope<TopupPaymentDto> {
        try await ApiClient.get("mobile/v1/wallet/topups/\(uuid)", auth: true)
    }

    /// The one payment screen: the server prices `{purpose, reference}` and answers what can pay it.
    static func createCheckout(_ body: CheckoutCreateRequest) async throws -> ApiEnvelope<CheckoutDto> {
        try await ApiClient.post("mobile/v1/wallet/checkouts", body: body, auth: true)
    }

    static func checkout(uuid: String) async throws -> ApiEnvelope<CheckoutDto> {
        try await ApiClient.get("mobile/v1/wallet/checkouts/\(uuid)", auth: true)
    }

    /// A coupon on the payment screen (DORR Sports prizes, admin coupons).
    static func applyCoupon(uuid: String, _ body: CheckoutCouponRequest) async throws -> ApiEnvelope<CheckoutDto> {
        try await ApiClient.post("mobile/v1/wallet/checkouts/\(uuid)/coupon", body: body, auth: true)
    }

    static func removeCoupon(uuid: String) async throws -> ApiEnvelope<CheckoutDto> {
        try await ApiClient.delete("mobile/v1/wallet/checkouts/\(uuid)/coupon")
    }

    static func coupons() async throws -> ApiEnvelope<[WalletCouponDto]> {
        try await ApiClient.get("mobile/v1/wallet/coupons", auth: true)
    }

    /// From the wallet, or handed to a gateway (then it settles by itself once the gateway confirms).
    static func payCheckout(pin: String, idempotencyKey: String?, uuid: String, _ body: CheckoutPayRequest) async throws -> ApiEnvelope<CheckoutDto> {
        var headers = ["X-Wallet-Pin": pin]
        if let idempotencyKey { headers["Idempotency-Key"] = idempotencyKey }
        return try await ApiClient.post("mobile/v1/wallet/checkouts/\(uuid)/pay", body: body, auth: true, headers: headers)
    }

    /// URPay only: submits the OTP the customer received by SMS.
    static func confirmTopup(pin: String, uuid: String, _ body: ConfirmOtpRequest) async throws -> ApiEnvelope<TopupPaymentDto> {
        try await ApiClient.post("mobile/v1/wallet/topups/\(uuid)/confirm", body: body, auth: true, headers: ["X-Wallet-Pin": pin])
    }

    private static func pinHeader(_ pin: String?) -> [String: String] {
        pin.map { ["X-Wallet-Pin": $0] } ?? [:]
    }

    private static func photoPart(_ field: String, _ data: Data) -> ApiClient.FilePart {
        ApiClient.FilePart(field: field, filename: "\(field).jpg", mimeType: "image/jpeg", data: data)
    }
}

// MARK: - DTOs (CodingKeys = @SerializedName; Kotlin defaults kept through decodeIfPresent)

extension KeyedDecodingContainer {
    func value<T: Decodable>(_ key: Key, _ fallback: T) -> T {
        ((try? decodeIfPresent(T.self, forKey: key)) ?? nil) ?? fallback
    }
}

struct WalletBalanceDto: Codable, Equatable {
    var countryCode: String?
    /// e.g. "+966" — transfers are addressed by phone within the same country.
    var dialCode: String?
    /// National number shape of this country — the transfer form validates against it while typing.
    var phoneLength: Int?
    var phoneStartsWith: String?
    /// The number others send to for THIS wallet (one per country wallet), raw and grouped ("123 4567 8901").
    var walletNumber: String?
    var walletNumberFormatted: String?
    /// What this wallet's QR code encodes (public facts only).
    var qrPayload: String?
    var currencyCode: String?
    var currencySymbol: String?
    var totalMinor: Int
    /// Can be withdrawn to the owner's bank.
    var withdrawableMinor: Int
    /// Received transfers / bonuses: spendable on services, never withdrawable.
    var spendOnlyMinor: Int
    /// Reserved by pending requests; not spendable until released.
    var heldMinor: Int
    /// Balances in other countries — shown read-only, not spendable here.
    var otherWallets: [OtherWalletDto]?

    enum CodingKeys: String, CodingKey {
        case countryCode = "country_code", dialCode = "dial_code", phoneLength = "phone_length"
        case phoneStartsWith = "phone_starts_with", walletNumber = "wallet_number"
        case walletNumberFormatted = "wallet_number_formatted", qrPayload = "qr_payload"
        case currencyCode = "currency_code", currencySymbol = "currency_symbol", totalMinor = "total_minor"
        case withdrawableMinor = "withdrawable_minor", spendOnlyMinor = "spend_only_minor"
        case heldMinor = "held_minor", otherWallets = "other_wallets"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        countryCode = c.value(.countryCode, nil)
        dialCode = c.value(.dialCode, nil)
        phoneLength = c.value(.phoneLength, nil)
        phoneStartsWith = c.value(.phoneStartsWith, nil)
        walletNumber = c.value(.walletNumber, nil)
        walletNumberFormatted = c.value(.walletNumberFormatted, nil)
        qrPayload = c.value(.qrPayload, nil)
        currencyCode = c.value(.currencyCode, nil)
        currencySymbol = c.value(.currencySymbol, nil)
        totalMinor = c.value(.totalMinor, 0)
        withdrawableMinor = c.value(.withdrawableMinor, 0)
        spendOnlyMinor = c.value(.spendOnlyMinor, 0)
        heldMinor = c.value(.heldMinor, 0)
        otherWallets = c.value(.otherWallets, nil)
    }
}

struct OtherWalletDto: Codable, Equatable {
    var countryCode: String?
    var currencyCode: String?
    var totalMinor: Int

    enum CodingKeys: String, CodingKey {
        case countryCode = "country_code", currencyCode = "currency_code", totalMinor = "total_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        countryCode = c.value(.countryCode, nil)
        currencyCode = c.value(.currencyCode, nil)
        totalMinor = c.value(.totalMinor, 0)
    }
}

struct WalletTransactionDto: Codable, Equatable, Identifiable {
    var uuid: String
    var type: String
    var typeLabel: String
    /// credit / debit
    var direction: String
    /// withdrawable / spend_only
    var bucket: String
    var amountMinor: Int
    var balanceAfterMinor: Int
    var note: String?
    var counterparty: CounterpartyDto?
    var createdAt: String?

    var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, type, direction, bucket, note, counterparty
        case typeLabel = "type_label", amountMinor = "amount_minor"
        case balanceAfterMinor = "balance_after_minor", createdAt = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        type = c.value(.type, "")
        typeLabel = c.value(.typeLabel, "")
        direction = c.value(.direction, "")
        bucket = c.value(.bucket, "")
        amountMinor = c.value(.amountMinor, 0)
        balanceAfterMinor = c.value(.balanceAfterMinor, 0)
        note = c.value(.note, nil)
        counterparty = c.value(.counterparty, nil)
        createdAt = c.value(.createdAt, nil)
    }
}

/// The other side of a transfer; the phone arrives already masked.
struct CounterpartyDto: Codable, Equatable {
    var name: String?
    var phone: String?
}

struct PaymentMethodDto: Codable, Equatable, Identifiable {
    var id: Int
    var code: String
    var gateway: String
    var name: String?
    var logoUrl: String?
    /// Listed, but the gateway has no credentials yet — shown as "coming soon" and not selectable.
    var comingSoon: Bool

    enum CodingKeys: String, CodingKey {
        case id, code, gateway, name
        case logoUrl = "logo_url", comingSoon = "coming_soon"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        code = c.value(.code, "")
        gateway = c.value(.gateway, "")
        name = c.value(.name, nil)
        logoUrl = c.value(.logoUrl, nil)
        comingSoon = c.value(.comingSoon, false)
    }
}

struct PinStatusDto: Codable, Equatable {
    var hasPin: Bool
    /// The PIN was reset to 0000 (approved recovery request): it must be replaced before it can move money.
    var mustChange: Bool
    /// Permanently locked (a wrong attempt right after a temporary lock) — only a selfie + ID, reviewed
    /// by a person, lifts it. Nothing else works while this is true, self-service recovery included.
    var isFrozen: Bool
    /// A temporary lock still running (ISO-8601) — the pad opens on its countdown, not the keys.
    var lockedUntil: String?
    /// The recovery method on file; null until one is chosen.
    var recovery: RecoveryInfoDto?
    /// The latest recovery/freeze request, if any.
    var request: RecoveryRequestDto?

    enum CodingKeys: String, CodingKey {
        case recovery, request
        case hasPin = "has_pin", mustChange = "must_change", isFrozen = "is_frozen", lockedUntil = "locked_until"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        hasPin = c.value(.hasPin, false)
        mustChange = c.value(.mustChange, false)
        isFrozen = c.value(.isFrozen, false)
        lockedUntil = c.value(.lockedUntil, nil)
        recovery = c.value(.recovery, nil)
        request = c.value(.request, nil)
    }
}

/// `method`: password, birth_date, id_photo, passport_photo, email. `ready`: an e-mail method is only ready once confirmed.
struct RecoveryInfoDto: Codable, Equatable {
    var method: String
    var ready: Bool
    var email: String?
    /// A new e-mail waiting for its code; the method above keeps working meanwhile.
    var pendingEmail: String?

    enum CodingKeys: String, CodingKey {
        case method, ready, email
        case pendingEmail = "pending_email"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        method = c.value(.method, "")
        ready = c.value(.ready, false)
        email = c.value(.email, nil)
        pendingEmail = c.value(.pendingEmail, nil)
    }
}

struct PinVerifyDto: Codable, Equatable {
    var verified: Bool
    var mustChange: Bool
    /// False the first time this device (X-Device-Id) ever unlocks this wallet — see the wallet-device endpoints.
    var deviceTrusted: Bool

    enum CodingKeys: String, CodingKey {
        case verified
        case mustChange = "must_change", deviceTrusted = "device_trusted"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        verified = c.value(.verified, true)
        mustChange = c.value(.mustChange, false)
        deviceTrusted = c.value(.deviceTrusted, true)
    }
}

/// `status`: pending, approved or rejected. `reason`: recovery_document | security_freeze.
struct RecoveryRequestDto: Codable, Equatable {
    var id: Int
    var method: String
    var reason: String
    var status: String
    var rejectionReason: String?

    enum CodingKeys: String, CodingKey {
        case id, method, reason, status
        case rejectionReason = "rejection_reason"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        method = c.value(.method, "")
        reason = c.value(.reason, "recovery_document")
        status = c.value(.status, "")
        rejectionReason = c.value(.rejectionReason, nil)
    }
}

struct RecoverySetupRequest: Encodable {
    var method: String
    var password: String? = nil
    var passwordConfirmation: String? = nil
    var birthDate: String? = nil
    var email: String? = nil

    enum CodingKeys: String, CodingKey {
        case method, password, email
        case passwordConfirmation = "password_confirmation", birthDate = "birth_date"
    }
}

struct RecoveryCodeRequest: Encodable {
    var code: String
}

struct RecoverPinRequest: Encodable {
    var password: String? = nil
    var birthDate: String? = nil
    var code: String? = nil
    var pin: String
    var pinConfirmation: String

    enum CodingKeys: String, CodingKey {
        case password, code, pin
        case birthDate = "birth_date", pinConfirmation = "pin_confirmation"
    }
}

struct CreatePinRequest: Encodable {
    var pin: String
    var pinConfirmation: String

    enum CodingKeys: String, CodingKey {
        case pin
        case pinConfirmation = "pin_confirmation"
    }
}

struct ChangePinRequest: Encodable {
    var currentPin: String
    var pin: String
    var pinConfirmation: String

    enum CodingKeys: String, CodingKey {
        case pin
        case currentPin = "current_pin", pinConfirmation = "pin_confirmation"
    }
}

struct TopupRequest: Encodable {
    var paymentMethodId: Int
    var amountMinor: Int

    enum CodingKeys: String, CodingKey {
        case paymentMethodId = "payment_method_id", amountMinor = "amount_minor"
    }
}

struct ConfirmOtpRequest: Encodable {
    var otp: String
}

/// `mode` is "phone" (national number, as typed), "wallet" (the 11-digit wallet number) or "qr" (a scanned code).
struct TransferLookupRequest: Encodable {
    var mode: String
    var phone: String? = nil
    var walletNumber: String? = nil
    /// The text a scanned wallet QR code held (mode "qr").
    var qr: String? = nil

    enum CodingKeys: String, CodingKey {
        case mode, phone, qr
        case walletNumber = "wallet_number"
    }
}

/// The person the money is about to reach, shown for confirmation before anything is sent.
struct TransferRecipientDto: Codable, Equatable {
    var recipientToken: String
    /// "phone" or "wallet" — which way the sender addressed them.
    var via: String
    /// Already masked by the server: first letter of each word, the rest asterisks.
    var nameMasked: String
    /// Full phone, only when looked up by phone.
    var phone: String?
    /// Only when looked up by wallet number.
    var walletNumber: String?
    var countryCode: String?
    var currencyCode: String?
    /// DORR's optional cut of the transfer, e.g. "2.5000" — 0 means no fee. A preview only; the rate
    /// actually applied is decided (and stamped onto the transaction) at send time.
    var feePercent: String
    /// "sender" (added on top of the amount) or "recipient" (deducted from what they receive).
    var feePayer: String
    /// A heads-up, not proof of anything: this person's phone number changed recently.
    var numberRecentlyChanged: Bool

    enum CodingKeys: String, CodingKey {
        case via, phone
        case recipientToken = "recipient_token", nameMasked = "name_masked", walletNumber = "wallet_number"
        case countryCode = "country_code", currencyCode = "currency_code", feePercent = "fee_percent"
        case feePayer = "fee_payer", numberRecentlyChanged = "number_recently_changed"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        recipientToken = c.value(.recipientToken, "")
        via = c.value(.via, "")
        nameMasked = c.value(.nameMasked, "")
        phone = c.value(.phone, nil)
        walletNumber = c.value(.walletNumber, nil)
        countryCode = c.value(.countryCode, nil)
        currencyCode = c.value(.currencyCode, nil)
        feePercent = c.value(.feePercent, "0.0000")
        feePayer = c.value(.feePayer, "recipient")
        numberRecentlyChanged = c.value(.numberRecentlyChanged, false)
    }
}

struct TransferRequest: Encodable {
    var recipientToken: String
    var amountMinor: Int

    enum CodingKeys: String, CodingKey {
        case recipientToken = "recipient_token", amountMinor = "amount_minor"
    }
}

struct TopupQuoteDto: Codable, Equatable {
    var paidAmountMinor: Int
    var feeMinor: Int
    var bonusMinor: Int
    var withdrawableMinor: Int
    var spendOnlyMinor: Int
    var totalCreditedMinor: Int

    enum CodingKeys: String, CodingKey {
        case paidAmountMinor = "paid_amount_minor", feeMinor = "fee_minor", bonusMinor = "bonus_minor"
        case withdrawableMinor = "withdrawable_minor", spendOnlyMinor = "spend_only_minor"
        case totalCreditedMinor = "total_credited_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        paidAmountMinor = c.value(.paidAmountMinor, 0)
        feeMinor = c.value(.feeMinor, 0)
        bonusMinor = c.value(.bonusMinor, 0)
        withdrawableMinor = c.value(.withdrawableMinor, 0)
        spendOnlyMinor = c.value(.spendOnlyMinor, 0)
        totalCreditedMinor = c.value(.totalCreditedMinor, 0)
    }
}

struct TopupPaymentDto: Codable, Equatable {
    var uuid: String
    /// pending / paid / failed / expired / refunded
    var status: String
    var amountMinor: Int
    var feeMinor: Int
    var bonusMinor: Int
    var redirectUrl: String?
    var requiresOtp: Bool

    enum CodingKeys: String, CodingKey {
        case uuid, status
        case amountMinor = "amount_minor", feeMinor = "fee_minor", bonusMinor = "bonus_minor"
        case redirectUrl = "redirect_url", requiresOtp = "requires_otp"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        uuid = c.value(.uuid, "")
        status = c.value(.status, "")
        amountMinor = c.value(.amountMinor, 0)
        feeMinor = c.value(.feeMinor, 0)
        bonusMinor = c.value(.bonusMinor, 0)
        redirectUrl = c.value(.redirectUrl, nil)
        requiresOtp = c.value(.requiresOtp, false)
    }
}

/// `reference` is whatever the purpose needs (`{ride_uuid}`, `{listing_id}`…); values are sent as JSON.
struct CheckoutCreateRequest: Encodable {
    var purpose: String
    var reference: [String: JSONValue]
}

/// A JSON value for free-form request maps (`Map<String, Any?>` on Android).
enum JSONValue: Encodable, Hashable {
    case string(String), int(Int), double(Double), bool(Bool), null
    case array([JSONValue])
    case object([String: JSONValue])

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let v): try c.encode(v)
        case .int(let v): try c.encode(v)
        case .double(let v): try c.encode(v)
        case .bool(let v): try c.encode(v)
        case .null: try c.encodeNil()
        case .array(let v): try c.encode(v)
        case .object(let v): try c.encode(v)
        }
    }
}

struct CheckoutPayRequest: Encodable {
    /// "wallet" or "gateway".
    var method: String
    var paymentMethodId: Int? = nil

    enum CodingKeys: String, CodingKey {
        case method
        case paymentMethodId = "payment_method_id"
    }
}

struct CheckoutDto: Codable, Equatable {
    var id: String
    var purpose: String
    var title: String
    var subtitle: String?
    var amountMinor: Int
    /// A coupon on it: what's charged is [payableMinor].
    var discountMinor: Int
    var payableMinor: Int?
    /// False for a card ride: its promo code goes on the ride's quote, not here.
    var couponsAllowed: Bool
    var coupon: CheckoutCouponDto?
    var currencyCode: String?
    /// pending / paid / failed / expired
    var status: String
    var paidVia: String?
    var failureReason: String?
    var wallet: CheckoutWalletDto
    var paymentMethods: [CheckoutMethodDto]
    /// The gateway top-up it was handed to, if any.
    var payment: TopupPaymentDto?

    enum CodingKeys: String, CodingKey {
        case id, purpose, title, subtitle, coupon, status, wallet, payment
        case amountMinor = "amount_minor", discountMinor = "discount_minor", payableMinor = "payable_minor"
        case couponsAllowed = "coupons_allowed", currencyCode = "currency_code", paidVia = "paid_via"
        case failureReason = "failure_reason", paymentMethods = "payment_methods"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, "")
        purpose = c.value(.purpose, "")
        title = c.value(.title, "")
        subtitle = c.value(.subtitle, nil)
        amountMinor = c.value(.amountMinor, 0)
        discountMinor = c.value(.discountMinor, 0)
        payableMinor = c.value(.payableMinor, nil)
        couponsAllowed = c.value(.couponsAllowed, true)
        coupon = c.value(.coupon, nil)
        currencyCode = c.value(.currencyCode, nil)
        status = c.value(.status, "")
        paidVia = c.value(.paidVia, nil)
        failureReason = c.value(.failureReason, nil)
        wallet = try c.decode(CheckoutWalletDto.self, forKey: .wallet)
        paymentMethods = c.value(.paymentMethods, [])
        payment = c.value(.payment, nil)
    }
}

struct CheckoutCouponDto: Codable, Equatable {
    var code: String?
    var discountMinor: Int

    enum CodingKeys: String, CodingKey {
        case code
        case discountMinor = "discount_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.value(.code, nil)
        discountMinor = c.value(.discountMinor, 0)
    }
}

/// One of my coupons: `kind` percent (value = %) or fixed (value in minor units).
struct WalletCouponDto: Codable, Equatable {
    var code: String
    var kind: String
    var value: Int
    var currencyCode: String?
    var expiresAt: String?
    var source: String?

    enum CodingKeys: String, CodingKey {
        case code, kind, value, source
        case currencyCode = "currency_code", expiresAt = "expires_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.value(.code, "")
        kind = c.value(.kind, "fixed")
        value = c.value(.value, 0)
        currencyCode = c.value(.currencyCode, nil)
        expiresAt = c.value(.expiresAt, nil)
        source = c.value(.source, nil)
    }
}

struct CheckoutCouponRequest: Encodable {
    var code: String
}

struct CheckoutWalletDto: Codable, Equatable {
    var availableMinor: Int
    var enough: Bool
    var shortfallMinor: Int

    enum CodingKeys: String, CodingKey {
        case enough
        case availableMinor = "available_minor", shortfallMinor = "shortfall_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        availableMinor = c.value(.availableMinor, 0)
        enough = c.value(.enough, false)
        shortfallMinor = c.value(.shortfallMinor, 0)
    }
}

struct CheckoutMethodDto: Codable, Equatable, Identifiable {
    var id: Int
    var code: String
    var gateway: String
    var name: String?
    var logoUrl: String?
    var comingSoon: Bool
    /// What this method would actually charge (a top-up fee included); null when it can't take this amount.
    var chargeMinor: Int?
    var available: Bool

    enum CodingKeys: String, CodingKey {
        case id, code, gateway, name, available
        case logoUrl = "logo_url", comingSoon = "coming_soon", chargeMinor = "charge_minor"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.value(.id, 0)
        code = c.value(.code, "")
        gateway = c.value(.gateway, "")
        name = c.value(.name, nil)
        logoUrl = c.value(.logoUrl, nil)
        comingSoon = c.value(.comingSoon, false)
        chargeMinor = c.value(.chargeMinor, nil)
        available = c.value(.available, false)
    }
}
