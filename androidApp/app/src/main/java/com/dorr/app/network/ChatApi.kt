package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.MediaType.Companion.toMediaType
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

    /** My "note to self" chat (created on first use). */
    @POST("mobile/v1/chat/conversations/self")
    suspend fun openSelf(@Header("Authorization") auth: String): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/conversations/{id}")
    suspend fun conversation(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ConversationDto>

    @PATCH("mobile/v1/chat/conversations/{id}/settings")
    suspend fun updateSettings(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<ConversationDto>

    /**
     * The same settings call, with a body that keeps `null`s ([jsonKeepingNulls]): Retrofit's Gson
     * drops null map values, so `theme_id: null` / `custom_theme: null` ("back to Dorr's look")
     * never reached the server through [updateSettings].
     */
    @PATCH("mobile/v1/chat/conversations/{id}/settings")
    suspend fun updateSettingsJson(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: RequestBody): ApiEnvelope<ConversationDto>

    /** My own wallpaper for this chat (only I see it). */
    @Multipart
    @POST("mobile/v1/chat/conversations/{id}/wallpaper")
    suspend fun uploadWallpaper(@Header("Authorization") auth: String, @Path("id") id: String, @Part image: MultipartBody.Part): ApiEnvelope<ConversationDto>

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

    /** "Read later": set a message aside for me (or take it off once done). */
    @PUT("mobile/v1/chat/messages/{id}/read-later")
    suspend fun readLater(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/messages/read-later")
    suspend fun readLaterList(@Header("Authorization") auth: String): ApiEnvelope<List<MessageDto>>

    /** "Needs a reply": on my follow-up list until I answer (or take it off). */
    @PUT("mobile/v1/chat/messages/{id}/follow-up")
    suspend fun followUp(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/messages/follow-up")
    suspend fun followUpList(@Header("Authorization") auth: String): ApiEnvelope<List<MessageDto>>

    /** Remind me about this message: `remind_at` (ISO-8601 with offset), `note?`. */
    @PUT("mobile/v1/chat/messages/{id}/reminder")
    suspend fun setReminder(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<JsonElement?>

    @DELETE("mobile/v1/chat/messages/{id}/reminder")
    suspend fun clearReminder(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/reminders")
    suspend fun reminders(@Header("Authorization") auth: String): ApiEnvelope<List<ReminderDto>>

    /** My personal status: `emoji?`, `text?`, `until?` (ISO), `audience?` (everyone · contacts · nobody). */
    @PUT("mobile/v1/chat/status")
    suspend fun setStatus(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<PrivacyDto>

    @DELETE("mobile/v1/chat/status")
    suspend fun clearStatus(@Header("Authorization") auth: String): ApiEnvelope<PrivacyDto>

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

    // ------------------------------------------------------------------ polls, view once, live location, link cards

    @PUT("mobile/v1/chat/messages/{id}/vote")
    suspend fun vote(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, List<Int>>): ApiEnvelope<MessageDto>

    @GET("mobile/v1/chat/messages/{id}/votes")
    suspend fun votes(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<PollOptionVotersDto>>

    @POST("mobile/v1/chat/messages/{id}/open")
    suspend fun openViewOnce(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ViewOnceFilesDto>

    @PUT("mobile/v1/chat/messages/{id}/live-location")
    suspend fun moveLive(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Double?>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/messages/{id}/live-location/stop")
    suspend fun stopLive(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    @GET("mobile/v1/chat/live-locations")
    suspend fun myLiveLocations(@Header("Authorization") auth: String): ApiEnvelope<List<MyLiveLocationDto>>

    @GET("mobile/v1/chat/link-preview")
    suspend fun linkPreview(@Header("Authorization") auth: String, @Query("url") url: String): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------------ money requests & bill splits

    /** A real wallet transfer to the requester, checked with the wallet PIN. */
    @POST("mobile/v1/chat/messages/{id}/pay")
    suspend fun payRequest(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    /** Send money straight from a one-to-one chat (PIN in `X-Wallet-Pin`): `amount_minor`, `note?`, `uuid`. */
    @POST("mobile/v1/chat/conversations/{id}/send-money")
    suspend fun sendMoney(@Header("Authorization") auth: String, @Header("X-Wallet-Pin") pin: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<MessageDto>

    @POST("mobile/v1/chat/messages/{id}/decline-request")
    suspend fun declineRequest(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    @POST("mobile/v1/chat/messages/{id}/cancel-request")
    suspend fun cancelRequest(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    // ------------------------------------------------------------------ stickers & GIFs

    @GET("mobile/v1/chat/stickers")
    suspend fun stickers(@Header("Authorization") auth: String): ApiEnvelope<StickerLibraryDto>

    /** A sticker I made from my own photo (512×512 PNG / WebP) — kept in "My stickers". */
    @Multipart
    @POST("mobile/v1/chat/stickers/mine")
    suspend fun uploadMySticker(@Header("Authorization") auth: String, @Part image: MultipartBody.Part): ApiEnvelope<StickerDto>

    @DELETE("mobile/v1/chat/stickers/mine/{id}")
    suspend fun deleteMySticker(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<JsonElement?>

    /** Giphy: trending (no q) or search. `kind`: gifs · stickers. */
    @GET("mobile/v1/chat/gifs")
    suspend fun gifs(@Header("Authorization") auth: String, @Query("kind") kind: String, @Query("q") q: String? = null, @Query("offset") offset: Int = 0): ApiEnvelope<GifPageDto>

    // ------------------------------------------------------------------ channels

    @Multipart
    @POST("mobile/v1/chat/channels")
    suspend fun createChannel(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part avatar: MultipartBody.Part?,
    ): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/channels/discover")
    suspend fun discoverChannels(@Header("Authorization") auth: String, @Query("search") search: String? = null, @Query("per_page") perPage: Int = 30): ApiEnvelope<List<ChannelCardDto>>

    @POST("mobile/v1/chat/channels/{id}/follow")
    suspend fun followChannel(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ConversationDto>

    @POST("mobile/v1/chat/channels/{id}/unfollow")
    suspend fun unfollowChannel(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @PATCH("mobile/v1/chat/channels/{id}/handle")
    suspend fun channelHandle(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String?>): ApiEnvelope<ConversationDto>

    // ------------------------------------------------------------------ joining groups by link

    @GET("mobile/v1/chat/invites/{token}")
    suspend fun previewInvite(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<InvitePreviewDto>

    /** 200 + the conversation, or 202 + `{status: pending}` when the admins approve new members. */
    @POST("mobile/v1/chat/invites/{token}/join")
    suspend fun joinByInvite(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/invites/{token}/cancel")
    suspend fun cancelJoin(@Header("Authorization") auth: String, @Path("token") token: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/groups/{id}/join-requests")
    suspend fun joinRequests(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<JoinRequestDto>>

    @POST("mobile/v1/chat/groups/{id}/join-requests/{request}/approve")
    suspend fun approveJoin(@Header("Authorization") auth: String, @Path("id") id: String, @Path("request") request: Int): ApiEnvelope<List<JoinRequestDto>>

    @POST("mobile/v1/chat/groups/{id}/join-requests/{request}/reject")
    suspend fun rejectJoin(@Header("Authorization") auth: String, @Path("id") id: String, @Path("request") request: Int): ApiEnvelope<List<JoinRequestDto>>

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
    suspend fun groupSettings(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<ConversationDto>

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

    /** The owner hands the group / channel to another member (and stays an admin). */
    @POST("mobile/v1/chat/groups/{id}/owner")
    suspend fun transferOwnership(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Int>): ApiEnvelope<ConversationDto>

    /** The owner deletes the group / channel for everyone. */
    @DELETE("mobile/v1/chat/groups/{id}")
    suspend fun deleteGroup(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/groups/{id}/invite")
    suspend fun invite(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<InviteDto>

    /** A new link (the old one stops working), lasting `expires_in_hours` (1, 24, 168, 720) or forever. */
    @POST("mobile/v1/chat/groups/{id}/invite/reset")
    suspend fun resetInvite(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Int>): ApiEnvelope<InviteDto>

    // ------------------------------------------------------------------ scheduled messages

    @GET("mobile/v1/chat/conversations/{id}/scheduled")
    suspend fun scheduled(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<ScheduledMessageDto>>

    /** `send_at`: ISO-8601 with its offset. */
    @POST("mobile/v1/chat/conversations/{id}/scheduled")
    suspend fun schedule(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<ScheduledMessageDto>

    @PATCH("mobile/v1/chat/scheduled/{id}")
    suspend fun updateScheduled(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any>): ApiEnvelope<ScheduledMessageDto>

    @DELETE("mobile/v1/chat/scheduled/{id}")
    suspend fun deleteScheduled(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/scheduled/{id}/send")
    suspend fun sendScheduledNow(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<MessageDto>

    // ------------------------------------------------------------------ AI (each call is one tap)

    @GET("mobile/v1/chat/ai")
    suspend fun aiCapabilities(@Header("Authorization") auth: String): ApiEnvelope<AiCapabilitiesDto>

    /** Into my language unless `to` says another (ar, en, fr…). */
    @POST("mobile/v1/chat/messages/{id}/translate")
    suspend fun translate(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, String>): ApiEnvelope<AiTextDto>

    @POST("mobile/v1/chat/messages/{id}/transcribe")
    suspend fun transcribe(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AiTextDto>

    @POST("mobile/v1/chat/conversations/{id}/summarize")
    suspend fun summarize(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<AiSummaryDto>

    @POST("mobile/v1/chat/conversations/{id}/smart-replies")
    suspend fun smartReplies(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AiRepliesDto>

    // ------------------------------------------------------------------ business tools

    @GET("mobile/v1/chat/business")
    suspend fun business(@Header("Authorization") auth: String): ApiEnvelope<BusinessDto>

    @PATCH("mobile/v1/chat/business")
    suspend fun updateBusiness(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<BusinessProfileDto>

    @GET("mobile/v1/chat/quick-replies")
    suspend fun quickReplies(@Header("Authorization") auth: String): ApiEnvelope<List<QuickReplyDto>>

    @POST("mobile/v1/chat/quick-replies")
    suspend fun addQuickReply(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<QuickReplyDto>

    @PATCH("mobile/v1/chat/quick-replies/{id}")
    suspend fun updateQuickReply(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: Map<String, String>): ApiEnvelope<QuickReplyDto>

    @DELETE("mobile/v1/chat/quick-replies/{id}")
    suspend fun deleteQuickReply(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<JsonElement?>

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

    /** The same, as JSON — so `privacy_schedule: null` (schedule off) isn't dropped by Gson. */
    @PATCH("mobile/v1/chat/privacy")
    suspend fun updatePrivacyJson(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<PrivacyDto>

    /** Quick privacy mode: every chat notification shows nothing for `minutes`. */
    @PUT("mobile/v1/chat/privacy-mode")
    suspend fun privacyModeOn(@Header("Authorization") auth: String, @Body body: Map<String, Int>): ApiEnvelope<PrivacyDto>

    /** Privacy mode off — with what came in meanwhile. */
    @DELETE("mobile/v1/chat/privacy-mode")
    suspend fun privacyModeOff(@Header("Authorization") auth: String): ApiEnvelope<PrivacyOffDto>

    @GET("mobile/v1/chat/privacy-mode/summary")
    suspend fun privacySummary(@Header("Authorization") auth: String, @Query("from") from: String): ApiEnvelope<PrivacySummaryDto>

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
    /** Their personal status ("🏖️ On holiday"), when its audience includes me. */
    val status: UserStatusDto? = null,
)

/** A personal status: an emoji and a few words, until a time (or until cleared). */
data class UserStatusDto(val emoji: String? = null, val text: String? = null, val until: String? = null)

/** My own status as I set it (also once it has ended). */
data class MyStatusDto(
    val emoji: String? = null,
    val text: String? = null,
    val until: String? = null,
    val audience: String = "contacts",
    val active: Boolean = false,
)

/** A reminder I set on a message. */
data class ReminderDto(@SerializedName("remind_at") val remindAt: String, val note: String? = null, val message: MessageDto)

data class GroupInfoDto(
    val name: String,
    val description: String?,
    val avatar: String?,
    @SerializedName("members_count") val membersCount: Int,
    @SerializedName("only_admins_send") val onlyAdminsSend: Boolean,
    @SerializedName("only_admins_edit_info") val onlyAdminsEditInfo: Boolean,
    @SerializedName("only_admins_add_members") val onlyAdminsAddMembers: Boolean,
    /** New members from the invite link wait for an admin. */
    @SerializedName("approve_joins") val approveJoins: Boolean = false,
    /** Admins only: how many are waiting. */
    @SerializedName("pending_join_requests") val pendingJoinRequests: Int = 0,
    /** Channels: @handle and whether it's listed in Discover. */
    val handle: String? = null,
    @SerializedName("is_public") val isPublic: Boolean = false,
    /** Slow mode: members wait this long between messages (0 = off; admins never wait). */
    @SerializedName("slow_mode_seconds") val slowModeSeconds: Int = 0,
    /** Admins only (null for members): words a member's message may not contain. */
    @SerializedName("banned_words") val bannedWords: List<String>? = null,
)

data class StickerDto(
    val id: Int,
    /** 0 for one of "My stickers". */
    @SerializedName("pack_id") val packId: Int = 0,
    val emoji: String?,
    val url: String?,
    val width: Int? = null,
    val height: Int? = null,
)

data class StickerPackDto(val id: Int, val name: String?, val cover: String?, val stickers: List<StickerDto> = emptyList())

data class StickerLibraryDto(
    val packs: List<StickerPackDto> = emptyList(),
    /** The stickers I made from my own photos, newest first. */
    val mine: List<StickerDto> = emptyList(),
    @SerializedName("library_enabled") val libraryEnabled: Boolean = false,
)

/** A Giphy GIF or animated sticker. */
data class GifDto(
    val id: String,
    val kind: String,
    val title: String?,
    val url: String,
    val webp: String? = null,
    val preview: String?,
    val width: Int = 0,
    val height: Int = 0,
)

data class GifPageDto(val items: List<GifDto> = emptyList(), @SerializedName("next_offset") val nextOffset: Int? = null)

/** A channel as Discover shows it. */
data class ChannelCardDto(
    val id: String,
    val name: String?,
    val description: String?,
    val avatar: String?,
    val handle: String?,
    @SerializedName("is_public") val isPublic: Boolean,
    @SerializedName("followers_count") val followersCount: Int,
    @SerializedName("is_following") val isFollowing: Boolean,
    @SerializedName("last_post_at") val lastPostAt: String?,
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
    @SerializedName("is_sensitive") val isSensitive: Boolean = false,
)

data class PresenceDto(val online: Boolean, @SerializedName("last_seen_at") val lastSeenAt: String?)

data class ConversationDto(
    val id: String,
    val type: String,
    val status: String,
    @SerializedName("is_request") val isRequest: Boolean,
    /** "Note to self": only me in it — no calls, no presence, no peer. */
    @SerializedName("is_self") val isSelf: Boolean = false,
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
    /** Calls work here (on for the platform and in both people's countries). Null in lists = assume yes. */
    @SerializedName("can_call") val canCall: Boolean? = null,
    /** The other person is a business with opening hours: the week and whether it's open now. */
    val business: BusinessHoursDto? = null,
) {
    /** Group-like (a channel too: it has a group row, roles, an invite link). */
    val isGroup: Boolean get() = type == "group" || type == "channel"
    val isChannel: Boolean get() = type == "channel"
    val isAdmin: Boolean get() = myRole == "admin" || myRole == "owner"
}

/** A look the admin made: wallpaper (image or colour) + the two bubble colours. */
data class ChatThemeDto(
    val id: Int,
    val name: String?,
    val wallpaper: String?,
    @SerializedName("background_color") val backgroundColor: String?,
    // Null on my own look when neither I nor the theme under it set one (drawn in Dorr's colours).
    @SerializedName("sender_color") val senderColor: String?,
    @SerializedName("receiver_color") val receiverColor: String?,
    @SerializedName("is_dark") val isDark: Boolean,
    @SerializedName("is_default") val isDefault: Boolean,
    /** My own look for this chat (wallpaper / colours I picked), laid over a theme. */
    @SerializedName("is_custom") val isCustom: Boolean = false,
    /** How much my own wallpaper is darkened, 0–80 (%). */
    val dim: Int = 0,
)

/** My own look for one chat, as I edit it — only I see it. Null fields = the theme's. */
data class CustomThemeDto(
    val wallpaper: String? = null,
    @SerializedName("sender_color") val senderColor: String? = null,
    @SerializedName("receiver_color") val receiverColor: String? = null,
    @SerializedName("background_color") val backgroundColor: String? = null,
    val dim: Int? = null,
)

/** A JSON request body that keeps `null` values (Retrofit's own Gson leaves them out). */
fun jsonKeepingNulls(body: Map<String, Any?>): RequestBody =
    com.google.gson.GsonBuilder().serializeNulls().create().toJson(body)
        .toRequestBody("application/json; charset=utf-8".toMediaType())

/** My pick for a chat, and what to draw (the pick, or the admin's default; null = Dorr's own look). */
data class ConversationThemeDto(
    @SerializedName("theme_id") val themeId: Int?,
    /** My own wallpaper / colours for this chat, if I made some. */
    val custom: CustomThemeDto? = null,
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
    @com.google.gson.annotations.JsonAdapter(NullableJsonObjectAdapter::class)
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
    /** On my "read later" list. */
    @SerializedName("is_read_later") val isReadLater: Boolean = false,
    /** On my "needs a reply" list. */
    @SerializedName("is_follow_up") val isFollowUp: Boolean = false,
    /** When I asked to be reminded about it (ISO), or null. */
    @SerializedName("reminder_at") val reminderAt: String? = null,
    /** Sent as urgent (it got through the recipient's mute). */
    @SerializedName("is_urgent") val isUrgent: Boolean = false,
    /** Sensitive: hidden until the recipient unlocks it, never in a notification. */
    @SerializedName("is_sensitive") val isSensitive: Boolean = false,
    val system: SystemDto?,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("view_once") val viewOnce: Boolean = false,
    /** Mine: someone opened it · theirs: I already opened it. */
    @SerializedName("view_once_opened") val viewOnceOpened: Boolean? = null,
    @SerializedName("link_preview") val linkPreview: LinkPreviewDto? = null,
    val poll: PollDto? = null,
    @SerializedName("live_location") val liveLocation: LiveLocationDto? = null,
    /** Money request / bill split, as I see it. */
    val payment: PaymentDto? = null,
    /** Channel posts: how many followers saw it. */
    val views: Int? = null,
    /** Sent without a notification sound. */
    @SerializedName("is_silent") val isSilent: Boolean = false,
    /** My own message: until when the server still accepts an edit / "delete for everyone" (null = no more). */
    @SerializedName("edit_until") val editUntil: String? = null,
    @SerializedName("delete_until") val deleteUntil: String? = null,
)

data class PaymentShareDto(
    val profile: ProfileDto?,
    @SerializedName("amount_minor") val amountMinor: Long,
    /** pending · paid · declined · owner (the requester's own share) */
    val status: String,
    @SerializedName("paid_at") val paidAt: String? = null,
)

data class MyShareDto(@SerializedName("amount_minor") val amountMinor: Long, val status: String)

data class PaymentDto(
    /** request · split */
    val kind: String,
    /** request: pending · paid · declined · cancelled — split: open · settled · cancelled */
    val status: String,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("paid_minor") val paidMinor: Long = 0,
    val shares: List<PaymentShareDto> = emptyList(),
    @SerializedName("my_share") val myShare: MyShareDto? = null,
    val currency: String? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    @SerializedName("is_requester") val isRequester: Boolean = false,
    @SerializedName("can_pay") val canPay: Boolean = false,
    @SerializedName("can_cancel") val canCancel: Boolean = false,
    @SerializedName("paid_at") val paidAt: String? = null,
) {
    val due: Long get() = if (kind == "request") amountMinor else myShare?.amountMinor ?: 0
}

data class LinkPreviewDto(
    val url: String,
    val title: String?,
    val description: String?,
    val image: String?,
    @SerializedName("site_name") val siteName: String?,
)

data class PollOptionDto(val id: Int, val text: String, val votes: Int = 0)

data class PollDto(
    val question: String?,
    val multiple: Boolean,
    val options: List<PollOptionDto>,
    @SerializedName("my_votes") val myVotes: List<Int> = emptyList(),
    val voters: Int = 0,
)

data class PollOptionVotersDto(val id: Int, val text: String, val voters: List<ProfileDto> = emptyList())

data class LiveLocationDto(
    val active: Boolean,
    @SerializedName("live_until") val liveUntil: String?,
    @SerializedName("updated_at") val updatedAt: String?,
)

data class MyLiveLocationDto(
    @SerializedName("message_id") val messageId: String,
    @SerializedName("conversation_id") val conversationId: String,
    @SerializedName("live_until") val liveUntil: String,
)

data class ViewOnceFilesDto(val attachments: List<AttachmentDto> = emptyList())

data class InvitePreviewDto(
    @SerializedName("conversation_id") val conversationId: String,
    val name: String,
    val description: String?,
    val avatar: String?,
    @SerializedName("members_count") val membersCount: Int,
    @SerializedName("is_member") val isMember: Boolean,
    @SerializedName("approve_joins") val approveJoins: Boolean = false,
    @SerializedName("request_status") val requestStatus: String? = null,
)

data class JoinRequestDto(val id: Int, val profile: ProfileDto?, @SerializedName("requested_at") val requestedAt: String?)

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

data class InviteDto(val token: String, val link: String, @SerializedName("expires_at") val expiresAt: String? = null)

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
    /** What a chat notification shows: all (name and text) · name · none. */
    @SerializedName("notification_privacy") val notificationPrivacy: String? = null,
    /** Who may send me an urgent message (it gets through my mute). */
    @SerializedName("who_can_urgent") val whoCanUrgent: String? = null,
    val status: MyStatusDto? = null,
    @SerializedName("privacy_mode") val privacyMode: PrivacyModeDto? = null,
)

/** Privacy mode: on now (until a time, or by the daily schedule), and the schedule. */
data class PrivacyModeDto(
    val on: Boolean = false,
    val until: String? = null,
    @SerializedName("started_at") val startedAt: String? = null,
    val schedule: PrivacyScheduleDto? = null,
)

data class PrivacyScheduleDto(val from: String = "22:00", val to: String = "07:00", val days: List<Int>? = null, val timezone: String? = null)

/** What came in while I was private. */
data class PrivacySummaryDto(val messages: Int = 0, val conversations: Int = 0, val from: String? = null)

data class PrivacyOffDto(val settings: PrivacyDto, val summary: PrivacySummaryDto)

/** A text written now, sent by the server at `send_at` (or `failed`, with the reason). */
data class ScheduledMessageDto(
    val id: String,
    @SerializedName("conversation_id") val conversationId: String?,
    val body: String,
    @SerializedName("is_silent") val isSilent: Boolean = false,
    @SerializedName("send_at") val sendAt: String,
    val status: String,
    @SerializedName("error_code") val errorCode: String? = null,
)

/** Which AI tools the server offers now (a provider is set up and the admin left them on). */
data class AiCapabilitiesDto(
    val enabled: Boolean = false,
    val translate: Boolean = false,
    val summarize: Boolean = false,
    @SerializedName("smart_replies") val smartReplies: Boolean = false,
    val transcribe: Boolean = false,
)

data class AiTextDto(val text: String, val to: String? = null)

data class AiSummaryDto(val text: String, val messages: Int = 0)

data class AiRepliesDto(val replies: List<String> = emptyList())

/** One day of opening hours (the list is 7 days, Sunday first). */
data class BusinessDayDto(val open: Boolean, val from: String, val to: String)

data class BusinessProfileDto(
    @SerializedName("welcome_enabled") val welcomeEnabled: Boolean = false,
    @SerializedName("welcome_message") val welcomeMessage: String? = null,
    @SerializedName("away_enabled") val awayEnabled: Boolean = false,
    @SerializedName("away_message") val awayMessage: String? = null,
    /** always · outside_hours */
    @SerializedName("away_mode") val awayMode: String = "outside_hours",
    val hours: List<BusinessDayDto> = emptyList(),
    val timezone: String = "UTC",
)

data class QuickReplyDto(val id: Int, val shortcut: String, val body: String)

data class BusinessDto(
    val profile: BusinessProfileDto,
    @SerializedName("quick_replies") val quickReplies: List<QuickReplyDto> = emptyList(),
)

/** What a customer sees about a business on the chat screen. */
data class BusinessHoursDto(
    val hours: List<BusinessDayDto> = emptyList(),
    val timezone: String = "UTC",
    @SerializedName("open_now") val openNow: Boolean = true,
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
