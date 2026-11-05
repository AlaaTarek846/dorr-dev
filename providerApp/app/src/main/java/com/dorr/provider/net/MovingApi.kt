package com.dorr.provider.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Furniture moving, the establishment's side (C-04, Modules/Moving/routes/api.php → `provider/v1/moving/...`): the home numbers,
 * the requests that reached my branches (neighbourhoods only), offers, jobs and handing them to drivers, changes and new times,
 * cancelling, the invoice and the tickets; and a driver's own jobs («مهامي»). `X-Establishment` picks the establishment.
 */
interface MovingApi {
    /** A number the seat's permissions don't cover is null: its card is hidden. */
    @GET("${ApiClient.PROVIDER}moving/dashboard")
    suspend fun dashboard(): Response<ApiEnvelope<MovingDashboardDto>>

    @GET("${ApiClient.PROVIDER}moving/requests")
    suspend fun requests(@Query("page") page: Int = 1): Response<ApiEnvelope<List<MovingRequestDto>>>

    @GET("${ApiClient.PROVIDER}moving/requests/{uuid}")
    suspend fun request(@Path("uuid") uuid: String): Response<ApiEnvelope<MovingRequestDto>>

    /** Refused with `moving_cannot_offer` (data.reason), `moving_offer_exists`, `moving_vehicle_busy`…; the server's text is shown as it is. */
    @POST("${ApiClient.PROVIDER}moving/requests/{uuid}/offers")
    suspend fun sendOffer(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<MovingOfferDto>>

    @GET("${ApiClient.PROVIDER}moving/offers")
    suspend fun offers(@Query("status") status: String? = null, @Query("page") page: Int = 1): Response<ApiEnvelope<List<MovingOfferDto>>>

    @PUT("${ApiClient.PROVIDER}moving/offers/{uuid}")
    suspend fun updateOffer(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<MovingOfferDto>>

    /** Answers with an empty `data` — call it through `apiDone`. */
    @POST("${ApiClient.PROVIDER}moving/offers/{uuid}/withdraw")
    suspend fun withdrawOffer(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    @POST("${ApiClient.PROVIDER}moving/offers/{uuid}/approve")
    suspend fun approveOffer(@Path("uuid") uuid: String): Response<ApiEnvelope<MovingOfferDto>>

    /** The running jobs (or one `status`), soonest first. */
    @GET("${ApiClient.PROVIDER}moving/jobs")
    suspend fun jobs(@Query("status") status: String? = null, @Query("page") page: Int = 1): Response<ApiEnvelope<List<MovingRequestDto>>>

    /** Who a job can be given to, each with why they can't (`problem`) and how many jobs they hold (needs jobs.assign). */
    @GET("${ApiClient.PROVIDER}moving/drivers")
    suspend fun drivers(@Query("branch_id") branchId: Long? = null): Response<ApiEnvelope<List<DriverOptionDto>>>

    /** A job's changes, the pending ones first: price differences, waiting fees and new times. */
    @GET("${ApiClient.PROVIDER}moving/jobs/{uuid}/changes")
    suspend fun changes(@Path("uuid") uuid: String): Response<ApiEnvelope<List<ChangeOrderDto>>>

    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/assign")
    suspend fun assign(@Path("uuid") uuid: String, @Body body: AssignBody): Response<ApiEnvelope<MovingRequestDto>>

    @GET("${ApiClient.PROVIDER}moving/my-jobs")
    suspend fun myJobs(): Response<ApiEnvelope<List<MovingRequestDto>>>

    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/accept")
    suspend fun acceptJob(@Path("uuid") uuid: String): Response<ApiEnvelope<MovingRequestDto>>

    /** Answers with an empty `data` — call it through `apiDone`. */
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/decline")
    suspend fun declineJob(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    /** The driver's next step on moving day: set_out, arrived, loading, departed, unloading (the delivery is [deliver]). */
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/step")
    suspend fun step(@Path("uuid") uuid: String, @Body body: StepBody): Response<ApiEnvelope<MovingRequestDto>>

    /**
     * The truck's positions, oldest first (`points`, at most 100). Called by [com.dorr.provider.duty.MoveTrackingService] with the
     * job's own establishment. The caller reads the HTTP status itself: a batch that failed stays to be sent again.
     */
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/locations")
    suspend fun locations(
        @Header(ApiClient.ESTABLISHMENT_HEADER) establishment: String?,
        @Path("uuid") uuid: String,
        @Body body: JsonObject,
    ): Response<ApiEnvelope<JsonElement>>

    @GET("${ApiClient.PROVIDER}moving/jobs/{uuid}/inventory")
    suspend fun inventory(@Path("uuid") uuid: String): Response<ApiEnvelope<List<InventoryItemDto>>>

    /** A numbered piece with its photo: `label`, `trip_no?`, `note?`, and `photo`. Only while arrived or loading. */
    @Multipart
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/inventory")
    suspend fun addItem(
        @Path("uuid") uuid: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part photo: MultipartBody.Part,
    ): Response<ApiEnvelope<InventoryItemDto>>

    /** A piece at delivery, while unloading: its after-photo and / or a reservation. Needs at least one of them. */
    @Multipart
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/inventory/{item}/delivered")
    suspend fun deliverItem(
        @Path("uuid") uuid: String,
        @Path("item") item: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part photo: MultipartBody.Part?,
    ): Response<ApiEnvelope<InventoryItemDto>>

    /** Delivery with the customer's code, or without it: `without_code` (refused / absent) and the `note`. */
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/deliver")
    suspend fun deliver(@Path("uuid") uuid: String, @Body body: DeliverBody): Response<ApiEnvelope<MovingRequestDto>>

    /** Ends the job (a refund to the customer), or for load_differs / customer_absent / unsafe_place opens a case Dorr decides. */
    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/cancel")
    suspend fun cancel(@Path("uuid") uuid: String, @Body body: CancelBody): Response<ApiEnvelope<JsonElement>>

    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/changes")
    suspend fun proposeChange(@Path("uuid") uuid: String, @Body body: ChangeBody): Response<ApiEnvelope<ChangeDto>>

    @POST("${ApiClient.PROVIDER}moving/jobs/{uuid}/reschedule")
    suspend fun reschedule(@Path("uuid") uuid: String, @Body body: RescheduleBody): Response<ApiEnvelope<ChangeDto>>

    /** The answer to the customer's new time. */
    @POST("${ApiClient.PROVIDER}moving/changes/{uuid}/respond")
    suspend fun respond(@Path("uuid") uuid: String, @Body body: RespondBody): Response<ApiEnvelope<ChangeDto>>

    @GET("${ApiClient.PROVIDER}moving/jobs/{uuid}/invoice")
    suspend fun invoice(@Path("uuid") uuid: String): Response<ApiEnvelope<InvoiceDto>>

    @GET("${ApiClient.PROVIDER}moving/tickets")
    suspend fun tickets(@Query("page") page: Int = 1): Response<ApiEnvelope<List<TicketDto>>>

    @GET("${ApiClient.PROVIDER}moving/tickets/{uuid}")
    suspend fun ticket(@Path("uuid") uuid: String): Response<ApiEnvelope<TicketDto>>

    /** The establishment's answer; it does not close the ticket (the customer or Dorr does). */
    @POST("${ApiClient.PROVIDER}moving/tickets/{uuid}/reply")
    suspend fun reply(@Path("uuid") uuid: String, @Body body: ReplyBody): Response<ApiEnvelope<TicketDto>>
}

data class MovingDashboardDto(
    @SerializedName("new_requests") val newRequests: Int? = null,
    @SerializedName("offers_awaiting_approval") val offersAwaitingApproval: Int? = null,
    @SerializedName("jobs_running") val jobsRunning: Int? = null,
    @SerializedName("jobs_unassigned") val jobsUnassigned: Int? = null,
    @SerializedName("jobs_today") val jobsToday: Int? = null,
    @SerializedName("my_jobs") val myJobs: Int? = null,
    @SerializedName("open_tickets") val openTickets: Int? = null,
)

/**
 * `MovingRequestResource`. Until the establishment's own offer is accepted the server masks it: the neighbourhoods (`area`)
 * only, no exact address, no coordinates, no phone. In the feed it carries `my_offer` when the establishment answered it.
 */
data class MovingRequestDto(
    val uuid: String = "",
    val status: String? = null,
    @SerializedName("branch_id") val branchId: Long? = null,
    /** single_piece / home / commercial */
    @SerializedName("load_type") val loadType: String? = null,
    val description: String? = null,
    @SerializedName("pieces_count") val piecesCount: String? = null,
    val dimensions: String? = null,
    @SerializedName("vehicle_type") val vehicleType: VehicleTypeDto? = null,
    @SerializedName("vehicle_type_id") val vehicleTypeId: Int? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    val extras: MovingExtrasDto? = null,
    val pickup: MovingEndDto? = null,
    val dropoff: MovingEndDto? = null,
    @SerializedName("distance_km") val distanceKm: Double? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    val notes: String? = null,
    val photos: List<MediaDto> = emptyList(),
    val videos: List<MediaDto> = emptyList(),
    @SerializedName("offers_deadline_at") val offersDeadlineAt: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val money: MovingMoneyDto? = null,
    val vehicle: JobVehicleDto? = null,
    @SerializedName("driver_accepted") val driverAccepted: Boolean = false,
    val driver: JobDriverDto? = null,
    @SerializedName("my_offer") val myOffer: MyOfferDto? = null,
)

data class MovingExtrasDto(
    val packing: Boolean = false,
    val disassembly: Boolean = false,
    val crane: Boolean = false,
    val technician: Boolean = false,
)

data class MovingEndDto(
    val lat: Double? = null,
    val lng: Double? = null,
    val address: String? = null,
    val area: String? = null,
    val floor: String? = null,
    val elevator: Boolean? = null,
    val entrance: String? = null,
)

data class MediaDto(val id: Long = 0, val url: String? = null)

data class MovingMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @SerializedName("tax_minor") val taxMinor: Long? = null,
    @SerializedName("total_minor") val totalMinor: Long? = null,
    @SerializedName("payment_status") val paymentStatus: String? = null,
)

data class JobVehicleDto(
    val uuid: String = "",
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String? = null,
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
)

data class JobDriverDto(
    val uuid: String = "",
    val name: String? = null,
    val avatar: String? = null,
    val accepted: Boolean = false,
)

data class MyOfferDto(
    val uuid: String = "",
    val status: String? = null,
    @SerializedName("price_minor") val priceMinor: Long? = null,
)

/** `MovingOfferResource`: status is awaiting_approval / sent / withdrawn / accepted / declined / expired. */
data class MovingOfferDto(
    val uuid: String = "",
    @SerializedName("request_uuid") val requestUuid: String? = null,
    val status: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("currency_code") val currencyCode: String? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    /** packing / disassembly / crane / technician / tax → true; an object, or `[]` when nothing was ticked. */
    val includes: JsonElement? = null,
    @SerializedName("technician_note") val technicianNote: String? = null,
    @SerializedName("arrival_at") val arrivalAt: String? = null,
    val terms: String? = null,
    @SerializedName("after_service") val afterService: String? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    val branch: String? = null,
    val vehicle: OfferVehicleDto? = null,
)

/** The ticked parts of [MovingOfferDto.includes]. */
fun MovingOfferDto.included(): Set<String> =
    includes?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()
        ?.filter { (_, v) -> v.isJsonPrimitive && runCatching { v.asBoolean }.getOrDefault(false) }
        ?.map { it.key }?.toSet().orEmpty()

data class OfferVehicleDto(
    val type: VehicleTypeDto? = null,
    val photos: List<String> = emptyList(),
)

data class AssignBody(
    @SerializedName("driver_uuid") val driverUuid: String,
    @SerializedName("vehicle_uuid") val vehicleUuid: String? = null,
    val reason: String? = null,
)

data class CancelBody(val reason: String, val details: String? = null)

data class ChangeBody(
    @SerializedName("price_delta_minor") val priceDeltaMinor: Long,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    val reason: String,
)

data class RescheduleBody(@SerializedName("scheduled_at") val scheduledAt: String, val reason: String)

data class RespondBody(val approve: Boolean)

data class ChangeDto(val uuid: String = "", val status: String? = null)

/** One change of a job. kind: change / waiting / reschedule. status: pending / awaiting_payment / approved / rejected. */
data class ChangeOrderDto(
    val uuid: String = "",
    val kind: String? = null,
    /** `user` (the customer) or `provider` (the establishment). */
    @SerializedName("proposed_by") val proposedBy: String? = null,
    val status: String? = null,
    @SerializedName("price_delta_minor") val priceDeltaMinor: Long? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    @SerializedName("new_scheduled_at") val newScheduledAt: String? = null,
    /** Free text; for a waiting fee `waiting:{minutes}`. */
    val reason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

/** `GET moving/drivers`. `problem`: driver_licence / driver_inactive / not_a_driver, or null when they can be chosen. */
data class DriverOptionDto(
    val uuid: String = "",
    val name: String? = null,
    val phone: String? = null,
    val avatar: String? = null,
    val problem: String? = null,
    @SerializedName("busy_jobs") val busyJobs: Int = 0,
)

data class StepBody(
    val step: String,
    val lat: Double? = null,
    val lng: Double? = null,
    /** The other proof when the location is unavailable at the door. */
    val note: String? = null,
)

/** `MovingInventoryResource`: a numbered piece, with its photo before loading and after delivery. */
data class InventoryItemDto(
    val uuid: String = "",
    val number: Int = 0,
    val label: String? = null,
    @SerializedName("trip_no") val tripNo: Int? = null,
    @SerializedName("condition_note") val conditionNote: String? = null,
    @SerializedName("delivered_note") val deliveredNote: String? = null,
    val before: String? = null,
    val after: String? = null,
)

data class DeliverBody(
    val code: String? = null,
    @SerializedName("recipient_name") val recipientName: String? = null,
    /** refused / absent, when delivered without the code. */
    @SerializedName("without_code") val withoutCode: String? = null,
    val note: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
)

data class InvoiceDto(
    val number: String? = null,
    @SerializedName("issued_at") val issuedAt: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val lines: List<InvoiceLineDto> = emptyList(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("tax_minor") val taxMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
    @SerializedName("commission_minor") val commissionMinor: Long? = null,
    @SerializedName("provider_net_minor") val providerNetMinor: Long? = null,
)

/** `moving` (the agreed price), `change` or `waiting` (an approved difference, with its reason). */
data class InvoiceLineDto(
    val key: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val reason: String? = null,
)

/** `MovingTicketResource`: status new / awaiting_company / company_replied / escalated / resolved / unresolved. */
data class TicketDto(
    val uuid: String = "",
    @SerializedName("request_uuid") val requestUuid: String? = null,
    val type: String? = null,
    val subject: String? = null,
    val status: String? = null,
    @SerializedName("escalation_reason") val escalationReason: String? = null,
    @SerializedName("closing_reason") val closingReason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val messages: List<TicketMessageDto>? = null,
)

data class TicketMessageDto(
    /** user / provider / admin */
    val author: String? = null,
    val body: String? = null,
    val at: String? = null,
)

data class ReplyBody(val body: String)
