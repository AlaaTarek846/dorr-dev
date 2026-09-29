package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.Part
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Wallet endpoints under /api/mobile/v1/wallet. Money is always an integer in
 * minor units (`*_minor`); the app never does float maths on it — see
 * [com.dorr.app.ui.screens.wallet.formatMinor].
 *
 * Endpoints that move money take the wallet PIN in `X-Wallet-Pin` and a
 * client-generated `Idempotency-Key`, so a retry after a dropped connection
 * can never charge twice.
 */
interface WalletApi {
    @GET("mobile/v1/wallet")
    suspend fun balance(@Header("Authorization") authorization: String): ApiEnvelope<WalletBalanceDto>

    /** Statement of the request country's wallet, newest first. `direction`: credit/debit; `bucket`: withdrawable/spend_only. */
    @GET("mobile/v1/wallet/transactions")
    suspend fun transactions(
        @Header("Authorization") authorization: String,
        @Query("page") page: Int,
        @Query("per_page") perPage: Int = 15,
        @Query("direction") direction: String? = null,
        @Query("bucket") bucket: String? = null,
    ): ApiEnvelope<List<WalletTransactionDto>>

    /** Step 1 of a transfer: who is behind this phone / wallet number? No PIN, moves nothing; returns a masked name + a short-lived token. */
    @POST("mobile/v1/wallet/transfers/lookup")
    suspend fun transferLookup(
        @Header("Authorization") authorization: String,
        @Body body: TransferLookupRequest,
    ): ApiEnvelope<TransferRecipientDto>

    /** Step 2: pays the recipient the lookup token names. Needs the PIN and a client-generated idempotency key; the recipient always gets spend-only money. */
    @POST("mobile/v1/wallet/transfers")
    suspend fun transfer(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
        @Header("Idempotency-Key") idempotencyKey: String,
        @Body body: TransferRequest,
    ): ApiEnvelope<WalletTransactionDto>

    @GET("mobile/v1/wallet/payment-methods")
    suspend fun paymentMethods(@Header("Authorization") authorization: String): ApiEnvelope<List<PaymentMethodDto>>

    @GET("mobile/v1/wallet/pin")
    suspend fun pinStatus(@Header("Authorization") authorization: String): ApiEnvelope<PinStatusDto>

    @POST("mobile/v1/wallet/pin")
    suspend fun createPin(
        @Header("Authorization") authorization: String,
        @Body body: CreatePinRequest,
    ): ApiEnvelope<PinStatusDto>

    @PUT("mobile/v1/wallet/pin")
    suspend fun changePin(
        @Header("Authorization") authorization: String,
        @Body body: ChangePinRequest,
    ): ApiEnvelope<PinStatusDto>

    /**
     * How the PIN can be got back — chosen before the PIN exists. JSON for password / birth date / e-mail;
     * the photo methods use [setRecoveryDocument]. Changing it once a PIN exists needs `X-Wallet-Pin`.
     */
    @POST("mobile/v1/wallet/pin/recovery")
    suspend fun setRecovery(
        @Header("Authorization") authorization: String,
        @Body body: RecoverySetupRequest,
        @Header("X-Wallet-Pin") pin: String? = null,
    ): ApiEnvelope<PinStatusDto>

    @Multipart
    @POST("mobile/v1/wallet/pin/recovery")
    suspend fun setRecoveryDocument(
        @Header("Authorization") authorization: String,
        @Part("method") method: RequestBody,
        @Part document: MultipartBody.Part,
        @Header("X-Wallet-Pin") pin: String? = null,
    ): ApiEnvelope<PinStatusDto>

    /** Sends a 4-digit code to the e-mail given at setup; `pending` = the new address of a method switch still to be confirmed. */
    @POST("mobile/v1/wallet/pin/recovery/email-code")
    suspend fun sendRecoveryEmailCode(
        @Header("Authorization") authorization: String,
        @retrofit2.http.Query("pending") pending: Boolean? = null,
    ): ApiEnvelope<Any?>

    @POST("mobile/v1/wallet/pin/recovery/confirm-email")
    suspend fun confirmRecoveryEmail(
        @Header("Authorization") authorization: String,
        @Body body: RecoveryCodeRequest,
    ): ApiEnvelope<PinStatusDto>

    /** "I forgot my PIN": proves the recovery secret and sets a new PIN in one call (password / birth date / e-mail code). */
    @POST("mobile/v1/wallet/pin/recover")
    suspend fun recoverPin(
        @Header("Authorization") authorization: String,
        @Body body: RecoverPinRequest,
    ): ApiEnvelope<PinStatusDto>

    /** Photo methods: uploads the new photo for a person to compare with the one from setup (201). */
    @Multipart
    @POST("mobile/v1/wallet/pin/recover")
    suspend fun recoverPinWithDocument(
        @Header("Authorization") authorization: String,
        @Part document: MultipartBody.Part,
    ): ApiEnvelope<PinStatusDto>

    /**
     * The only way out of a permanent freeze: a selfie + an ID/passport photo, reviewed by a person —
     * whatever the recovery method on file actually is.
     */
    @Multipart
    @POST("mobile/v1/wallet/pin/unfreeze")
    suspend fun unfreezePin(
        @Header("Authorization") authorization: String,
        @Part idDocument: MultipartBody.Part,
        @Part selfie: MultipartBody.Part,
    ): ApiEnvelope<PinStatusDto>

    /**
     * Succeeds (200) only if the PIN in `X-Wallet-Pin` is right — used to unlock the wallet screens.
     * `X-Device-Id` (added to every request by ApiClient) decides `device_trusted` in the answer.
     */
    @POST("mobile/v1/wallet/pin/verify")
    suspend fun verifyPin(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
    ): ApiEnvelope<PinVerifyDto>

    /** A device that unlocked with the right PIN but was never seen before still has to prove the
     *  phone on file is reachable from it — a code goes there (dev-fixed, same as everywhere else). */
    @POST("mobile/v1/wallet/device/verify-code")
    suspend fun sendDeviceTrustCode(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
    ): ApiEnvelope<Any?>

    @POST("mobile/v1/wallet/device/confirm")
    suspend fun confirmDeviceTrust(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
        @Body body: RecoveryCodeRequest,
    ): ApiEnvelope<Any?>

    @POST("mobile/v1/wallet/topups/quote")
    suspend fun quote(
        @Header("Authorization") authorization: String,
        @Body body: TopupRequest,
    ): ApiEnvelope<TopupQuoteDto>

    @POST("mobile/v1/wallet/topups")
    suspend fun startTopup(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
        @Header("Idempotency-Key") idempotencyKey: String,
        @Body body: TopupRequest,
    ): ApiEnvelope<TopupPaymentDto>

    @GET("mobile/v1/wallet/topups/{uuid}")
    suspend fun topup(
        @Header("Authorization") authorization: String,
        @Path("uuid") uuid: String,
    ): ApiEnvelope<TopupPaymentDto>

    /** URPay only: submits the OTP the customer received by SMS. */
    @POST("mobile/v1/wallet/topups/{uuid}/confirm")
    suspend fun confirmTopup(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("uuid") uuid: String,
        @Body body: ConfirmOtpRequest,
    ): ApiEnvelope<TopupPaymentDto>
}

data class WalletBalanceDto(
    @SerializedName("country_code") val countryCode: String?,
    /** e.g. "+966" — transfers are addressed by phone within the same country. */
    @SerializedName("dial_code") val dialCode: String? = null,
    /** National number shape of this country — the transfer form validates against it while typing. */
    @SerializedName("phone_length") val phoneLength: Int? = null,
    @SerializedName("phone_starts_with") val phoneStartsWith: String? = null,
    /** The number others send to for THIS wallet (one per country wallet), raw and grouped ("123 4567 8901"). */
    @SerializedName("wallet_number") val walletNumber: String? = null,
    @SerializedName("wallet_number_formatted") val walletNumberFormatted: String? = null,
    /** What this wallet's QR code encodes (public facts only). */
    @SerializedName("qr_payload") val qrPayload: String? = null,
    @SerializedName("currency_code") val currencyCode: String?,
    @SerializedName("currency_symbol") val currencySymbol: String?,
    @SerializedName("total_minor") val totalMinor: Long,
    /** Can be withdrawn to the owner's bank. */
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long,
    /** Received transfers / bonuses: spendable on services, never withdrawable. */
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long,
    /** Reserved by pending requests; not spendable until released. */
    @SerializedName("held_minor") val heldMinor: Long,
    /** Balances in other countries — shown read-only, not spendable here. */
    @SerializedName("other_wallets") val otherWallets: List<OtherWalletDto>? = null,
)

data class OtherWalletDto(
    @SerializedName("country_code") val countryCode: String?,
    @SerializedName("currency_code") val currencyCode: String?,
    @SerializedName("total_minor") val totalMinor: Long,
)

data class WalletTransactionDto(
    val uuid: String,
    val type: String,
    @SerializedName("type_label") val typeLabel: String,
    /** credit / debit */
    val direction: String,
    /** withdrawable / spend_only */
    val bucket: String,
    @SerializedName("amount_minor") val amountMinor: Long,
    @SerializedName("balance_after_minor") val balanceAfterMinor: Long,
    val note: String?,
    val counterparty: CounterpartyDto? = null,
    @SerializedName("created_at") val createdAt: String?,
)

data class PaymentMethodDto(
    val id: Int,
    val code: String,
    val gateway: String,
    val name: String?,
    @SerializedName("logo_url") val logoUrl: String?,
    /** Listed, but the gateway has no credentials yet — shown as "coming soon" and not selectable. */
    @SerializedName("coming_soon") val comingSoon: Boolean = false,
)

data class PinStatusDto(
    @SerializedName("has_pin") val hasPin: Boolean,
    /** The PIN was reset to 0000 (approved recovery request): it must be replaced before it can move money. */
    @SerializedName("must_change") val mustChange: Boolean = false,
    /** Permanently locked (a wrong attempt right after a temporary lock) — only a selfie + ID, reviewed
     *  by a person, lifts it. Nothing else works while this is true, self-service recovery included. */
    @SerializedName("is_frozen") val isFrozen: Boolean = false,
    /** A temporary lock still running (ISO-8601) — the pad opens on its countdown, not the keys. */
    @SerializedName("locked_until") val lockedUntil: String? = null,
    /** The recovery method on file; null until one is chosen. */
    val recovery: RecoveryInfoDto? = null,
    /** The latest recovery/freeze request, if any. */
    val request: RecoveryRequestDto? = null,
)

/** `method`: password, birth_date, id_photo, passport_photo, email. `ready`: an e-mail method is only ready once confirmed. */
data class RecoveryInfoDto(
    val method: String,
    val ready: Boolean,
    val email: String? = null,
    /** A new e-mail waiting for its code; the method above keeps working meanwhile. */
    @SerializedName("pending_email") val pendingEmail: String? = null,
)

data class PinVerifyDto(
    val verified: Boolean = true,
    @SerializedName("must_change") val mustChange: Boolean = false,
    /** False the first time this device (X-Device-Id) ever unlocks this wallet — see the wallet-device endpoints below. */
    @SerializedName("device_trusted") val deviceTrusted: Boolean = true,
)

/** `status`: pending, approved or rejected. `reason`: recovery_document | security_freeze. */
data class RecoveryRequestDto(
    val id: Long,
    val method: String,
    val reason: String = "recovery_document",
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
)

data class RecoverySetupRequest(
    val method: String,
    val password: String? = null,
    @SerializedName("password_confirmation") val passwordConfirmation: String? = null,
    @SerializedName("birth_date") val birthDate: String? = null,
    val email: String? = null,
)

data class RecoveryCodeRequest(val code: String)

data class RecoverPinRequest(
    val password: String? = null,
    @SerializedName("birth_date") val birthDate: String? = null,
    val code: String? = null,
    val pin: String,
    @SerializedName("pin_confirmation") val pinConfirmation: String,
)

data class CreatePinRequest(
    val pin: String,
    @SerializedName("pin_confirmation") val pinConfirmation: String,
)

data class ChangePinRequest(
    @SerializedName("current_pin") val currentPin: String,
    val pin: String,
    @SerializedName("pin_confirmation") val pinConfirmation: String,
)

data class TopupRequest(
    @SerializedName("payment_method_id") val paymentMethodId: Int,
    @SerializedName("amount_minor") val amountMinor: Long,
)

data class ConfirmOtpRequest(val otp: String)

/** `mode` is "phone" (national number, as typed), "wallet" (the 11-digit wallet number) or "qr" (a scanned code). */
data class TransferLookupRequest(
    val mode: String,
    val phone: String? = null,
    @SerializedName("wallet_number") val walletNumber: String? = null,
    /** The text a scanned wallet QR code held (mode "qr"). */
    val qr: String? = null,
)

/** The person the money is about to reach, shown for confirmation before anything is sent. */
data class TransferRecipientDto(
    @SerializedName("recipient_token") val recipientToken: String,
    /** "phone" or "wallet" — which way the sender addressed them. */
    val via: String,
    /** Already masked by the server: first letter of each word, the rest asterisks. */
    @SerializedName("name_masked") val nameMasked: String,
    /** Full phone, only when looked up by phone. */
    val phone: String? = null,
    /** Only when looked up by wallet number. */
    @SerializedName("wallet_number") val walletNumber: String? = null,
    @SerializedName("country_code") val countryCode: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    /** DORR's optional cut of the transfer, e.g. "2.5000" — 0 means no fee. A preview only; the rate
     *  actually applied is decided (and stamped onto the transaction) at send time. */
    @SerializedName("fee_percent") val feePercent: String = "0.0000",
    /** "sender" (added on top of the amount) or "recipient" (deducted from what they receive). */
    @SerializedName("fee_payer") val feePayer: String = "recipient",
    /** A heads-up, not proof of anything: this person's phone number changed recently. */
    @SerializedName("number_recently_changed") val numberRecentlyChanged: Boolean = false,
)

data class TransferRequest(
    @SerializedName("recipient_token") val recipientToken: String,
    @SerializedName("amount_minor") val amountMinor: Long,
)

/** The other side of a transfer; the phone arrives already masked. */
data class CounterpartyDto(val name: String?, val phone: String?)

data class TopupQuoteDto(
    @SerializedName("paid_amount_minor") val paidAmountMinor: Long,
    @SerializedName("fee_minor") val feeMinor: Long,
    @SerializedName("bonus_minor") val bonusMinor: Long,
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long,
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long,
    @SerializedName("total_credited_minor") val totalCreditedMinor: Long,
)

data class TopupPaymentDto(
    val uuid: String,
    /** pending / paid / failed / expired / refunded */
    val status: String,
    @SerializedName("amount_minor") val amountMinor: Long,
    @SerializedName("fee_minor") val feeMinor: Long,
    @SerializedName("bonus_minor") val bonusMinor: Long,
    @SerializedName("redirect_url") val redirectUrl: String?,
    @SerializedName("requires_otp") val requiresOtp: Boolean,
)
