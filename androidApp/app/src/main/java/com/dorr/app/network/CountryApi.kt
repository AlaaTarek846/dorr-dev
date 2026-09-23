package com.dorr.app.network

import retrofit2.http.GET

interface CountryApi {
    /** GET /api/general/v1/countries/detect — caller's country by IP, pre-login. */
    @GET("general/v1/countries/detect")
    suspend fun detect(): ApiEnvelope<CountryDto>

    /** GET /api/general/v1/countries/dropdown — full active-country list, pre-login. */
    @GET("general/v1/countries/dropdown")
    suspend fun list(): ApiEnvelope<List<CountryDto>>
}
