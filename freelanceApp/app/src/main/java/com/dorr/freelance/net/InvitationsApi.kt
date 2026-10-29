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

data class InvitationRefDto(val uuid: String = "", val status: String = "sent")

data class InvitationDto(
    val uuid: String = "",
    val status: String = "sent",
    /** The project can still take offers. */
    val open: Boolean = false,
    val project: ProjectDto? = null,
    val offered: Boolean = false,
    val can: InvitationCanDto = InvitationCanDto(),
)

data class InvitationCanDto(val accept: Boolean = false, val decline: Boolean = false, val offer: Boolean = false)
