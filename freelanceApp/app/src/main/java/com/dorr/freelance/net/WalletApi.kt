package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * The freelancer's wallet (FL-005) under `/api/freelance/v1/wallet`: balance, statement, PIN and its recovery, topping up and
 * withdrawing the earnings. Money is always an integer in minor units (`*_minor`); the app never does float maths on it.
 * Everything that moves money takes the wallet PIN in `X-Wallet-Pin` and, for a top-up or a withdrawal, a client-made
 * `Idempotency-Key`, so a retry after a dropped connection can never charge twice.
 */
interface WalletApi {
    @GET("${ApiClient.FREELANCE}wallet")
    suspend fun balance(): Response<ApiEnvelope<WalletBalanceDto>>

    @GET("${ApiClient.FREELANCE}wallet/transactions")
    suspend fun transactions(
        @Query("page") page: Int,
        @Query("per_page") perPage: Int = 15,
        @Query("direction") direction: String? = null,
        @Query("bucket") bucket: String? = null,
    ): Response<ApiEnvelope<List<WalletTransactionDto>>>

    // ---------------------------------------------------------------- PIN
    @GET("${ApiClient.FREELANCE}wallet/pin")
    suspend fun pinStatus(): Response<ApiEnvelope<PinStatusDto>>

    /** A PIN can only be made after a way to get it back has been chosen (and, for e-mail, confirmed). */
    @POST("${ApiClient.FREELANCE}wallet/pin")
    suspend fun createPin(@Body body: CreatePinRequest): Response<ApiEnvelope<PinStatusDto>>

    @PUT("${ApiClient.FREELANCE}wallet/pin")
    suspend fun changePin(@Body body: ChangePinRequest): Response<ApiEnvelope<PinStatusDto>>

    /** Succeeds only if the PIN in `X-Wallet-Pin` is right — the unlock. `X-Device-Id` decides `device_trusted`. */
    @POST("${ApiClient.FREELANCE}wallet/pin/verify")
    suspend fun verifyPin(@Header("X-Wallet-Pin") pin: String): Response<ApiEnvelope<PinVerifyDto>>

    @POST("${ApiClient.FREELANCE}wallet/pin/recovery")
    suspend fun setRecovery(@Body body: RecoverySetupRequest, @Header("X-Wallet-Pin") pin: String? = null): Response<ApiEnvelope<PinStatusDto>>

    @POST("${ApiClient.FREELANCE}wallet/pin/recovery/email-code")
    suspend fun sendRecoveryEmailCode(@Query("pending") pending: Boolean? = null): Response<ApiEnvelope<Any>>

    @POST("${ApiClient.FREELANCE}wallet/pin/recovery/confirm-email")
    suspend fun confirmRecoveryEmail(@Body body: CodeBody): Response<ApiEnvelope<PinStatusDto>>

    /** "I forgot my PIN": proves the recovery secret and sets a new PIN in one call. */
    @POST("${ApiClient.FREELANCE}wallet/pin/recover")
    suspend fun recoverPin(@Body body: RecoverPinRequest): Response<ApiEnvelope<PinStatusDto>>

    /** A new device unlocked with the right PIN: a code goes to the phone on file before it is trusted. */
    @POST("${ApiClient.FREELANCE}wallet/device/verify-code")
    suspend fun sendDeviceCode(@Header("X-Wallet-Pin") pin: String): Response<ApiEnvelope<Any>>

    @POST("${ApiClient.FREELANCE}wallet/device/confirm")
    suspend fun confirmDevice(@Header("X-Wallet-Pin") pin: String, @Body body: CodeBody): Response<ApiEnvelope<Any>>

    // ---------------------------------------------------------------- topping up
    @GET("${ApiClient.FREELANCE}wallet/payment-methods")
    suspend fun paymentMethods(): Response<ApiEnvelope<List<PaymentMethodDto>>>

    @POST("${ApiClient.FREELANCE}wallet/topups/quote")
    suspend fun quote(@Body body: TopupRequest): Response<ApiEnvelope<TopupQuoteDto>>

    @POST("${ApiClient.FREELANCE}wallet/topups")
    suspend fun startTopup(
        @Header("X-Wallet-Pin") pin: String,
        @Header("Idempotency-Key") key: String,
        @Body body: TopupRequest,
    ): Response<ApiEnvelope<TopupPaymentDto>>

    @GET("${ApiClient.FREELANCE}wallet/topups/{uuid}")
    suspend fun topup(@Path("uuid") uuid: String): Response<ApiEnvelope<TopupPaymentDto>>

    /** A gateway that sends an SMS code: the code that confirms the payment. */
    @POST("${ApiClient.FREELANCE}wallet/topups/{uuid}/confirm")
    suspend fun confirmTopup(@Header("X-Wallet-Pin") pin: String, @Path("uuid") uuid: String, @Body body: OtpBody): Response<ApiEnvelope<TopupPaymentDto>>

    // ---------------------------------------------------------------- withdrawing
    @GET("${ApiClient.FREELANCE}wallet/withdrawal-methods")
    suspend fun withdrawalMethods(): Response<ApiEnvelope<List<WithdrawalMethodDto>>>

    @POST("${ApiClient.FREELANCE}wallet/withdrawal-methods")
    suspend fun addWithdrawalMethod(@Header("X-Wallet-Pin") pin: String, @Body body: JsonObject): Response<ApiEnvelope<WithdrawalMethodDto>>

    @PATCH("${ApiClient.FREELANCE}wallet/withdrawal-methods/{id}/favorite")
    suspend fun favoriteMethod(@Path("id") id: Int): Response<ApiEnvelope<WithdrawalMethodDto>>

    @DELETE("${ApiClient.FREELANCE}wallet/withdrawal-methods/{id}")
    suspend fun deleteWithdrawalMethod(@Path("id") id: Int): Response<ApiEnvelope<Any>>

    @GET("${ApiClient.FREELANCE}wallet/withdrawals")
    suspend fun withdrawals(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 25): Response<ApiEnvelope<List<WithdrawalDto>>>

    /** Holds the money; an admin pays it out and approves. */
    @POST("${ApiClient.FREELANCE}wallet/withdrawals")
    suspend fun withdraw(
        @Header("X-Wallet-Pin") pin: String,
        @Header("Idempotency-Key") key: String,
        @Body body: JsonObject,
    ): Response<ApiEnvelope<WithdrawalDto>>

    /** The transfer receipt, once approved — private, only its owner. */
    @Streaming
    @GET("${ApiClient.FREELANCE}wallet/withdrawals/{id}/receipt")
    suspend fun receipt(@Path("id") id: Int): Response<ResponseBody>
}

data class WalletBalanceDto(
    @SerializedName("country_code") val countryCode: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    /** Can be withdrawn to the owner's bank (the earnings and what was topped up). */
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long = 0,
    /** Bonuses: spendable on services, never withdrawable. */
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long = 0,
    /** Reserved by a pending request; not spendable until released. */
    @SerializedName("held_minor") val heldMinor: Long = 0,
    @SerializedName("wallet_number_formatted") val walletNumberFormatted: String? = null,
)

data class WalletTransactionDto(
    val uuid: String = "",
    val type: String = "",
    @SerializedName("type_label") val typeLabel: String = "",
    /** credit / debit */
    val direction: String = "credit",
    /** withdrawable / spend_only */
    val bucket: String = "withdrawable",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("balance_after_minor") val balanceAfterMinor: Long = 0,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class PaymentMethodDto(
    val id: Int = 0,
    val code: String = "",
    val gateway: String = "",
    val name: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    /** Listed, but the gateway has no credentials yet — shown as "coming soon" and not selectable. */
    @SerializedName("coming_soon") val comingSoon: Boolean = false,
)

data class PinStatusDto(
    @SerializedName("has_pin") val hasPin: Boolean = false,
    /** The PIN was reset to 0000 (approved recovery): it must be replaced before it can move money. */
    @SerializedName("must_change") val mustChange: Boolean = false,
    /** Permanently locked after wrong attempts — only a person's review lifts it. */
    @SerializedName("is_frozen") val isFrozen: Boolean = false,
    /** A temporary lock still running (ISO-8601). */
    @SerializedName("locked_until") val lockedUntil: String? = null,
    val recovery: RecoveryInfoDto? = null,
)

/** `method`: password, birth_date, id_photo, passport_photo, email. `ready`: an e-mail method is only ready once confirmed. */
data class RecoveryInfoDto(
    val method: String = "",
    val ready: Boolean = false,
    val email: String? = null,
    @SerializedName("pending_email") val pendingEmail: String? = null,
)

data class PinVerifyDto(
    val verified: Boolean = true,
    @SerializedName("must_change") val mustChange: Boolean = false,
    /** False the first time this phone ever unlocks this wallet. */
    @SerializedName("device_trusted") val deviceTrusted: Boolean = true,
)

data class CreatePinRequest(val pin: String, @SerializedName("pin_confirmation") val pinConfirmation: String)

data class ChangePinRequest(
    @SerializedName("current_pin") val currentPin: String,
    val pin: String,
    @SerializedName("pin_confirmation") val pinConfirmation: String,
)

data class RecoverySetupRequest(
    val method: String,
    val password: String? = null,
    @SerializedName("password_confirmation") val passwordConfirmation: String? = null,
    @SerializedName("birth_date") val birthDate: String? = null,
    val email: String? = null,
)

data class RecoverPinRequest(
    val password: String? = null,
    @SerializedName("birth_date") val birthDate: String? = null,
    val code: String? = null,
    val pin: String,
    @SerializedName("pin_confirmation") val pinConfirmation: String,
)

data class CodeBody(val code: String)

data class OtpBody(val otp: String)

data class TopupRequest(
    @SerializedName("payment_method_id") val paymentMethodId: Int,
    @SerializedName("amount_minor") val amountMinor: Long,
)

data class TopupQuoteDto(
    @SerializedName("paid_amount_minor") val paidAmountMinor: Long = 0,
    @SerializedName("fee_minor") val feeMinor: Long = 0,
    @SerializedName("bonus_minor") val bonusMinor: Long = 0,
    @SerializedName("withdrawable_minor") val withdrawableMinor: Long = 0,
    @SerializedName("spend_only_minor") val spendOnlyMinor: Long = 0,
    @SerializedName("total_credited_minor") val totalCreditedMinor: Long = 0,
)

data class TopupPaymentDto(
    val uuid: String = "",
    /** pending / paid / failed / expired / refunded */
    val status: String = "pending",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("fee_minor") val feeMinor: Long = 0,
    @SerializedName("bonus_minor") val bonusMinor: Long = 0,
    @SerializedName("redirect_url") val redirectUrl: String? = null,
    @SerializedName("requires_otp") val requiresOtp: Boolean = false,
)

data class WithdrawalMethodDto(
    val id: Int = 0,
    val type: String = "bank",
    val label: String? = null,
    val display: String? = null,
    @SerializedName("is_favorite") val isFavorite: Boolean = false,
)

data class WithdrawalMethodShortDto(val type: String = "bank", val label: String? = null, val display: String? = null)

data class WithdrawalDto(
    val id: Int = 0,
    /** pending / approved / rejected */
    val status: String = "pending",
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val method: WithdrawalMethodShortDto? = null,
    val note: String? = null,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    @SerializedName("has_receipt") val hasReceipt: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)
