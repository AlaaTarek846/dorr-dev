package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.ApiError
import com.dorr.core.network.coreGson
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import com.google.gson.annotations.JsonAdapter
import com.google.gson.annotations.SerializedName
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * My clients (FS-703): the freelancer's private CRM, under `/api/freelance/v1/crm`. What it opens is the plan's (`benefits.crm`: 0 none, 1 clients,
 * the star and notes, 2 + labels and follow-up reminders). A plan with none answers the list with 200 and [CrmOverviewDto.locked] (so the screen can
 * offer the upgrade instead of an error); a change the plan does not open is a 403 `freelance_crm_locked` (see [crmLocked]). Every answer to a change
 * is the client page as `show` gives it, so the screen repaints from one object.
 */
interface CrmApi {
    /** [tab] is `all`, `active`, `past`, `leads` or `starred`; [search] matches the client's name. */
    @GET("${ApiClient.FREELANCE}crm/clients")
    suspend fun clients(
        @Query("tab") tab: String? = null,
        @Query("search") search: String? = null,
        @Query("page") page: Int? = null,
        @Query("per_page") perPage: Int? = null,
    ): Response<ApiEnvelope<CrmOverviewDto>>

    @GET("${ApiClient.FREELANCE}crm/clients/{uuid}")
    suspend fun client(@Path("uuid") uuid: String): Response<ApiEnvelope<CrmClientPageDto>>

    /** The star (CRM 1) and the label (CRM 2). Built by [CrmBody], because a cleared label is a `null` the JSON must carry. */
    @PUT("${ApiClient.FREELANCE}crm/clients/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<CrmClientPageDto>>

    @POST("${ApiClient.FREELANCE}crm/clients/{uuid}/notes")
    suspend fun addNote(@Path("uuid") uuid: String, @Body body: CrmNoteRequest): Response<ApiEnvelope<CrmClientPageDto>>

    @PUT("${ApiClient.FREELANCE}crm/clients/{uuid}/notes/{note}")
    suspend fun editNote(@Path("uuid") uuid: String, @Path("note") note: String, @Body body: CrmNoteRequest): Response<ApiEnvelope<CrmClientPageDto>>

    @DELETE("${ApiClient.FREELANCE}crm/clients/{uuid}/notes/{note}")
    suspend fun deleteNote(@Path("uuid") uuid: String, @Path("note") note: String): Response<ApiEnvelope<CrmClientPageDto>>

    @POST("${ApiClient.FREELANCE}crm/clients/{uuid}/reminders")
    suspend fun addReminder(@Path("uuid") uuid: String, @Body body: CrmReminderRequest): Response<ApiEnvelope<CrmClientPageDto>>

    @PUT("${ApiClient.FREELANCE}crm/clients/{uuid}/reminders/{reminder}")
    suspend fun finishReminder(@Path("uuid") uuid: String, @Path("reminder") reminder: String, @Body body: CrmReminderDoneRequest): Response<ApiEnvelope<CrmClientPageDto>>

    @DELETE("${ApiClient.FREELANCE}crm/clients/{uuid}/reminders/{reminder}")
    suspend fun deleteReminder(@Path("uuid") uuid: String, @Path("reminder") reminder: String): Response<ApiEnvelope<CrmClientPageDto>>

    /** Every follow-up still waiting, soonest first (the list's own `due` carries the same). */
    @GET("${ApiClient.FREELANCE}crm/reminders")
    suspend fun reminders(): Response<ApiEnvelope<CrmRemindersDto>>
}

data class CrmNoteRequest(val body: String)

data class CrmReminderRequest(val title: String, @SerializedName("due_at") val dueAt: String)

data class CrmReminderDoneRequest(val done: Boolean)

/**
 * The body of `PUT crm/clients/{uuid}`. Gson leaves a `null` out, so clearing the label (`{"label": null}`) is written here by hand — the server
 * reads a present-and-null `label` as «no label» and an absent one as «leave it».
 */
object CrmBody {
    private val json = "application/json".toMediaTypeOrNull()

    fun star(starred: Boolean): RequestBody = JsonObject().apply { addProperty("starred", starred) }.toString().toRequestBody(json)

    /** [label] is `vip`, `regular`, `lead`, `paused`, or null to clear it. */
    fun label(label: String?): RequestBody = JsonObject().apply {
        if (label == null) add("label", JsonNull.INSTANCE) else addProperty("label", label)
    }.toString().toRequestBody(json)
}

/** The frame every answer shares, as the list reads it. [locked] = my plan has no CRM (then there is no list, only [upgrade]). */
data class CrmOverviewDto(
    /** 0 = none (locked), 1 = clients, star and notes, 2 = + labels and follow-ups. */
    val level: Int = 0,
    val locked: Boolean = true,
    /** False when plans are not sold in my country, so there is nothing to upgrade to. */
    @SerializedName("subscriptions_enabled") val subscriptionsEnabled: Boolean = false,
    /** The lowest plan that opens more than mine; null at the top or where plans are not sold. */
    val upgrade: CrmUpgradeDto? = null,
    /** The labels my plan allows (level 2 only). */
    val labels: List<String> = emptyList(),
    val limits: CrmLimitsDto = CrmLimitsDto(),
    @field:JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    val tab: String = "all",
    val counts: CrmCountsDto = CrmCountsDto(),
    val clients: List<CrmClientDto> = emptyList(),
    val meta: CrmMetaDto = CrmMetaDto(),
    /** The follow-ups still waiting, soonest first (level 2). */
    val due: List<CrmReminderDto> = emptyList(),
)

data class CrmUpgradeDto(val slug: String = "", val name: String? = null)

data class CrmLimitsDto(
    @SerializedName("notes_max") val notesMax: Int = 50,
    @SerializedName("reminders_max") val remindersMax: Int = 30,
)

data class CrmCountsDto(
    val all: Int = 0,
    val active: Int = 0,
    val past: Int = 0,
    val leads: Int = 0,
    val starred: Int = 0,
)

data class CrmMetaDto(
    val total: Int = 0,
    val page: Int = 1,
    @SerializedName("per_page") val perPage: Int = 20,
    @SerializedName("last_page") val lastPage: Int = 1,
)

/** `GET crm/clients/{uuid}` and every change: the frame and the client. */
data class CrmClientPageDto(
    val level: Int = 0,
    val locked: Boolean = false,
    @SerializedName("subscriptions_enabled") val subscriptionsEnabled: Boolean = false,
    val upgrade: CrmUpgradeDto? = null,
    val labels: List<String> = emptyList(),
    val limits: CrmLimitsDto = CrmLimitsDto(),
    @field:JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    val client: CrmClientDto? = null,
)

/**
 * A client of mine. The list gives the card (the first fields); the page also fills [notes], [reminders] (level 2), [history] (the newest first)
 * and [leads] (what is still waiting).
 */
data class CrmClientDto(
    val uuid: String = "",
    val name: String = "",
    /** `active` (a running project), `past` (only finished ones) or `lead` (an offer or an invitation, no work yet). */
    val stage: String = "lead",
    /** `vip`, `regular`, `lead`, `paused` (level 2), or null. */
    val label: String? = null,
    val starred: Boolean = false,
    val projects: Int = 0,
    val active: Int = 0,
    val completed: Int = 0,
    @SerializedName("earned_minor") val earnedMinor: Long = 0,
    /** What this client gave me, from the published reviews; null before any. */
    val rating: Double? = null,
    @SerializedName("notes_count") val notesCount: Int = 0,
    /** The soonest open follow-up. */
    @SerializedName("next_due_at") val nextDueAt: String? = null,
    @SerializedName("reminders_count") val remindersCount: Int = 0,
    /** An offer of mine is still waiting on him. */
    @SerializedName("waiting_offer") val waitingOffer: Boolean = false,
    /** He invited me to a project and I have not answered. */
    @SerializedName("invited_you") val invitedYou: Boolean = false,
    @SerializedName("last_activity_at") val lastActivityAt: String? = null,
    val notes: List<CrmNoteDto> = emptyList(),
    val reminders: List<CrmReminderDto> = emptyList(),
    val history: List<CrmHistoryDto> = emptyList(),
    val leads: List<CrmLeadDto> = emptyList(),
)

data class CrmNoteDto(
    val uuid: String = "",
    val body: String = "",
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
)

/** A follow-up. On the list's `due` it also names the client ([client]). */
data class CrmReminderDto(
    val uuid: String = "",
    val title: String = "",
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("done_at") val doneAt: String? = null,
    val overdue: Boolean = false,
    val client: CrmClientRefDto? = null,
)

data class CrmClientRefDto(val uuid: String? = null, val name: String? = null)

data class CrmRemindersDto(val reminders: List<CrmReminderDto> = emptyList())

/** A project I did with the client: [project] is its uuid (the agreement page opens by it); [service] = it came from a ready-made service. */
data class CrmHistoryDto(
    val project: String? = null,
    val title: String? = null,
    /** `active` or `completed`. */
    val status: String = "",
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("earned_minor") val earnedMinor: Long = 0,
    val service: Boolean = false,
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("completed_at") val completedAt: String? = null,
)

/** Something still waiting: [kind] `offer` (mine, on his project) or `invitation` (his, to me); [status] `submitted`, `viewed`, `shortlisted` or `pending`. */
data class CrmLeadDto(
    val kind: String = "offer",
    /** The project's uuid. */
    val project: String? = null,
    val title: String? = null,
    val status: String = "",
    val at: String? = null,
)

/** The 403 `freelance_crm_locked` data: the level I have, the level the change needs and the plan that opens it. */
data class CrmLockedDto(
    val level: Int = 0,
    val needed: Int = 1,
    val upgrade: CrmUpgradeDto? = null,
)

/** The upgrade detail of a `freelance_crm_locked` refusal, or null when this error is something else. */
fun ApiError.crmLocked(): CrmLockedDto? {
    if (errorCode != "freelance_crm_locked") return null
    val d = data ?: return CrmLockedDto()
    return runCatching { coreGson().fromJson(d, CrmLockedDto::class.java) }.getOrNull() ?: CrmLockedDto()
}
