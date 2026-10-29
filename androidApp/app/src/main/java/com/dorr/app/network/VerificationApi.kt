package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path

/**
 * Account verification under /api/mobile/v1/chat/verification (docs/verification_tasks.md, batch 1):
 * which tracks are open here and what they ask for, the account's request — send it with its
 * documents, follow it, send what was asked for, cancel it. Changes arrive live as
 * `chat.verification.updated` on the account's channel ([com.dorr.app.chat.ChatRealtime]); the screen
 * reloads once on that event and when it opens — it never asks on a timer.
 */
interface VerificationApi {
    @GET("mobile/v1/chat/verification/options")
    suspend fun options(@Header("Authorization") auth: String): ApiEnvelope<VerificationOptionsDto>

    /** My badge (status, type, since, until); `data` is null when never verified. */
    @GET("mobile/v1/chat/verification/badge")
    suspend fun badge(@Header("Authorization") auth: String): ApiEnvelope<VerificationBadgeDto>

    /** The latest request (open or closed); `data` is null when there is none. */
    @GET("mobile/v1/chat/verification/request")
    suspend fun current(@Header("Authorization") auth: String): ApiEnvelope<VerificationRequestDto>

    /** Fields: track, full_name, contact_phone, contact_email, notes. Files: `documents[{typeId}][front|back]`. */
    @Multipart
    @POST("mobile/v1/chat/verification/request")
    suspend fun submit(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<VerificationRequestDto>

    /** Fields: note (and contact changes). Files: the documents asked for again. */
    @Multipart
    @POST("mobile/v1/chat/verification/request/{uuid}/complete")
    suspend fun complete(
        @Header("Authorization") auth: String,
        @Path("uuid") uuid: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<VerificationRequestDto>

    @POST("mobile/v1/chat/verification/request/{uuid}/cancel")
    suspend fun cancel(
        @Header("Authorization") auth: String,
        @Path("uuid") uuid: String,
        @Body body: Map<String, String?> = emptyMap(),
    ): ApiEnvelope<VerificationRequestDto>
}

data class VerificationBadgeDto(
    val status: String,
    val type: String = "standard",
    @SerializedName("is_verified") val isVerified: Boolean = false,
    @SerializedName("active_from") val activeFrom: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    val reason: String? = null,
)

data class VerificationOptionsDto(val tracks: List<VerificationTrackDto> = emptyList())

data class VerificationTrackDto(
    val track: String,
    val available: Boolean = false,
    val documents: List<VerificationDocumentTypeDto> = emptyList(),
)

data class VerificationDocumentTypeDto(
    val id: Int,
    val code: String,
    val name: String? = null,
    val instructions: String? = null,
    @SerializedName("is_required") val isRequired: Boolean = true,
    @SerializedName("needs_back_side") val needsBackSide: Boolean = false,
)

data class VerificationRequestDto(
    val uuid: String,
    val track: String,
    val status: String,
    @SerializedName("is_open") val isOpen: Boolean = false,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("contact_phone") val contactPhone: String? = null,
    @SerializedName("contact_email") val contactEmail: String? = null,
    @SerializedName("deadline_at") val deadlineAt: String? = null,
    @SerializedName("deadline_owner") val deadlineOwner: String? = null,
    @SerializedName("deadline_paused") val deadlinePaused: Boolean = false,
    @SerializedName("identity_checked") val identityChecked: Boolean = false,
    @SerializedName("completion_reason") val completionReason: String? = null,
    @SerializedName("completion_document_type_ids") val completionDocumentTypeIds: List<Int> = emptyList(),
    @SerializedName("decision_reason") val decisionReason: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    @SerializedName("next_step") val nextStep: String? = null,
    @SerializedName("can_cancel") val canCancel: Boolean = false,
    @SerializedName("can_complete") val canComplete: Boolean = false,
    val documents: List<VerificationDocumentDto> = emptyList(),
    val history: List<VerificationHistoryDto> = emptyList(),
)

data class VerificationDocumentDto(
    val id: Int,
    val type: VerificationDocumentTypeRefDto? = null,
    val status: String,
    @SerializedName("review_note") val reviewNote: String? = null,
)

data class VerificationDocumentTypeRefDto(val id: Int, val code: String? = null, val name: String? = null)

data class VerificationHistoryDto(val status: String, val by: String? = null, val at: String? = null)
