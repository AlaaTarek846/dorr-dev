package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The chat under /api/mobile/v1/chat — docs/modules/chat/API.md. Conversations, messages and
 * calls are addressed by uuid. Every message the app sends carries its own uuid, so a retry after
 * a dropped connection is the same message, never a duplicate.
 */
interface ChatApi {
    @GET("mobile/v1/chat/realtime-config")
    suspend fun realtimeConfig(@Header("Authorization") auth: String): ApiEnvelope<RealtimeConfigDto>

    // ------------------------------------------------------------------ conversations

    @GET("mobile/v1/chat/conversations")
    suspend fun conversations(
        @Header("Authorization") auth: String,
        @Query("filter") filter: String? = null,
        @Query("search") search: String? = null,
        @Query("folder") folder: Int? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): ConversationListEnvelope

    @POST("mobile/v1/chat/conversations/direct")
    suspend fun openDirect(@Header("Authorization") auth: String, @Body body: OpenDirectRequest): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/conversations/{id}")
    suspend fun conversation(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ConversationDto>

    @PATCH("mobile/v1/chat/conversations/{id}/settings")
    suspend fun updateSettings(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/conversations/{id}/clear")
    suspend fun clear(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ConversationDto>

    @DELETE("mobile/v1/chat/conversations/{id}")
    suspend fun deleteConversation(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/conversations/{id}/read")
    suspend fun markRead(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String?> = emptyMap()): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/conversations/delivered")
    suspend fun markDelivered(@Header("Authorization") auth: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/conversations/{id}/typing")
    suspend fun typing(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<JsonElement?>

    @PUT("mobile/v1/chat/conversations/{id}/disappearing")
    suspend fun disappearing(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Int?>): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/conversations/{id}/accept")
    suspend fun accept(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/conversations/{id}/reject")
    suspend fun reject(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean> = emptyMap()): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------------ messages

    @GET("mobile/v1/chat/conversations/{id}/messages")
    suspend fun messages(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Query("before") before: String? = null,
        @Query("after") after: String? = null,
        @Query("around") around: String? = null,
        @Query("limit") limit: Int = 50,
    ): ApiEnvelope<MessagePageDto>

    @POST("mobile/v1/chat/conversations/{id}/messages")
    suspend fun send(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<MessageDto>

    @Multipart
    @POST("mobile/v1/chat/conversations/{id}/messages")
    suspend fun sendWithFiles(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<MessageDto>

    @PATCH("mobile/v1/chat/messages/{id}")
    suspend fun edit(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<MessageDto>

    @DELETE("mobile/v1/chat/messages/{id}")
    suspend fun deleteForEveryone(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    @POST("mobile/v1/chat/conversations/{id}/messages/delete-for-me")
    suspend fun deleteForMe(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, List<String>>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/messages/forward")
    suspend fun forward(@Header("Authorization") auth: String, @Body body: Map<String, List<String>>): ApiEnvelope<List<MessageDto>>

    @PUT("mobile/v1/chat/messages/{id}/reaction")
    suspend fun react(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String?>): ApiEnvelope<MessageDto>

    @PUT("mobile/v1/chat/messages/{id}/star")
    suspend fun star(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/messages/starred")
    suspend fun starred(@Header("Authorization") auth: String): ApiEnvelope<List<MessageDto>>

    @POST("mobile/v1/chat/messages/{id}/pin")
    suspend fun pin(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Int>): ApiEnvelope<List<MessageDto>>

    @DELETE("mobile/v1/chat/messages/{id}/pin")
    suspend fun unpin(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MessageDto>>

    @GET("mobile/v1/chat/conversations/{id}/pinned")
    suspend fun pinned(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MessageDto>>

    @GET("mobile/v1/chat/messages/{id}/info")
    suspend fun info(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageInfoDto>

    @GET("mobile/v1/chat/conversations/{id}/messages/search")
    suspend fun searchIn(@Header("Authorization") auth: String, @Path("id") id: String, @Query("q") q: String): ApiEnvelope<List<MessageDto>>

    @GET("mobile/v1/chat/conversations/{id}/gallery")
    suspend fun gallery(@Header("Authorization") auth: String, @Path("id") id: String, @Query("kind") kind: String, @Query("before") before: String? = null): ApiEnvelope<GalleryPageDto>

    // ------------------------------------------------------------------ groups

    @Multipart
    @POST("mobile/v1/chat/groups")
    suspend fun createGroup(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part avatar: MultipartBody.Part?,
    ): ApiEnvelope<CreatedGroupDto>

    @Multipart
    @POST("mobile/v1/chat/groups/{id}")
    suspend fun updateGroup(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part avatar: MultipartBody.Part?,
    ): ApiEnvelope<ConversationDto>

    @PATCH("mobile/v1/chat/groups/{id}/settings")
    suspend fun groupSettings(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/groups/{id}/members")
    suspend fun members(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MemberDto>>

    @POST("mobile/v1/chat/groups/{id}/members")
    suspend fun addMembers(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, List<Int>>): ApiEnvelope<JsonElement?>

    @DELETE("mobile/v1/chat/groups/{id}/members/{participant}")
    suspend fun removeMember(@Header("Authorization") auth: String, @Path("id") id: String, @Path("participant") participant: Int): ApiEnvelope<List<MemberDto>>

    @PATCH("mobile/v1/chat/groups/{id}/members/{participant}/role")
    suspend fun setRole(@Header("Authorization") auth: String, @Path("id") id: String, @Path("participant") participant: Int, @Body body: Map<String, String>): ApiEnvelope<List<MemberDto>>

    @POST("mobile/v1/chat/groups/{id}/leave")
    suspend fun leave(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/groups/{id}/invite")
    suspend fun invite(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<InviteDto>

    // ------------------------------------------------------------------ contacts, privacy, presence

    @GET("mobile/v1/chat/contacts")
    suspend fun contacts(@Header("Authorization") auth: String, @Query("registered") registered: Int = 0): ApiEnvelope<List<ContactDto>>

    @POST("mobile/v1/chat/contacts/sync")
    suspend fun syncContacts(@Header("Authorization") auth: String, @Body body: ContactSyncRequest): ApiEnvelope<List<ContactDto>>

    @POST("mobile/v1/chat/contacts")
    suspend fun addContact(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<ContactDto>

    @POST("mobile/v1/chat/contacts/lookup")
    suspend fun lookup(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<ProfileDto>

    @GET("mobile/v1/chat/contacts/qr")
    suspend fun myQr(@Header("Authorization") auth: String): ApiEnvelope<QrDto>

    @POST("mobile/v1/chat/contacts/qr/reset")
    suspend fun resetQr(@Header("Authorization") auth: String): ApiEnvelope<QrDto>

    @POST("mobile/v1/chat/contacts/qr/resolve")
    suspend fun resolveQr(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<ProfileDto>

    @GET("mobile/v1/chat/privacy")
    suspend fun privacy(@Header("Authorization") auth: String): ApiEnvelope<PrivacyDto>

    @PATCH("mobile/v1/chat/privacy")
    suspend fun updatePrivacy(@Header("Authorization") auth: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<PrivacyDto>

    @GET("mobile/v1/chat/blocks")
    suspend fun blocks(@Header("Authorization") auth: String): ApiEnvelope<List<BlockDto>>

    @POST("mobile/v1/chat/blocks")
    suspend fun block(@Header("Authorization") auth: String, @Body body: Map<String, Int>): ApiEnvelope<List<BlockDto>>

    @POST("mobile/v1/chat/blocks/remove")
    suspend fun unblock(@Header("Authorization") auth: String, @Body body: Map<String, Int>): ApiEnvelope<List<BlockDto>>

    @POST("mobile/v1/chat/presence")
    suspend fun presence(@Header("Authorization") auth: String, @Body body: Map<String, Boolean>): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------------ calls

    @GET("mobile/v1/chat/calls")
    suspend fun calls(@Header("Authorization") auth: String): ApiEnvelope<List<CallDto>>

    @POST("mobile/v1/chat/conversations/{id}/calls")
    suspend fun startCall(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<CallSessionDto>

    @GET("mobile/v1/chat/calls/{id}")
    suspend fun call(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CallDto>

    @POST("mobile/v1/chat/calls/{id}/accept")
    suspend fun acceptCall(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CallSessionDto>

    @POST("mobile/v1/chat/calls/{id}/decline")
    suspend fun declineCall(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CallSessionDto>

    @POST("mobile/v1/chat/calls/{id}/leave")
    suspend fun leaveCall(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CallSessionDto>
}

// ====================================================================== DTOs

data class RealtimeConfigDto(
    val enabled: Boolean,
    val key: String?,
    val cluster: String?,
    val host: String?,
    val port: Int?,
    @SerializedName("use_tls") val useTls: Boolean,
    @SerializedName("auth_path") val authPath: String,
)

/** The chat list answer — the envelope plus the requests badge next to `data`. */
data class ConversationListEnvelope(
    val success: Boolean,
    val data: List<ConversationDto>?,
    val pagination: PaginationDto? = null,
    @SerializedName("requests_count") val requestsCount: Int = 0,
)

data class ProfileDto(
    val type: String,
    val id: Int,
    val key: String,
    val name: String?,
    @SerializedName("account_name") val accountName: String? = null,
    val phone: String? = null,
    val avatar: String? = null,
    @SerializedName("is_contact") val isContact: Boolean = false,
    @SerializedName("is_me") val isMe: Boolean = false,
    @SerializedName("is_deleted") val isDeleted: Boolean = false,
)

data class GroupInfoDto(
    val name: String,
    val description: String?,
    val avatar: String?,
    @SerializedName("members_count") val membersCount: Int,
    @SerializedName("only_admins_send") val onlyAdminsSend: Boolean,
    @SerializedName("only_admins_edit_info") val onlyAdminsEditInfo: Boolean,
    @SerializedName("only_admins_add_members") val onlyAdminsAddMembers: Boolean,
)

data class LastMessageDto(
    val id: String,
    val type: String,
    val body: String?,
    val sender: ProfileDto?,
    @SerializedName("is_mine") val isMine: Boolean,
    val status: String?,
    @SerializedName("is_deleted") val isDeleted: Boolean,
    val system: String?,
    @SerializedName("created_at") val createdAt: String?,
)

data class PresenceDto(val online: Boolean, @SerializedName("last_seen_at") val lastSeenAt: String?)

data class ConversationDto(
    val id: String,
    val type: String,
    val status: String,
    @SerializedName("is_request") val isRequest: Boolean,
    val title: String?,
    val avatar: String?,
    val peer: ProfileDto?,
    val group: GroupInfoDto?,
    @SerializedName("my_role") val myRole: String,
    @SerializedName("is_member") val isMember: Boolean,
    @SerializedName("can_send") val canSend: Boolean,
    @SerializedName("last_message") val lastMessage: LastMessageDto?,
    @SerializedName("last_message_at") val lastMessageAt: String?,
    @SerializedName("unread_count") val unreadCount: Int,
    @SerializedName("marked_unread") val markedUnread: Boolean,
    @SerializedName("has_unread_mention") val hasUnreadMention: Boolean,
    @SerializedName("is_pinned") val isPinned: Boolean,
    @SerializedName("is_archived") val isArchived: Boolean,
    @SerializedName("is_locked") val isLocked: Boolean,
    @SerializedName("is_muted") val isMuted: Boolean,
    @SerializedName("disappearing_seconds") val disappearingSeconds: Int?,
    // Only on the single-chat screen:
    val presence: PresenceDto? = null,
    @SerializedName("i_blocked") val iBlocked: Boolean? = null,
    @SerializedName("blocked_me") val blockedMe: Boolean? = null,
    @SerializedName("block_screenshots") val blockScreenshots: Boolean? = null,
) {
    val isGroup: Boolean get() = type == "group"
    val isAdmin: Boolean get() = myRole == "admin" || myRole == "owner"
}

data class AttachmentDto(
    val id: String,
    val url: String,
    val name: String?,
    @SerializedName("mime_type") val mimeType: String?,
    val size: Long?,
    val width: Int?,
    val height: Int?,
    @SerializedName("duration_ms") val durationMs: Long?,
)

data class ReplyPreviewDto(
    val id: String?,
    val type: String?,
    val body: String?,
    val sender: ProfileDto?,
    val thumbnail: String?,
    @SerializedName("is_deleted") val isDeleted: Boolean,
)

data class ReactionSummaryDto(val emoji: String, val count: Int)

data class ReactionsDto(val summary: List<ReactionSummaryDto> = emptyList(), val mine: String? = null, val total: Int = 0)

data class SystemDto(val event: String?, val actor: ProfileDto?, val targets: List<ProfileDto> = emptyList(), val text: String?)

data class MessageDto(
    val id: String,
    @SerializedName("conversation_id") val conversationId: String,
    val type: String,
    val body: String?,
    val meta: JsonObject?,
    val attachments: List<AttachmentDto> = emptyList(),
    val sender: ProfileDto?,
    @SerializedName("is_mine") val isMine: Boolean?,
    val status: String?,
    @SerializedName("reply_to") val replyTo: ReplyPreviewDto?,
    @SerializedName("is_forwarded") val isForwarded: Boolean = false,
    @SerializedName("forwarded_many_times") val forwardedManyTimes: Boolean = false,
    val mentions: List<ProfileDto> = emptyList(),
    @SerializedName("is_edited") val isEdited: Boolean = false,
    @SerializedName("is_deleted") val isDeleted: Boolean = false,
    @SerializedName("expires_at") val expiresAt: String?,
    val reactions: ReactionsDto = ReactionsDto(),
    @SerializedName("is_starred") val isStarred: Boolean = false,
    val system: SystemDto?,
    @SerializedName("created_at") val createdAt: String?,
)

data class MessagePageDto(
    val messages: List<MessageDto>,
    @SerializedName("has_more_before") val hasMoreBefore: Boolean,
    @SerializedName("has_more_after") val hasMoreAfter: Boolean,
)

data class GalleryPageDto(val messages: List<MessageDto>, @SerializedName("has_more") val hasMore: Boolean)

data class ReceiptRowDto(val participant: ProfileDto?, @SerializedName("delivered_at") val deliveredAt: JsonElement?, @SerializedName("read_at") val readAt: String?)

data class MessageInfoDto(
    @SerializedName("read_by") val readBy: List<ReceiptRowDto>,
    @SerializedName("delivered_to") val deliveredTo: List<ReceiptRowDto>,
    val pending: List<ReceiptRowDto>,
)

data class MemberDto(
    @SerializedName("participant_id") val participantId: Int,
    val role: String,
    @SerializedName("joined_at") val joinedAt: String?,
    val profile: ProfileDto?,
)

data class CreatedGroupDto(val conversation: ConversationDto, @SerializedName("not_added") val notAdded: List<String>)

data class InviteDto(val token: String, val link: String)

data class ContactDto(
    val id: Int,
    val name: String,
    val phone: String,
    @SerializedName("is_favorite") val isFavorite: Boolean,
    val source: String,
    @SerializedName("is_registered") val isRegistered: Boolean,
    val profile: ProfileDto?,
)

data class ContactEntry(val name: String, val phone: String)

data class ContactSyncRequest(val contacts: List<ContactEntry>, val full: Boolean)

data class QrDto(val token: String, val payload: String)

data class PrivacyDto(
    @SerializedName("last_seen") val lastSeen: String,
    @SerializedName("profile_photo") val profilePhoto: String,
    @SerializedName("who_can_message") val whoCanMessage: String,
    @SerializedName("who_can_add_to_groups") val whoCanAddToGroups: String,
    @SerializedName("who_can_call") val whoCanCall: String,
    @SerializedName("read_receipts") val readReceipts: Boolean,
    @SerializedName("block_screenshots") val blockScreenshots: Boolean,
    @SerializedName("notification_preview") val notificationPreview: Boolean,
)

data class BlockDto(val profile: ProfileDto?, @SerializedName("blocked_at") val blockedAt: String?)

data class OpenDirectRequest(@SerializedName("participant_id") val participantId: Int, @SerializedName("participant_type") val participantType: String = "user")

data class CallParticipantDto(val profile: ProfileDto?, val status: String)

data class CallDto(
    val id: String,
    @SerializedName("conversation_id") val conversationId: String,
    @SerializedName("conversation_type") val conversationType: String,
    @SerializedName("group_name") val groupName: String?,
    val type: String,
    val status: String,
    @SerializedName("is_outgoing") val isOutgoing: Boolean,
    val initiator: ProfileDto?,
    val participants: List<CallParticipantDto> = emptyList(),
    @SerializedName("answered_at") val answeredAt: String?,
    @SerializedName("ended_at") val endedAt: String?,
    @SerializedName("duration_seconds") val durationSeconds: Int?,
    @SerializedName("created_at") val createdAt: String?,
)

data class JoinDto(val url: String, val room: String, val token: String)

data class CallSessionDto(val call: CallDto, val join: JoinDto?)
