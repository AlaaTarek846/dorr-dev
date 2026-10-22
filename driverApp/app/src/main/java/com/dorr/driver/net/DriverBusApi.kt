package com.dorr.driver.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

/** The captain's buses (docs/rides_tasks.md O-20, O-21, O-24). */
interface DriverBusApi {
    /** My bus and a QR label for each of its seats. */
    @GET("driver/v1/bus/vehicle")
    suspend fun vehicle(): Response<ApiEnvelope<BusVehicleDto>>

    @GET("driver/v1/bus/trips")
    suspend fun trips(): Response<ApiEnvelope<List<DriverBusTripDto>>>

    /** `{origin, destination, depart_at, return_at?, price_outbound_minor, price_return_minor?, price_round_minor?, seats_total?, note?}` */
    @POST("driver/v1/bus/trips")
    suspend fun publish(@Body body: JsonObject): Response<ApiEnvelope<DriverBusTripDto>>

    @GET("driver/v1/bus/trips/{uuid}")
    suspend fun trip(@Path("uuid") uuid: String): Response<ApiEnvelope<DriverBusTripDto>>

    @PUT("driver/v1/bus/trips/{uuid}")
    suspend fun update(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<DriverBusTripDto>>

    @POST("driver/v1/bus/trips/{uuid}/cancel")
    suspend fun cancel(@Path("uuid") uuid: String): Response<ApiEnvelope<DriverBusTripDto>>

    /** `{to: departed | returning | completed}` */
    @POST("driver/v1/bus/trips/{uuid}/move")
    suspend fun move(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<DriverBusTripDto>>

    /** `{qr}` or `{leg, seat}` */
    @POST("driver/v1/bus/trips/{uuid}/board")
    suspend fun board(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<BusBoardedDto>>

    /** O-68: the chat with one passenger (their ticket) and in-app calls. */
    @GET("driver/v1/bus/bookings/{uuid}/messages")
    suspend fun messages(@Path("uuid") booking: String): Response<ApiEnvelope<ChatDto>>

    @POST("driver/v1/bus/bookings/{uuid}/messages")
    suspend fun send(@Path("uuid") booking: String, @Body body: JsonObject): Response<ApiEnvelope<MessageDto>>

    @POST("driver/v1/bus/bookings/{uuid}/calls")
    suspend fun startCall(@Path("uuid") booking: String): Response<ApiEnvelope<TripCallStartDto>>

    @POST("driver/v1/bus/bookings/{uuid}/calls/{call}/accept")
    suspend fun acceptCall(@Path("uuid") booking: String, @Path("call") call: String): Response<ApiEnvelope<TripCallStartDto>>

    @POST("driver/v1/bus/bookings/{uuid}/calls/{call}/end")
    suspend fun endCall(@Path("uuid") booking: String, @Path("call") call: String): Response<ApiEnvelope<TripCallDto>>
}

data class BusSeatLabelDto(val seat: Int, val qr: String)

data class BusVehicleDto(val uuid: String, val name: String? = null, val plate: String? = null, val seats: Int = 0, @SerializedName("seat_labels") val seatLabels: List<BusSeatLabelDto> = emptyList())

data class BusTakenLegsDto(val outbound: List<Int> = emptyList(), @SerializedName("return") val returnLeg: List<Int> = emptyList())

data class BusFreeLegsDto(val outbound: Int = 0, @SerializedName("return") val returnLeg: Int? = null)

data class BusPassengerSeatDto(val leg: String, val seat: Int, val boarded: Boolean = false)

data class BusPassengerDto(
    @SerializedName("booking_uuid") val bookingUuid: String,
    val name: String? = null,
    val phone: String? = null,
    @SerializedName("trip_type") val tripType: String = "outbound",
    val seats: List<BusPassengerSeatDto> = emptyList(),
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    /** O-68: messages from them not read yet, and a call going on. */
    val unread: Int = 0,
    val call: TripCallDto? = null,
)

data class BusTripPricesDto(
    @SerializedName("outbound_minor") val outboundMinor: Long? = null,
    @SerializedName("return_minor") val returnMinor: Long? = null,
    @SerializedName("round_minor") val roundMinor: Long? = null,
)

data class DriverBusTripDto(
    val uuid: String,
    val status: String = "scheduled",
    val origin: PlaceDto,
    val destination: PlaceDto,
    @SerializedName("depart_at") val departAt: String? = null,
    @SerializedName("return_at") val returnAt: String? = null,
    /** O-68: from when «start» / «start the way back» open (a little before their time). */
    @SerializedName("can_depart_at") val canDepartAt: String? = null,
    @SerializedName("can_return_at") val canReturnAt: String? = null,
    val prices: BusTripPricesDto = BusTripPricesDto(),
    val currency: CurrencyDto? = null,
    @SerializedName("seats_total") val seatsTotal: Int = 0,
    @SerializedName("seats_per_row") val seatsPerRow: Int = 4,
    val free: BusFreeLegsDto = BusFreeLegsDto(),
    val taken: BusTakenLegsDto? = null,
    val note: String? = null,
    val passengers: List<BusPassengerDto> = emptyList(),
)

data class BusBoardedDto(val leg: String, val seat: Int, val name: String? = null, val passengers: List<BusPassengerDto> = emptyList())
