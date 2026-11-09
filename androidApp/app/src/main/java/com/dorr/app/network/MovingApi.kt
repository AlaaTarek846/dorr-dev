package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
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
 * Furniture moving, the customer's side (Modules/Moving, docs/moving_business.md): one draft on the server, publishing
 * it, the offers three at a time, accepting one on the shared payment screen, following the job, and what comes after
 * (report, rating, changes, cancelling, invoice, tickets). Money is always in minor units; the server prices everything.
 */
interface MovingApi {
    @GET("mobile/v1/moving/options")
    suspend fun options(@Header("Authorization") auth: String): ApiEnvelope<MovingOptionsDto>

    /**
     * The road between the two ends (the admin's map engine, cached on the server per pair). Asked once when both ends
     * are set or one of them changes, never on a timer. `estimated`: no road was found, the numbers are a straight line.
     */
    @GET("mobile/v1/moving/route")
    suspend fun route(
        @Header("Authorization") auth: String,
        @Query("from_lat") fromLat: Double,
        @Query("from_lng") fromLng: Double,
        @Query("to_lat") toLat: Double,
        @Query("to_lng") toLng: Double,
    ): ApiEnvelope<MovingRouteDto>

    /** `status=active`: only what is not over yet. Paged (`pagination.has_more_pages`). */
    @GET("mobile/v1/moving/requests")
    suspend fun requests(@Header("Authorization") auth: String, @Query("status") status: String?, @Query("page") page: Int): ApiEnvelope<List<MovingRequestDto>>

    @POST("mobile/v1/moving/requests")
    suspend fun create(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<MovingRequestDto>

    @GET("mobile/v1/moving/requests/{uuid}")
    suspend fun show(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingShowDto>

    @PUT("mobile/v1/moving/requests/{uuid}")
    suspend fun update(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<MovingRequestDto>

    @Multipart
    @POST("mobile/v1/moving/requests/{uuid}/media")
    suspend fun addMedia(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Part file: MultipartBody.Part): ApiEnvelope<MovingMediaDto>

    /** Answers 204 with no body, so no envelope to read. */
    @DELETE("mobile/v1/moving/requests/{uuid}/media/{media}")
    suspend fun removeMedia(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("media") media: Int): Response<Unit>

    /** Refused with `moving_fields_missing` and `data.missing` (read it with [movingRefusal]). */
    @POST("mobile/v1/moving/requests/{uuid}/publish")
    suspend fun publish(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingRequestDto>

    @POST("mobile/v1/moving/requests/{uuid}/withdraw")
    suspend fun withdraw(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingRequestDto>

    /** Expired or withdrawn: a new draft with the same details and photos (a time that has passed is cleared). Refused: `moving_cannot_republish`. */
    @POST("mobile/v1/moving/requests/{uuid}/republish")
    suspend fun republish(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingRequestDto>

    @GET("mobile/v1/moving/requests/{uuid}/offers")
    suspend fun offers(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Query("page") page: Int): ApiEnvelope<MovingOffersPageDto>

    /** A checkout for this offer; paying it on the shared payment screen holds the money and accepts the offer. */
    @POST("mobile/v1/moving/requests/{uuid}/offers/{offer}/accept")
    suspend fun accept(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("offer") offer: String): ApiEnvelope<CheckoutDto>

    @GET("mobile/v1/moving/requests/{uuid}/cancellation")
    suspend fun cancellation(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingCancellationDto>

    /** `data` is the request, or `{case, kind}` when the reason opened an exception for Dorr. */
    @POST("mobile/v1/moving/requests/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement>

    @POST("mobile/v1/moving/requests/{uuid}/confirm")
    suspend fun confirm(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingRequestDto>

    @Multipart
    @POST("mobile/v1/moving/requests/{uuid}/report")
    suspend fun report(
        @Header("Authorization") auth: String,
        @Path("uuid") uuid: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part photos: List<MultipartBody.Part>,
    ): ApiEnvelope<JsonElement>

    @GET("mobile/v1/moving/requests/{uuid}/changes")
    suspend fun changes(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<List<MovingChangeDto>>

    /** A yes that costs more comes back with `checkout`: open the payment screen with it. */
    @POST("mobile/v1/moving/requests/{uuid}/changes/{change}/respond")
    suspend fun respondChange(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("change") change: String, @Body body: JsonObject): ApiEnvelope<MovingRespondDto>

    @POST("mobile/v1/moving/requests/{uuid}/reschedule")
    suspend fun reschedule(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<MovingChangeDto>

    @POST("mobile/v1/moving/requests/{uuid}/rating")
    suspend fun rate(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<JsonElement>

    @GET("mobile/v1/moving/requests/{uuid}/invoice")
    suspend fun invoice(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<MovingInvoiceDto>

    @GET("mobile/v1/moving/requests/{uuid}/tickets")
    suspend fun tickets(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<List<MovingTicketDto>>

    @POST("mobile/v1/moving/requests/{uuid}/tickets")
    suspend fun openTicket(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<MovingTicketDto>

    @GET("mobile/v1/moving/tickets/{ticket}")
    suspend fun ticket(@Header("Authorization") auth: String, @Path("ticket") ticket: String): ApiEnvelope<MovingTicketDto>

    /** `action`: reply | resolve | escalate. */
    @POST("mobile/v1/moving/tickets/{ticket}/act")
    suspend fun actOnTicket(@Header("Authorization") auth: String, @Path("ticket") ticket: String, @Body body: JsonObject): ApiEnvelope<MovingTicketDto>
}

/**
 * A refused moving call: the server's own words, its code, and what a publish still needs. Read once, because an
 * OkHttp error body can be read only once; [apiFailure] has no room for `data.missing`.
 */
data class MovingRefusal(val message: String?, val code: String?, val http: Int?, val missing: List<String> = emptyList())

fun Throwable.movingRefusal(): MovingRefusal {
    if (this !is retrofit2.HttpException) return MovingRefusal(null, null, null)
    val raw = runCatching { response()?.errorBody()?.string() }.getOrNull()
    val root = raw?.let { runCatching { JsonParser.parseString(it) }.getOrNull() }?.takeIf { it.isJsonObject }?.asJsonObject
    fun JsonElement?.text(): String? = this?.takeIf { it.isJsonPrimitive }?.asString
    val firstError = root?.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()?.firstOrNull()?.value
        ?.let { if (it.isJsonArray) it.asJsonArray.firstOrNull() else it }.text()
    val missing = root?.get("data")?.takeIf { it.isJsonObject }?.asJsonObject?.get("missing")?.takeIf { it.isJsonArray }?.asJsonArray
        ?.mapNotNull { it.text() }.orEmpty()
    return MovingRefusal(firstError ?: root?.get("message").text(), root?.get("error_code").text(), code(), missing)
}

data class MovingVehicleTypeDto(
    val id: Int = 0,
    val code: String? = null,
    val name: String? = null,
    val description: String? = null,
    val image: String? = null,
)

data class MovingOptionsDto(
    /** False when the service is off in this country: the unavailable card shows, and no new request starts. */
    val available: Boolean = true,
    @SerializedName("load_types") val loadTypes: List<String> = emptyList(),
    @SerializedName("vehicle_types") val vehicleTypes: List<MovingVehicleTypeDto> = emptyList(),
    /** Largest photo or video the country allows, in megabytes; null when it set none. */
    @SerializedName("media_max_mb") val mediaMaxMb: Double? = null,
)

/** `GET moving/route`: the road distance and time, and the line to draw (null when [estimated]). */
data class MovingRouteDto(
    @SerializedName("distance_m") val distanceM: Int? = null,
    @SerializedName("duration_s") val durationS: Int? = null,
    val polyline: String? = null,
    val estimated: Boolean = true,
)

data class MovingMediaDto(val id: Int = 0, val url: String? = null, val collection: String? = null)

data class MovingExtrasDto(
    val packing: Boolean = false,
    val disassembly: Boolean = false,
    val crane: Boolean = false,
    val technician: Boolean = false,
)

/** One end of the move. */
data class MovingEndDto(
    val lat: Double? = null,
    val lng: Double? = null,
    val address: String? = null,
    val area: String? = null,
    val floor: Int? = null,
    val elevator: Boolean? = null,
    val entrance: String? = null,
)

data class MovingMoneyDto(
    @SerializedName("price_minor") val priceMinor: Long? = null,
    @SerializedName("tax_minor") val taxMinor: Long? = null,
    @SerializedName("total_minor") val totalMinor: Long? = null,
    @SerializedName("payment_status") val paymentStatus: String? = null,
)

data class MovingEstablishmentDto(val uuid: String? = null, val name: String? = null, val type: String? = null, val logo: String? = null)

data class MovingVehicleDto(
    val uuid: String? = null,
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String? = null,
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
)

data class MovingDriverDto(val uuid: String? = null, val name: String? = null, val avatar: String? = null, val accepted: Boolean = false)

data class MovingRequestDto(
    val uuid: String = "",
    val status: String = "draft",
    @SerializedName("load_type") val loadType: String? = null,
    val description: String? = null,
    @SerializedName("pieces_count") val piecesCount: Int? = null,
    val dimensions: String? = null,
    @SerializedName("vehicle_type") val vehicleType: MovingVehicleTypeDto? = null,
    @SerializedName("vehicle_type_id") val vehicleTypeId: Int? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    val extras: MovingExtrasDto? = null,
    val pickup: MovingEndDto? = null,
    val dropoff: MovingEndDto? = null,
    @SerializedName("distance_km") val distanceKm: Double? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    val notes: String? = null,
    val photos: List<MovingMediaDto> = emptyList(),
    val videos: List<MovingMediaDto> = emptyList(),
    @SerializedName("offers_deadline_at") val offersDeadlineAt: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    /** How many decimals the amounts of this request have (offers and invoices take them from here). */
    @SerializedName("currency_decimals") val currencyDecimals: Int = 2,
    val money: MovingMoneyDto? = null,
    val establishment: MovingEstablishmentDto? = null,
    val vehicle: MovingVehicleDto? = null,
    @SerializedName("driver_accepted") val driverAccepted: Boolean = false,
    val driver: MovingDriverDto? = null,
)

data class MovingDriverLocationDto(val lat: Double? = null, val lng: Double? = null, val at: String? = null)

/** A numbered piece with its photos before loading and after delivery. */
data class MovingInventoryItemDto(
    val uuid: String = "",
    val number: Int? = null,
    val label: String? = null,
    @SerializedName("trip_no") val tripNo: Int? = null,
    @SerializedName("condition_note") val conditionNote: String? = null,
    @SerializedName("delivered_note") val deliveredNote: String? = null,
    val before: String? = null,
    val after: String? = null,
)

data class MovingCaseDto(
    val uuid: String = "",
    /** report | exception */
    val kind: String? = null,
    val status: String? = null,
    val decision: String? = null,
    @SerializedName("award_minor") val awardMinor: Long? = null,
)

/** `GET requests/{uuid}`: the hub of a request. */
data class MovingShowDto(
    val request: MovingRequestDto = MovingRequestDto(),
    @SerializedName("offers_count") val offersCount: Int = 0,
    /** The customer has rated this move: no rating form. */
    val rated: Boolean = false,
    val missing: List<String> = emptyList(),
    @SerializedName("delivery_code") val deliveryCode: String? = null,
    @SerializedName("delivery_qr") val deliveryQr: String? = null,
    @SerializedName("cancel_fee_minor") val cancelFeeMinor: Long? = null,
    @SerializedName("driver_location") val driverLocation: MovingDriverLocationDto? = null,
    @SerializedName("objection_until") val objectionUntil: String? = null,
    val inventory: List<MovingInventoryItemDto> = emptyList(),
    val cases: List<MovingCaseDto> = emptyList(),
)

data class MovingOfferEstablishmentDto(
    val uuid: String? = null,
    val name: String? = null,
    val type: String? = null,
    val logo: String? = null,
    val average: Double? = null,
    val count: Int = 0,
    val jobs: Int = 0,
)

data class MovingOfferVehicleDto(val type: MovingVehicleTypeDto? = null, val photos: List<String> = emptyList())

data class MovingOfferDto(
    val uuid: String = "",
    @SerializedName("request_uuid") val requestUuid: String? = null,
    val status: String? = null,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("currency_code") val currencyCode: String? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    /** A map of what the price holds: `{packing: true, crane: false, …}` (an empty one may come as `[]`). */
    val includes: JsonElement? = null,
    @SerializedName("technician_note") val technicianNote: String? = null,
    @SerializedName("arrival_at") val arrivalAt: String? = null,
    val terms: String? = null,
    @SerializedName("after_service") val afterService: String? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    val establishment: MovingOfferEstablishmentDto? = null,
    val branch: String? = null,
    val vehicle: MovingOfferVehicleDto? = null,
    /** Why it sits where it does: cheapest, earliest, vehicle_fit. */
    val reasons: List<String> = emptyList(),
)

data class MovingOffersPageDto(
    val total: Int = 0,
    val page: Int = 1,
    @SerializedName("has_more") val hasMore: Boolean = false,
    val offers: List<MovingOfferDto> = emptyList(),
)

/** The fee table in force for this request; a null means the country set none, so no fee. */
data class MovingCancellationDto(
    @SerializedName("near_hours") val nearHours: Double? = null,
    @SerializedName("early_bp") val earlyBp: Double? = null,
    @SerializedName("near_bp") val nearBp: Double? = null,
    @SerializedName("on_way_bp") val onWayBp: Double? = null,
    @SerializedName("arrived_bp") val arrivedBp: Double? = null,
    val reasons: List<String> = emptyList(),
)

data class MovingChangeDto(
    val uuid: String = "",
    /** change | waiting | reschedule */
    val kind: String? = null,
    /** provider | user */
    @SerializedName("proposed_by") val proposedBy: String? = null,
    /** pending | awaiting_payment | approved | rejected */
    val status: String? = null,
    @SerializedName("price_delta_minor") val priceDeltaMinor: Long = 0,
    @SerializedName("extra_total_minor") val extraTotalMinor: Long? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    @SerializedName("new_scheduled_at") val newScheduledAt: String? = null,
    /** Free text; a waiting fee says `waiting:{minutes}`. */
    val reason: String? = null,
    val before: JsonElement? = null,
    val after: JsonElement? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MovingRespondDto(val change: MovingChangeDto? = null, val checkout: CheckoutDto? = null)

data class MovingInvoiceEstablishmentDto(val name: String? = null, @SerializedName("tax_number") val taxNumber: String? = null)

data class MovingInvoiceLineDto(
    /** moving | waiting | change */
    val key: String? = null,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val reason: String? = null,
)

data class MovingInvoiceDto(
    val number: String? = null,
    @SerializedName("issued_at") val issuedAt: String? = null,
    @SerializedName("currency_code") val currencyCode: String? = null,
    val establishment: MovingInvoiceEstablishmentDto? = null,
    val includes: JsonElement? = null,
    @SerializedName("workers_count") val workersCount: Int? = null,
    @SerializedName("trips_count") val tripsCount: Int? = null,
    val lines: List<MovingInvoiceLineDto> = emptyList(),
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("tax_minor") val taxMinor: Long = 0,
    @SerializedName("total_minor") val totalMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
)

data class MovingTicketMessageDto(
    /** user | provider | admin */
    val author: String? = null,
    val body: String? = null,
    val at: String? = null,
)

data class MovingTicketDto(
    val uuid: String = "",
    @SerializedName("request_uuid") val requestUuid: String? = null,
    val type: String? = null,
    val subject: String? = null,
    /** awaiting_company | company_replied | escalated | awaiting_customer | resolved | unresolved */
    val status: String? = null,
    @SerializedName("escalation_reason") val escalationReason: String? = null,
    @SerializedName("closing_reason") val closingReason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val messages: List<MovingTicketMessageDto> = emptyList(),
)
