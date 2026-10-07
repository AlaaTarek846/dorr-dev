package com.dorr.app.network

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.PUT
import retrofit2.http.Path

/**
 * The AI Assistant chat under /api/user/v1/ai-chat — Modules/AI, routes/user.php,
 * AiChatController. Same shape as ChatApi's own conventions (Bearer header on every
 * call, ApiEnvelope<T> wrapper) so the two chat systems read alike in the app even
 * though they are unrelated modules server-side.
 *
 * The streaming endpoint (messages/stream) is intentionally not used here: per
 * AiChatService::streamMessage()'s own docblock it just trickles the exact same,
 * already-complete answer out over SSE for a typing-animation effect — calling the
 * plain endpoint and animating the reveal client-side gets the identical result
 * without an SSE parser, and still lets an attachment go through the same call.
 */
interface AiChatApi {
    @GET("user/v1/ai-chat/status")
    suspend fun status(@Header("Authorization") auth: String): ApiEnvelope<AiStatusDto>

    @GET("user/v1/ai-chat/usage")
    suspend fun usage(@Header("Authorization") auth: String): ApiEnvelope<AiUsageDto>

    @GET("user/v1/ai-chat/conversations")
    suspend fun conversations(@Header("Authorization") auth: String): ApiEnvelope<List<AiConversationDto>>

    @POST("user/v1/ai-chat/conversations")
    suspend fun createConversation(@Header("Authorization") auth: String): ApiEnvelope<AiConversationDto>

    @GET("user/v1/ai-chat/conversations/{id}")
    suspend fun conversation(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiConversationDto>

    @DELETE("user/v1/ai-chat/conversations/{id}")
    suspend fun deleteConversation(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Any?>

    // Phase 11 (doc S10/S27): the "files available to this conversation"
    // list - Modules/AI's AiConversationFileController. Distinct from
    // the single quick `attachment` on sendMessage() below (doc S11).
    @GET("user/v1/ai-chat/conversations/{id}/files")
    suspend fun conversationFiles(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<AiFileDto>>

    @FormUrlEncoded
    @POST("user/v1/ai-chat/conversations/{id}/files")
    suspend fun attachConversationFile(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Field("file_id") fileId: Int,
    ): ApiEnvelope<AiFileDto>

    @DELETE("user/v1/ai-chat/conversations/{id}/files/{fileId}")
    suspend fun detachConversationFile(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Path("fileId") fileId: Int,
    ): ApiEnvelope<Any?>

    // Phase 11 (doc S5/S6): standalone upload used for the multi-file
    // "attach to this conversation" flow - Modules/AI's
    // AiFileUploadController. Uploading with conversation_id already
    // attaches the file server-side (AiFileEngine::attachToConversation(),
    // Phase 10) - no separate attach call needed after this succeeds.
    @Multipart
    @POST("user/v1/ai-files")
    suspend fun uploadConversationFile(
        @Header("Authorization") auth: String,
        @Part file: MultipartBody.Part,
        @Part("conversation_id") conversationId: RequestBody,
    ): ApiEnvelope<AiFileDto>

    @GET("user/v1/ai-files/{id}/status")
    suspend fun fileStatus(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiFileDto>

    /**
     * Always multipart: `attachment` is simply omitted when there is no file. A real
     * provider call happens behind this (billed, capped by the chat send rate limiter),
     * so a fresh Idempotency-Key per user tap lets a dropped/retried request replay the
     * one persisted answer instead of risking a second AI call and a duplicate reply.
     */
    @Multipart
    @POST("user/v1/ai-chat/conversations/{id}/messages")
    suspend fun sendMessage(
        @Header("Authorization") auth: String,
        @Header("Idempotency-Key") idempotencyKey: String,
        @Path("id") id: Int,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part attachment: MultipartBody.Part?,
    ): ApiEnvelope<AiSendMessageResultDto>

    /**
     * Phase 7 (realtime voice): mints a short-lived Realtime session
     * credential — see AiRealtimeService/OpenAiConnector::createRealtimeSession()
     * server-side. This call never carries or returns the real provider API
     * key; the returned client_secret is itself short-lived and is used ONLY
     * to open a WebRTC connection straight to OpenAI's own webrtc_endpoint,
     * never sent back to this backend.
     */
    @POST("user/v1/ai-realtime/session")
    suspend fun createRealtimeSession(
        @Header("Authorization") auth: String,
        @Body body: AiRealtimeSessionRequestDto,
    ): ApiEnvelope<AiRealtimeSessionDto>

    @POST("user/v1/ai-realtime/session/{id}/end")
    suspend fun endRealtimeSession(
        @Header("Authorization") auth: String,
        @Path("id") id: Int,
        @Body body: AiRealtimeSessionEndRequestDto,
    ): ApiEnvelope<Any?>

    // Professional AI subscription system linked to the wallet (2026-09-29):
    // Modules/AI's routes/user.php prefix('user/v1/ai-subscription'). subscribe()
    // and changePlan() carry X-Wallet-Pin exactly like WalletApi.transfer() does -
    // both move real money and the backend requires it (RequiresWalletPin).

    @GET("user/v1/ai-subscription/plans")
    suspend fun subscriptionPlans(@Header("Authorization") auth: String): ApiEnvelope<List<AiPlanDto>>

    @GET("user/v1/ai-subscription")
    suspend fun currentSubscription(@Header("Authorization") auth: String): ApiEnvelope<AiSubscriptionDto?>

    @GET("user/v1/ai-subscription/payments")
    suspend fun subscriptionPayments(@Header("Authorization") auth: String): ApiEnvelope<List<AiSubscriptionPaymentDto>>

    @PUT("user/v1/ai-subscription/auto-renew")
    suspend fun updateAutoRenew(
        @Header("Authorization") auth: String,
        @Body body: AiAutoRenewRequestDto,
    ): ApiEnvelope<AiSubscriptionDto>

    @POST("user/v1/ai-subscription/subscribe")
    suspend fun subscribeToPlan(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Body body: AiSubscribeRequestDto,
    ): ApiEnvelope<AiSubscriptionDto>

    @POST("user/v1/ai-subscription/change-plan")
    suspend fun changeSubscriptionPlan(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Body body: AiChangePlanRequestDto,
    ): ApiEnvelope<AiSubscriptionDto>

    // Business gap fix (2026-10-03): the user-facing counterpart of the
    // admin-only admin/v1/ai-user-language-preferences screen, and the only
    // place a user can actually control the AI Assistant's reply
    // language/dialect from the mobile app (AiChatLanguageResolver already
    // read this row on every chat reply/voice transcript/realtime call -
    // there was simply no screen to change it from, until this one).
    @GET("user/v1/ai-language-preference")
    suspend fun languagePreference(@Header("Authorization") auth: String): ApiEnvelope<AiLanguagePreferenceShowDto>

    @PUT("user/v1/ai-language-preference")
    suspend fun updateLanguagePreference(
        @Header("Authorization") auth: String,
        @Body body: AiLanguagePreferenceUpdateRequestDto,
    ): ApiEnvelope<AiUserLanguagePreferenceDto>
}
