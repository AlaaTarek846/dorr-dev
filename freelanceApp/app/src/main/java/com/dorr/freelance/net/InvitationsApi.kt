package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import retrofit2.Response
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** FL-205: the invitations clients sent me to their private projects, and my yes or no (a verified account is needed to answer). */
interface InvitationsApi {
    @GET("${ApiClient.FREELANCE}invitations")
    suspend fun list(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 15, @Query("status") status: String? = null): Response<ApiEnvelope<List<InvitationDto>>>

    @POST("${ApiClient.FREELANCE}invitations/{uuid}/accept")
    suspend fun accept(@Path("uuid") uuid: String): Response<ApiEnvelope<InvitationRefDto>>

    @POST("${ApiClient.FREELANCE}invitations/{uuid}/decline")
    suspend fun decline(@Path("uuid") uuid: String): Response<ApiEnvelope<InvitationRefDto>>
}

/** [team]: FS-213 - the client invited my team (I offer in its name). */
data class InvitationRefDto(val uuid: String = "", val status: String = "sent", val team: InvitationTeamDto? = null)

/** FS-213: the team a client invited through me (its Owner). */
data class InvitationTeamDto(val uuid: String = "", val name: String = "", @com.google.gson.annotations.SerializedName("logo_url") val logoUrl: String? = null)

data class InvitationDto(
    val uuid: String = "",
    val status: String = "sent",
    /** The project can still take offers. */
    val open: Boolean = false,
    val project: ProjectDto? = null,
    val offered: Boolean = false,
    val can: InvitationCanDto = InvitationCanDto(),
    /** FS-213: the client invited my team, not me alone. */
    val team: InvitationTeamDto? = null,
)

data class InvitationCanDto(val accept: Boolean = false, val decline: Boolean = false, val offer: Boolean = false)
