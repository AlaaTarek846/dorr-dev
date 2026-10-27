package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.Multipart
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * DORR AI WORLD under /api/user/v1/ai-world - Modules/AI, AiWorldController + AiWorldProjectController.
 * Confirming a project and regenerating a scene move wallet money, so they carry X-Wallet-Pin.
 * Money is in minor units (1000 = 10.00) of [currency].
 */
interface AiWorldApi {
    @GET("user/v1/ai-world/info")
    suspend fun info(@Header("Authorization") auth: String): ApiEnvelope<AiWorldInfoDto>

    @GET("user/v1/ai-world/locations/search")
    suspend fun searchLocations(@Header("Authorization") auth: String, @Query("q") query: String): ApiEnvelope<List<AiWorldLocationDto>>

    /** The Director: proposes scenes and a price. Charges nothing, creates nothing. */
    @POST("user/v1/ai-world/projects/plan")
    suspend fun plan(@Header("Authorization") auth: String, @Body body: AiWorldPlanRequest): ApiEnvelope<AiWorldPlanDto>

    @GET("user/v1/ai-world/projects")
    suspend fun projects(@Header("Authorization") auth: String): ApiEnvelope<List<AiWorldProjectDto>>

    @GET("user/v1/ai-world/projects/{id}")
    suspend fun project(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiWorldProjectDto>

    @POST("user/v1/ai-world/projects")
    suspend fun create(@Header("Authorization") auth: String, @Body body: AiWorldCreateRequest): ApiEnvelope<AiWorldProjectDto>

    @GET("user/v1/ai-world/consent-text")
    suspend fun consentText(@Header("Authorization") auth: String): ApiEnvelope<AiWorldConsentTextDto>

    @GET("user/v1/ai-world/identity-profiles")
    suspend fun profiles(@Header("Authorization") auth: String): ApiEnvelope<List<AiWorldProfileDto>>

    @Multipart
    @POST("user/v1/ai-world/identity-profiles")
    suspend fun createProfile(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part photos: List<MultipartBody.Part>,
    ): ApiEnvelope<AiWorldProfileDto>

    @POST("user/v1/ai-world/identity-profiles/{id}/consent")
    suspend fun consent(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiWorldConsentRequest): ApiEnvelope<AiWorldProfileDto>

    @DELETE("user/v1/ai-world/identity-profiles/{id}")
    suspend fun deleteProfile(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Unit?>

    @DELETE("user/v1/ai-world/projects/{id}")
    suspend fun deleteProject(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Unit?>

    @POST("user/v1/ai-world/projects/{id}/report")
    suspend fun report(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiWorldReportRequest): ApiEnvelope<Unit?>

    @POST("user/v1/ai-world/projects/{id}/confirm")
    suspend fun confirm(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: Int): ApiEnvelope<AiWorldProjectDto>

    @POST("user/v1/ai-world/projects/{id}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiWorldProjectDto>

    @POST("user/v1/ai-world/projects/{id}/scenes/{sceneId}/approve")
    suspend fun approve(@Header("Authorization") auth: String, @Path("id") id: Int, @Path("sceneId") sceneId: Int): ApiEnvelope<AiWorldProjectDto>

    @POST("user/v1/ai-world/projects/{id}/scenes/{sceneId}/regenerate")
    suspend fun regenerate(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: Int, @Path("sceneId") sceneId: Int): ApiEnvelope<AiWorldProjectDto>
}

data class AiWorldInfoDto(
    val enabled: Boolean = false,
    val modes: Map<String, Boolean> = emptyMap(),
    val limits: AiWorldLimitsDto? = null,
)

data class AiWorldLimitsDto(
    @SerializedName("max_scenes") val maxScenes: Int = 5,
    @SerializedName("scene_seconds") val sceneSeconds: List<Int> = listOf(4, 6, 8),
)

data class AiWorldFidelityDto(val tier: String = "", val label: String = "", val description: String = "")

data class AiWorldLocationDto(
    val id: Int,
    val name: String,
    val city: String? = null,
    val address: String? = null,
    val fidelity: AiWorldFidelityDto? = null,
)

data class AiWorldPlanRequest(
    val brief: String,
    @SerializedName("location_ids") val locationIds: List<Int>,
    val quality: String = "standard",
)

data class AiWorldSceneDraftDto(
    val prompt: String,
    val seconds: Int,
    @SerializedName("location_id") val locationId: Int? = null,
    @SerializedName("location_name") val locationName: String? = null,
)

data class AiWorldPlanDto(
    val scenes: List<AiWorldSceneDraftDto> = emptyList(),
    @SerializedName("total_seconds") val totalSeconds: Int = 0,
    @SerializedName("estimate_minor") val estimateMinor: Long? = null,
    val currency: String? = null,
    val source: String = "ai",
)

data class AiWorldCreateRequest(
    val title: String? = null,
    val brief: String? = null,
    val quality: String = "standard",
    @SerializedName("identity_profile_id") val identityProfileId: Int? = null,
    val scenes: List<AiWorldSceneInput>,
)

data class AiWorldSceneInput(
    val prompt: String,
    val seconds: Int,
    @SerializedName("location_id") val locationId: Int? = null,
)

data class AiWorldSceneDto(
    val id: Int,
    val position: Int,
    val prompt: String,
    val seconds: Int,
    val status: String,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
)

data class AiWorldProjectDto(
    val id: Int,
    val title: String? = null,
    val status: String,
    @SerializedName("total_seconds") val totalSeconds: Int = 0,
    @SerializedName("estimated_minor") val estimatedMinor: Long = 0,
    @SerializedName("charged_minor") val chargedMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
    val currency: String? = null,
    @SerializedName("has_final_video") val hasFinalVideo: Boolean = false,
    val scenes: List<AiWorldSceneDto> = emptyList(),
)

data class AiWorldConsentTextDto(
    val version: String = "1",
    val text: String = "",
    val attestations: Map<String, String> = emptyMap(),
)

data class AiWorldProfileDto(
    val id: Int,
    val label: String,
    @SerializedName("subject_type") val subjectType: String,
    val status: String,
    val photos: Int = 0,
)

data class AiWorldConsentRequest(val attestation: String)

data class AiWorldReportRequest(val type: String, val notes: String? = null)
