package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * My freelancers' teams (FS-209), under `/api/freelance/v1` behind the `teams` switch: the teams I am in, making one, its profile and people,
 * and the invitations into a team (the team's side and mine). A person who is not in a team gets 404 for it, like the server's other routes.
 */
interface TeamsApi {
    @GET("${ApiClient.FREELANCE}teams")
    suspend fun mine(): Response<ApiEnvelope<TeamListDto>>

    @POST("${ApiClient.FREELANCE}teams")
    suspend fun create(@Body body: TeamCreateRequest): Response<ApiEnvelope<TeamEnvelopeDto>>

    @GET("${ApiClient.FREELANCE}teams/{team}/profile")
    suspend fun profile(@Path("team") team: String): Response<ApiEnvelope<TeamEnvelopeDto>>

    @PUT("${ApiClient.FREELANCE}teams/{team}/profile")
    suspend fun update(@Path("team") team: String, @Body body: TeamProfileRequest): Response<ApiEnvelope<TeamEnvelopeDto>>

    @PUT("${ApiClient.FREELANCE}teams/{team}/profile/me")
    suspend fun consent(@Path("team") team: String, @Body body: TeamConsentRequest): Response<ApiEnvelope<TeamConsentDto>>

    @Multipart
    @POST("${ApiClient.FREELANCE}teams/{team}/profile/logo")
    suspend fun logo(@Path("team") team: String, @Part logo: MultipartBody.Part): Response<ApiEnvelope<TeamLogoDto>>

    @DELETE("${ApiClient.FREELANCE}teams/{team}/profile/logo")
    suspend fun removeLogo(@Path("team") team: String): Response<ApiEnvelope<TeamLogoDto>>

    @GET("${ApiClient.FREELANCE}teams/{team}/members")
    suspend fun members(@Path("team") team: String): Response<ApiEnvelope<TeamMembersDto>>

    @PUT("${ApiClient.FREELANCE}teams/{team}/members/{member}/permissions")
    suspend fun setPermissions(@Path("team") team: String, @Path("member") member: String, @Body body: TeamPermissionsRequest): Response<ApiEnvelope<TeamMemberEnvelopeDto>>

    @PUT("${ApiClient.FREELANCE}teams/{team}/members/{member}/role")
    suspend fun setRole(@Path("team") team: String, @Path("member") member: String, @Body body: TeamRoleRequest): Response<ApiEnvelope<TeamMemberEnvelopeDto>>

    @POST("${ApiClient.FREELANCE}teams/{team}/leave")
    suspend fun leave(@Path("team") team: String): Response<ApiEnvelope<Any>>

    @DELETE("${ApiClient.FREELANCE}teams/{team}/members/{member}")
    suspend fun remove(@Path("team") team: String, @Path("member") member: String): Response<ApiEnvelope<Any>>

    @GET("${ApiClient.FREELANCE}teams/{team}/invitations")
    suspend fun invitations(@Path("team") team: String): Response<ApiEnvelope<TeamInvitationsDto>>

    @POST("${ApiClient.FREELANCE}teams/{team}/invitations")
    suspend fun invite(@Path("team") team: String, @Body body: TeamInviteRequest): Response<ApiEnvelope<TeamInvitationEnvelopeDto>>

    @DELETE("${ApiClient.FREELANCE}teams/{team}/invitations/{invitation}")
    suspend fun cancelInvitation(@Path("team") team: String, @Path("invitation") invitation: String): Response<ApiEnvelope<Any>>

    /** The invitations waiting for me. */
    @GET("${ApiClient.FREELANCE}team-invitations")
    suspend fun myInvitations(): Response<ApiEnvelope<TeamInvitationsDto>>

    @POST("${ApiClient.FREELANCE}team-invitations/{invitation}/accept")
    suspend fun accept(@Path("invitation") invitation: String): Response<ApiEnvelope<TeamInvitationEnvelopeDto>>

    @POST("${ApiClient.FREELANCE}team-invitations/{invitation}/decline")
    suspend fun decline(@Path("invitation") invitation: String): Response<ApiEnvelope<TeamInvitationEnvelopeDto>>

    /** Which team and role an invitation code is for, before I accept. */
    @GET("${ApiClient.FREELANCE}team-invitations/by-link/{token}")
    suspend fun preview(@Path("token") token: String): Response<ApiEnvelope<TeamInvitationEnvelopeDto>>

    @POST("${ApiClient.FREELANCE}team-invitations/by-link/{token}/accept")
    suspend fun acceptCode(@Path("token") token: String): Response<ApiEnvelope<TeamInvitationEnvelopeDto>>

    /** FS-209b: the team's projects I reach (every one for an Owner or a PM, the ones I am on for a member), each with my place on it. */
    @GET("${ApiClient.FREELANCE}teams/{team}/projects")
    suspend fun projects(@Path("team") team: String): Response<ApiEnvelope<List<TeamProjectDto>>>

    /** FS-209b: what each member carries on the team's running projects (an Owner or a PM reads everybody, a member his own row). */
    @GET("${ApiClient.FREELANCE}teams/{team}/workload")
    suspend fun workload(@Path("team") team: String): Response<ApiEnvelope<TeamWorkloadDto>>

    /** FS-209b: who of the team is on a part of a team's project. */
    @GET("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/assignees")
    suspend fun assignees(@Path("uuid") uuid: String, @Path("milestone") milestone: String): Response<ApiEnvelope<MilestoneAssigneesDto>>

    /** FS-209b: exactly these people are on the part (`[]` takes everybody off). Owner or PM. */
    @PUT("${ApiClient.FREELANCE}projects/{uuid}/milestones/{milestone}/assignees")
    suspend fun setAssignees(@Path("uuid") uuid: String, @Path("milestone") milestone: String, @Body body: AssigneesRequest): Response<ApiEnvelope<MilestoneAssigneesDto>>

    // ---- FS-209c: the team's internal chat (never seen by the client) ----

    /** The general thread first, then the projects' threads, the newest message first. */
    @GET("${ApiClient.FREELANCE}teams/{team}/chat/threads")
    suspend fun chatThreads(@Path("team") team: String): Response<ApiEnvelope<TeamThreadsDto>>

    /** An Owner opens (or gets) the thread about a project the team works on. */
    @POST("${ApiClient.FREELANCE}teams/{team}/chat/threads")
    suspend fun openChatThread(@Path("team") team: String, @Body body: TeamThreadRequest): Response<ApiEnvelope<TeamThreadEnvelopeDto>>

    /** Newest first; `next_before` is the id to ask the older page with. */
    @GET("${ApiClient.FREELANCE}teams/{team}/chat/threads/{thread}/messages")
    suspend fun chatMessages(@Path("team") team: String, @Path("thread") thread: String, @Query("before") before: Int? = null, @Query("limit") limit: Int? = null): Response<ApiEnvelope<TeamMessagesDto>>

    @POST("${ApiClient.FREELANCE}teams/{team}/chat/threads/{thread}/messages")
    suspend fun sendChat(@Path("team") team: String, @Path("thread") thread: String, @Body body: TeamMessageRequest): Response<ApiEnvelope<TeamMessageEnvelopeDto>>

    @POST("${ApiClient.FREELANCE}teams/{team}/chat/threads/{thread}/read")
    suspend fun readChat(@Path("team") team: String, @Path("thread") thread: String): Response<ApiEnvelope<TeamReadDto>>

    /** Its sender only, within a few minutes. */
    @DELETE("${ApiClient.FREELANCE}teams/{team}/chat/messages/{id}")
    suspend fun deleteChatMessage(@Path("team") team: String, @Path("id") id: Int): Response<ApiEnvelope<Any>>

    // ---- FS-209c: the team's verification (optional; the Owners) ----

    @GET("${ApiClient.FREELANCE}teams/{team}/verification")
    suspend fun verification(@Path("team") team: String): Response<ApiEnvelope<TeamVerificationDto>>

    /** Multipart: `title` and `file` (jpg / png / pdf, up to 8 MB). */
    @Multipart
    @POST("${ApiClient.FREELANCE}teams/{team}/verification/documents")
    suspend fun addVerificationDocument(@Path("team") team: String, @Part("title") title: okhttp3.RequestBody, @Part file: MultipartBody.Part): Response<ApiEnvelope<TeamVerificationDocDto>>

    @DELETE("${ApiClient.FREELANCE}teams/{team}/verification/documents/{uuid}")
    suspend fun deleteVerificationDocument(@Path("team") team: String, @Path("uuid") uuid: String): Response<ApiEnvelope<Any>>

    /** One of the papers (private, signed in): a draft's or a sent request's. */
    @Streaming
    @GET("${ApiClient.FREELANCE}teams/{team}/verification/documents/{uuid}")
    suspend fun verificationFile(@Path("team") team: String, @Path("uuid") uuid: String): Response<ResponseBody>

    @POST("${ApiClient.FREELANCE}teams/{team}/verification/submit")
    suspend fun submitVerification(@Path("team") team: String): Response<ApiEnvelope<TeamVerificationDto>>
}

data class TeamThreadsDto(
    val threads: List<TeamThreadDto> = emptyList(),
    @SerializedName("can_write") val canWrite: Boolean = false,
    /** An Owner may open the thread of a project. */
    @SerializedName("can_open_project") val canOpenProject: Boolean = false,
    /** What the team is told about its chat (the staff may read it while a dispute is decided). */
    val notice: String? = null,
)

data class TeamThreadDto(
    val uuid: String = "",
    val general: Boolean = false,
    val project: TeamProjectRefDto? = null,
    val unread: Int = 0,
    @SerializedName("last_message_preview") val lastMessagePreview: String? = null,
    @SerializedName("last_message_at") val lastMessageAt: String? = null,
    @SerializedName("can_write") val canWrite: Boolean = false,
    /** A dispute on the project is being decided: the staff may read the thread. */
    @SerializedName("under_review") val underReview: Boolean = false,
)

data class TeamThreadEnvelopeDto(val thread: TeamThreadDto = TeamThreadDto())

data class TeamThreadRequest(val project: String)

data class TeamMessagesDto(
    val thread: TeamThreadDto? = null,
    val messages: List<TeamMessageDto> = emptyList(),
    @SerializedName("next_before") val nextBefore: Int? = null,
    val notice: String? = null,
)

/** `kind` = text | deleted; `mine` is null on a message that arrived live. */
data class TeamMessageDto(
    val id: Int = 0,
    val from: TeamMessageFromDto? = null,
    val mine: Boolean? = null,
    val kind: String = "text",
    val body: String? = null,
    @SerializedName("client_msg_id") val clientMsgId: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class TeamMessageFromDto(val id: Int? = null, val name: String? = null)

data class TeamMessageEnvelopeDto(val message: TeamMessageDto = TeamMessageDto())

data class TeamMessageRequest(val body: String, @SerializedName("client_msg_id") val clientMsgId: String? = null)

data class TeamReadDto(val read: Int = 0)

/** `status` = none | submitted | rejected | verified. */
data class TeamVerificationDto(
    val status: String = "none",
    @SerializedName("verified_at") val verifiedAt: String? = null,
    /** The papers prepared and not sent yet. */
    val documents: List<TeamVerificationDocDto> = emptyList(),
    @SerializedName("can_edit") val canEdit: Boolean = false,
    @SerializedName("can_submit") val canSubmit: Boolean = false,
    @SerializedName("last_request") val lastRequest: TeamVerificationRequestDto? = null,
)

data class TeamVerificationRequestDto(
    val uuid: String = "",
    val status: String = "submitted",
    /** Why it was turned down. */
    val reason: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    @SerializedName("decided_at") val decidedAt: String? = null,
    val documents: List<TeamVerificationDocDto> = emptyList(),
)

data class TeamVerificationDocDto(
    val uuid: String = "",
    val title: String = "",
    val file: TeamVerificationFileDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class TeamVerificationFileDto(val name: String? = null, val mime: String? = null, val size: Long? = null, val path: String? = null)

/** FS-209b: one of the team's projects with my place on it (`price_minor` is null when my place may not see money). */
data class TeamProjectDto(
    val project: TeamProjectRefDto = TeamProjectRefDto(),
    val agreement: TeamAgreementRefDto = TeamAgreementRefDto(),
    @SerializedName("signed_by") val signedBy: TeamPersonRefDto? = null,
    @SerializedName("team_access") val teamAccess: TeamAccessDto = TeamAccessDto(),
)

data class TeamProjectRefDto(val uuid: String? = null, val title: String? = null, val status: String? = null)

data class TeamAgreementRefDto(
    val uuid: String = "",
    val status: String = "pending_approval",
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String = "",
)

data class TeamPersonRefDto(val uuid: String? = null, val name: String? = null)

/**
 * FS-209b: my place on a team's project (on the project page, the agreement and the part actions). `abilities` are view · money · net · start
 * · report · deliver · manage · assign; the answer already hides what they do not allow, so these only decide what to explain.
 */
data class TeamAccessDto(
    val team: TeamRefDto = TeamRefDto(),
    val role: String = "member",
    val signer: Boolean = false,
    val abilities: List<String> = emptyList(),
) {
    fun can(ability: String) = ability in abilities
}

data class TeamWorkloadDto(val members: List<TeamLoadDto> = emptyList())

data class TeamLoadDto(
    val freelancer: TeamPersonRefDto = TeamPersonRefDto(),
    val role: String = "member",
    val projects: Int = 0,
    @SerializedName("parts_open") val partsOpen: Int = 0,
    @SerializedName("tasks_open") val tasksOpen: Int = 0,
    @SerializedName("tasks_overdue") val tasksOverdue: Int = 0,
    @SerializedName("tasks_blocked") val tasksBlocked: Int = 0,
    @SerializedName("tasks_done") val tasksDone: Int = 0,
)

data class MilestoneAssigneesDto(val milestone: TeamPersonRefDto? = null, val assignees: List<TeamPersonRefDto> = emptyList())

data class AssigneesRequest(val freelancers: List<String>)

data class TeamCreateRequest(val name: String)

/** Only what is set is sent: the server leaves the rest as it is. */
data class TeamProfileRequest(
    val name: String? = null,
    val bio: String? = null,
    val visibility: String? = null,
    @SerializedName("accepting_projects") val acceptingProjects: Boolean? = null,
)

data class TeamConsentRequest(@SerializedName("show_in_profile") val showInProfile: Boolean)

data class TeamPermissionsRequest(val permissions: Map<String, Boolean>)

data class TeamRoleRequest(val role: String)

/** [phone] null = an invitation by code (no phone number needed). */
data class TeamInviteRequest(val phone: String? = null, val role: String)

data class TeamListDto(val teams: List<TeamSummaryDto> = emptyList())

/** A card of «my teams». */
data class TeamSummaryDto(
    val uuid: String = "",
    val name: String = "",
    /** active | suspended | archived */
    val status: String = "active",
    /** owner | pm | member */
    val role: String = "member",
    @SerializedName("joined_at") val joinedAt: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    val verified: Boolean = false,
    @SerializedName("members_count") val membersCount: Int = 0,
    @SerializedName("accepting_projects") val acceptingProjects: Boolean = false,
    /** public | private */
    val visibility: String = "private",
)

data class TeamEnvelopeDto(val team: TeamDto = TeamDto())

/** The team's own view of its profile (`teams/{uuid}/profile` for somebody in it). */
data class TeamDto(
    val uuid: String = "",
    val name: String = "",
    val bio: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    val verified: Boolean = false,
    @SerializedName("accepting_projects") val acceptingProjects: Boolean = false,
    @SerializedName("running_projects") val runningProjects: Int = 0,
    val since: String? = null,
    @SerializedName("members_count") val membersCount: Int = 0,
    val members: List<TeamPersonDto> = emptyList(),
    val specialties: List<TeamNamedDto> = emptyList(),
    val skills: List<TeamNamedDto> = emptyList(),
    val languages: List<String> = emptyList(),
    val stats: TeamStatsDto = TeamStatsDto(),
    val status: String = "active",
    val visibility: String = "private",
    /** none | submitted | rejected | verified */
    @SerializedName("verification_status") val verificationStatus: String = "none",
    val me: TeamMeDto = TeamMeDto(),
)

data class TeamPersonDto(
    /** The person's uuid. */
    val freelancer: String? = null,
    val name: String? = null,
    val role: String = "member",
    @SerializedName("show_in_profile") val showInProfile: Boolean = false,
)

data class TeamNamedDto(val id: Int = 0, val name: String? = null)

data class TeamStatsDto(
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
    @SerializedName("completion_rate") val completionRate: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
)

data class TeamMeDto(
    val role: String? = null,
    @SerializedName("show_in_profile") val showInProfile: Boolean = false,
    @SerializedName("can_edit") val canEdit: Boolean = false,
)

data class TeamConsentDto(@SerializedName("show_in_profile") val showInProfile: Boolean = false)

data class TeamLogoDto(@SerializedName("logo_url") val logoUrl: String? = null)

data class TeamMembersDto(
    val members: List<TeamMemberDto> = emptyList(),
    val me: TeamMembersMeDto = TeamMembersMeDto(),
    /** The abilities an Owner may switch for a PM or a member. */
    val switches: List<String> = emptyList(),
)

data class TeamMembersMeDto(
    val role: String? = null,
    val abilities: Map<String, Boolean> = emptyMap(),
    /** FS-212: the agreements of this team that I signed and that have not ended - while there are any I can not leave or step down. */
    @SerializedName("signed_agreements") val signedAgreements: Int = 0,
    /** FS-212: the offers in the team's name that I signed and that are still open - I take them back before I can leave or step down. */
    @SerializedName("open_offers") val openOffers: Int = 0,
)

data class TeamMemberEnvelopeDto(val member: TeamMemberDto = TeamMemberDto())

data class TeamMemberDto(
    val freelancer: TeamMemberPersonDto = TeamMemberPersonDto(),
    val role: String = "member",
    @SerializedName("joined_at") val joinedAt: String? = null,
    val abilities: Map<String, Boolean> = emptyMap(),
    /** FS-212: for an Owner, the agreements of the team he signed that have not ended (he stays an Owner until they do). */
    @SerializedName("signed_agreements") val signedAgreements: Int = 0,
    /** FS-212: and the offers in the team's name he signed that are still open (he stays an Owner until they end or are taken back). */
    @SerializedName("open_offers") val openOffers: Int = 0,
)

data class TeamMemberPersonDto(val uuid: String? = null, val name: String? = null)

data class TeamInvitationsDto(
    val invitations: List<TeamInvitationDto> = emptyList(),
    /** Days an invitation stays open (the team's side only). */
    val days: Int? = null,
)

data class TeamInvitationEnvelopeDto(val invitation: TeamInvitationDto = TeamInvitationDto())

data class TeamInvitationDto(
    val uuid: String = "",
    val team: TeamRefDto? = null,
    val role: String = "member",
    @SerializedName("by_link") val byCode: Boolean = false,
    val invited: TeamMemberPersonDto? = null,
    @SerializedName("invited_by") val invitedBy: TeamInviterDto? = null,
    /** pending | accepted | declined | cancelled | expired */
    val status: String = "pending",
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    /** The code of an invitation by code, for the people who may invite (while it waits). */
    val token: String? = null,
)

data class TeamRefDto(val uuid: String = "", val name: String = "")

data class TeamInviterDto(val name: String? = null)
