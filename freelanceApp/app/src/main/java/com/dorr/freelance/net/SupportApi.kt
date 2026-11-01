package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.dorr.core.network.parseError
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Rate the app, the FAQs and support, as in the DORR app (`mobile/v1/ratings|faqs|support-*`), under `/api/freelance/v1/`:
 * the guided help, my tickets, the conversation inside each, and closing / reopening one. Everything is scoped to the
 * signed-in freelancer. Live changes arrive on the account's Pusher channel (`support.message`, `support.ticket.updated`),
 * see [Realtime.events].
 */
interface SupportApi {
    @GET("freelance/v1/support-tickets")
    suspend fun listTickets(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 20): Response<ApiEnvelope<List<SupportTicketDto>>>

    @GET("freelance/v1/support-tickets/{id}")
    suspend fun ticket(@Path("id") id: Int): Response<ApiEnvelope<SupportTicketDto>>

    @Multipart
    @POST("freelance/v1/support-tickets")
    suspend fun createTicket(
        @Part("title") title: RequestBody,
        @Part("body") body: RequestBody,
        @Part image: MultipartBody.Part?,
    ): Response<ApiEnvelope<SupportTicketDto>>

    @PATCH("freelance/v1/support-tickets/{id}/status")
    suspend fun setStatus(@Path("id") id: Int, @Body body: SupportStatusRequest): Response<ApiEnvelope<SupportTicketDto>>

    /** The guided help menu (the whole active tree for freelancers, in the request's language). */
    @GET("freelance/v1/support-help")
    suspend fun help(): Response<ApiEnvelope<SupportHelpDto>>

    /** What was pressed at the end of a help topic (counted on the dashboard). */
    @POST("freelance/v1/support-help/{id}/feedback")
    suspend fun helpFeedback(@Path("id") id: Int, @Body body: SupportAutoReplyFeedbackRequest): Response<ApiEnvelope<Any?>>

    @POST("freelance/v1/support-tickets/{id}/auto-reply-feedback")
    suspend fun autoReplyFeedback(@Path("id") id: Int, @Body body: SupportAutoReplyFeedbackRequest): Response<ApiEnvelope<SupportTicketDto>>

    /** Newest page first (`order=desc`): a long conversation opens at its end, older pages follow. */
    @GET("freelance/v1/support-tickets/{id}/messages")
    suspend fun messages(
        @Path("id") id: Int,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
        @Query("order") order: String = "desc",
    ): Response<ApiEnvelope<List<SupportMessageDto>>>

    @Multipart
    @POST("freelance/v1/support-tickets/{id}/messages")
    suspend fun sendMessage(
        @Path("id") id: Int,
        @Part("body") body: RequestBody?,
        @Part image: MultipartBody.Part?,
    ): Response<ApiEnvelope<SupportMessageDto>>

    /** The Freelance service's legal page (`privacy` / `term`), public; the server falls back to the general page. */
    @GET("freelance/v1/legal-pages")
    suspend fun legalPage(@Query("type") type: String): Response<ApiEnvelope<LegalPageDto?>>

    /** Settings > Security: the devices signed in on this account (any can be signed out, or all but this one) and the security log. */
    @GET("freelance/v1/security/devices")
    suspend fun securityDevices(): Response<ApiEnvelope<List<SecurityDeviceDto>>>

    @DELETE("freelance/v1/security/devices/{id}")
    suspend fun signOutDevice(@Path("id") id: Long): Response<ApiEnvelope<SignOutResultDto>>

    @POST("freelance/v1/security/devices/logout-others")
    suspend fun signOutOtherDevices(): Response<ApiEnvelope<SignOutResultDto>>

    @GET("freelance/v1/security/events")
    suspend fun securityEvents(@Query("page") page: Int = 1, @Query("warnings") warnings: Int? = null): Response<ApiEnvelope<List<SecurityEventDto>>>

    /** The freelancer's own invite code (Account > Share the app with friends). */
    @GET("freelance/v1/referrals/my-code")
    suspend fun myReferralCode(): Response<ApiEnvelope<MyReferralCodeDto>>

    @GET("freelance/v1/faqs")
    suspend fun faqs(): Response<ApiEnvelope<List<FaqDto>>>

    /** One rating per freelancer: GET tells whether it exists, POST creates it or updates the same stars and comment. */
    @GET("freelance/v1/ratings/mine")
    suspend fun myRating(): Response<ApiEnvelope<MyAppRatingDto>>

    @POST("freelance/v1/ratings")
    suspend fun submitRating(@Body body: SubmitRatingRequest): Response<ApiEnvelope<AppRatingDto>>
}

data class SupportLastMessageDto(
    val sender: String? = null,
    val body: String? = null,
    @SerializedName("has_image") val hasImage: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SupportTicketDto(
    val id: Int,
    /** The random number the freelancer and support quote (the id stays internal). */
    val number: String? = null,
    val title: String?,
    val body: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    /** `opened`, `reopened`, `resolved` or `closed`. */
    val status: String?,
    @SerializedName("accepts_replies") val acceptsReplies: Boolean = true,
    /** The person said an automatic answer was not enough: no more automatic replies. */
    @SerializedName("auto_reply_stopped") val autoReplyStopped: Boolean = false,
    @SerializedName("last_message") val lastMessage: SupportLastMessageDto? = null,
    @SerializedName("last_message_at") val lastMessageAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
) {
    /** What is shown as "#…": the ticket's number, or its id for an old payload without one. */
    val displayNumber: String get() = number ?: id.toString()
}

data class SupportMessageDto(
    val id: Int,
    @SerializedName("ticket_id") val ticketId: Int? = null,
    /** `user` (me), `support` or `system` (an automatic reply). */
    val sender: String? = null,
    val body: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("agent_name") val agentName: String? = null,
    @SerializedName("is_auto") val isAuto: Boolean = false,
    /** `ack`, `away` or `faq`. */
    @SerializedName("auto_kind") val autoKind: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class SupportStatusRequest(val status: String)
data class SupportAutoReplyFeedbackRequest(val solved: Boolean)

data class SupportHelpDto(
    val greeting: String? = null,
    val items: List<SupportHelpNodeDto> = emptyList(),
)

/** A topic: with `children` it is a menu, without any it is an answer. */
data class SupportHelpNodeDto(
    val id: Int,
    val title: String? = null,
    val answer: String? = null,
    val children: List<SupportHelpNodeDto> = emptyList(),
)

data class FaqDto(
    val id: Int,
    val question: String,
    val answer: String,
    val status: Boolean = true,
    @SerializedName("sort_order") val sortOrder: Int? = null,
)

data class LegalPageDto(
    val id: Int? = null,
    val type: String? = null,
    val content: String? = null,
    val status: Boolean? = null,
)

data class SubmitRatingRequest(
    val stars: Float,
    val comment: String? = null,
)

/** The app rating (not a project review: see [RatingDto] in AgreementsApi). */
data class AppRatingDto(
    val id: Int,
    val stars: Double,
    val comment: String? = null,
    val type: String? = null,
    /** True for 4–5 stars: the app may show the Play in-app review. */
    @SerializedName("prompt_store_review") val promptStoreReview: Boolean = false,
)

data class MyAppRatingDto(
    val rated: Boolean = false,
    val rating: AppRatingDto? = null,
)

/** A live event on the account's channel (`support.message`, `support.ticket.updated`…) with its JSON payload. */
data class SecurityDeviceDto(
    val id: Long,
    @SerializedName("device_name") val deviceName: String?,
    val platform: String?,
    @SerializedName("platform_label") val platformLabel: String?,
    @SerializedName("app_version") val appVersion: String?,
    val ip: String?,
    @SerializedName("country_code") val countryCode: String?,
    val current: Boolean,
    @SerializedName("signed_in_at") val signedInAt: String?,
    @SerializedName("last_active_at") val lastActiveAt: String?,
)

data class SignOutResultDto(
    @SerializedName("signed_out_current") val signedOutCurrent: Boolean? = null,
    val count: Int? = null,
    val devices: List<SecurityDeviceDto>? = null,
)

data class SecurityEventDto(
    val id: Long,
    val type: String,
    val label: String,
    val description: String?,
    val warning: Boolean,
    @SerializedName("device_name") val deviceName: String?,
    @SerializedName("platform_label") val platformLabel: String?,
    val ip: String?,
    @SerializedName("country_code") val countryCode: String?,
    @SerializedName("created_at") val createdAt: String?,
)

data class MyReferralCodeDto(val code: String? = null)

data class LiveEvent(val name: String, val data: JsonObject)

/** An answer in the shape the ported screens read: the data and (for a list) whether another page follows. */
class SupportEnvelope<T>(val data: T?, val pagination: SupportPagination? = null, val message: String = "")

data class SupportPagination(val hasMorePages: Boolean)

/** A refused call: [message] is the server's own text in the request's language. A lost connection is thrown as it is (an IOException). */
class SupportFailure(override val message: String?) : RuntimeException(message)

class ApiFailure(val message: String?)

/** The text of a failed [SupportClient] call (null for a connection problem: the screen shows its own). */
fun Throwable.apiFailure(): ApiFailure = ApiFailure((this as? SupportFailure)?.message)

/**
 * [SupportApi] with the DORR app's calling style (return the answer, throw on a refusal) so the support screens read the
 * same as the DORR app's. A list answer carries `pagination.hasMorePages` (a full page means another one follows, as in
 * [PagedRows]).
 */
object SupportClient {
    private val api: SupportApi get() = ApiClient.support

    suspend fun listTickets(page: Int = 1, perPage: Int = 20) = list(perPage) { api.listTickets(page, perPage) }
    suspend fun ticket(id: Int) = one { api.ticket(id) }
    suspend fun createTicket(title: RequestBody, body: RequestBody, image: MultipartBody.Part?) = one { api.createTicket(title, body, image) }
    suspend fun setStatus(id: Int, body: SupportStatusRequest) = one { api.setStatus(id, body) }
    suspend fun help() = one { api.help() }
    suspend fun helpFeedback(id: Int, body: SupportAutoReplyFeedbackRequest) = one { api.helpFeedback(id, body) }
    suspend fun autoReplyFeedback(id: Int, body: SupportAutoReplyFeedbackRequest) = one { api.autoReplyFeedback(id, body) }
    suspend fun messages(id: Int, page: Int = 1, perPage: Int = 30) = list(perPage) { api.messages(id, page, perPage) }
    suspend fun sendMessage(id: Int, body: RequestBody?, image: MultipartBody.Part?) = one { api.sendMessage(id, body, image) }
    suspend fun faqs() = one { api.faqs() }
    suspend fun myReferralCode() = one { api.myReferralCode() }
    suspend fun securityDevices() = list(Int.MAX_VALUE) { api.securityDevices() }
    suspend fun signOutDevice(id: Long) = one { api.signOutDevice(id) }
    suspend fun signOutOtherDevices() = one { api.signOutOtherDevices() }
    suspend fun securityEvents(page: Int, warnings: Boolean) = list(20) { api.securityEvents(page, if (warnings) 1 else null) }
    suspend fun legalPage(type: String) = one { api.legalPage(type) }
    suspend fun myRating() = one { api.myRating() }
    suspend fun submitRating(body: SubmitRatingRequest) = one { api.submitRating(body) }

    private suspend fun <T> one(block: suspend () -> Response<ApiEnvelope<T>>): SupportEnvelope<T> =
        checked(block()).let { SupportEnvelope(it.data, null, it.message.orEmpty()) }

    private suspend fun <T> list(perPage: Int, block: suspend () -> Response<ApiEnvelope<List<T>>>): SupportEnvelope<List<T>> {
        val answer = checked(block())
        val rows = answer.data.orEmpty()
        return SupportEnvelope(rows, SupportPagination(rows.size >= perPage), answer.message.orEmpty())
    }

    private fun <T> checked(response: Response<ApiEnvelope<T>>): ApiEnvelope<T> {
        if (!response.isSuccessful) {
            throw SupportFailure(parseError(response.code(), response.errorBody()?.string(), null).displayMessage.ifBlank { null })
        }
        return response.body() ?: throw SupportFailure(null)
    }
}
