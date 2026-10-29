package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Educational groups (docs/groups_tasks.md GR-011..017): lessons, homework, tests and the question
 * bank, sessions and attendance, and the next term. Every rule is checked on the server.
 */
interface ClassroomApi {
    // ---------------------------------------------------------------- lessons (GR-013)
    @GET("mobile/v1/chat/groups/{id}/lessons")
    suspend fun lessons(@Header("Authorization") auth: String, @Path("id") id: String, @Query("q") q: String?, @Query("page") page: Int): ApiEnvelope<LessonPageDto>

    @GET("mobile/v1/chat/groups/{id}/lessons/{lesson}")
    suspend fun lesson(@Header("Authorization") auth: String, @Path("id") id: String, @Path("lesson") lesson: String): ApiEnvelope<LessonDto>

    @Multipart
    @POST("mobile/v1/chat/groups/{id}/lessons")
    suspend fun createLesson(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<LessonDto>

    @DELETE("mobile/v1/chat/groups/{id}/lessons/{lesson}")
    suspend fun deleteLesson(@Header("Authorization") auth: String, @Path("id") id: String, @Path("lesson") lesson: String): ApiEnvelope<JsonElement?>

    // ---------------------------------------------------------------- homework (GR-014)
    @GET("mobile/v1/chat/groups/{id}/assignments")
    suspend fun assignments(@Header("Authorization") auth: String, @Path("id") id: String, @Query("page") page: Int): ApiEnvelope<AssignmentPageDto>

    @GET("mobile/v1/chat/groups/{id}/assignments/{a}")
    suspend fun assignment(@Header("Authorization") auth: String, @Path("id") id: String, @Path("a") assignment: String): ApiEnvelope<AssignmentDto>

    @Multipart
    @POST("mobile/v1/chat/groups/{id}/assignments")
    suspend fun createAssignment(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<AssignmentDto>

    @Multipart
    @POST("mobile/v1/chat/groups/{id}/assignments/{a}/submit")
    suspend fun submit(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @Path("a") assignment: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<SubmissionDto>

    @GET("mobile/v1/chat/groups/{id}/assignments/{a}/submissions")
    suspend fun submissions(@Header("Authorization") auth: String, @Path("id") id: String, @Path("a") assignment: String): ApiEnvelope<SubmissionsDto>

    @POST("mobile/v1/chat/groups/{id}/assignments/{a}/grades")
    suspend fun grade(@Header("Authorization") auth: String, @Path("id") id: String, @Path("a") assignment: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    // ---------------------------------------------------------------- tests (GR-015) · bank (GR-016)
    @GET("mobile/v1/chat/groups/{id}/quizzes")
    suspend fun quizzes(@Header("Authorization") auth: String, @Path("id") id: String, @Query("page") page: Int): ApiEnvelope<QuizPageDto>

    @GET("mobile/v1/chat/groups/{id}/quizzes/{q}")
    suspend fun quiz(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String): ApiEnvelope<QuizDto>

    @POST("mobile/v1/chat/groups/{id}/quizzes")
    suspend fun createQuiz(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<QuizDto>

    @PATCH("mobile/v1/chat/groups/{id}/quizzes/{q}")
    suspend fun updateQuiz(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<QuizDto>

    @POST("mobile/v1/chat/groups/{id}/quizzes/{q}/start")
    suspend fun startQuiz(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String): ApiEnvelope<QuizDto>

    @POST("mobile/v1/chat/groups/{id}/quizzes/{q}/submit")
    suspend fun submitQuiz(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<QuizDto>

    @GET("mobile/v1/chat/groups/{id}/quizzes/{q}/attempts")
    suspend fun attempts(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String): ApiEnvelope<List<AttemptDto>>

    @POST("mobile/v1/chat/groups/{id}/quizzes/{q}/attempts/{attempt}/mark")
    suspend fun mark(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String, @Path("attempt") attempt: Int, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    // ---------------------------------------------------------------- sessions and attendance (GR-017)
    @GET("mobile/v1/chat/groups/{id}/sessions")
    suspend fun sessions(@Header("Authorization") auth: String, @Path("id") id: String, @Query("when") whenFilter: String, @Query("page") page: Int): ApiEnvelope<SessionPageDto>

    @GET("mobile/v1/chat/groups/{id}/sessions/{s}")
    suspend fun session(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") session: String): ApiEnvelope<SessionDto>

    @POST("mobile/v1/chat/groups/{id}/sessions")
    suspend fun createSession(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<SessionDto>

    @POST("mobile/v1/chat/groups/{id}/sessions/{s}/attendance")
    suspend fun recordAttendance(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") session: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<SessionDto>

    @GET("mobile/v1/chat/groups/{id}/attendance")
    suspend fun myAttendance(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<AttendanceReportDto>

    // ---------------------------------------------------------------- extras (GR-021, 022, 024, 025, 026, 028, 030)
    @GET("mobile/v1/chat/groups/{id}/bag")
    suspend fun bag(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<BagDto>

    @GET("mobile/v1/chat/groups/{id}/quizzes/schedule")
    suspend fun testSchedule(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<TestScheduleDto>

    @GET("mobile/v1/chat/groups/{id}/quizzes/{q}/leaderboard")
    suspend fun leaderboard(@Header("Authorization") auth: String, @Path("id") id: String, @Path("q") quiz: String): ApiEnvelope<List<LeaderRowDto>>

    @GET("mobile/v1/chat/groups/{id}/stars")
    suspend fun stars(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<StarDto>>

    @POST("mobile/v1/chat/groups/{id}/stars")
    suspend fun awardStar(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<StarDto>

    @GET("mobile/v1/chat/groups/{id}/reading")
    suspend fun reading(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<ReadingDto>>

    @POST("mobile/v1/chat/groups/{id}/reading")
    suspend fun addReading(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/groups/{id}/reading/{item}/progress")
    suspend fun readingProgress(@Header("Authorization") auth: String, @Path("id") id: String, @Path("item") item: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/groups/{id}/certificates")
    suspend fun certificates(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<CertificateDto>>

    @POST("mobile/v1/chat/groups/{id}/certificates")
    suspend fun issueCertificate(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<CertificateDto>

    @POST("mobile/v1/chat/groups/{id}/surveys")
    suspend fun survey(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    // ---------------------------------------------------------------- help requests (GR-031, GR-041)
    @GET("mobile/v1/chat/groups/{id}/help")
    suspend fun helpRequests(@Header("Authorization") auth: String, @Path("id") id: String, @Query("status") status: String): ApiEnvelope<HelpPageDto>

    @POST("mobile/v1/chat/groups/{id}/help")
    suspend fun askHelp(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<HelpDto>

    @POST("mobile/v1/chat/groups/{id}/help/{h}/answer")
    suspend fun answerHelp(@Header("Authorization") auth: String, @Path("id") id: String, @Path("h") help: String, @Body body: Map<String, String>): ApiEnvelope<HelpDto>

    @POST("mobile/v1/chat/groups/{id}/help/{h}/close")
    suspend fun closeHelp(@Header("Authorization") auth: String, @Path("id") id: String, @Path("h") help: String): ApiEnvelope<HelpDto>

    // ---------------------------------------------------------------- terms (GR-012)
    @POST("mobile/v1/chat/groups/{id}/spaces/{space}/next-term")
    suspend fun nextTerm(@Header("Authorization") auth: String, @Path("id") id: String, @Path("space") space: Int, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<GroupSpaceDto>

    @GET("mobile/v1/chat/groups/{id}/spaces/{space}/members")
    suspend fun spaceMembers(@Header("Authorization") auth: String, @Path("id") id: String, @Path("space") space: Int): ApiEnvelope<SpaceMembersDto>

    // ---------------------------------------------------------------- work groups (GR-040, 042..046) · polls (GR-045)
    @GET("mobile/v1/chat/groups/{id}/shifts")
    suspend fun shifts(@Header("Authorization") auth: String, @Path("id") id: String, @Query("from") from: String?, @Query("mine") mine: Int?): ApiEnvelope<List<ShiftDto>>

    @POST("mobile/v1/chat/groups/{id}/shifts")
    suspend fun createShift(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<ShiftDto>

    @DELETE("mobile/v1/chat/groups/{id}/shifts/{s}")
    suspend fun deleteShift(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") shift: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/groups/{id}/shifts/{s}/handover")
    suspend fun handover(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") shift: String, @Body body: Map<String, String>): ApiEnvelope<ShiftDto>

    @POST("mobile/v1/chat/groups/{id}/shifts/{s}/swap")
    suspend fun askSwap(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") shift: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<ShiftDto>

    @POST("mobile/v1/chat/groups/{id}/shifts/{s}/swap/answer")
    suspend fun answerSwap(@Header("Authorization") auth: String, @Path("id") id: String, @Path("s") shift: String, @Body body: Map<String, Boolean>): ApiEnvelope<ShiftDto>

    @GET("mobile/v1/chat/groups/{id}/goals")
    suspend fun goals(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<GoalDto>>

    @POST("mobile/v1/chat/groups/{id}/goals")
    suspend fun createGoal(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<GoalDto>

    @POST("mobile/v1/chat/groups/{id}/goals/{g}/points")
    suspend fun givePoints(@Header("Authorization") auth: String, @Path("id") id: String, @Path("g") goal: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<GoalDto>

    @GET("mobile/v1/chat/groups/{id}/badges")
    suspend fun badges(@Header("Authorization") auth: String, @Path("id") id: String, @Query("participant_id") participantId: Int?): ApiEnvelope<List<BadgeDto>>

    @POST("mobile/v1/chat/groups/{id}/badges")
    suspend fun giveBadge(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<BadgeDto>

    @GET("mobile/v1/chat/groups/{id}/ideas")
    suspend fun ideas(@Header("Authorization") auth: String, @Path("id") id: String, @Query("status") status: String?): ApiEnvelope<List<IdeaDto>>

    @POST("mobile/v1/chat/groups/{id}/ideas")
    suspend fun propose(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<IdeaDto>

    @POST("mobile/v1/chat/groups/{id}/ideas/{i}/vote")
    suspend fun voteIdea(@Header("Authorization") auth: String, @Path("id") id: String, @Path("i") idea: String): ApiEnvelope<IdeaDto>

    @POST("mobile/v1/chat/groups/{id}/ideas/{i}/status")
    suspend fun ideaStatus(@Header("Authorization") auth: String, @Path("id") id: String, @Path("i") idea: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<IdeaDto>

    @GET("mobile/v1/chat/groups/{id}/meetings")
    suspend fun meetings(@Header("Authorization") auth: String, @Path("id") id: String, @Query("when") whenTab: String): ApiEnvelope<List<MeetingDto>>

    @POST("mobile/v1/chat/groups/{id}/meetings")
    suspend fun createMeeting(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<MeetingDto>

    @POST("mobile/v1/chat/groups/{id}/meetings/{m}/minutes")
    suspend fun saveMinutes(@Header("Authorization") auth: String, @Path("id") id: String, @Path("m") meeting: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<MeetingDto>

    @POST("mobile/v1/chat/groups/{id}/meetings/{m}/tasks")
    suspend fun meetingTasks(@Header("Authorization") auth: String, @Path("id") id: String, @Path("m") meeting: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/groups/{id}/polls")
    suspend fun groupPoll(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<JsonElement?>

    // ---------------------------------------------------------------- parents (GR-018, 019, 027 — D8)
    @POST("mobile/v1/chat/groups/{id}/parents/code")
    suspend fun parentCode(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ParentCodeDto>

    @GET("mobile/v1/chat/groups/{id}/parents/mine")
    suspend fun myParents(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<MyParentDto>>

    @GET("mobile/v1/chat/groups/{id}/parents")
    suspend fun groupParents(@Header("Authorization") auth: String, @Path("id") id: String, @Query("status") status: String?): ApiEnvelope<GroupParentsDto>

    @PATCH("mobile/v1/chat/groups/{id}/parents/settings")
    suspend fun parentSettings(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Boolean>): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/groups/{id}/parents/{link}/decide")
    suspend fun decideParent(@Header("Authorization") auth: String, @Path("id") id: String, @Path("link") link: String, @Body body: Map<String, Boolean>): ApiEnvelope<ParentLinkDto>

    @DELETE("mobile/v1/chat/groups/{id}/parents/{link}")
    suspend fun removeParent(@Header("Authorization") auth: String, @Path("id") id: String, @Path("link") link: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/groups/{id}/parent-meetings")
    suspend fun parentMeetings(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<ParentMeetingDto>>

    @POST("mobile/v1/chat/groups/{id}/parent-meetings")
    suspend fun createParentMeeting(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<ParentMeetingDto>

    @DELETE("mobile/v1/chat/groups/{id}/parent-meetings/{m}")
    suspend fun deleteParentMeeting(@Header("Authorization") auth: String, @Path("id") id: String, @Path("m") meeting: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/parent/link")
    suspend fun redeemParentCode(@Header("Authorization") auth: String, @Body body: Map<String, String>): ApiEnvelope<ChildLinkDto>

    @GET("mobile/v1/chat/parent/children")
    suspend fun children(@Header("Authorization") auth: String): ApiEnvelope<List<ChildLinkDto>>

    @GET("mobile/v1/chat/parent/children/{link}")
    suspend fun child(@Header("Authorization") auth: String, @Path("link") link: String): ApiEnvelope<ChildPageDto>

    @DELETE("mobile/v1/chat/parent/children/{link}")
    suspend fun unlinkChild(@Header("Authorization") auth: String, @Path("link") link: String): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/parent/children/{link}/message")
    suspend fun messageTeacher(@Header("Authorization") auth: String, @Path("link") link: String, @Body body: Map<String, Int>): ApiEnvelope<OpenedChatDto>

    @GET("mobile/v1/chat/parent/children/{link}/meetings")
    suspend fun childMeetings(@Header("Authorization") auth: String, @Path("link") link: String): ApiEnvelope<List<ParentMeetingDto>>

    @POST("mobile/v1/chat/parent/children/{link}/meetings/{m}/book")
    suspend fun bookSlot(@Header("Authorization") auth: String, @Path("link") link: String, @Path("m") meeting: String, @Body body: Map<String, String>): ApiEnvelope<ParentMeetingDto>

    @DELETE("mobile/v1/chat/parent/children/{link}/meetings/{m}/book")
    suspend fun cancelSlot(@Header("Authorization") auth: String, @Path("link") link: String, @Path("m") meeting: String): ApiEnvelope<JsonElement?>
}

data class EduFileDto(val id: String, val url: String, val name: String? = null, @SerializedName("mime_type") val mimeType: String? = null)

data class EduPersonDto(@SerializedName("participant_id") val participantId: Int, val profile: ProfileDto? = null)

data class EduSpaceDto(val id: Int, val name: String? = null)

data class LessonPageDto(val lessons: List<LessonDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class LessonDto(
    val id: String,
    val title: String,
    val body: String? = null,
    val space: EduSpaceDto? = null,
    val files: List<EduFileDto> = emptyList(),
    @SerializedName("created_by") val createdBy: EduPersonDto? = null,
    @SerializedName("can_manage") val canManage: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class AssignmentPageDto(val assignments: List<AssignmentDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class AssignmentDto(
    val id: String,
    val title: String,
    val instructions: String? = null,
    @SerializedName("due_at") val dueAt: String? = null,
    @SerializedName("is_past_due") val isPastDue: Boolean = false,
    val mode: String = "individual",
    @SerializedName("max_score") val maxScore: Int? = null,
    val space: EduSpaceDto? = null,
    val files: List<EduFileDto> = emptyList(),
    @SerializedName("my_submission") val mySubmission: SubmissionDto? = null,
    @SerializedName("my_grade") val myGrade: GradeDto? = null,
    @SerializedName("submissions_count") val submissionsCount: Int? = null,
    val can: EduCanDto = EduCanDto(),
)

data class EduCanDto(val manage: Boolean = false, val submit: Boolean = false, val grade: Boolean = false, val take: Boolean = false)

data class SubmissionDto(
    val id: Int,
    val by: EduPersonDto? = null,
    val team: List<EduPersonDto>? = null,
    val body: String? = null,
    val files: List<EduFileDto> = emptyList(),
    val version: Int = 1,
    @SerializedName("is_late") val isLate: Boolean = false,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    val grades: List<StudentGradeDto>? = null,
)

data class GradeDto(val score: Double? = null, val feedback: String? = null, @SerializedName("graded_at") val gradedAt: String? = null)

data class StudentGradeDto(@SerializedName("participant_id") val participantId: Int, val score: Double? = null, val feedback: String? = null)

data class SubmissionsDto(val submissions: List<SubmissionDto> = emptyList(), val missing: List<MissingStudentDto> = emptyList())

data class MissingStudentDto(@SerializedName("participant_id") val participantId: Int, val profile: ProfileDto? = null, val score: Double? = null)

data class QuizPageDto(val quizzes: List<QuizDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class QuizDto(
    val id: String,
    val title: String,
    val instructions: String? = null,
    @SerializedName("duration_minutes") val durationMinutes: Int? = null,
    @SerializedName("is_published") val isPublished: Boolean = false,
    @SerializedName("is_open") val isOpen: Boolean = false,
    @SerializedName("is_competition") val isCompetition: Boolean = false,
    @SerializedName("questions_count") val questionsCount: Int = 0,
    @SerializedName("total_points") val totalPoints: Double = 0.0,
    val questions: List<QuestionDto>? = null,
    @SerializedName("my_attempt") val myAttempt: MyAttemptDto? = null,
    val can: EduCanDto = EduCanDto(),
    @SerializedName("attempts_count") val attemptsCount: Int? = null,
)

data class QuestionDto(
    val id: Int,
    /** choice | true_false | essay */
    val type: String,
    val text: String,
    val options: List<String>? = null,
    val points: Double = 1.0,
    val correct: JsonElement? = null,
)

data class MyAttemptDto(
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("submitted_at") val submittedAt: String? = null,
    @SerializedName("waiting_for_marking") val waitingForMarking: Boolean = false,
    val total: Double? = null,
)

data class AttemptDto(
    val id: Int,
    val student: EduPersonDto? = null,
    val answers: Map<String, JsonElement?>? = null,
    val marks: Map<String, Double?>? = null,
    val total: Double = 0.0,
    @SerializedName("needs_marking") val needsMarking: Boolean = false,
)

data class SessionPageDto(val sessions: List<SessionDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class SessionDto(
    val id: String,
    val title: String,
    @SerializedName("starts_at") val startsAt: String? = null,
    val space: EduSpaceDto? = null,
    @SerializedName("my_status") val myStatus: String? = null,
    @SerializedName("can_manage") val canManage: Boolean = false,
    val register: List<RegisterRowDto>? = null,
)

data class RegisterRowDto(@SerializedName("participant_id") val participantId: Int, val profile: ProfileDto? = null, val status: String? = null)

data class AttendanceReportDto(val counts: Map<String, Int> = emptyMap(), val sessions: List<SessionDto> = emptyList())

data class BagDto(
    @SerializedName("to_hand_in") val toHandIn: List<BagItemDto> = emptyList(),
    val lessons: List<BagItemDto> = emptyList(),
    val grades: List<BagGradeDto> = emptyList(),
    val tests: List<ScheduledTestDto> = emptyList(),
    val reading: List<BagItemDto> = emptyList(),
    val stars: Int = 0,
    val certificates: List<CertificateDto> = emptyList(),
)

data class BagItemDto(val id: String, val title: String, @SerializedName("due_at") val dueAt: String? = null, @SerializedName("is_past_due") val isPastDue: Boolean = false)

data class BagGradeDto(val kind: String, val id: String, val title: String, val score: Double? = null, val max: Int? = null, val feedback: String? = null)

data class TestScheduleDto(val tests: List<ScheduledTestDto> = emptyList())

data class ScheduledTestDto(
    val id: String,
    val title: String,
    @SerializedName("opens_at") val opensAt: String? = null,
    @SerializedName("is_open") val isOpen: Boolean = false,
    @SerializedName("is_competition") val isCompetition: Boolean = false,
)

data class LeaderRowDto(val rank: Int, val student: EduPersonDto? = null, val total: Double = 0.0)

data class StarDto(val student: EduPersonDto? = null, val reason: String? = null, @SerializedName("week_of") val weekOf: String? = null)

data class ReadingDto(
    val id: String,
    val title: String,
    val author: String? = null,
    val link: String? = null,
    val pages: Int? = null,
    @SerializedName("my_status") val myStatus: String? = null,
    @SerializedName("class") val classProgress: ReadingClassDto? = null,
)

data class ReadingClassDto(val students: Int = 0, val reading: Int = 0, val done: Int = 0)

data class CertificateDto(val id: String, val title: String, val reason: String? = null, val student: EduPersonDto? = null, val pdf: String? = null)

data class HelpPageDto(val requests: List<HelpDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class HelpDto(
    val id: String,
    val question: String,
    val status: String,
    val answer: String? = null,
    val asker: EduPersonDto? = null,
    val to: EduPersonDto? = null,
    @SerializedName("answered_by") val answeredBy: EduPersonDto? = null,
    val can: HelpCanDto = HelpCanDto(),
)

data class HelpCanDto(val answer: Boolean = false, val close: Boolean = false)

data class SpaceMembersDto(@SerializedName("participant_ids") val participantIds: List<Int> = emptyList())


// ---------------------------------------------------------------- work groups (GR-040, 042..046)

data class ShiftDto(
    val id: String,
    val title: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val member: EduPersonDto? = null,
    @SerializedName("handover_note") val handoverNote: String? = null,
    @SerializedName("handed_over_at") val handedOverAt: String? = null,
    @SerializedName("swap_with") val swapWith: EduPersonDto? = null,
    val can: ShiftCanDto = ShiftCanDto(),
)

data class ShiftCanDto(
    val manage: Boolean = false,
    val handover: Boolean = false,
    @SerializedName("ask_swap") val askSwap: Boolean = false,
    @SerializedName("answer_swap") val answerSwap: Boolean = false,
)

data class GoalDto(
    val id: String,
    val title: String,
    val description: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val leaderboard: List<GoalRowDto> = emptyList(),
)

data class GoalRowDto(val rank: Int, val member: EduPersonDto? = null, val points: Int = 0)

data class BadgeDto(val id: Int, val emoji: String? = null, val title: String, val reason: String? = null, val member: EduPersonDto? = null)

data class IdeaDto(
    val id: String,
    val title: String,
    val body: String? = null,
    val status: String = "new",
    @SerializedName("status_note") val statusNote: String? = null,
    val votes: Int = 0,
    val voted: Boolean = false,
    val by: EduPersonDto? = null,
)

data class MeetingDto(
    val id: String,
    val title: String,
    @SerializedName("starts_at") val startsAt: String? = null,
    val agenda: String? = null,
    val minutes: String? = null,
    @SerializedName("minutes_by") val minutesBy: EduPersonDto? = null,
    @SerializedName("can_edit") val canEdit: Boolean = false,
)


// ---------------------------------------------------------------- parents (GR-018, 019, 027 — D8)

data class ParentCodeDto(val code: String, @SerializedName("expires_at") val expiresAt: String? = null)

data class MyParentDto(val id: String, val status: String, val parent: ProfileDto? = null)

data class GroupParentsDto(val messaging: Boolean = false, val links: List<ParentLinkDto> = emptyList())

data class ParentLinkDto(val id: String, val status: String, val student: EduPersonDto? = null, val parent: ProfileDto? = null)

data class ChildLinkDto(
    val id: String,
    val status: String,
    val group: ChildGroupDto? = null,
    val student: ProfileDto? = null,
    val messaging: Boolean = false,
    val teachers: List<EduPersonDto> = emptyList(),
)

data class ChildGroupDto(val id: String? = null, val name: String? = null)

data class ChildPageDto(
    val id: String,
    val status: String,
    val group: ChildGroupDto? = null,
    val student: ProfileDto? = null,
    val messaging: Boolean = false,
    val teachers: List<EduPersonDto> = emptyList(),
    val homework: List<BagItemDto> = emptyList(),
    val grades: List<BagGradeDto> = emptyList(),
    val tests: List<ScheduledTestDto> = emptyList(),
    val stars: Int = 0,
    val attendance: AttendanceReportDto = AttendanceReportDto(),
    val sessions: List<SessionDto> = emptyList(),
    val announcements: List<ChildNoticeDto> = emptyList(),
)

data class ChildNoticeDto(val id: String, val body: String? = null, @SerializedName("created_at") val createdAt: String? = null)

data class OpenedChatDto(@SerializedName("conversation_id") val conversationId: String)

data class ParentMeetingDto(
    val id: String,
    val title: String,
    val note: String? = null,
    val mode: String = "group",
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("slot_minutes") val slotMinutes: Int? = null,
    val slots: List<ParentSlotDto> = emptyList(),
    @SerializedName("my_slot") val mySlot: String? = null,
    val call: ParentCallDto? = null,
)

data class ParentSlotDto(val at: String, val free: Boolean = true, val mine: Boolean = false, val parent: ProfileDto? = null, val student: ProfileDto? = null)

data class ParentCallDto(val token: String, val type: String = "video")
