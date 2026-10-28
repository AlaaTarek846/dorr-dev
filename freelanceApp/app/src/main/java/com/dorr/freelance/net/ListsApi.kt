package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/** The freelancer's specialties, skills, work history and certificates (FL-104), under `/api/freelance/v1`. */
interface ListsApi {
    // The catalogs the freelancer picks from.
    @GET("${ApiClient.FREELANCE}specialties")
    suspend fun specialtyTree(): Response<ApiEnvelope<List<SpecialtyNodeDto>>>

    @GET("${ApiClient.FREELANCE}skills")
    suspend fun skillCatalog(@Query("q") q: String?, @Query("per_page") perPage: Int = 30): Response<ApiEnvelope<List<CatalogSkillDto>>>

    @POST("${ApiClient.FREELANCE}skills/suggest")
    suspend fun suggestSkill(@Body body: SuggestSkillRequest): Response<ApiEnvelope<CatalogSkillDto>>

    // Mine.
    @GET("${ApiClient.FREELANCE}profile/specialties")
    suspend fun mySpecialties(): Response<ApiEnvelope<List<MySpecialtyDto>>>

    @PUT("${ApiClient.FREELANCE}profile/specialties")
    suspend fun setSpecialties(@Body body: SetSpecialtiesRequest): Response<ApiEnvelope<List<MySpecialtyDto>>>

    @GET("${ApiClient.FREELANCE}profile/skills")
    suspend fun mySkills(): Response<ApiEnvelope<List<MySkillDto>>>

    @PUT("${ApiClient.FREELANCE}profile/skills")
    suspend fun setSkills(@Body body: SetSkillsRequest): Response<ApiEnvelope<List<MySkillDto>>>

    @GET("${ApiClient.FREELANCE}profile/experiences")
    suspend fun experiences(): Response<ApiEnvelope<List<ExperienceDto>>>

    @POST("${ApiClient.FREELANCE}profile/experiences")
    suspend fun addExperience(@Body body: Map<String, @JvmSuppressWildcards Any>): Response<ApiEnvelope<ExperienceDto>>

    @PUT("${ApiClient.FREELANCE}profile/experiences/{id}")
    suspend fun updateExperience(@Path("id") id: Int, @Body body: Map<String, @JvmSuppressWildcards Any>): Response<ApiEnvelope<ExperienceDto>>

    @DELETE("${ApiClient.FREELANCE}profile/experiences/{id}")
    suspend fun deleteExperience(@Path("id") id: Int): Response<ApiEnvelope<Any>>

    @GET("${ApiClient.FREELANCE}profile/certificates")
    suspend fun certificates(): Response<ApiEnvelope<List<CertificateDto>>>

    /** Multipart: the text fields as [fields], the proof file (optional) as [proof]. */
    @Multipart
    @POST("${ApiClient.FREELANCE}profile/certificates")
    suspend fun addCertificate(@PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part proof: MultipartBody.Part?): Response<ApiEnvelope<CertificateDto>>

    /** Edited with POST so a multipart body works. */
    @Multipart
    @POST("${ApiClient.FREELANCE}profile/certificates/{id}")
    suspend fun updateCertificate(@Path("id") id: Int, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part proof: MultipartBody.Part?): Response<ApiEnvelope<CertificateDto>>

    @DELETE("${ApiClient.FREELANCE}profile/certificates/{id}")
    suspend fun deleteCertificate(@Path("id") id: Int): Response<ApiEnvelope<Any>>

    /** The proof file: private, only its owner, signed in. */
    @Streaming
    @GET("${ApiClient.FREELANCE}profile/certificates/{id}/proof")
    suspend fun certificateProof(@Path("id") id: Int): Response<ResponseBody>
}

data class SpecialtyNodeDto(
    val id: Int,
    val name: String = "",
    val description: String? = null,
    val children: List<SpecialtyNodeDto> = emptyList(),
)

data class MySpecialtyDto(
    val id: Int,
    val name: String = "",
    val parent: String? = null,
    @SerializedName("is_primary") val isPrimary: Boolean = false,
    @SerializedName("is_active") val isActive: Boolean = true,
)

data class SetSpecialtiesRequest(
    @SerializedName("specialty_ids") val specialtyIds: List<Int>,
    @SerializedName("primary_id") val primaryId: Int,
)

data class CatalogSkillDto(
    val id: Int,
    val name: String = "",
    val pending: Boolean = false,
    val created: Boolean? = null,
)

data class SuggestSkillRequest(val name: String, val locale: String? = null)

data class MySkillDto(
    val id: Int,
    val name: String = "",
    val level: String? = null,
    val pending: Boolean = false,
)

data class SkillRow(@SerializedName("skill_id") val skillId: Int, val level: String? = null)

data class SetSkillsRequest(val skills: List<SkillRow>)

data class ExperienceDto(
    val id: Int,
    val title: String = "",
    val company: String? = null,
    @SerializedName("started_on") val startedOn: String? = null,
    @SerializedName("ended_on") val endedOn: String? = null,
    @SerializedName("is_current") val isCurrent: Boolean = false,
    val description: String? = null,
)

data class CertificateDto(
    val id: Int,
    val title: String = "",
    val issuer: String = "",
    @SerializedName("issued_on") val issuedOn: String? = null,
    @SerializedName("expires_on") val expiresOn: String? = null,
    @SerializedName("is_expired") val isExpired: Boolean = false,
    @SerializedName("credential_url") val credentialUrl: String? = null,
    val proof: ProofDto? = null,
)

data class ProofDto(val name: String? = null, val mime: String? = null, val size: Long? = null)
