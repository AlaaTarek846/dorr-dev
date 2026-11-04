package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.GET
import retrofit2.http.Query

/**
 * My performance (FL-L01, phase 4), under `/api/freelance/v1`: my offers, the orders on my ready-made services, what I earned and my name
 * with clients over the last days. How much shows is my plan's: a plan with none answers 200 with [InsightsDto.locked] and the plan that
 * opens it, so the screen can offer the upgrade instead of showing an error.
 */
interface InsightsApi {
    /** [days] is asked for; the answer says the days it was worked out for (the plan's nearest window). */
    @GET("${ApiClient.FREELANCE}insights")
    suspend fun insights(@Query("days") days: Int? = null): Response<ApiEnvelope<InsightsDto>>
}

data class InsightsDto(
    /** 0 = none (locked), 1 = a summary of 30 days, 2 = the full numbers. */
    val level: Int = 0,
    val locked: Boolean = true,
    /** False when plans are not sold in my country, so there is nothing to upgrade to. */
    @SerializedName("subscriptions_enabled") val subscriptionsEnabled: Boolean = false,
    /** The windows my plan can look back over (30 / 90 / 365). */
    @SerializedName("allowed_days") val allowedDays: List<Int> = emptyList(),
    /** The days the numbers are for. */
    val days: Int? = null,
    @field:com.google.gson.annotations.JsonAdapter(CurrencyLabelAdapter::class) val currency: String? = null,
    val decimals: Int = 2,
    /** The lowest plan that opens more than mine; null at the top or where plans are not sold. */
    val upgrade: InsightsUpgradeDto? = null,
    val data: InsightsDataDto? = null,
)

data class InsightsUpgradeDto(val slug: String = "", val name: String? = null)

data class InsightsDataDto(
    val offers: InsightOffersDto = InsightOffersDto(),
    val services: InsightServicesDto = InsightServicesDto(),
    val earnings: InsightEarningsDto = InsightEarningsDto(),
    val reputation: InsightReputationDto = InsightReputationDto(),
    /** How often my profile and ready-made services were looked at (FL-L11). */
    val views: InsightViewsDto = InsightViewsDto(),
    /** The full level only. */
    val specialties: List<InsightSpecialtyDto>? = null,
)

/**
 * Visits to my profile (seen from an offer, or from my portfolio) and to my ready-made services, in the period and in the period of the same
 * length before it — only numbers, never who looked. [series] and [topServices] come with the full level.
 */
data class InsightViewsDto(
    val profile: Int = 0,
    val service: Int = 0,
    @SerializedName("profile_before") val profileBefore: Int = 0,
    @SerializedName("service_before") val serviceBefore: Int = 0,
    /** Where the visits came from: `offer`, `portfolio`, `market`. */
    @SerializedName("by_source") val bySource: Map<String, Int> = emptyMap(),
    /** `week` or `month`. */
    val bucket: String? = null,
    val series: List<InsightViewPointDto>? = null,
    @SerializedName("top_services") val topServices: List<InsightTopServiceDto>? = null,
)

data class InsightViewPointDto(val from: String = "", val profile: Int = 0, val service: Int = 0)

data class InsightTopServiceDto(val id: Int = 0, val title: String = "", val views: Int = 0, val orders: Int = 0)

/** The funnel of the offers I sent in the period; the rates are whole percents of the offers I did not take back (null when there are none). */
data class InsightOffersDto(
    val sent: Int = 0,
    val withdrawn: Int = 0,
    val viewed: Int = 0,
    val shortlisted: Int = 0,
    val accepted: Int = 0,
    val rejected: Int = 0,
    @SerializedName("view_rate") val viewRate: Int? = null,
    @SerializedName("accept_rate") val acceptRate: Int? = null,
)

data class InsightServicesDto(
    val received: Int = 0,
    val awaiting: Int = 0,
    val active: Int = 0,
    val completed: Int = 0,
    val declined: Int = 0,
    val expired: Int = 0,
    val cancelled: Int = 0,
    /** Of the orders I answered, the percent I accepted. */
    @SerializedName("accept_rate") val acceptRate: Int? = null,
)

data class InsightEarningsDto(
    @SerializedName("earned_minor") val earnedMinor: Long = 0,
    /** `week` (30 days) or `month`; the full level only, with [series]. */
    val bucket: String? = null,
    val series: List<InsightPointDto>? = null,
)

/** One bar: [from] is the first day of its week or month (`2026-10-01`). */
data class InsightPointDto(val from: String = "", @SerializedName("earned_minor") val earnedMinor: Long = 0)

data class InsightReputationDto(
    /** The average, from three ratings; null before that. */
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int = 0,
    @SerializedName("completed_projects") val completedProjects: Int = 0,
    @SerializedName("completion_rate") val completionRate: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
    @SerializedName("completed_in_period") val completedInPeriod: Int = 0,
)

data class InsightSpecialtyDto(
    val id: Int = 0,
    val name: String? = null,
    val sent: Int = 0,
    val accepted: Int = 0,
    @SerializedName("accept_rate") val acceptRate: Int? = null,
)

/** «Improve my profile» (FL-L11): the same for every plan, under `/api/freelance/v1`. */
interface ProfileCoachApi {
    @GET("${ApiClient.FREELANCE}profile-coach")
    suspend fun coach(): Response<ApiEnvelope<CoachDto>>
}

/** [level] is `start`, `good` or `great`; [done] of [total] steps of the bar are done. */
data class CoachDto(
    val percent: Int = 0,
    val level: String = "start",
    val done: Int = 0,
    val total: Int = 0,
    val tips: List<CoachTipDto> = emptyList(),
)

/**
 * One thing to do next. [key] says what (the app writes the sentence), [impact] is `high` / `medium` / `low`, [points] what the bar gains
 * (0 for the ones it does not count) and [action] the screen to open: `profile`, `skills`, `specialties`, `portfolio`, `verification`, `services`.
 */
data class CoachTipDto(val key: String = "", val impact: String = "low", val points: Int = 0, val action: String = "profile")
