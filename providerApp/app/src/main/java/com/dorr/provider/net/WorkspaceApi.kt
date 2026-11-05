package com.dorr.provider.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Where the provider works (docs/moving_tasks.md phase A, Modules/Provider/routes/app.php): the establishments I hold a seat in
 * and the invitations waiting for me, creating my own establishment, its papers, and answering an invitation. Every call
 * carries the chosen establishment as `X-Establishment` (ApiClient).
 */
interface WorkspaceApi {
    /** My seats (owner first) and the invitations waiting for an answer — read when the app opens. */
    @GET("${ApiClient.PROVIDER}workspace")
    suspend fun workspace(): Response<ApiEnvelope<WorkspaceDto>>

    /** The services to pick from (image and order from the admin), the vehicle types and the permission groups. */
    @GET("${ApiClient.PROVIDER}workspace/options")
    suspend fun options(@Query("country_id") countryId: Int? = null): Response<ApiEnvelope<WorkspaceOptionsDto>>

    /** My own establishment, after the sign-in (C-02): its type, name, services and main branch. */
    @POST("${ApiClient.PROVIDER}establishments")
    suspend fun create(@Body body: CreateEstablishmentRequest): Response<ApiEnvelope<MembershipDto>>

    /** The establishment the app works in, with my seat in it (permissions). */
    @GET("${ApiClient.PROVIDER}establishment")
    suspend fun establishment(): Response<ApiEnvelope<EstablishmentShowDto>>

    /** Sends the establishment for review; refused with `provider_documents_missing` (data.missing) while papers are missing. */
    @POST("${ApiClient.PROVIDER}establishment/submit")
    suspend fun submit(): Response<ApiEnvelope<EstablishmentDto>>

    @POST("${ApiClient.PROVIDER}invitations/{uuid}/accept")
    suspend fun accept(@Path("uuid") uuid: String): Response<ApiEnvelope<MembershipDto>>

    /** Answers with an empty `data` — call it through `apiDone`. */
    @POST("${ApiClient.PROVIDER}invitations/{uuid}/decline")
    suspend fun decline(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    /** Leaves an establishment I work in (not my own); answers with an empty `data` — call it through `apiDone`. */
    @POST("${ApiClient.PROVIDER}memberships/{uuid}/leave")
    suspend fun leave(@Path("uuid") uuid: String): Response<ApiEnvelope<JsonElement>>

    /** What the establishment (or one driver, by [driverUuid]) must upload, what was uploaded, and what is still missing. */
    @GET("${ApiClient.PROVIDER}documents")
    suspend fun documents(@Query("driver_uuid") driverUuid: String? = null): Response<ApiEnvelope<DocumentsDto>>

    /** One paper: `subject`, `subject_uuid` (not for the establishment), `document_type_id`, `number`, `expires_on`; the front and back. */
    @Multipart
    @POST("${ApiClient.PROVIDER}documents")
    suspend fun upload(
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part front: MultipartBody.Part,
        @Part back: MultipartBody.Part?,
    ): Response<ApiEnvelope<ProviderDocumentDto>>
}

data class WorkspaceDto(
    val memberships: List<MembershipDto> = emptyList(),
    val invitations: List<MembershipDto> = emptyList(),
    @SerializedName("owns_establishment") val ownsEstablishment: Boolean = false,
)

/** A seat in an establishment (`MembershipResource`). */
data class MembershipDto(
    val uuid: String = "",
    val status: String? = null,
    @SerializedName("is_owner") val isOwner: Boolean = false,
    /** «حصري»: while I am in this establishment, my own establishment cannot send offers (§14). */
    @SerializedName("is_exclusive") val isExclusive: Boolean = false,
    @SerializedName("all_branches") val allBranches: Boolean = true,
    @SerializedName("branch_ids") val branchIds: List<Long>? = null,
    @SerializedName("offer_limit_minor") val offerLimitMinor: Long? = null,
    val permissions: List<String> = emptyList(),
    val phone: String? = null,
    val establishment: MembershipEstablishmentDto? = null,
    @SerializedName("joined_at") val joinedAt: String? = null,
)

/** Whether this seat holds [permission] (the owner holds them all; the server checks every one again). */
fun MembershipDto?.can(permission: String): Boolean = this != null && (isOwner || permission in permissions)

data class MembershipEstablishmentDto(
    val uuid: String = "",
    val name: String? = null,
    val type: String? = null,
    val status: String? = null,
    val logo: String? = null,
)

data class WorkspaceOptionsDto(
    val services: List<ServiceOptionDto> = emptyList(),
    @SerializedName("vehicle_types") val vehicleTypes: List<VehicleTypeDto> = emptyList(),
    /** group → its permission keys, in the server's order (config `provider.permissions`). */
    @SerializedName("permission_groups") val permissionGroups: Map<String, List<String>> = emptyMap(),
    /** branch_manager / quoter / driver → the keys each template ticks. */
    @SerializedName("permission_templates") val permissionTemplates: Map<String, List<String>> = emptyMap(),
)

/** A service the establishment can offer, in the admin's order with its image (§12). */
data class ServiceOptionDto(
    val id: Int = 0,
    @SerializedName("module_name") val moduleName: String? = null,
    val name: String? = null,
    val description: String? = null,
    val image: String? = null,
)

data class CreateEstablishmentRequest(
    /** `individual` or `company`. */
    val type: String,
    val name: String,
    @SerializedName("country_id") val countryId: Int,
    @SerializedName("commercial_register_no") val commercialRegisterNo: String? = null,
    @SerializedName("tax_number") val taxNumber: String? = null,
    @SerializedName("service_ids") val serviceIds: List<Int>,
    val branch: BranchRequest,
)

data class BranchRequest(
    val name: String? = null,
    val lat: Double,
    val lng: Double,
    @SerializedName("radius_km") val radiusKm: Double? = null,
    val address: String? = null,
)

data class EstablishmentShowDto(
    val establishment: EstablishmentDto? = null,
    val membership: MembershipDto? = null,
)

/** `EstablishmentResource`: status is draft / under_review / approved / rejected / suspended. */
data class EstablishmentDto(
    val uuid: String = "",
    val type: String? = null,
    val name: String? = null,
    @SerializedName("country_id") val countryId: Int? = null,
    @SerializedName("commercial_register_no") val commercialRegisterNo: String? = null,
    @SerializedName("tax_number") val taxNumber: String? = null,
    val status: String? = null,
    @SerializedName("status_reason") val statusReason: String? = null,
    val logo: String? = null,
    val services: List<EstablishmentServiceDto>? = null,
    val branches: List<BranchDto>? = null,
)

data class EstablishmentServiceDto(
    val id: Int = 0,
    @SerializedName("module_name") val moduleName: String? = null,
    val name: String? = null,
    val image: String? = null,
    val on: Boolean = true,
)

data class BranchDto(
    val id: Long = 0,
    val uuid: String? = null,
    val name: String? = null,
    @SerializedName("is_main") val isMain: Boolean = false,
    val lat: Double? = null,
    val lng: Double? = null,
    @SerializedName("radius_km") val radiusKm: Double? = null,
    val address: String? = null,
    /** Takes requests (the main branch always does). */
    val status: Boolean = true,
)

data class DocumentsDto(
    val required: List<DocumentTypeDto> = emptyList(),
    val documents: List<ProviderDocumentDto> = emptyList(),
    /** The codes of the required types not uploaded yet (or whose last upload was rejected / expired). */
    val missing: List<String> = emptyList(),
)

/** `DocumentTypeResource`. */
data class DocumentTypeDto(
    val id: Int = 0,
    val code: String = "",
    val subject: String? = null,
    val kind: String? = null,
    val name: String = "",
    val instructions: String? = null,
    @SerializedName("has_number") val hasNumber: Boolean = false,
    @SerializedName("has_expiry") val hasExpiry: Boolean = false,
    @SerializedName("needs_back_side") val needsBackSide: Boolean = false,
)

/** `ProviderDocumentResource`: status is pending / approved / rejected / expired. */
data class ProviderDocumentDto(
    val uuid: String = "",
    @SerializedName("document_type_id") val documentTypeId: Int = 0,
    val code: String? = null,
    val number: String? = null,
    @SerializedName("expires_on") val expiresOn: String? = null,
    val status: String? = null,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    val front: String? = null,
    val back: String? = null,
)
