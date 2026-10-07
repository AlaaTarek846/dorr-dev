package com.dorr.app.network

import retrofit2.http.GET

interface CountryApi {
    /** GET /api/general/v1/countries/dropdown — full active-country list, pre-login. */
    @GET("general/v1/countries/dropdown")
    suspend fun list(): ApiEnvelope<List<CountryDto>>

    /**
     * GET /api/general/v1/countries/detect — the caller's country by IP, in the dropdown shape;
     * the default country when it can't be told or isn't active.
     */
    @GET("general/v1/countries/detect")
    suspend fun detect(): ApiEnvelope<CountryDto>
}
