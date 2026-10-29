package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.POST
import retrofit2.http.Path

/** The freelancer's own account (FL-003): deleting it. */
interface AccountApi {
    /** Deletes the account (and its works and files); the caller must confirm. */
    @HTTP(method = "DELETE", path = "${ApiClient.FREELANCE}account", hasBody = true)
    suspend fun delete(@Body body: ConfirmRequest = ConfirmRequest()): Response<ApiEnvelope<Any>>

    /** FL-708: my warnings and restrictions, and appealing one (once, within the days). */
    @GET("${ApiClient.FREELANCE}sanctions")
    suspend fun sanctions(): Response<ApiEnvelope<SanctionsDto>>

    @POST("${ApiClient.FREELANCE}sanctions/{uuid}/appeal")
    suspend fun appealSanction(@Path("uuid") uuid: String, @Body body: SanctionAppealRequest): Response<ApiEnvelope<SanctionsDto>>
}

data class SanctionsDto(val items: List<SanctionDto>? = null)

data class SanctionDto(
    val uuid: String = "",
    val type: String = "warning",
    val state: String = "active",
    val reason: String? = null,
    val blocks: List<String>? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("can_appeal") val canAppeal: Boolean? = null,
    @SerializedName("appeal_until") val appealUntil: String? = null,
    val appeal: SanctionAppealDto? = null,
)

data class SanctionAppealDto(val status: String = "open", val note: String? = null, @SerializedName("decision_note") val decisionNote: String? = null)

data class SanctionAppealRequest(val note: String)

data class ConfirmRequest(val confirm: Boolean = true)
