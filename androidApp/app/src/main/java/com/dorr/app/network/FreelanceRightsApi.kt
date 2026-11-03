package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

/**
 * FL-L13, ownership and licences on the client's side (`mobile/v1/freelance/...`): the terms come with the offer, the service and the
 * agreement (the `rights` fields of those DTOs; null where the feature is off) — these are the two things a client can do: allow (or take
 * back) the showing of a finished work in the freelancer's portfolio, and report a work shown on Dorr that is not the freelancer's own.
 */
interface FreelanceRightsApi {
    /** The agreement as it is after the change; the portfolio switch is the client's alone and starts off. */
    @PUT("mobile/v1/freelance/projects/{uuid}/agreement/rights/portfolio")
    suspend fun setPortfolio(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: FreelancePortfolioAllowRequest): ApiEnvelope<FreelanceAgreementDto>

    /** subject_type = portfolio_item · service; a repeat by the same person is refused (409), a work that is not shown (404). */
    @POST("mobile/v1/freelance/rights/claims")
    suspend fun claim(@Header("Authorization") auth: String, @Body body: FreelanceRightsClaimRequest): ApiEnvelope<FreelanceRightsClaimDto>
}

data class FreelancePortfolioAllowRequest(val allowed: Boolean)

data class FreelanceRightsClaimRequest(
    @SerializedName("subject_type") val subjectType: String,
    val subject: String,
    val details: String,
)

data class FreelanceRightsClaimDto(val uuid: String = "", val status: String = "open")

/** What the client may do with the work, in the freelancer's words: commercial or personal, exclusive or not, how long, where. */
data class FreelanceRightsDetailsDto(
    val commercial: Boolean = true,
    val exclusive: Boolean = false,
    @SerializedName("term_months") val termMonths: Int? = null,
    val territory: String = "worldwide",
)

/**
 * The terms an offer or a ready-made service carries (null: the feature is off in the country). [model] = transfer · license · retained;
 * [clauses] are the lines to print, already in my language; [portfolio] is the freelancer's wish to show the finished work.
 */
data class FreelanceRightsSummaryDto(
    val model: String = "transfer",
    @SerializedName("model_label") val modelLabel: String = "",
    val summary: String = "",
    val clauses: List<String> = emptyList(),
    val details: FreelanceRightsDetailsDto = FreelanceRightsDetailsDto(),
    val portfolio: Boolean = false,
)

/** The standard text the agreement was made with (kept with it, in my language). */
data class FreelanceRightsTermsDto(val title: String = "", val body: String = "", val version: Int = 0)

data class FreelanceRightsPortfolioDto(
    val wanted: Boolean = false,
    /** The client's leave to show the finished work in the portfolio (starts off). */
    val allowed: Boolean = false,
    @SerializedName("asked_at") val askedAt: String? = null,
)

/** Parts whose rights have passed (approved and paid) out of all the live parts. */
data class FreelanceRightsPartsDto(val total: Int = 0, val granted: Int = 0)

data class FreelanceRightsCanDto(
    @SerializedName("portfolio_toggle") val portfolioToggle: Boolean = false,
    @SerializedName("portfolio_request") val portfolioRequest: Boolean = false,
)

/** The terms inside the agreement: pending (nothing passed — I only preview and review) · partial · granted. */
data class FreelanceAgreementRightsDto(
    val model: String = "transfer",
    @SerializedName("model_label") val modelLabel: String = "",
    val summary: String = "",
    val clauses: List<String> = emptyList(),
    val details: FreelanceRightsDetailsDto = FreelanceRightsDetailsDto(),
    val terms: FreelanceRightsTermsDto = FreelanceRightsTermsDto(),
    val portfolio: FreelanceRightsPortfolioDto = FreelanceRightsPortfolioDto(),
    val parts: FreelanceRightsPartsDto = FreelanceRightsPartsDto(),
    val state: String = "pending",
    val can: FreelanceRightsCanDto = FreelanceRightsCanDto(),
)

/** One outside material the freelancer listed with a delivery (a font, a stock photo…). */
data class FreelanceRightsMaterialDto(val name: String = "", val source: String? = null, val license: String? = null)

/** The freelancer's word with a delivery that the work is theirs, and what they listed from outside. */
data class FreelanceDeliveryRightsDto(
    @SerializedName("confirmed_at") val confirmedAt: String? = null,
    val materials: List<FreelanceRightsMaterialDto> = emptyList(),
)
