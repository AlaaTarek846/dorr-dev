package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * FL-L13, ownership and licences, under `/api/freelance/v1`: what the offer and service forms need (the three ways to hand over the work
 * with the standard text of my country), the finished works whose client let me show them in my portfolio, and asking that leave.
 * The terms themselves ride on the offer, the service, the agreement and each delivery (`rights`, null where the feature is off).
 */
interface RightsApi {
    @GET("${ApiClient.FREELANCE}rights")
    suspend fun options(): Response<ApiEnvelope<RightsOptionsDto>>

    /** My finished works a client allowed me to show. */
    @GET("${ApiClient.FREELANCE}rights/portfolio-sources")
    suspend fun portfolioSources(): Response<ApiEnvelope<List<PortfolioSourceDto>>>

    /** A notice to the client asking their leave (not again for a few days); answers with the agreement. */
    @POST("${ApiClient.FREELANCE}projects/{uuid}/agreement/rights/portfolio-request")
    suspend fun askPortfolio(@Path("uuid") uuid: String): Response<ApiEnvelope<AgreementDto>>
}

/** What I say on an offer or a service (and the platform's default in [RightsOptionsDto.default]). */
data class RightsInputDto(
    /** transfer · license · retained */
    val model: String = "transfer",
    val commercial: Boolean = true,
    val exclusive: Boolean = true,
    /** Null: no time limit. */
    @SerializedName("term_months") val termMonths: Int? = null,
    /** worldwide · country */
    val territory: String = "worldwide",
    /** My wish to show the finished work in my portfolio (the client still decides). */
    val portfolio: Boolean = false,
)

data class RightsOptionsDto(
    val enabled: Boolean = false,
    val default: RightsInputDto = RightsInputDto(),
    val models: List<RightsModelDto> = emptyList(),
    val limits: RightsLimitsDto = RightsLimitsDto(),
)

data class RightsModelDto(
    val model: String = "",
    val label: String = "",
    val summary: String = "",
    /** The standard text of my country for this model, in the language of the request. */
    val terms: RightsTextDto = RightsTextDto(),
)

data class RightsTextDto(val title: String = "", val body: String = "", val version: Int = 0)

data class RightsLimitsDto(
    @SerializedName("max_term_months") val maxTermMonths: Int = 120,
    @SerializedName("max_materials") val maxMaterials: Int = 10,
)

data class RightsDetailsDto(
    val commercial: Boolean = true,
    val exclusive: Boolean = false,
    @SerializedName("term_months") val termMonths: Int? = null,
    val territory: String = "worldwide",
)

/** The terms of an offer or a service as its screen shows them (already in the language of the request). */
data class RightsSummaryDto(
    val model: String = "transfer",
    @SerializedName("model_label") val modelLabel: String = "",
    val summary: String = "",
    val clauses: List<String> = emptyList(),
    val details: RightsDetailsDto = RightsDetailsDto(),
    val portfolio: Boolean = false,
)

/** The terms an agreement keeps, with where the passing of the rights stands. */
data class AgreementRightsDto(
    val model: String = "transfer",
    @SerializedName("model_label") val modelLabel: String = "",
    val summary: String = "",
    val clauses: List<String> = emptyList(),
    val details: RightsDetailsDto = RightsDetailsDto(),
    /** The standard text of the day the agreement was made. */
    val terms: RightsTextDto = RightsTextDto(),
    val portfolio: RightsPortfolioDto = RightsPortfolioDto(),
    val parts: RightsPartsDto = RightsPartsDto(),
    /** pending · partial · granted */
    val state: String = "pending",
    val can: RightsCanDto = RightsCanDto(),
)

data class RightsPortfolioDto(
    val wanted: Boolean = false,
    /** The client's leave to show the finished work. */
    val allowed: Boolean = false,
    @SerializedName("asked_at") val askedAt: String? = null,
)

data class RightsPartsDto(val total: Int = 0, val granted: Int = 0)

data class RightsCanDto(
    @SerializedName("portfolio_toggle") val portfolioToggle: Boolean = false,
    @SerializedName("portfolio_request") val portfolioRequest: Boolean = false,
)

/** What a delivery keeps: my word that the work is mine to hand over, and the outside material I listed. */
data class DeliveryRightsDto(
    @SerializedName("confirmed_at") val confirmedAt: String? = null,
    val materials: List<RightsMaterialDto> = emptyList(),
)

data class RightsMaterialDto(val name: String = "", val source: String? = null, val license: String? = null)

/** A finished work I may show; [used] once a portfolio item already comes from it. */
data class PortfolioSourceDto(
    val uuid: String = "",
    val title: String = "",
    @SerializedName("completed_at") val completedAt: String? = null,
    val used: Boolean = false,
)
