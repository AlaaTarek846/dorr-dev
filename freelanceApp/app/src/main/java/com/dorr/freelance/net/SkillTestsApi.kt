package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/**
 * Skill tests and badges (FL-L08), under `/api/freelance/v1`: the tests of my skills and where I stand, a test's questions (texts only —
 * the right answer never comes down) and my answers, which the server marks.
 */
interface SkillTestsApi {
    @GET("${ApiClient.FREELANCE}skill-tests")
    suspend fun overview(): Response<ApiEnvelope<SkillTestsDto>>

    @POST("${ApiClient.FREELANCE}skill-tests/{skill}/start")
    suspend fun start(@Path("skill") skillId: Int): Response<ApiEnvelope<SkillAttemptDto>>

    @POST("${ApiClient.FREELANCE}skill-test-attempts/{attempt}/submit")
    suspend fun submit(@Path("attempt") attemptId: Int, @Body body: SkillSubmitRequest): Response<ApiEnvelope<SkillResultDto>>
}

data class SkillTestsDto(
    /** False where the admin has the feature off in my country. */
    val enabled: Boolean = false,
    val tests: List<SkillTestDto> = emptyList(),
    /** Every badge I hold now (also the ones whose test is no longer offered). */
    val badges: List<SkillBadgeDto> = emptyList(),
)

data class SkillTestDto(
    @SerializedName("skill_id") val skillId: Int = 0,
    val name: String? = null,
    val questions: Int = 0,
    val minutes: Int = 0,
    @SerializedName("pass_percent") val passPercent: Int = 0,
    val badge: SkillBadgeDto? = null,
    @SerializedName("in_progress") val inProgress: Boolean = false,
    /** When I may try again after a miss; null when I may now. */
    @SerializedName("retake_at") val retakeAt: String? = null,
    @SerializedName("can_start") val canStart: Boolean = false,
)

data class SkillBadgeDto(
    @SerializedName("skill_id") val skillId: Int = 0,
    val name: String? = null,
    @SerializedName("score_percent") val scorePercent: Int = 0,
    @SerializedName("earned_at") val earnedAt: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
)

data class SkillAttemptDto(
    @SerializedName("attempt_id") val attemptId: Int = 0,
    @SerializedName("skill_id") val skillId: Int = 0,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("seconds_left") val secondsLeft: Int = 0,
    @SerializedName("pass_percent") val passPercent: Int = 0,
    val questions: List<SkillQuestionDto> = emptyList(),
)

data class SkillQuestionDto(val id: Int = 0, val prompt: String = "", val options: List<String> = emptyList())

/** [answers] maps a question id to the position of the option picked among those shown. */
data class SkillSubmitRequest(val answers: Map<String, Int>)

data class SkillResultDto(
    @SerializedName("attempt_id") val attemptId: Int = 0,
    @SerializedName("skill_id") val skillId: Int = 0,
    /** passed | failed | expired */
    val status: String = "",
    val passed: Boolean = false,
    @SerializedName("score_percent") val scorePercent: Int = 0,
    @SerializedName("correct_count") val correctCount: Int = 0,
    val total: Int = 0,
    @SerializedName("pass_percent") val passPercent: Int = 0,
    val badge: SkillBadgeDto? = null,
    @SerializedName("retake_at") val retakeAt: String? = null,
)
