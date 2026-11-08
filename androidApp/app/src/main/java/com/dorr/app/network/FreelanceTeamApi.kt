package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FS-104, a project's team (docs/modules/freelance/PERMISSIONS.md): the owner brings Dorr users into one of his projects, each with one role
 * (manager · reviewer · collaborator · finance · delegate); the person invited answers; the projects I was brought into. A member opens the
 * owner's project pages as usual — the server hides what his role may not see or do. Read when a screen opens and after each action.
 */
interface FreelanceTeamApi {
    @GET("mobile/v1/freelance/projects/{uuid}/team")
    suspend fun team(@Header("Authorization") auth: String, @Path("uuid") project: String): ApiEnvelope<TeamPageDto>

    @POST("mobile/v1/freelance/projects/{uuid}/team")
    suspend fun invite(@Header("Authorization") auth: String, @Path("uuid") project: String, @Body body: TeamInviteRequest): ApiEnvelope<TeamPageDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/team/{member}")
    suspend fun update(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("member") member: String, @Body body: TeamRoleRequest): ApiEnvelope<TeamPageDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/team/{member}")
    suspend fun remove(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("member") member: String): ApiEnvelope<TeamPageDto>

    @GET("mobile/v1/freelance/team/invitations")
    suspend fun invitations(@Header("Authorization") auth: String): ApiEnvelope<TeamInvitationsDto>

    /** [answer] = accept · decline. */
    @POST("mobile/v1/freelance/team/invitations/{uuid}/{answer}")
    suspend fun respond(@Header("Authorization") auth: String, @Path("uuid") invitation: String, @Path("answer") answer: String): ApiEnvelope<TeamAnswerDto>

    /** The projects I am in, each with my [FreelanceProjectDto.access]. */
    @GET("mobile/v1/freelance/team/projects")
    suspend fun shared(@Header("Authorization") auth: String, @Query("page") page: Int = 1, @Query("per_page") perPage: Int = 30): ApiEnvelope<List<FreelanceProjectDto>>

    @POST("mobile/v1/freelance/team/projects/{uuid}/leave")
    suspend fun leave(@Header("Authorization") auth: String, @Path("uuid") project: String): ApiEnvelope<Any?>
}

/** Who I am on a project page: `owner`, or my role on the owner's team, with what that role may do. */
data class FreelanceAccessDto(
    val role: String = "owner",
    val abilities: List<String> = emptyList(),
    val money: Boolean = true,
    val owner: FreelanceAccessOwnerDto? = null,
    @SerializedName("max_amount_minor") val maxAmountMinor: Long? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("delegation_over") val delegationOver: Boolean = false,
) {
    val isOwner: Boolean get() = role == "owner"
    fun can(ability: String): Boolean = isOwner || ability in abilities
}

data class FreelanceAccessOwnerDto(val name: String = "")

/** The owner's team at a glance (on his project page). */
data class FreelanceTeamSummaryDto(val members: Int = 0, val invited: Int = 0, val max: Int = 10)

/** A step a member of the client's team took for the owner (the record names him, nothing else). */
data class FreelanceTrailByDto(val name: String = "", val team: Boolean = true)

data class FlHomeTeamDto(val shared: Int = 0, val invitations: Int = 0)

data class TeamPageDto(
    val members: List<TeamMemberDto> = emptyList(),
    val max: Int = 10,
    val roles: List<String> = emptyList(),
    @SerializedName("max_delegate_days") val maxDelegateDays: Int = 90,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String = "",
)

data class TeamMemberDto(
    val uuid: String = "",
    val name: String = "",
    val phone: String = "",
    val role: String = "collaborator",
    /** invited · active · expired */
    val status: String = "invited",
    @SerializedName("max_amount_minor") val maxAmountMinor: Long? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("delegation_over") val delegationOver: Boolean = false,
    @SerializedName("invited_at") val invitedAt: String? = null,
)

data class TeamInviteRequest(
    val phone: String,
    val role: String,
    @SerializedName("max_amount_minor") val maxAmountMinor: Long? = null,
    val days: Int? = null,
)

data class TeamRoleRequest(
    val role: String,
    @SerializedName("max_amount_minor") val maxAmountMinor: Long? = null,
    val days: Int? = null,
)

data class TeamInvitationsDto(val invitations: List<TeamInvitationDto> = emptyList())

data class TeamInvitationDto(
    val uuid: String = "",
    val role: String = "collaborator",
    val project: TeamInvitationProjectDto = TeamInvitationProjectDto(),
    val owner: FreelanceAccessOwnerDto = FreelanceAccessOwnerDto(),
    @SerializedName("max_amount_minor") val maxAmountMinor: Long? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
)

data class TeamInvitationProjectDto(val uuid: String = "", val title: String = "")

data class TeamAnswerDto(val status: String = "", val project: String = "")
