package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Daily intelligence in the chat, batch A (spec 148, 149, 152, 154): commitments, handoffs and
 * agreements. Each one is made from a message (or in a chat) and has its card there — the card's
 * `meta` is the same shape as these answers, sent again on every change (`chat.message.updated`).
 */
interface LifeApi {
    /** `{title?, assignee? (participant id), due_at?, remind_minutes?}` */
    @POST("mobile/v1/chat/messages/{id}/commitment")
    suspend fun commitFromMessage(@Header("Authorization") auth: String, @Path("id") messageId: String, @Body body: JsonObject): ApiEnvelope<LifeCommitmentDto>

    @POST("mobile/v1/chat/conversations/{id}/commitment")
    suspend fun commit(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<LifeCommitmentDto>

    /** `scope`: mine · theirs · all. Pages of 15. */
    @GET("mobile/v1/chat/commitments")
    suspend fun commitments(
        @Header("Authorization") auth: String,
        @Query("scope") scope: String,
        @Query("open") open: Int = 1,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
        @Query("q") q: String? = null,
    ): ApiEnvelope<List<LifeCommitmentDto>>

    @GET("mobile/v1/chat/commitments/{id}")
    suspend fun commitment(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<LifeCommitmentDto>

    /** `{accept}` */
    @POST("mobile/v1/chat/commitments/{id}/respond")
    suspend fun respond(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<LifeCommitmentDto>

    @POST("mobile/v1/chat/commitments/{id}/done")
    suspend fun done(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<LifeCommitmentDto>

    @POST("mobile/v1/chat/commitments/{id}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<LifeCommitmentDto>

    /** `{to_participant_id}` (a member of the chat) or `{to_user_id}`, `note?` */
    @POST("mobile/v1/chat/messages/{id}/handoff")
    suspend fun handOff(@Header("Authorization") auth: String, @Path("id") messageId: String, @Body body: JsonObject): ApiEnvelope<HandoffDto>

    /** `action`: accept · decline · done · cancel */
    @POST("mobile/v1/chat/handoffs/{id}/{action}")
    suspend fun moveHandoff(@Header("Authorization") auth: String, @Path("id") id: String, @Path("action") action: String): ApiEnvelope<HandoffDto>

    /** `scope`: received · sent. Pages of 15. */
    @GET("mobile/v1/chat/handoffs")
    suspend fun handoffs(
        @Header("Authorization") auth: String,
        @Query("scope") scope: String,
        @Query("open") open: Int = 1,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
        @Query("q") q: String? = null,
    ): ApiEnvelope<List<HandoffDto>>

    /** `{title, terms[{label, value}], parties?[], source? (message id)}` */
    @POST("mobile/v1/chat/conversations/{id}/agreements")
    suspend fun propose(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<AgreementDto>

    @GET("mobile/v1/chat/agreements/{id}")
    suspend fun agreement(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AgreementDto>

    /** `{accept, version}` */
    @POST("mobile/v1/chat/agreements/{id}/answer")
    suspend fun answer(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<AgreementDto>

    /** `{title?, terms[]}` — a new version; everyone answers again. */
    @PUT("mobile/v1/chat/agreements/{id}")
    suspend fun amend(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<AgreementDto>

    @POST("mobile/v1/chat/agreements/{id}/cancel")
    suspend fun cancelAgreement(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AgreementDto>

    // ------------------------------------------------------------------ my communication rules (146, 151, 155)

    @GET("mobile/v1/chat/attention")
    suspend fun attention(@Header("Authorization") auth: String): ApiEnvelope<AttentionDto>

    /** `{attention?{source: now|digest|later}, digest_minutes?, always_through?[keys], call_hours?|null, contexts?{key: {attention?, disclosure?, calls?}}}` */
    @PUT("mobile/v1/chat/attention")
    suspend fun saveAttention(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<AttentionDto>

    /** `{context: home|work|meeting|travel|private|null, minutes?}` */
    @PUT("mobile/v1/chat/attention/context")
    suspend fun setContext(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<AttentionDto>

    /** What needs something from me (spec 147): reply · pay · approve · follow · appointments. Pages of 15. */
    @GET("mobile/v1/chat/life-inbox")
    suspend fun lifeInbox(
        @Header("Authorization") auth: String,
        @Query("timezone") timezone: String,
        @Query("section") section: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
        @Query("q") q: String? = null,
    ): ApiEnvelope<LifeInboxDto>
}

/**
 * One thing to do. `kind`: needs_reply · mention · money_request · bill_split · commitment · handoff ·
 * agreement · join_request · decision · my_commitment · my_handoff · waiting_for · appointment · task.
 */
data class LifeItemDto(
    val kind: String = "",
    val id: String? = null,
    val title: String? = null,
    val by: String? = null,
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long? = null,
    val currency: String? = null,
    @SerializedName("message_id") val messageId: String? = null,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    val at: String? = null,
    val late: Boolean = false,
    val status: String? = null,
    val version: Int? = null,
    @SerializedName("all_day") val allDay: Boolean = false,
    val date: String? = null,
    val location: String? = null,
)

data class LifeInboxCountsDto(
    val reply: Int = 0,
    val pay: Int = 0,
    val approve: Int = 0,
    val follow: Int = 0,
    val appointments: Int = 0,
    @SerializedName("message_requests") val messageRequests: Int = 0,
)

data class LifeInboxDto(
    val reply: List<LifeItemDto> = emptyList(),
    val pay: List<LifeItemDto> = emptyList(),
    val approve: List<LifeItemDto> = emptyList(),
    val follow: List<LifeItemDto> = emptyList(),
    val appointments: List<LifeItemDto> = emptyList(),
    val counts: LifeInboxCountsDto? = null,
    @SerializedName("message_requests") val messageRequests: Int = 0,
    val total: Int = 0,
)

data class ActiveContextDto(val key: String = "", val until: String? = null)

/** A context's effect: `disclosure` all · name · none · null (as usual), `calls` everyone · through · nobody. */
data class ContextDto(
    val key: String = "",
    val attention: Map<String, String> = emptyMap(),
    val disclosure: String? = null,
    val calls: String = "everyone",
    val custom: Boolean = false,
)

data class ThroughDto(val key: String = "", val name: String? = null, val avatar: String? = null)

data class CallHoursDto(val from: String? = null, val to: String? = null, val days: List<Int> = emptyList(), val timezone: String? = null)

data class QuietStateDto(val on: Boolean = false, val scope: String? = null)

data class AttentionDto(
    /** source (contacts · strangers · groups · mentions · channels · money · urgent) → now · digest · later */
    val attention: Map<String, String> = emptyMap(),
    @SerializedName("digest_minutes") val digestMinutes: Int = 60,
    val context: ActiveContextDto? = null,
    val contexts: List<ContextDto> = emptyList(),
    @SerializedName("always_through") val alwaysThrough: List<ThroughDto> = emptyList(),
    @SerializedName("call_hours") val callHours: CallHoursDto? = null,
    @SerializedName("who_can_call") val whoCanCall: String? = null,
    val quiet: QuietStateDto? = null,
    val sources: List<String> = emptyList(),
)

data class LifePersonDto(
    @SerializedName("participant_id") val participantId: Int = 0,
    val key: String? = null,
    val name: String? = null,
    val avatar: String? = null,
)

data class LifeSourceDto(val id: String? = null, val type: String? = null, val preview: String? = null)

data class LifeConversationDto(val id: String = "", val type: String? = null, val name: String? = null)

data class LifeLogDto(val action: String = "", val by: LifePersonDto? = null, val data: JsonElement? = null, val at: String? = null)

/** `status`: proposed · accepted · declined · done · cancelled */
data class LifeCommitmentDto(
    val id: String = "",
    val title: String? = null,
    val status: String = "proposed",
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("remind_minutes") val remindMinutes: Int? = null,
    val by: LifePersonDto? = null,
    val assignee: LifePersonDto? = null,
    val source: LifeSourceDto? = null,
    @SerializedName("done_at") val doneAt: String? = null,
    val conversation: LifeConversationDto? = null,
    val log: List<LifeLogDto>? = null,
)

/** `status`: pending · accepted · declined · done · cancelled */
data class HandoffDto(
    val id: String = "",
    val note: String? = null,
    val status: String = "pending",
    val from: LifePersonDto? = null,
    val to: LifePersonDto? = null,
    val source: LifeSourceDto? = null,
    @SerializedName("done_at") val doneAt: String? = null,
    val conversation: LifeConversationDto? = null,
)

data class AgreementTermDto(val label: String = "", val value: String = "")

data class AgreementPartyDto(
    @SerializedName("participant_id") val participantId: Int = 0,
    val key: String? = null,
    val name: String? = null,
    val avatar: String? = null,
    /** accepted · declined · null (not yet) — for this version */
    val decision: String? = null,
    val at: String? = null,
)

/** `status`: proposed · agreed · declined · cancelled. A record only: nothing is paid or booked. */
data class AgreementDto(
    val id: String = "",
    val title: String? = null,
    val terms: List<AgreementTermDto> = emptyList(),
    val version: Int = 1,
    val status: String = "proposed",
    val by: LifePersonDto? = null,
    val parties: List<AgreementPartyDto> = emptyList(),
    val source: LifeSourceDto? = null,
    @SerializedName("agreed_at") val agreedAt: String? = null,
    val log: List<LifeLogDto>? = null,
)
