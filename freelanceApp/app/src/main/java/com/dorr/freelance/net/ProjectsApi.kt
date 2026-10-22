package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Browsing the clients' projects (FL-206 / FL-207), under `/api/freelance/v1/projects`: the published public projects of the
 * freelancer's own country, one project's page, and the ones saved. The client and the files are never part of the answer.
 */
interface ProjectsApi {
    @GET("${ApiClient.FREELANCE}projects")
    suspend fun list(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
        @Query("q") q: String? = null,
        @Query("specialty_id") specialtyId: Int? = null,
        @Query("budget_min_minor") budgetMinMinor: Long? = null,
        @Query("budget_max_minor") budgetMaxMinor: Long? = null,
        @Query("duration_max_days") durationMaxDays: Int? = null,
        @Query("favorites") favorites: Int? = null,
        /** `mine` = my specialties (and their sub-specialties), `all`; left out, the server picks mine when I have any. */
        @Query("scope") scope: String? = null,
    ): Response<ApiEnvelope<List<ProjectDto>>>

    @GET("${ApiClient.FREELANCE}projects/{uuid}")
    suspend fun show(@Path("uuid") uuid: String): Response<ApiEnvelope<ProjectDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/favorite")
    suspend fun favorite(@Path("uuid") uuid: String): Response<ApiEnvelope<ProjectDto>>

    @DELETE("${ApiClient.FREELANCE}projects/{uuid}/favorite")
    suspend fun unfavorite(@Path("uuid") uuid: String): Response<ApiEnvelope<ProjectDto>>
}

data class ProjectDto(
    val uuid: String,
    val title: String? = null,
    val specialty: NamedDto? = null,
    val skills: List<NamedDto> = emptyList(),
    val budget: ProjectBudgetDto = ProjectBudgetDto(),
    @SerializedName("duration_days") val durationDays: Int? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    /** published, or awarded once the client chose an offer (FL-309). */
    val status: String = "published",
    @SerializedName("is_favorite") val isFavorite: Boolean = false,
    /** The list shows a short text … */
    val summary: String? = null,
    /** … the page shows all of it. */
    val description: String? = null,
    @SerializedName("attachments_count") val attachmentsCount: Int = 0,
    val offers: ProjectOffersDto = ProjectOffersDto(),
    /** I already sent an offer on it (FL-301). */
    val offered: Boolean = false,
)

/** Whether offers can still be made on this project (FL-204). */
data class ProjectOffersDto(
    val accepting: Boolean = true,
    @SerializedName("deadline_at") val deadlineAt: String? = null,
    @SerializedName("close_reason") val closeReason: String? = null,
)

data class NamedDto(val id: Int, val name: String = "")

data class ProjectBudgetDto(
    val type: String = "fixed",
    @SerializedName("min_minor") val minMinor: Long? = null,
    @SerializedName("max_minor") val maxMinor: Long? = null,
    val currency: String = "",
)
