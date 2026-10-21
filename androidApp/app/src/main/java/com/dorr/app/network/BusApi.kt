package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** Bus booking (docs/rides_tasks.md O-22, O-23): find a trip, its seat map, book seats, my tickets. */
interface BusApi {
    /** By where I leave from, where I go, or both (each optional), and optionally the day (YYYY-MM-DD). */
    @GET("mobile/v1/rides/bus/trips")
    suspend fun search(
        @Header("Authorization") auth: String,
        @Query("from_lat") fromLat: Double? = null,
        @Query("from_lng") fromLng: Double? = null,
        @Query("to_lat") toLat: Double? = null,
        @Query("to_lng") toLng: Double? = null,
        @Query("date") date: String? = null,
    ): ApiEnvelope<List<BusTripDto>>

    @GET("mobile/v1/rides/bus/trips/{uuid}")
    suspend fun trip(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<BusTripDto>

    /** `{trip_type: outbound|return|round, seats_outbound: [..], seats_return: [..]}` — held «unpaid», paid on the payment screen (O-67). */
    @POST("mobile/v1/rides/bus/trips/{uuid}/book")
    suspend fun book(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<BusBookingDto>

    @GET("mobile/v1/rides/bus/bookings")
    suspend fun bookings(@Header("Authorization") auth: String): ApiEnvelope<List<BusBookingDto>>

    @GET("mobile/v1/rides/bus/bookings/{uuid}")
    suspend fun booking(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<BusBookingDto>

    /** O-68: the chat with the captain about my ticket, and in-app calls (no numbers exchanged). */
    @GET("mobile/v1/rides/bus/bookings/{uuid}/messages")
    suspend fun messages(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideChatDto>

    @POST("mobile/v1/rides/bus/bookings/{uuid}/messages")
    suspend fun send(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<RideMessageDto>

    @POST("mobile/v1/rides/bus/bookings/{uuid}/calls")
    suspend fun startCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<RideCallStartDto>

    @POST("mobile/v1/rides/bus/bookings/{uuid}/calls/{call}/accept")
    suspend fun acceptCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("call") call: String): ApiEnvelope<RideCallStartDto>

    @POST("mobile/v1/rides/bus/bookings/{uuid}/calls/{call}/end")
    suspend fun endCall(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Path("call") call: String): ApiEnvelope<RideCallDto>

    @POST("mobile/v1/rides/bus/bookings/{uuid}/cancel")
    suspend fun cancel(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<BusBookingDto>
}

data class BusPlaceDto(val lat: Double, val lng: Double, val address: String? = null)

data class BusPricesDto(
    @SerializedName("outbound_minor") val outboundMinor: Long? = null,
    @SerializedName("return_minor") val returnMinor: Long? = null,
    @SerializedName("round_minor") val roundMinor: Long? = null,
)

data class BusFreeDto(val outbound: Int = 0, @SerializedName("return") val returnLeg: Int? = null)

data class BusTakenDto(val outbound: List<Int> = emptyList(), @SerializedName("return") val returnLeg: List<Int> = emptyList())

data class BusInfoDto(
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
    @SerializedName("color_hex") val colorHex: String? = null,
    val plate: String? = null,
    val seats: Int = 0,
)

data class BusCaptainDto(val name: String? = null, val avatar: String? = null)

data class BusTripDto(
    val uuid: String,
    val status: String = "scheduled",
    /** On a search result: `outbound` (origin → destination) or `return` (destination → origin). */
    val leg: String? = null,
    val origin: BusPlaceDto,
    val destination: BusPlaceDto,
    @SerializedName("depart_at") val departAt: String? = null,
    @SerializedName("return_at") val returnAt: String? = null,
    val prices: BusPricesDto = BusPricesDto(),
    val currency: RideCurrencyDto? = null,
    @SerializedName("seats_total") val seatsTotal: Int = 0,
    @SerializedName("seats_per_row") val seatsPerRow: Int = 4,
    val free: BusFreeDto = BusFreeDto(),
    val taken: BusTakenDto? = null,
    val note: String? = null,
    val bus: BusInfoDto? = null,
    val captain: BusCaptainDto? = null,
    @SerializedName("captain_phone") val captainPhone: String? = null,
    @SerializedName("from_distance_m") val fromDistanceM: Int? = null,
    @SerializedName("to_distance_m") val toDistanceM: Int? = null,
)

data class BusTicketDto(val leg: String, val seat: Int, val qr: String? = null, val boarded: Boolean = false)

data class BusBookingDto(
    val uuid: String,
    val status: String,
    /** O-67: «unpaid» — held for the payment screen until then. */
    @SerializedName("payment_expires_at") val paymentExpiresAt: String? = null,
    @SerializedName("discount_minor") val discountMinor: Long = 0,
    @SerializedName("trip_type") val tripType: String,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val tickets: List<BusTicketDto> = emptyList(),
    /** O-68: the captain's messages not read yet, and a call going on. */
    val unread: Int = 0,
    val call: RideCallDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val trip: BusTripDto? = null,
)

/** A bus trip's leg as the rider travels it (the coming-back leg runs destination → origin). */
internal fun BusTripDto.fromPlace(leg: String?): BusPlaceDto = if (leg == "return") destination else origin

internal fun BusTripDto.toPlace(leg: String?): BusPlaceDto = if (leg == "return") origin else destination

internal fun BusTripDto.leavesAt(leg: String?): String? = if (leg == "return") returnAt else departAt
