package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FS-103, the client's talent lists (`mobile/v1/freelance/talent-lists`): lists of freelancers kept by a specialty, a note and a private
 * reminder for each one, and inviting a list to one of my private published projects. The freelancer is never told who saved him. Read once
 * when a screen opens and again after each action. [updateMember] takes a raw body because a reminder is turned off by an explicit null
 * (`remind_in_days`), which the usual converter would leave out.
 */
interface FreelanceTalentApi {
    @GET("mobile/v1/freelance/talent-lists")
    suspend fun lists(@Header("Authorization") auth: String, @Query("specialty_id") specialtyId: Int? = null): ApiEnvelope<TalentListsDto>

    @POST("mobile/v1/freelance/talent-lists")
    suspend fun create(@Header("Authorization") auth: String, @Body body: TalentListRequest): ApiEnvelope<TalentListDetailDto>

    @GET("mobile/v1/freelance/talent-lists/{uuid}")
    suspend fun show(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<TalentListDetailDto>

    @PUT("mobile/v1/freelance/talent-lists/{uuid}")
    suspend fun update(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: TalentListRequest): ApiEnvelope<TalentListDetailDto>

    @DELETE("mobile/v1/freelance/talent-lists/{uuid}")
    suspend fun delete(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<Any?>

    @POST("mobile/v1/freelance/talent-lists/{uuid}/members")
    suspend fun addMember(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: TalentMemberRequest): ApiEnvelope<TalentListDetailDto>

    /** `note` and/or `remind_in_days` (30 · 60 · 90 · 180, or null for off) — only the keys sent change. */
    @PUT("mobile/v1/freelance/talent-lists/{uuid}/members/{freelancer}")
    suspend fun updateMember(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("freelancer") freelancer: String, @Body body: RequestBody): ApiEnvelope<TalentListDetailDto>

    @DELETE("mobile/v1/freelance/talent-lists/{uuid}/members/{freelancer}")
    suspend fun removeMember(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("freelancer") freelancer: String): ApiEnvelope<TalentListDetailDto>

    /** Which of my lists hold this freelancer (the list uuids). */
    @GET("mobile/v1/freelance/talent-lists/containing/{freelancer}")
    suspend fun containing(@Header("Authorization") auth: String, @Path("freelancer") freelancer: String): ApiEnvelope<TalentContainingDto>

    @POST("mobile/v1/freelance/talent-lists/{uuid}/invite")
    suspend fun invite(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: TalentInviteRequest): ApiEnvelope<TalentInviteResultDto>
}

data class TalentSpecialtyDto(val id: Int = 0, val name: String = "")

data class TalentListDto(
    val uuid: String = "",
    val name: String = "",
    val specialty: TalentSpecialtyDto? = null,
    val count: Int = 0,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class TalentListsDto(
    val lists: List<TalentListDto> = emptyList(),
    @SerializedName("max_lists") val maxLists: Int = 20,
)

data class TalentMemberDto(
    val uuid: String = "",
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val verified: Boolean = false,
    val completeness: Int = 0,
    val note: String? = null,
    @SerializedName("remind_at") val remindAt: String? = null,
    @SerializedName("worked_together") val workedTogether: Boolean = false,
    @SerializedName("added_at") val addedAt: String? = null,
)

/** A list with its members (the answer of show, create, update and every member call). */
data class TalentListDetailDto(
    val uuid: String = "",
    val name: String = "",
    val specialty: TalentSpecialtyDto? = null,
    val count: Int = 0,
    @SerializedName("created_at") val createdAt: String? = null,
    val members: List<TalentMemberDto> = emptyList(),
    @SerializedName("remind_options") val remindOptions: List<Int> = listOf(30, 60, 90, 180),
)

data class TalentContainingDto(val lists: List<String> = emptyList())

data class TalentListRequest(val name: String, @SerializedName("specialty_id") val specialtyId: Int? = null)

data class TalentMemberRequest(val freelancer: String, val note: String? = null)

/** [freelancers] null: everyone in the list. */
data class TalentInviteRequest(val project: String, val freelancers: List<String>? = null)

/** `reason` = not_found · already_offered · already_invited · declined. */
data class TalentSkippedDto(val uuid: String = "", val reason: String = "")

data class TalentInviteResultDto(val invited: Int = 0, val skipped: List<TalentSkippedDto> = emptyList())

/** FS-106: how a running project is doing; null when the agreement is not running. */
data class FreelanceHealthDto(
    /** healthy · at_risk · delayed · blocked */
    val state: String = "healthy",
    val reasons: List<FreelanceHealthReasonDto> = emptyList(),
    @SerializedName("next_step") val nextStep: FreelanceHealthStepDto? = null,
)

/** `mine`: this side is the one who has to act. */
data class FreelanceHealthReasonDto(val code: String = "", val level: String = "", val mine: Boolean = false, val data: FreelanceHealthDataDto? = null)

data class FreelanceHealthDataDto(val part: String? = null, val days: Int? = null, @SerializedName("due_at") val dueAt: String? = null)

data class FreelanceHealthStepDto(val code: String = "", val mine: Boolean = false)
