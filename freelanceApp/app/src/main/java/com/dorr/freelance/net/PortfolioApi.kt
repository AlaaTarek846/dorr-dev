package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path

/** The freelancer's portfolio (FL-105), under `/api/freelance/v1/profile/portfolio`. */
interface PortfolioApi {
    @GET("${ApiClient.FREELANCE}profile/portfolio")
    suspend fun list(): Response<ApiEnvelope<List<PortfolioItemDto>>>

    @POST("${ApiClient.FREELANCE}profile/portfolio")
    suspend fun create(@Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<PortfolioItemDto>>

    @PUT("${ApiClient.FREELANCE}profile/portfolio/{id}")
    suspend fun update(@Path("id") id: Int, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<PortfolioItemDto>>

    @DELETE("${ApiClient.FREELANCE}profile/portfolio/{id}")
    suspend fun delete(@Path("id") id: Int): Response<ApiEnvelope<Any>>

    /** The new order: every work of mine once. */
    @PUT("${ApiClient.FREELANCE}profile/portfolio/order")
    suspend fun order(@Body body: PortfolioOrderRequest): Response<ApiEnvelope<List<PortfolioItemDto>>>

    @Multipart
    @POST("${ApiClient.FREELANCE}profile/portfolio/{id}/images")
    suspend fun addImage(@Path("id") id: Int, @Part image: MultipartBody.Part): Response<ApiEnvelope<PortfolioItemDto>>

    @DELETE("${ApiClient.FREELANCE}profile/portfolio/{id}/images/{media}")
    suspend fun deleteImage(@Path("id") id: Int, @Path("media") media: Int): Response<ApiEnvelope<PortfolioItemDto>>
}

data class PortfolioOrderRequest(val ids: List<Int>)

data class PortfolioItemDto(
    val id: Int = 0,
    val uuid: String? = null,
    val title: String = "",
    val description: String? = null,
    @SerializedName("completed_on") val completedOn: String? = null,
    val specialty: PortfolioRefDto? = null,
    val skills: List<PortfolioRefDto> = emptyList(),
    val images: List<PortfolioImageDto> = emptyList(),
    @SerializedName("external_url") val externalUrl: String? = null,
    val video: PortfolioVideoDto? = null,
    /** private · clients_only · public */
    val visibility: String = "private",
    @SerializedName("rights_confirmed") val rightsConfirmed: Boolean = false,
    val hidden: Boolean = false,
    @SerializedName("hidden_reason") val hiddenReason: String? = null,
)

data class PortfolioRefDto(val id: Int = 0, val name: String = "")

data class PortfolioImageDto(
    val id: Int = 0,
    val name: String? = null,
    val size: Long? = null,
    /** My own image is read signed in from this path (no public link). */
    val path: String? = null,
)

data class PortfolioVideoDto(val provider: String? = null, @SerializedName("embed_url") val embedUrl: String? = null)
