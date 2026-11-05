package com.dorr.provider.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Running the establishment (C-03, Modules/Provider/routes/app.php): its branches (`branches.manage`), its team and their
 * permissions (`members.manage`), and its vehicles with their photos and papers (`fleet.vehicles`). The server checks every
 * permission again; the app only hides what the seat can't do.
 */
interface ManageApi {
    @GET("${ApiClient.PROVIDER}branches")
    suspend fun branches(): Response<ApiEnvelope<List<BranchDto>>>

    @POST("${ApiClient.PROVIDER}branches")
    suspend fun addBranch(@Body body: RequestBody): Response<ApiEnvelope<BranchDto>>

    @PUT("${ApiClient.PROVIDER}branches/{uuid}")
    suspend fun updateBranch(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<BranchDto>>

    /** Answers with an empty `data` — call it through `apiDone`. */
    @DELETE("${ApiClient.PROVIDER}branches/{uuid}")
    suspend fun deleteBranch(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    /** The team, owner first; invited people not answered yet are in it with `status = invited`. */
    @GET("${ApiClient.PROVIDER}members")
    suspend fun members(): Response<ApiEnvelope<List<MemberDto>>>

    @POST("${ApiClient.PROVIDER}members")
    suspend fun invite(@Body body: RequestBody): Response<ApiEnvelope<MemberDto>>

    @PUT("${ApiClient.PROVIDER}members/{uuid}")
    suspend fun updateMember(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<MemberDto>>

    /** Answers with an empty `data` — call it through `apiDone`. */
    @DELETE("${ApiClient.PROVIDER}members/{uuid}")
    suspend fun removeMember(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    /** Who changed what in the team (REQ-002), newest first, 30 a page. */
    @GET("${ApiClient.PROVIDER}members/events")
    suspend fun memberEvents(@Query("page") page: Int = 1): Response<ApiEnvelope<List<MemberEventDto>>>

    @GET("${ApiClient.PROVIDER}vehicles")
    suspend fun vehicles(): Response<ApiEnvelope<List<VehicleDto>>>

    @POST("${ApiClient.PROVIDER}vehicles")
    suspend fun addVehicle(@Body body: RequestBody): Response<ApiEnvelope<VehiclePageDto>>

    /** The vehicle, the photos and papers it needs (`required`), what it has (`documents`) and what is still `missing`. */
    @GET("${ApiClient.PROVIDER}vehicles/{uuid}")
    suspend fun vehicle(@Path("uuid") uuid: String): Response<ApiEnvelope<VehiclePageDto>>

    /** An approved vehicle whose data changes (anything but its branch) goes back to review (VehicleService::update). */
    @PUT("${ApiClient.PROVIDER}vehicles/{uuid}")
    suspend fun updateVehicle(@Path("uuid") uuid: String, @Body body: RequestBody): Response<ApiEnvelope<VehiclePageDto>>

    @POST("${ApiClient.PROVIDER}vehicles/{uuid}/deactivate")
    suspend fun deactivateVehicle(@Path("uuid") uuid: String): Response<ApiEnvelope<VehiclePageDto>>
}

data class BranchBody(
    val name: String,
    val lat: Double,
    val lng: Double,
    @SerializedName("radius_km") val radiusKm: Double? = null,
    val address: String? = null,
    val status: Boolean = true,
)

/** A seat in the team list (`MembershipResource` with `person`). */
data class MemberDto(
    val uuid: String = "",
    val status: String? = null,
    @SerializedName("is_owner") val isOwner: Boolean = false,
    @SerializedName("is_exclusive") val isExclusive: Boolean = false,
    @SerializedName("all_branches") val allBranches: Boolean = true,
    @SerializedName("branch_ids") val branchIds: List<Long>? = null,
    @SerializedName("offer_limit_minor") val offerLimitMinor: Long? = null,
    val permissions: List<String> = emptyList(),
    val phone: String? = null,
    val person: MemberPersonDto? = null,
    @SerializedName("joined_at") val joinedAt: String? = null,
)

data class MemberPersonDto(
    val id: Long? = null,
    val name: String? = null,
    val phone: String? = null,
    val avatar: String? = null,
)

/** One line of the team's log: what happened, to whom and by whom (no id of its own). */
data class MemberEventDto(
    /** invited, joined, declined, permissions_changed, removed, left */
    val action: String? = null,
    val changes: JsonElement? = null,
    val member: MemberEventPersonDto? = null,
    val actor: MemberEventPersonDto? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MemberEventPersonDto(
    val uuid: String? = null,
    val name: String? = null,
    val phone: String? = null,
)

/** `VehicleTypeResource`: the admin's vehicle types, each with its image. */
data class VehicleTypeDto(
    val id: Int = 0,
    val code: String? = null,
    val name: String? = null,
    val description: String? = null,
    val image: String? = null,
)

/** `VehicleResource`: status is pending / approved / rejected / inactive. */
data class VehicleDto(
    val uuid: String = "",
    @SerializedName("branch_id") val branchId: Long? = null,
    @SerializedName("vehicle_type") val vehicleType: VehicleTypeDto? = null,
    @SerializedName("vehicle_type_id") val vehicleTypeId: Int? = null,
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String? = null,
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
    val year: Int? = null,
    val status: String? = null,
    @SerializedName("status_reason") val statusReason: String? = null,
)

/** "ABC 1234" as written on the plate. */
fun VehicleDto.plate(): String = listOfNotNull(plateLetters?.takeIf { it.isNotBlank() }, plateNumber).joinToString(" ")

data class VehicleBody(
    @SerializedName("vehicle_type_id") val vehicleTypeId: Int,
    @SerializedName("branch_id") val branchId: Long? = null,
    @SerializedName("plate_letters") val plateLetters: String? = null,
    @SerializedName("plate_number") val plateNumber: String,
    val make: String? = null,
    val model: String? = null,
    val color: String? = null,
    val year: Int? = null,
)

data class VehiclePageDto(
    val vehicle: VehicleDto? = null,
    val required: List<DocumentTypeDto> = emptyList(),
    val documents: List<ProviderDocumentDto> = emptyList(),
    val missing: List<String> = emptyList(),
)
