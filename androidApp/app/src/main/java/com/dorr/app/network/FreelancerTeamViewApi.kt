package com.dorr.app.network

import com.google.gson.annotations.SerializedName

/*
 * FS-210: a freelancers' team as the client reads it (docs/modules/freelance/API.md «عرض باسم الفريق» and «ملف الفريق»). Not the client's own
 * project team (FS-104, FreelanceTeamApi.kt). The client reads the first name, the role and what each person takes on, and a person is on a
 * team's profile only when they agreed to it (D10) — the server sends nothing else, and the app asks for nothing else.
 */

/**
 * The team an offer or an agreement is made in the name of (`team` is null on a freelancer's own). [members] are the people the offer
 * names (the agreement keeps them as they were when the work started).
 */
data class FreelancerTeamViewDto(
    val uuid: String = "",
    val name: String = "",
    val profile: FreelancerTeamBadgeDto = FreelancerTeamBadgeDto(),
    val members: List<FreelancerTeamStaffDto> = emptyList(),
)

/** What an offer shows next to the team's name: logo, badge, rating and finished projects. */
data class FreelancerTeamBadgeDto(
    @SerializedName("logo_url") val logoUrl: String? = null,
    val verified: Boolean = false,
    @SerializedName("accepting_projects") val acceptingProjects: Boolean = false,
    /** The average once there are three ratings, else null (the app says «new»). */
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int? = null,
    @SerializedName("completed_projects") val completedProjects: Int? = null,
)

/** A person on the offer: first name, `pm` or `executor`, and what they take on. */
data class FreelancerTeamStaffDto(val name: String = "", val role: String = "", val responsibility: String? = null)

/** `GET mobile/v1/freelance/freelancer-teams/{uuid}` answers `{team}`. */
data class FreelancerTeamEnvelopeDto(val team: FreelancerTeamProfileDto? = null)

/** The team's profile for a client. */
data class FreelancerTeamProfileDto(
    val uuid: String = "",
    val name: String = "",
    val bio: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    val verified: Boolean = false,
    @SerializedName("accepting_projects") val acceptingProjects: Boolean = false,
    @SerializedName("running_projects") val runningProjects: Int = 0,
    val since: String? = null,
    /** All the people on the team; [members] holds only the ones who agreed to be shown. */
    @SerializedName("members_count") val membersCount: Int = 0,
    val members: List<FreelancerTeamPersonDto> = emptyList(),
    val specialties: List<FreelanceNamedDto> = emptyList(),
    val skills: List<FreelanceNamedDto> = emptyList(),
    val languages: List<String> = emptyList(),
    val stats: FreelancerTeamStatsDto = FreelancerTeamStatsDto(),
    val reviews: List<FreelanceReviewLineDto> = emptyList(),
    val portfolio: List<FreelancePortfolioDto> = emptyList(),
)

/** A person who agreed to be shown: first name, `owner` · `pm` · `member`, headline and photo. */
data class FreelancerTeamPersonDto(
    val name: String = "",
    val role: String = "",
    val headline: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
)

/** The team's own figures (never its people's): rating from three ratings, projects finished, on time and the share finished. */
data class FreelancerTeamStatsDto(
    val rating: Double? = null,
    @SerializedName("reviews_count") val reviewsCount: Int? = null,
    @SerializedName("completed_projects") val completedProjects: Int? = null,
    @SerializedName("completion_rate") val completionRate: Int? = null,
    @SerializedName("on_time_rate") val onTimeRate: Int? = null,
)
