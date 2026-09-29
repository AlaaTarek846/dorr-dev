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

    @GET("mobile/v1/chat/themes")
    suspend fun themes(@Header("Authorization") auth: String): ApiEnvelope<List<ChatThemeDto>>

    @GET("mobile/v1/chat/report-types")
    suspend fun reportTypes(@Header("Authorization") auth: String): ApiEnvelope<List<ReportTypeDto>>

    @POST("mobile/v1/chat/conversations/{id}/report")
    suspend fun report(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

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

    // ------------------------------------------------------------------ live location

    @GET("mobile/v1/chat/live-locations")
    suspend fun myLiveLocations(@Header("Authorization") auth: String): ApiEnvelope<List<LiveLocationDto>>

    @PUT("mobile/v1/chat/messages/{id}/live-location")
    suspend fun moveLive(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Body body: Map<String, @JvmSuppressWildcards Any?>,
    ): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/messages/{id}/live-location/stop")
    suspend fun stopLive(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    // ------------------------------------------------------------------ channels

    @GET("mobile/v1/chat/channels/discover")
    suspend fun discoverChannels(
        @Header("Authorization") auth: String,
        @Query("search") search: String? = null,
    ): ApiEnvelope<List<ChannelCardDto>>

    /** `channel` is a uuid or an @handle — the server takes either. */
    @GET("mobile/v1/chat/channels/{channel}")
    suspend fun showChannel(@Header("Authorization") auth: String, @Path("channel") channel: String): ApiEnvelope<ChannelCardDto>

    @Multipart
    @POST("mobile/v1/chat/channels")
    suspend fun createChannel(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part avatar: MultipartBody.Part?,
    ): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/channels/{channel}/follow")
    suspend fun followChannel(@Header("Authorization") auth: String, @Path("channel") channel: String): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/channels/{conversation}/unfollow")
    suspend fun unfollowChannel(@Header("Authorization") auth: String, @Path("conversation") id: String): ApiEnvelope<JsonElement?>

    @PUT("mobile/v1/chat/channels/{conversation}/handle")
    suspend fun setChannelHandle(
        @Header("Authorization") auth: String,
        @Path("conversation") id: String,
        @Body body: Map<String, String?>,
    ): ApiEnvelope<ConversationDto>

    // ------------------------------------------------------------------ expressions

    @GET("mobile/v1/chat/stickers")
    suspend fun stickers(@Header("Authorization") auth: String): ApiEnvelope<StickerLibraryDto>

    @GET("mobile/v1/chat/gifs")
    suspend fun gifs(
        @Header("Authorization") auth: String,
        @Query("kind") kind: String = "gifs",
        @Query("q") query: String? = null,
        @Query("offset") offset: Int = 0,
    ): ApiEnvelope<GiphyPageDto>

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

    // ------------------------------------------------------------------ polls, view once

    /** Replace my answer. An empty list takes the vote back. */
    @PUT("mobile/v1/chat/messages/{id}/vote")
    suspend fun vote(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Body body: Map<String, @JvmSuppressWildcards Any?>,
    ): ApiEnvelope<MessageDto?>

    /** Open a view-once file. The media comes back this one time only. */
    @POST("mobile/v1/chat/messages/{id}/open")
    suspend fun openViewOnce(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AttachmentListDto>

    // ------------------------------------------------------------------ invites

    /** The group behind an invite link, shown before joining. */
    @GET("mobile/v1/chat/invites/{token}")
    suspend fun previewInvite(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<InvitePreviewDto>

    @POST("mobile/v1/chat/invites/{token}/join")
    suspend fun joinByInvite(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------------ message extras

    /** The card for a link while it is still being typed in the composer. */
    @GET("mobile/v1/chat/link-preview")
    suspend fun linkPreview(
        @Header("Authorization") auth: String,
        @Query("url") url: String,
    ): ApiEnvelope<JsonElement?>

    /** Who voted for each option of a poll. */
    @GET("mobile/v1/chat/messages/{id}/votes")
    suspend fun votes(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<PollOptionVotersDto>>

    /** Pay a money request or my share of a split. The PIN travels in `X-Wallet-Pin`, not the body. */
    @POST("mobile/v1/chat/messages/{id}/pay")
    suspend fun payRequest(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String,
        @Path("id") id: String,
    ): ApiEnvelope<MessageDto?>

    @POST("mobile/v1/chat/messages/{id}/decline-request")
    suspend fun declineRequest(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    @POST("mobile/v1/chat/messages/{id}/cancel-request")
    suspend fun cancelRequest(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    // ------------------------------------------------------------------ group join requests
    // When a group sets `approve_joins`, joining by link leaves a pending request for the admins.

    @GET("mobile/v1/chat/groups/{id}/join-requests")
    suspend fun joinRequests(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<JoinRequestDto>>

    @POST("mobile/v1/chat/groups/{id}/join-requests/{request}/approve")
    suspend fun approveJoin(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Path("request") request: Int,
    ): ApiEnvelope<List<JoinRequestDto>>

    @POST("mobile/v1/chat/groups/{id}/join-requests/{request}/reject")
    suspend fun rejectJoin(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Path("request") request: Int,
    ): ApiEnvelope<List<JoinRequestDto>>

    /** Withdraw my own pending request. */
    @POST("mobile/v1/chat/invites/{token}/join/cancel")
    suspend fun cancelJoin(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<JsonElement?>

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

    // ------------------------------------------------------------------ folders

    @GET("mobile/v1/chat/folders")
    suspend fun folders(@Header("Authorization") auth: String): ApiEnvelope<List<FolderDto>>

    @POST("mobile/v1/chat/folders")
    suspend fun createFolder(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<FolderDto>

    @PATCH("mobile/v1/chat/folders/{id}")
    suspend fun renameFolder(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: Map<String, String>): ApiEnvelope<FolderDto>

    @DELETE("mobile/v1/chat/folders/{id}")
    suspend fun deleteFolder(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<JsonElement?>

    @PUT("mobile/v1/chat/folders/{id}/conversations")
    suspend fun folderConversations(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: Map<String, List<String>>): ApiEnvelope<FolderDto>

    // ------------------------------------------------------------------ stories

    @GET("mobile/v1/chat/stories")
    suspend fun stories(@Header("Authorization") auth: String): ApiEnvelope<StoryFeedDto>

    @Multipart
    @POST("mobile/v1/chat/stories")
    suspend fun postStory(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part file: MultipartBody.Part?,
    ): ApiEnvelope<JsonElement?>

    @DELETE("mobile/v1/chat/stories/{id}")
    suspend fun deleteStory(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/stories/{id}/view")
    suspend fun viewStory(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @PUT("mobile/v1/chat/stories/{id}/reaction")
    suspend fun reactStory(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String?>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/stories/{id}/reply")
    suspend fun replyStory(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<MessageDto>

    @GET("mobile/v1/chat/stories/{id}/viewers")
    suspend fun storyViewers(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<StoryViewerDto>>

    @GET("mobile/v1/chat/stories/privacy")
    suspend fun storyPrivacy(@Header("Authorization") auth: String): ApiEnvelope<StoryPrivacyDto>

    @PUT("mobile/v1/chat/stories/privacy")
    suspend fun updateStoryPrivacy(@Header("Authorization") auth: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<StoryPrivacyDto>

    @POST("mobile/v1/chat/stories/mute")
    suspend fun muteStories(@Header("Authorization") auth: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<StoryFeedDto>

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
    @SerializedName("onesignal_app_id") val oneSignalAppId: String? = null,
)

data class FolderDto(
    val id: Int,
    val name: String,
    @SerializedName("sort_order") val sortOrder: Int = 0,
    @SerializedName("conversations_count") val conversationsCount: Int = 0,
    @SerializedName("conversation_ids") val conversationIds: List<String> = emptyList(),
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
    /** The @handle — a channel can be looked up by it, an ordinary group has none. */
    @SerializedName("handle") val handle: String? = null,
    @SerializedName("members_count") val membersCount: Int,
    @SerializedName("only_admins_send") val onlyAdminsSend: Boolean,
    @SerializedName("only_admins_edit_info") val onlyAdminsEditInfo: Boolean,
    @SerializedName("only_admins_add_members") val onlyAdminsAddMembers: Boolean,
        /** Join by link leaves a pending request for the admins instead of adding the member. */
        @SerializedName("approve_joins") val approveJoins: Boolean = false,
        /** How many people are waiting for an answer; only admins are told (0 for everyone else). */
        @SerializedName("pending_join_requests") val pendingJoinRequests: Int = 0,
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
    val theme: ConversationThemeDto? = null,
    // Only on the single-chat screen:
    val presence: PresenceDto? = null,
    @SerializedName("i_blocked") val iBlocked: Boolean? = null,
    @SerializedName("blocked_me") val blockedMe: Boolean? = null,
    @SerializedName("block_screenshots") val blockScreenshots: Boolean? = null,
) {
    val isGroup: Boolean get() = type == "group"
    val isAdmin: Boolean get() = myRole == "admin" || myRole == "owner"
}

/** A look the admin made: wallpaper (image or colour) + the two bubble colours. */
data class ChatThemeDto(
    val id: Int,
    val name: String?,
    val wallpaper: String?,
    @SerializedName("background_color") val backgroundColor: String?,
    @SerializedName("sender_color") val senderColor: String,
    @SerializedName("receiver_color") val receiverColor: String,
    @SerializedName("is_dark") val isDark: Boolean,
    @SerializedName("is_default") val isDefault: Boolean,
)

/** My pick for a chat, and what to draw (the pick, or the admin's default; null = Dorr's own look). */
data class ConversationThemeDto(
    @SerializedName("theme_id") val themeId: Int?,
    val applied: ChatThemeDto?,
)

data class ReportTypeDto(val id: Int, val name: String?)

data class AttachmentDto(
    val id: String,
    val url: String,
    val name: String?,
    @SerializedName("mime_type") val mimeType: String?,
    val size: Long?,
    val width: Int?,
    val height: Int?,
    @SerializedName("duration_ms") val durationMs: Long?,
        /** Videos: the poster frame. */
        val thumbnail: String? = null,
    )

    /** Opening a view-once file answers with the one set of files it was allowed to show. */
    data class AttachmentListDto(
        val attachments: List<AttachmentDto> = emptyList(),
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
    /** Money requests and split bills: which of the two shapes it is, from `kind`. */
    val payment: PaymentDto? = null,
    val poll: PollDto? = null,
    @SerializedName("view_once_opened") val viewOnceOpened: Boolean = false,
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

data class StoryStyleDto(val background: String? = null, val font: String? = null, val align: String? = null)

data class StoryMediaDto(val url: String, @SerializedName("mime_type") val mimeType: String?)

data class StoryDto(
    val id: String,
    val type: String,
    val body: String?,
    val style: StoryStyleDto?,
    val media: StoryMediaDto?,
    @SerializedName("duration_ms") val durationMs: Long?,
    @SerializedName("allow_replies") val allowReplies: Boolean,
    @SerializedName("is_mine") val isMine: Boolean,
    val seen: Boolean,
    @SerializedName("my_reaction") val myReaction: String?,
    val views: Int?,
    val reactions: Int?,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("expires_at") val expiresAt: String?,
)

data class StoryGroupDto(
    val owner: ProfileDto?,
    val stories: List<StoryDto>,
    @SerializedName("all_seen") val allSeen: Boolean,
    @SerializedName("last_at") val lastAt: String?,
    @SerializedName("block_screenshots") val blockScreenshots: Boolean = false,
)

data class StoryFeedDto(val mine: StoryGroupDto?, val recent: List<StoryGroupDto> = emptyList(), val muted: List<StoryGroupDto> = emptyList())

data class StoryViewerDto(val viewer: ProfileDto?, @SerializedName("viewed_at") val viewedAt: String?, val reaction: String?)

data class StoryPrivacyDto(val audience: String, val except: List<ProfileDto> = emptyList(), val only: List<ProfileDto> = emptyList())

data class JoinDto(val url: String, val room: String, val token: String)

data class CallSessionDto(val call: CallDto, val join: JoinDto?)

/** One of my live locations the server still has running (resume after an app restart). */
data class LiveLocationDto(
    @SerializedName("message_id") val messageId: String,
    @SerializedName("conversation_id") val conversationId: String,
    @SerializedName("live_until") val liveUntil: String,
    /** Not a server field: the app works it out from `live_until` and the message's `stopped` meta. */
    val active: Boolean = false,
)

/** A channel in the discover list / the preview sheet (Channels\ChannelService::present). */
data class ChannelCardDto(
    val id: String,
    val name: String?,
    val description: String?,
    val avatar: String?,
    val handle: String?,
    @SerializedName("is_public") val isPublic: Boolean = false,
    @SerializedName("followers_count") val followersCount: Int = 0,
    @SerializedName("is_following") val isFollowing: Boolean = false,
    @SerializedName("last_post_at") val lastPostAt: String? = null,
)

/** One sticker in a Dorr pack (StickerService / ChatSticker::present). */
data class StickerDto(
    val id: Int,
    @SerializedName("pack_id") val packId: Int = 0,
    val emoji: String? = null,
    val url: String? = null,
    val width: Int? = null,
    val height: Int? = null,
)

data class StickerPackDto(
    val id: Int,
    val name: String? = null,
    val cover: String? = null,
    val stickers: List<StickerDto> = emptyList(),
)

/** The composer's sticker panel: Dorr's packs, plus whether the Giphy library is switched on. */
data class StickerLibraryDto(
    val packs: List<StickerPackDto> = emptyList(),
    @SerializedName("library_enabled") val libraryEnabled: Boolean = false,
)

/** One GIF / animated sticker from Giphy (GiphyService::normalize). */
data class GifDto(
    val id: String,
    val source: String = "giphy",
    val kind: String = "gif",
    val title: String? = null,
    val url: String,
    val webp: String? = null,
    val mp4: String? = null,
    val preview: String? = null,
    val width: Int = 0,
    val height: Int = 0,
)

/** One page of the Giphy library; `nextOffset` is null when there is nothing more. */
data class GiphyPageDto(
    val items: List<GifDto> = emptyList(),
    @SerializedName("next_offset") val nextOffset: Int? = null,
)

/** One option of a poll, with how many people ticked it. */
data class PollOptionDto(
    val id: Int,
    val text: String,
    val votes: Int = 0,
)

/** A poll as this viewer sees it: the options, their counts, and which ones I ticked. */
data class PollDto(
    val question: String? = null,
    val multiple: Boolean = false,
    val options: List<PollOptionDto> = emptyList(),
    /** How many distinct people voted, across every option. */
    val voters: Int = 0,
    @SerializedName("my_votes") val myVotes: List<Int> = emptyList(),
)

/** A link card (LinkPreviewService / ChatLinkPreview::card). */
data class LinkPreviewDto(
    val url: String,
    val title: String? = null,
    val description: String? = null,
    val image: String? = null,
    @SerializedName("site_name") val siteName: String? = null,
)

/** Who voted for one poll option (MessageExtrasService::voters). */
data class PollOptionVotersDto(
    val id: Int,
    val text: String,
    val voters: List<ProfileDto> = emptyList(),
)

/** The group behind an invite link, before joining (GroupService::previewInvite). */
data class InvitePreviewDto(
    @SerializedName("conversation_id") val conversationId: String,
    val name: String? = null,
    val description: String? = null,
    val avatar: String? = null,
    @SerializedName("members_count") val membersCount: Int = 0,
    @SerializedName("is_member") val isMember: Boolean = false,
    /** When true, joining by link asks the admins instead of adding me straight away. */
    @SerializedName("approve_joins") val approveJoins: Boolean = false,
    /** `type:id`, the key the chat list matches a join decision against. */
    val key: String? = null,
        /** `pending` while a request is in, `approved`/`rejected` after an admin answered. */
        @SerializedName("request_status") val requestStatus: String? = null,
)

/** Someone waiting to join a group that asks an admin first. */
data class JoinRequestDto(
    val id: Int,
    val profile: ProfileDto? = null,
    @SerializedName("requested_at") val requestedAt: String? = null,
)

/** One person's share in a split bill (MoneyRequestService::present). */
data class SplitShareDto(
    val profile: ProfileDto? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val status: String = "pending",
    @SerializedName("paid_at") val paidAt: String? = null,
)

/**
 * A money request or a split bill on one message. `kind` says which: a request has `amountMinor`
 * / `due`, a split has `totalMinor` / `shares` / `myShare`; both carry the currency.
 */
data class PaymentDto(
    val kind: String = "request",
    val status: String = "pending",
    @SerializedName("is_requester") val isRequester: Boolean = false,
    @SerializedName("can_pay") val canPay: Boolean = false,
    @SerializedName("can_cancel") val canCancel: Boolean = false,
    // A money request:
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("paid_at") val paidAt: String? = null,
    // A split bill:
    @SerializedName("total_minor") val totalMinor: Long = 0,
    val mode: String = "equal",
    val shares: List<SplitShareDto> = emptyList(),
    @SerializedName("paid_minor") val paidMinor: Long = 0,
    @SerializedName("my_share") val myShare: SplitShareDto? = null,
    // Currency, on both:
    val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("decimal_places") val decimalPlaces: Int = 2,
) {
    /** What the card shows as the amount still owed. */
    val due: Long get() = if (kind == "split") myShare?.amountMinor ?: 0L else amountMinor
}
