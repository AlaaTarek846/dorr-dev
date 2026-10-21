package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Streaming

/** Verifying the account by hand (FL-107 / FL-108 / FL-110), under `/api/freelance/v1/verification`. */
interface VerificationApi {
    @GET("${ApiClient.FREELANCE}verification")
    suspend fun overview(): Response<ApiEnvelope<VerificationOverviewDto>>

    /** The text fields in [fields], the files as [front] and [back]; a new upload of a type replaces its draft. */
    @Multipart
    @POST("${ApiClient.FREELANCE}verification/documents")
    suspend fun upload(
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part front: MultipartBody.Part,
        @Part back: MultipartBody.Part?,
    ): Response<ApiEnvelope<VerificationDocDto>>

    /** A draft only. */
    @DELETE("${ApiClient.FREELANCE}verification/documents/{id}")
    suspend fun deleteDocument(@Path("id") id: Int): Response<ApiEnvelope<Any>>

    /** Sends the uploaded documents for review. */
    @POST("${ApiClient.FREELANCE}verification/submit")
    suspend fun submit(): Response<ApiEnvelope<VerificationOverviewDto>>

    /** My own file (front or back): private, signed in. */
    @Streaming
    @GET("${ApiClient.FREELANCE}verification/documents/{id}/{side}")
    suspend fun file(@Path("id") id: Int, @Path("side") side: String): Response<ResponseBody>
}

data class VerificationOverviewDto(
    val status: String? = null,
    @SerializedName("can_apply") val canApply: Boolean = false,
    val step: String? = null,
    @SerializedName("needs_residency") val needsResidency: Boolean = false,
    val required: List<RequiredDocDto> = emptyList(),
    val others: List<VerificationDocDto> = emptyList(),
    @SerializedName("can_submit") val canSubmit: Boolean = false,
    @SerializedName("submit_blockers") val submitBlockers: List<String> = emptyList(),
    @SerializedName("last_request") val lastRequest: LastRequestDto? = null,
)

data class RequiredDocDto(
    val type: DocTypeDto,
    val document: VerificationDocDto? = null,
    val satisfied: Boolean = false,
)

data class DocTypeDto(
    val id: Int,
    val code: String = "",
    val name: String = "",
    val instructions: String? = null,
    @SerializedName("has_number") val hasNumber: Boolean = false,
    @SerializedName("has_expiry") val hasExpiry: Boolean = false,
    @SerializedName("needs_back_side") val needsBackSide: Boolean = false,
)

data class VerificationDocDto(
    val id: Int,
    val type: DocTypeRefDto? = null,
    val number: String? = null,
    @SerializedName("expires_on") val expiresOn: String? = null,
    @SerializedName("is_expired") val isExpired: Boolean = false,
    /** draft · pending · approved · rejected · expired · replaced */
    val status: String = "draft",
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    val files: Map<String, DocFileDto?> = emptyMap(),
)

data class DocTypeRefDto(val id: Int = 0, val code: String = "", val name: String = "")

data class DocFileDto(val name: String? = null, val mime: String? = null, val size: Long? = null)

data class LastRequestDto(
    val uuid: String? = null,
    /** submitted · approved · rejected · changes_requested (as the server names them) */
    val status: String? = null,
    val reason: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    @SerializedName("decided_at") val decidedAt: String? = null,
)
