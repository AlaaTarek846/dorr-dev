package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

/**
 * My reputation (FS-604), under `/api/freelance/v1`: the six figures clients see, where each comes from, the projects behind delivery and
 * completion (mine only - clients never see them), and my requests to correct the data of one project.
 */
interface ReputationApi {
    @GET("${ApiClient.FREELANCE}reputation")
    suspend fun show(): Response<ApiEnvelope<ReputationDto>>

    /** 409 `freelance_reputation_open` (one open request per project and figure), 422 `freelance_reputation_limit` / `_not_yours`. */
    @POST("${ApiClient.FREELANCE}reputation/corrections")
    suspend fun ask(@Body body: ReputationCorrectionRequest): Response<ApiEnvelope<ReputationCorrectionsDto>>
}

data class ReputationDto(
    val indicators: List<ReputationIndicatorDto> = emptyList(),
    /** The mean of the known figures, null while fewer than three are known. */
    val overall: Int? = null,
    val corrections: List<ReputationCorrectionDto> = emptyList(),
    /** client_delay · client_ended · scope_changed · wrong_data · other */
    val reasons: List<String> = emptyList(),
    /** How many requests may wait for an answer at once. */
    @SerializedName("open_max") val openMax: Int = 0,
)

data class ReputationIndicatorDto(
    /** quality · delivery · communication · completion · repeat_clients · technical */
    val key: String = "",
    /** 0..100, null while there is not enough data. */
    val value: Int? = null,
    /** excellent · good · fair · low · new */
    val level: String = "new",
    val source: ReputationSourceDto = ReputationSourceDto(),
    /** Only for delivery and completion. */
    val projects: List<ReputationProjectDto>? = null,
)

data class ReputationSourceDto(
    /** ratings · parts · projects · clients · certifications */
    val kind: String = "",
    val count: Int = 0,
    val hits: Int? = null,
    val excluded: Int? = null,
    val best: List<ReputationBestDto>? = null,
    @SerializedName("verified_works") val verifiedWorks: Int? = null,
)

data class ReputationBestDto(val name: String? = null, val level: String? = null)

data class ReputationProjectDto(
    /** The agreement's uuid (what a correction names). */
    val agreement: String = "",
    val title: String? = null,
    val parts: Int? = null,
    val late: Int? = null,
    /** on_time · late (delivery) · completed · ended_by_me (completion) */
    val outcome: String = "",
    /** Staff took it out of the figure after a correction. */
    val excluded: Boolean = false,
)

data class ReputationCorrectionDto(
    val uuid: String = "",
    /** delivery · completion */
    val indicator: String = "",
    val reason: String = "",
    val note: String? = null,
    /** open · accepted · rejected */
    val status: String = "",
    @SerializedName("decision_note") val decisionNote: String? = null,
    @SerializedName("sent_at") val sentAt: String? = null,
    @SerializedName("decided_at") val decidedAt: String? = null,
    val project: ReputationCorrectionProjectDto? = null,
)

data class ReputationCorrectionProjectDto(val agreement: String? = null, val uuid: String? = null, val title: String? = null)

data class ReputationCorrectionsDto(val corrections: List<ReputationCorrectionDto> = emptyList())

/** [note] is 10..1000 characters. */
data class ReputationCorrectionRequest(val agreement: String, val indicator: String, val reason: String, val note: String)
