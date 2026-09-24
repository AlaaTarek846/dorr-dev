package com.dorr.app.network

import retrofit2.http.GET

interface ServiceApi {
    /** GET /api/general/v1/services — active services flagged for the app home (managed in the dashboard). */
    @GET("general/v1/services")
    suspend fun list(): ApiEnvelope<List<ServiceDto>>
}
