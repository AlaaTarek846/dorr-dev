package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
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

    /** Succeeds (200) only if the PIN in `X-Wallet-Pin` is right — used to unlock the wallet screens. */
    @POST("mobile/v1/wallet/pin/verify")
    suspend fun verifyPin(
        @Header("Authorization") authorization: String,
        @Header("X-Wallet-Pin") pin: String,
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

data class PinStatusDto(@SerializedName("has_pin") val hasPin: Boolean)

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
