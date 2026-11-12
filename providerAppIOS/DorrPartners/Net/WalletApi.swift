// providerApp `net/WalletApi.kt`
import Foundation

/// The wallet under `/api/provider/v1/wallet` (Modules/Wallet/routes/provider.php) — the establishment's wallet once
/// `X-Establishment` is sent (WalletOwner): balance, statement, PIN and its recovery, topping up and withdrawing the earnings. Money is always an integer in minor units (`*_minor`); the app never does float maths on it.
/// Everything that moves money takes the wallet PIN in `X-Wallet-Pin` and, for a top-up or a withdrawal, a client-made
/// `Idempotency-Key`, so a retry after a dropped connection can never charge twice.
enum WalletApi {
    private static let base = Api.provider + "wallet"

    static func balance() async -> ApiResult<WalletBalanceDto> { await Api.call("GET", base) }

    static func transactions(page: Int, perPage: Int = 15, direction: String? = nil, bucket: String? = nil) async -> ApiResult<[WalletTransactionDto]> {
        await Api.call("GET", base + "/transactions", query: ["page": String(page), "per_page": String(perPage), "direction": direction, "bucket": bucket])
    }

    // MARK: - PIN

    static func pinStatus() async -> ApiResult<PinStatusDto> { await Api.call("GET", base + "/pin") }

    /// A PIN can only be made after a way to get it back has been chosen (and, for e-mail, confirmed).
    static func createPin(_ body: CreatePinRequest) async -> ApiResult<PinStatusDto> { await Api.call("POST", base + "/pin", body: body.json) }

    static func changePin(_ body: ChangePinRequest) async -> ApiResult<PinStatusDto> { await Api.call("PUT", base + "/pin", body: body.json) }

    /// Succeeds only if the PIN in `X-Wallet-Pin` is right — the unlock. `X-Device-Id` decides `device_trusted`.
    static func verifyPin(_ pin: String) async -> ApiResult<PinVerifyDto> {
        await Api.call("POST", base + "/pin/verify", headers: ["X-Wallet-Pin": pin])
    }

    static func setRecovery(_ body: RecoverySetupRequest, pin: String? = nil) async -> ApiResult<PinStatusDto> {
        await Api.call("POST", base + "/pin/recovery", body: body.json, headers: pin.map { ["X-Wallet-Pin": $0] } ?? [:])
    }

    /// Android `apiDone { … }` for a wallet call that may answer without `data` (and needs the PIN header): nil when the
    /// server said yes (any 2xx), else the text to show.
    static func done(_ call: () async -> ApiResult<Ignored>, offline: String, generic: String) async -> String? {
        switch await call() {
        case .ok: return nil
        case .offline: return offline
        case .failed(let error):
            // A 2xx whose envelope carries no `data` comes back as a "failure" with status 200: it went through.
            if error.httpStatus == 200 { return nil }
            return error.displayMessage.isEmpty ? generic : error.displayMessage
        }
    }

    static func sendRecoveryEmailCode(pending: Bool? = nil) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "/pin/recovery/email-code", query: ["pending": pending.map { $0 ? "true" : "false" }])
    }

    static func confirmRecoveryEmail(_ body: CodeBody) async -> ApiResult<PinStatusDto> {
        await Api.call("POST", base + "/pin/recovery/confirm-email", body: body.json)
    }

    /// "I forgot my PIN": proves the recovery secret and sets a new PIN in one call.
    static func recoverPin(_ body: RecoverPinRequest) async -> ApiResult<PinStatusDto> { await Api.call("POST", base + "/pin/recover", body: body.json) }

    /// The photo methods (ID / passport): the picture goes as multipart, as in the DORR app.
    static func setRecoveryDocument(method: String, document: Data, pin: String? = nil) async -> ApiResult<PinStatusDto> {
        await Api.upload(base + "/pin/recovery", fields: ["method": method],
                         files: [FilePart(field: "document", filename: "document.jpg", mimeType: "image/jpeg", data: document)],
                         headers: pin.map { ["X-Wallet-Pin": $0] } ?? [:])
    }

    /// Photo methods: uploads the new photo for a person to compare with the one from setup (201).
    static func recoverPinWithDocument(_ document: Data) async -> ApiResult<PinStatusDto> {
        await Api.upload(base + "/pin/recover", files: [FilePart(field: "document", filename: "document.jpg", mimeType: "image/jpeg", data: document)])
    }

    /// The only way out of a permanent freeze: a selfie + an ID/passport photo, reviewed by a person.
    static func unfreezePin(idDocument: Data, selfie: Data) async -> ApiResult<PinStatusDto> {
        await Api.upload(base + "/pin/unfreeze", files: [
            FilePart(field: "id_document", filename: "id_document.jpg", mimeType: "image/jpeg", data: idDocument),
            FilePart(field: "selfie", filename: "selfie.jpg", mimeType: "image/jpeg", data: selfie),
        ])
    }

    /// A new device unlocked with the right PIN: a code goes to the phone on file before it is trusted. The phone that
    /// created the PIN is trusted by `POST wallet/pin` itself (`X-Device-Id`, sent on every request).
    static func sendDeviceTrustCode(pin: String) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "/device/verify-code", headers: ["X-Wallet-Pin": pin])
    }

    static func confirmDeviceTrust(pin: String, _ body: CodeBody) async -> ApiResult<Ignored> {
        await Api.call("POST", base + "/device/confirm", body: body.json, headers: ["X-Wallet-Pin": pin])
    }

    // MARK: - topping up

    static func paymentMethods() async -> ApiResult<[PaymentMethodDto]> { await Api.call("GET", base + "/payment-methods") }

    static func quote(_ body: TopupRequest) async -> ApiResult<TopupQuoteDto> { await Api.call("POST", base + "/topups/quote", body: body.json) }

    static func startTopup(pin: String, key: String, _ body: TopupRequest) async -> ApiResult<TopupPaymentDto> {
        await Api.call("POST", base + "/topups", body: body.json, headers: ["X-Wallet-Pin": pin, "Idempotency-Key": key])
    }

    static func topup(_ uuid: String) async -> ApiResult<TopupPaymentDto> { await Api.call("GET", base + "/topups/\(uuid)") }

    /// A gateway that sends an SMS code: the code that confirms the payment.
    static func confirmTopup(pin: String, _ uuid: String, _ body: OtpBody) async -> ApiResult<TopupPaymentDto> {
        await Api.call("POST", base + "/topups/\(uuid)/confirm", body: ["otp": body.otp], headers: ["X-Wallet-Pin": pin])
    }

    // MARK: - withdrawing

    static func withdrawalMethods() async -> ApiResult<[WithdrawalMethodDto]> { await Api.call("GET", base + "/withdrawal-methods") }

    static func addWithdrawalMethod(pin: String, _ body: JsonBody) async -> ApiResult<WithdrawalMethodDto> {
        await Api.call("POST", base + "/withdrawal-methods", body: body, headers: ["X-Wallet-Pin": pin])
    }

    static func favoriteMethod(_ id: Int) async -> ApiResult<WithdrawalMethodDto> { await Api.call("PATCH", base + "/withdrawal-methods/\(id)/favorite") }

    static func deleteWithdrawalMethod(_ id: Int) async -> ApiResult<Ignored> { await Api.call("DELETE", base + "/withdrawal-methods/\(id)") }

    static func withdrawals(page: Int = 1, perPage: Int = 25) async -> ApiResult<[WithdrawalDto]> {
        await Api.call("GET", base + "/withdrawals", query: ["page": String(page), "per_page": String(perPage)])
    }

    /// Holds the money; an admin pays it out and approves.
    static func withdraw(pin: String, key: String, _ body: JsonBody) async -> ApiResult<WithdrawalDto> {
        await Api.call("POST", base + "/withdrawals", body: body, headers: ["X-Wallet-Pin": pin, "Idempotency-Key": key])
    }

    /// The transfer receipt, once approved — private, only its owner. Nil when it could not be fetched.
    static func receipt(_ id: Int) async -> Data? { await Api.bytes(base + "/withdrawals/\(id)/receipt") }
}

struct WalletBalanceDto: Decodable, Equatable {
    var countryCode: String?
    var currencyCode: String?
    var currencySymbol: String?
    var totalMinor: Int?
    /// Can be withdrawn to the owner's bank (the earnings and what was topped up).
    var withdrawableMinor: Int?
    /// Bonuses: spendable on services, never withdrawable.
    var spendOnlyMinor: Int?
    /// Reserved by a pending request; not spendable until released.
    var heldMinor: Int?
    var walletNumberFormatted: String?
}

struct WalletTransactionDto: Decodable, Identifiable, Equatable {
    var id: String { uuid ?? "" }
    var uuid: String?
    var type: String?
    var typeLabel: String?
    /// credit / debit
    var direction: String?
    /// withdrawable / spend_only
    var bucket: String?
    var amountMinor: Int?
    var balanceAfterMinor: Int?
    var note: String?
    var createdAt: String?
}

struct PaymentMethodDto: Decodable, Identifiable, Equatable {
    var id: Int?
    var code: String?
    var gateway: String?
    var name: String?
    var logoUrl: String?
    /// Listed, but the gateway has no credentials yet — shown as "coming soon" and not selectable.
    var comingSoon: Bool?
}

struct PinStatusDto: Decodable, Equatable {
    var hasPin: Bool?
    /// The PIN was reset to 0000 (approved recovery): it must be replaced before it can move money.
    var mustChange: Bool?
    /// Permanently locked after wrong attempts — only a person's review lifts it.
    var isFrozen: Bool?
    /// A temporary lock still running (ISO-8601).
    var lockedUntil: String?
    var recovery: RecoveryInfoDto?
    /// The latest photo recovery / unfreeze request, while one matters (pending, or a rejection to show).
    var request: RecoveryRequestDto?
}

/// `status`: pending, approved or rejected. `reason`: recovery_document | security_freeze.
struct RecoveryRequestDto: Decodable, Equatable {
    var id: Int?
    var method: String?
    var reason: String?
    var status: String?
    var rejectionReason: String?
}

/// `method`: password, birth_date, id_photo, passport_photo, email. `ready`: an e-mail method is only ready once confirmed.
struct RecoveryInfoDto: Decodable, Equatable {
    var method: String?
    var ready: Bool?
    var email: String?
    var pendingEmail: String?
}

struct PinVerifyDto: Decodable, Equatable {
    var verified: Bool?
    var mustChange: Bool?
    /// False the first time this phone ever unlocks this wallet.
    var deviceTrusted: Bool?
}

struct CreatePinRequest {
    let pin: String
    let pinConfirmation: String
    var json: JsonBody { ["pin": pin, "pin_confirmation": pinConfirmation] }
}

struct ChangePinRequest {
    let currentPin: String
    let pin: String
    let pinConfirmation: String
    var json: JsonBody { ["current_pin": currentPin, "pin": pin, "pin_confirmation": pinConfirmation] }
}

/// Gson leaves a null field out of the body — so do these.
private func compact(_ body: JsonBody) -> JsonBody { body.filter { $0.value != nil } }

struct RecoverySetupRequest {
    let method: String
    var password: String?
    var passwordConfirmation: String?
    var birthDate: String?
    var email: String?
    var json: JsonBody {
        compact(["method": method, "password": password, "password_confirmation": passwordConfirmation, "birth_date": birthDate, "email": email])
    }
}

struct RecoverPinRequest {
    var password: String?
    var birthDate: String?
    var code: String?
    let pin: String
    let pinConfirmation: String
    var json: JsonBody {
        compact(["password": password, "birth_date": birthDate, "code": code, "pin": pin, "pin_confirmation": pinConfirmation])
    }
}

struct CodeBody {
    let code: String
    var json: JsonBody { ["code": code] }
}

struct OtpBody {
    let otp: String
}

struct TopupRequest {
    let paymentMethodId: Int
    let amountMinor: Int
    var json: JsonBody { ["payment_method_id": paymentMethodId, "amount_minor": amountMinor] }
}

struct TopupQuoteDto: Decodable, Equatable {
    var paidAmountMinor: Int?
    var feeMinor: Int?
    var bonusMinor: Int?
    var withdrawableMinor: Int?
    var spendOnlyMinor: Int?
    var totalCreditedMinor: Int?
}

struct TopupPaymentDto: Decodable, Equatable {
    var uuid: String?
    /// pending / paid / failed / expired / refunded
    var status: String?
    var amountMinor: Int?
    var feeMinor: Int?
    var bonusMinor: Int?
    var redirectUrl: String?
    var requiresOtp: Bool?
}

struct WithdrawalMethodDto: Decodable, Identifiable, Equatable {
    var id: Int?
    var type: String?
    var label: String?
    var display: String?
    var isFavorite: Bool?
}

struct WithdrawalMethodShortDto: Decodable, Equatable {
    var type: String?
    var label: String?
    var display: String?
}

struct WithdrawalDto: Decodable, Identifiable, Equatable {
    var id: Int?
    /// pending / approved / rejected
    var status: String?
    var amountMinor: Int?
    var currencyCode: String?
    var method: WithdrawalMethodShortDto?
    var note: String?
    var rejectionReason: String?
    var hasReceipt: Bool?
    var createdAt: String?
}
