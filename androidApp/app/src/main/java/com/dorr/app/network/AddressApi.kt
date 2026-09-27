package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The account's own addresses under /api/mobile/v1/addresses. Every call is
 * scoped to the bearer token's user server-side; the app never sends a user
 * id. Mirrors Modules\User AddressController.
 */
interface AddressApi {
    @GET("mobile/v1/addresses")
    suspend fun list(
        @Header("Authorization") authorization: String,
        @Query("search") search: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<AddressDto>>

    @GET("mobile/v1/addresses/{id}")
    suspend fun show(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): ApiEnvelope<AddressDto>

    @POST("mobile/v1/addresses")
    suspend fun store(
        @Header("Authorization") authorization: String,
        @Body body: SaveAddressRequest,
    ): ApiEnvelope<AddressDto>

    @PUT("mobile/v1/addresses/{id}")
    suspend fun update(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body body: SaveAddressRequest,
    ): ApiEnvelope<AddressDto>

    @DELETE("mobile/v1/addresses/{id}")
    suspend fun delete(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): ApiEnvelope<Any?>

    @PATCH("mobile/v1/addresses/{id}/set-default")
    suspend fun setDefault(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body body: SetDefaultRequest,
    ): ApiEnvelope<AddressDto>
}

data class AddressDto(
    val id: Long,
    val type: String,
    val title: String?,
    @SerializedName("building_number") val buildingNumber: String?,
    val floor: String?,
    @SerializedName("address_details") val addressDetails: String?,
    val landmark: String?,
    val latitude: Double?,
    val longitude: Double?,
    @SerializedName("is_default") val isDefault: Boolean,
)

data class SaveAddressRequest(
    val type: String,
    val title: String? = null,
    @SerializedName("building_number") val buildingNumber: String? = null,
    val floor: String? = null,
    @SerializedName("address_details") val addressDetails: String? = null,
    val landmark: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerializedName("is_default") val isDefault: Boolean = false,
)

data class SetDefaultRequest(
    @SerializedName("is_default") val isDefault: Boolean,
)
