package com.dorr.freelance.net

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.ApiError
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.network.parseError
import com.google.gson.annotations.SerializedName
import java.io.IOException
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FL-L12, the professional community under `/api/freelance/v1/community`: a LinkedIn-like feed for freelancers only (per country), posts
 * (a post, a question, or a finished work from my portfolio) with up to 4 images, comments with one level of replies, reactions, saves,
 * follows and reports. Reading is open to any freelancer; writing needs a verified account and no mute from the staff. Lists page with a
 * `cursor`: the answer carries `next_cursor` / `has_more` next to `data` (see [cursorCall]). The switch is the country feature `community`
 * (`freelance_feature_off` when it is off).
 */
interface CommunityApi {
    @GET("${ApiClient.FREELANCE}community")
    suspend fun home(): Response<ApiEnvelope<CommunityHomeDto>>

    /** `tab`: for_you · following · specialty · mine · saved; `kind`: post · question · work; `member`: one member's posts. */
    @GET("${ApiClient.FREELANCE}community/feed")
    suspend fun feed(
        @Query("tab") tab: String? = null,
        @Query("kind") kind: String? = null,
        @Query("specialty_id") specialtyId: Int? = null,
        @Query("search") search: String? = null,
        @Query("member") member: String? = null,
        @Query("cursor") cursor: String? = null,
        @Query("per_page") perPage: Int? = 15,
    ): Response<CursorEnvelope<List<CommunityPostDto>>>

    /** The post and its first comments (the best answer first, then the oldest), each with its first replies. */
    @GET("${ApiClient.FREELANCE}community/posts/{uuid}")
    suspend fun post(@Path("uuid") uuid: String): Response<CursorEnvelope<CommunityPostDetailDto>>

    /** Multipart: `kind`, `body`, `specialty_id?`, `portfolio_item?` (kind work) as [fields]; up to 4 `images[]`. */
    @Multipart
    @POST("${ApiClient.FREELANCE}community/posts")
    suspend fun create(@PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part images: List<MultipartBody.Part>): Response<ApiEnvelope<CommunityPostDto>>

    @PUT("${ApiClient.FREELANCE}community/posts/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: CommunityPostUpdate): Response<ApiEnvelope<CommunityPostDto>>

    @DELETE("${ApiClient.FREELANCE}community/posts/{uuid}")
    suspend fun delete(@Path("uuid") uuid: String): Response<ApiEnvelope<Any>>

    @GET("${ApiClient.FREELANCE}community/posts/{uuid}/comments")
    suspend fun comments(@Path("uuid") uuid: String, @Query("cursor") cursor: String? = null): Response<CursorEnvelope<List<CommunityCommentDto>>>

    @POST("${ApiClient.FREELANCE}community/posts/{uuid}/comments")
    suspend fun comment(@Path("uuid") uuid: String, @Body body: CommunityCommentRequest): Response<ApiEnvelope<CommunityCommentDto>>

    /** A question's best answer (`comment` null takes the mark off). The question's author only. */
    @POST("${ApiClient.FREELANCE}community/posts/{uuid}/accept")
    suspend fun accept(@Path("uuid") uuid: String, @Body body: CommunityAcceptRequest): Response<ApiEnvelope<CommunityPostDto>>

    @POST("${ApiClient.FREELANCE}community/posts/{uuid}/save")
    suspend fun save(@Path("uuid") uuid: String): Response<ApiEnvelope<CommunitySavedDto>>

    @DELETE("${ApiClient.FREELANCE}community/posts/{uuid}/save")
    suspend fun unsave(@Path("uuid") uuid: String): Response<ApiEnvelope<CommunitySavedDto>>

    /** [type]: `posts` · `comments`; `kind` null takes my reaction back. */
    @PUT("${ApiClient.FREELANCE}community/{type}/{uuid}/reaction")
    suspend fun react(@Path("type") type: String, @Path("uuid") uuid: String, @Body body: CommunityReactionRequest): Response<ApiEnvelope<CommunityReactionDto>>

    @GET("${ApiClient.FREELANCE}community/comments/{uuid}/replies")
    suspend fun replies(@Path("uuid") uuid: String, @Query("cursor") cursor: String? = null): Response<CursorEnvelope<List<CommunityCommentDto>>>

    @PUT("${ApiClient.FREELANCE}community/comments/{uuid}")
    suspend fun updateComment(@Path("uuid") uuid: String, @Body body: CommunityCommentRequest): Response<ApiEnvelope<CommunityCommentDto>>

    @DELETE("${ApiClient.FREELANCE}community/comments/{uuid}")
    suspend fun deleteComment(@Path("uuid") uuid: String): Response<ApiEnvelope<Any>>

    @GET("${ApiClient.FREELANCE}community/members/{uuid}")
    suspend fun member(@Path("uuid") uuid: String): Response<ApiEnvelope<CommunityMemberDto>>

    @POST("${ApiClient.FREELANCE}community/members/{uuid}/follow")
    suspend fun follow(@Path("uuid") uuid: String): Response<ApiEnvelope<CommunityMemberDto>>

    @DELETE("${ApiClient.FREELANCE}community/members/{uuid}/follow")
    suspend fun unfollow(@Path("uuid") uuid: String): Response<ApiEnvelope<CommunityMemberDto>>

    /** [which]: `followers` · `following`. */
    @GET("${ApiClient.FREELANCE}community/members/{uuid}/{which}")
    suspend fun people(@Path("uuid") uuid: String, @Path("which") which: String, @Query("cursor") cursor: String? = null): Response<CursorEnvelope<List<CommunityAuthorDto>>>

    /** 409 `freelance_community_report_exists` when already reported; 422 `freelance_community_report_own` on my own. */
    @POST("${ApiClient.FREELANCE}community/reports")
    suspend fun report(@Body body: CommunityReportRequest): Response<ApiEnvelope<CommunityReportDto>>
}

// ------------------------------------------------------------------------------------------------------------------- DTOs

data class CommunityHomeDto(
    val me: CommunityMemberDto = CommunityMemberDto(),
    @SerializedName("can_write") val canWrite: Boolean = false,
    val muted: CommunityMuteDto? = null,
    val limits: CommunityLimitsDto = CommunityLimitsDto(),
    val kinds: List<CommunityKeyLabel> = emptyList(),
    val reactions: List<CommunityKeyLabel> = emptyList(),
    @SerializedName("report_reasons") val reportReasons: List<CommunityKeyLabel> = emptyList(),
    val rules: List<String> = emptyList(),
    val specialties: List<CommunitySpecialtyDto> = emptyList(),
    @SerializedName("my_specialties") val mySpecialties: List<Int> = emptyList(),
)

data class CommunityMuteDto(val until: String? = null, val reason: String? = null)

data class CommunityLimitsDto(
    @SerializedName("body_min") val bodyMin: Int = 10,
    @SerializedName("body_max") val bodyMax: Int = 3000,
    @SerializedName("comment_max") val commentMax: Int = 1500,
    @SerializedName("max_images") val maxImages: Int = 4,
    @SerializedName("posts_per_day") val postsPerDay: Int = 5,
    @SerializedName("posts_today") val postsToday: Int = 0,
    @SerializedName("comments_per_day") val commentsPerDay: Int = 60,
    @SerializedName("comments_today") val commentsToday: Int = 0,
)

data class CommunityKeyLabel(val key: String = "", val label: String = "")

data class CommunitySpecialtyDto(val id: Int = 0, val name: String = "", val primary: Boolean = false)

/** The plan badge (`null` = the member's plan has none): `plan` key and its name in my language. */
data class CommunityBadgeDto(val plan: String? = null, val name: String? = null)

/** A member's card (`GET members/{uuid}`, the follow answer, and `me` in the home). */
data class CommunityMemberDto(
    val uuid: String = "",
    val name: String? = null,
    val headline: String? = null,
    val bio: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val badge: CommunityBadgeDto? = null,
    val verified: Boolean = false,
    val specialties: List<CommunitySpecialtyDto> = emptyList(),
    val posts: Int = 0,
    val followers: Int = 0,
    val following: Int = 0,
    @SerializedName("is_me") val isMe: Boolean = false,
    @SerializedName("is_following") val isFollowing: Boolean = false,
    /** In the home's `me` only. */
    val saved: Int = 0,
)

/** Who wrote a post or a comment (and a row of followers / following). [isFollowing] is null where the server does not say. */
data class CommunityAuthorDto(
    val uuid: String? = null,
    val name: String? = null,
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val badge: CommunityBadgeDto? = null,
    @SerializedName("is_me") val isMe: Boolean = false,
    @SerializedName("is_following") val isFollowing: Boolean? = null,
)

data class CommunityImageDto(val id: Int = 0, val url: String? = null)

/** The finished work a `work` post shares (from the author's portfolio). */
data class CommunityWorkDto(val uuid: String? = null, val title: String? = null, @SerializedName("image_url") val imageUrl: String? = null)

data class CommunityHiddenDto(val reason: String? = null, @SerializedName("under_review") val underReview: Boolean = false)

data class CommunityPostCan(val edit: Boolean = false, val delete: Boolean = false, val report: Boolean = false, val accept: Boolean = false)

data class CommunityPostDto(
    val uuid: String = "",
    /** post · question · work */
    val kind: String = "post",
    @SerializedName("kind_label") val kindLabel: String? = null,
    val body: String = "",
    val author: CommunityAuthorDto = CommunityAuthorDto(),
    /** Where it was posted from (the feed mixes every open country). */
    val country: CountryRefDto? = null,
    val specialty: CommunitySpecialtyDto? = null,
    val images: List<CommunityImageDto> = emptyList(),
    val work: CommunityWorkDto? = null,
    val reactions: Int = 0,
    @SerializedName("top_reactions") val topReactions: List<String> = emptyList(),
    @SerializedName("my_reaction") val myReaction: String? = null,
    val comments: Int = 0,
    val saved: Boolean = false,
    /** The uuid of a question's best answer. */
    @SerializedName("accepted_comment") val acceptedComment: String? = null,
    /** Shown to the author only: why it is hidden, or that it waits for the staff after reports. */
    val hidden: CommunityHiddenDto? = null,
    val edited: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    val can: CommunityPostCan = CommunityPostCan(),
)

data class CommunityCommentCan(val edit: Boolean = false, val delete: Boolean = false, val report: Boolean = false)

data class CommunityCommentDto(
    val uuid: String = "",
    val body: String = "",
    val author: CommunityAuthorDto = CommunityAuthorDto(),
    /** The uuid of the comment this replies to (one level only). */
    val parent: String? = null,
    val reactions: Int = 0,
    @SerializedName("my_reaction") val myReaction: String? = null,
    val replies: Int = 0,
    val accepted: Boolean = false,
    val hidden: CommunityHiddenDto? = null,
    val edited: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    val can: CommunityCommentCan = CommunityCommentCan(),
    /** Up to three, on a comment of the post's page. */
    @SerializedName("first_replies") val firstReplies: List<CommunityCommentDto>? = null,
)

data class CommunityPostDetailDto(val post: CommunityPostDto = CommunityPostDto(), val comments: List<CommunityCommentDto> = emptyList())

data class CommunityReactionDto(val reactions: Int = 0, @SerializedName("my_reaction") val myReaction: String? = null, val top: List<String> = emptyList())

data class CommunitySavedDto(val saved: Boolean = false)

data class CommunityReportDto(val uuid: String? = null, val status: String? = null)

/** Gson leaves nulls out: a field left null is not changed. */
data class CommunityPostUpdate(
    val body: String? = null,
    @SerializedName("specialty_id") val specialtyId: Int? = null,
    @SerializedName("remove_images") val removeImages: List<Int>? = null,
)

data class CommunityCommentRequest(val body: String, val parent: String? = null)

/** A null [comment] is left out, which the server reads as "take the mark off". */
data class CommunityAcceptRequest(val comment: String?)

/** A null [kind] is left out, which the server reads as "take my reaction back". */
data class CommunityReactionRequest(val kind: String?)

data class CommunityReportRequest(
    @SerializedName("subject_type") val subjectType: String,
    val subject: String,
    val reason: String,
    val details: String? = null,
)

// --------------------------------------------------------------------------------------------------- cursor pages

/** The envelope of a cursor page: `next_cursor` / `has_more` sit next to `data` (ApiResponse merges its meta into the root). */
data class CursorEnvelope<T>(
    val success: Boolean = false,
    val message: String? = null,
    val data: T? = null,
    @SerializedName("next_cursor") val nextCursor: String? = null,
    @SerializedName("has_more") val hasMore: Boolean? = null,
)

/** One page and where the next one starts (null: this was the last). */
data class CursorPage<T>(val data: T, val next: String?, val hasMore: Boolean)

/** [apiCall] for a cursor page: the data with its cursor, or what went wrong. */
suspend fun <T> cursorCall(block: suspend () -> Response<CursorEnvelope<T>>): ApiResult<CursorPage<T>> {
    val response = try {
        block()
    } catch (e: IOException) {
        return ApiResult.Offline(e)
    } catch (e: com.google.gson.JsonParseException) {
        return ApiResult.Failed(ApiError(0, e.message ?: "Unreadable response", null, emptyMap(), null))
    }
    val body = response.body()
    if (response.isSuccessful && body != null && body.success && body.data != null) {
        val next = body.nextCursor?.takeIf { it.isNotBlank() }
        return ApiResult.Ok(CursorPage(body.data, next, (body.hasMore ?: (next != null)) && next != null), body.message)
    }
    return ApiResult.Failed(parseError(response.code(), response.errorBody()?.string(), body?.message))
}

/**
 * A list that fills itself page by page with the server's cursor while the freelancer scrolls (nothing on a timer). [reload] reads the
 * first page and replaces what is shown only once it arrives (a pull-to-refresh keeps the rows meanwhile); [loadMore] adds the next page.
 * One request at a time; a row that moved between pages is not shown twice.
 */
class CursorRows<T>(
    private val key: (T) -> Any,
    private val fetch: suspend (cursor: String?) -> ApiResult<CursorPage<List<T>>>,
) {
    val rows = mutableStateListOf<T>()

    /** The first page has been answered (rows or an error). */
    var loaded by mutableStateOf(false)
        private set

    var loading by mutableStateOf(false)
        private set

    /** A reload of rows already shown (pull-to-refresh). */
    var refreshing by mutableStateOf(false)
        private set

    var hasMore by mutableStateOf(false)
        private set

    var failure by mutableStateOf<ApiResult<*>?>(null)
        private set

    private var cursor: String? = null

    suspend fun reload() {
        if (loading) return
        refreshing = loaded
        try {
            load(null, first = true)
        } finally {
            refreshing = false
        }
    }

    /** Back to "nothing loaded" (a new filter): the screen shows its skeleton until [reload] answers. */
    fun reset() {
        generation++
        rows.clear()
        loaded = false
        hasMore = false
        failure = null
        cursor = null
        loading = false
        refreshing = false
    }

    suspend fun loadMore() {
        if (loaded && hasMore && failure == null && !loading) load(cursor, first = false)
    }

    fun retry() {
        failure = null
    }

    /** Bumped by [reset]: an answer for an older filter is dropped. */
    private var generation = 0

    private suspend fun load(from: String?, first: Boolean) {
        val gen = generation
        loading = true
        try {
            val result = fetch(from)
            if (gen != generation) return
            when (result) {
                is ApiResult.Ok -> {
                    failure = null
                    if (first) rows.clear()
                    rows.addAll(result.data.data.filter { row -> rows.none { key(it) == key(row) } })
                    cursor = result.data.next
                    hasMore = result.data.hasMore
                }
                else -> failure = result
            }
            loaded = true
        } finally {
            if (gen == generation) loading = false
        }
    }

    fun update(match: (T) -> Boolean, change: (T) -> T) {
        for (i in rows.indices) if (match(rows[i])) rows[i] = change(rows[i])
    }

    fun remove(match: (T) -> Boolean) {
        rows.removeAll(match)
    }

    fun prepend(row: T) {
        rows.removeAll { key(it) == key(row) }
        rows.add(0, row)
    }
}

// --------------------------------------------------------------------------------------------------- shared state

/** Something one screen changed that the others showing the same post or member should follow (no reload needed). */
sealed interface CommunityChange {
    data class PostCreated(val post: CommunityPostDto) : CommunityChange
    data class PostUpdated(val post: CommunityPostDto) : CommunityChange
    data class PostDeleted(val uuid: String) : CommunityChange
    data class FollowChanged(val member: String, val following: Boolean) : CommunityChange
}

/**
 * What the community screens share: the home answer (my card, the limits, the reasons, the rules…) read once when the app's home opens
 * (and when the connection returns or after my own actions), whether the feature is on in my country ([available]: null until known),
 * and the stream of [CommunityChange]s.
 */
object CommunityHub {
    private val _home = MutableStateFlow<CommunityHomeDto?>(null)
    val home: StateFlow<CommunityHomeDto?> = _home.asStateFlow()

    private val _available = MutableStateFlow<Boolean?>(null)
    val available: StateFlow<Boolean?> = _available.asStateFlow()

    private val _changes = MutableSharedFlow<CommunityChange>(extraBufferCapacity = 32)
    val changes: SharedFlow<CommunityChange> = _changes

    suspend fun refresh(): ApiResult<CommunityHomeDto> {
        val result = apiCall { ApiClient.community.home() }
        when (result) {
            is ApiResult.Ok -> {
                _home.value = result.data
                _available.value = true
            }
            is ApiResult.Failed -> if (result.error.errorCode == "freelance_feature_off") {
                _available.value = false
                _home.value = null
            }
            else -> Unit
        }
        return result
    }

    fun emit(change: CommunityChange) {
        _changes.tryEmit(change)
    }

    fun clear() {
        _home.value = null
        _available.value = null
    }

    private const val PREFS = "dorr_freelance_community"

    /** The community rules are shown once, the first time the composer opens. */
    fun rulesSeen(context: Context): Boolean = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean("rules_seen", false)

    fun markRulesSeen(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean("rules_seen", true).apply()
    }
}

/** My reaction moved from [before] to [after]: the count and the top icons as they will be, before the server answers. */
fun optimisticReaction(count: Int, top: List<String>, before: String?, after: String?): Pair<Int, List<String>> {
    val n = when {
        before == null && after != null -> count + 1
        before != null && after == null -> (count - 1).coerceAtLeast(0)
        else -> count
    }
    val icons = when {
        n == 0 -> emptyList()
        after != null && after !in top -> (listOf(after) + top).take(3)
        else -> top
    }
    return n to icons
}
