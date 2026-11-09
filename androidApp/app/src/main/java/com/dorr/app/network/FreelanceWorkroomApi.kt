package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * FS-150e: a project's Workroom as the client sees it — tasks, files (with versions and notes), the decisions log and the timeline.
 * Everything lives under `mobile/v1/freelance/projects/{uuid}/workroom/…` and works while the agreement is `active` (write) or `completed`
 * (read only). The screens read once when they open, once on a live event (`freelance.workroom` on the project's channel) and once when
 * the connection returns — nothing on a timer.
 *
 * A list answers with its rows, a `summary` (the numbers for the chips), what I `can` do and `read_only`; a page is 30 rows and
 * `pagination.has_more_pages` says another follows. A field I want cleared on an update (a part, a day) goes as an empty string.
 * The bytes of a file version are read with `FreelanceApi.file` (the version's `path`, under `mobile/v1/freelance/`).
 */
interface FreelanceWorkroomApi {
    // ---- tasks ----

    @GET("mobile/v1/freelance/projects/{uuid}/workroom/tasks")
    suspend fun tasks(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Query("status") status: String? = null,
        @Query("milestone") milestone: String? = null,
        @Query("assignee") assignee: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("overdue") overdue: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): ApiEnvelope<WorkTasksDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/tasks")
    suspend fun createTask(@Header("Authorization") auth: String, @Path("uuid") project: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<WorkTaskAnswerDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/workroom/tasks/{task}")
    suspend fun updateTask(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("task") task: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<WorkTaskAnswerDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/tasks/{task}/status")
    suspend fun setTaskStatus(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("task") task: String, @Body body: WorkStatusRequest): ApiEnvelope<WorkTaskAnswerDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/workroom/tasks/{task}")
    suspend fun deleteTask(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("task") task: String): ApiEnvelope<Any?>

    // ---- files ----

    @GET("mobile/v1/freelance/projects/{uuid}/workroom/files")
    suspend fun files(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Query("milestone") milestone: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("search") search: String? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): ApiEnvelope<WorkFilesDto>

    /** The file with all its versions (newest first). */
    @GET("mobile/v1/freelance/projects/{uuid}/workroom/files/{file}")
    suspend fun file(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("file") file: String): ApiEnvelope<WorkFileAnswerDto>

    /** Multipart: `file` and the text fields (`title`, `description`, `milestone`, `note`). */
    @Multipart
    @POST("mobile/v1/freelance/projects/{uuid}/workroom/files")
    suspend fun uploadFile(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part file: MultipartBody.Part,
    ): ApiEnvelope<WorkFileAnswerDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/workroom/files/{file}")
    suspend fun updateFile(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("file") file: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<WorkFileAnswerDto>

    /** A new version of a file (its owner only): `file` and an optional `note`. */
    @Multipart
    @POST("mobile/v1/freelance/projects/{uuid}/workroom/files/{file}/versions")
    suspend fun addVersion(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Path("file") file: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part upload: MultipartBody.Part,
    ): ApiEnvelope<WorkFileAnswerDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/workroom/files/{file}")
    suspend fun deleteFile(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("file") file: String): ApiEnvelope<Any?>

    // ---- notes on a file or a delivery ----

    @GET("mobile/v1/freelance/projects/{uuid}/workroom/comments")
    suspend fun comments(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Query("file") file: String? = null,
        @Query("delivery") delivery: String? = null,
        @Query("version") version: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): ApiEnvelope<WorkCommentsDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/comments")
    suspend fun addComment(@Header("Authorization") auth: String, @Path("uuid") project: String, @Body body: WorkCommentRequest): ApiEnvelope<WorkCommentAnswerDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/workroom/comments/{comment}")
    suspend fun editComment(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("comment") comment: String, @Body body: WorkCommentBodyRequest): ApiEnvelope<WorkCommentAnswerDto>

    @DELETE("mobile/v1/freelance/projects/{uuid}/workroom/comments/{comment}")
    suspend fun deleteComment(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("comment") comment: String): ApiEnvelope<Any?>

    // ---- decisions ----

    @GET("mobile/v1/freelance/projects/{uuid}/workroom/decisions")
    suspend fun decisions(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Query("status") status: String? = null,
        @Query("milestone") milestone: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("waiting") waiting: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): ApiEnvelope<WorkDecisionsDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/decisions")
    suspend fun proposeDecision(@Header("Authorization") auth: String, @Path("uuid") project: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<WorkDecisionAnswerDto>

    @PUT("mobile/v1/freelance/projects/{uuid}/workroom/decisions/{decision}")
    suspend fun updateDecision(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("decision") decision: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): ApiEnvelope<WorkDecisionAnswerDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/decisions/{decision}/agree")
    suspend fun agreeDecision(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("decision") decision: String, @Body body: WorkNoteRequest): ApiEnvelope<WorkDecisionAnswerDto>

    @POST("mobile/v1/freelance/projects/{uuid}/workroom/decisions/{decision}/decline")
    suspend fun declineDecision(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("decision") decision: String, @Body body: WorkNoteRequest): ApiEnvelope<WorkDecisionAnswerDto>

    /** Takes back my own proposal while it waits. */
    @DELETE("mobile/v1/freelance/projects/{uuid}/workroom/decisions/{decision}")
    suspend fun withdrawDecision(@Header("Authorization") auth: String, @Path("uuid") project: String, @Path("decision") decision: String): ApiEnvelope<Any?>

    // ---- timeline ----

    @GET("mobile/v1/freelance/projects/{uuid}/workroom/timeline")
    suspend fun timeline(
        @Header("Authorization") auth: String,
        @Path("uuid") project: String,
        @Query("kind") kind: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): ApiEnvelope<WorkTimelineDto>
}

/** A part of the agreement a task, a file or a decision is tied to. */
data class WorkPartDto(val uuid: String = "", val title: String = "", val position: Int = 0)

// ---- tasks ----

data class WorkTasksDto(
    val tasks: List<WorkTaskDto> = emptyList(),
    val summary: WorkTaskSummaryDto = WorkTaskSummaryDto(),
    val limit: WorkTaskLimitDto = WorkTaskLimitDto(),
    @SerializedName("read_only") val readOnly: Boolean = false,
    val can: WorkTasksCanDto = WorkTasksCanDto(),
)

data class WorkTaskSummaryDto(val total: Int = 0, val todo: Int = 0, val doing: Int = 0, val done: Int = 0, val blocked: Int = 0, val overdue: Int = 0) {
    /** Not finished: the tasks that still ask for something. */
    val open: Int get() = todo + doing + blocked
}

data class WorkTaskLimitDto(val max: Int? = null)

data class WorkTasksCanDto(@SerializedName("task_create") val taskCreate: Boolean = false)

/** `status` = todo | doing | blocked | done; `assignee_side` = client | freelancer. `mine`: it is on my side (given to me). */
data class WorkTaskDto(
    val uuid: String = "",
    val title: String = "",
    val description: String? = null,
    val status: String = "todo",
    @SerializedName("due_on") val dueOn: String? = null,
    val overdue: Boolean = false,
    val milestone: WorkPartDto? = null,
    @SerializedName("assignee_side") val assigneeSide: String = "client",
    @SerializedName("created_by_side") val createdBySide: String = "client",
    val mine: Boolean = false,
    @SerializedName("completed_at") val completedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
    val can: WorkTaskCanDto = WorkTaskCanDto(),
)

data class WorkTaskCanDto(
    @SerializedName("task_edit") val taskEdit: Boolean = false,
    @SerializedName("task_delete") val taskDelete: Boolean = false,
    @SerializedName("task_status") val taskStatus: Boolean = false,
)

data class WorkTaskAnswerDto(val task: WorkTaskDto = WorkTaskDto(), val summary: WorkTaskSummaryDto = WorkTaskSummaryDto())

data class WorkStatusRequest(val status: String)

// ---- files ----

data class WorkFilesDto(
    val files: List<WorkFileDto> = emptyList(),
    val summary: WorkFileSummaryDto = WorkFileSummaryDto(),
    val limit: WorkFileLimitDto = WorkFileLimitDto(),
    val upload: WorkUploadRulesDto = WorkUploadRulesDto(),
    @SerializedName("read_only") val readOnly: Boolean = false,
    val can: WorkFilesCanDto = WorkFilesCanDto(),
)

data class WorkFileSummaryDto(val files: Int = 0, val versions: Int = 0, @SerializedName("storage_bytes") val storageBytes: Long = 0)

data class WorkFileLimitDto(@SerializedName("max_files") val maxFiles: Int? = null, @SerializedName("max_storage_mb") val maxStorageMb: Int? = null)

/** What may be uploaded: the biggest file in KB, the allowed types (extensions) and how many versions a file takes. */
data class WorkUploadRulesDto(
    @SerializedName("max_kb") val maxKb: Int = 0,
    val mimes: List<String> = emptyList(),
    @SerializedName("max_versions") val maxVersions: Int = 0,
)

data class WorkFilesCanDto(@SerializedName("file_upload") val fileUpload: Boolean = false)

data class WorkFileDto(
    val uuid: String = "",
    val title: String = "",
    val description: String? = null,
    val milestone: WorkPartDto? = null,
    @SerializedName("versions_count") val versionsCount: Int = 1,
    @SerializedName("comments_count") val commentsCount: Int = 0,
    /** The newest version. */
    val current: WorkVersionDto? = null,
    /** Every version, newest first — only on the file's own page. */
    val versions: List<WorkVersionDto> = emptyList(),
    @SerializedName("created_by_side") val createdBySide: String = "client",
    val mine: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
    val can: WorkFileCanDto = WorkFileCanDto(),
)

data class WorkVersionDto(
    val number: Int = 1,
    val note: String? = null,
    val name: String = "",
    @SerializedName("mime_type") val mimeType: String = "",
    @SerializedName("size_bytes") val sizeBytes: Long = 0,
    @SerializedName("is_image") val isImage: Boolean = false,
    /** FL-L13: only a small picture of it may be seen for now (never saved or shared). */
    @SerializedName("preview_only") val previewOnly: Boolean = false,
    @SerializedName("by_side") val bySide: String = "client",
    @SerializedName("created_at") val createdAt: String? = null,
    /** Where the bytes are read (signed in), under `mobile/v1/freelance/`. */
    val path: String? = null,
)

data class WorkFileCanDto(
    @SerializedName("file_edit") val fileEdit: Boolean = false,
    @SerializedName("file_delete") val fileDelete: Boolean = false,
    @SerializedName("version_add") val versionAdd: Boolean = false,
    @SerializedName("comment_add") val commentAdd: Boolean = false,
)

data class WorkFileAnswerDto(val file: WorkFileDto = WorkFileDto(), val summary: WorkFileSummaryDto? = null)

// ---- notes ----

data class WorkCommentsDto(
    val comments: List<WorkCommentDto> = emptyList(),
    @SerializedName("read_only") val readOnly: Boolean = false,
    val can: WorkCommentsCanDto = WorkCommentsCanDto(),
)

data class WorkCommentsCanDto(@SerializedName("comment_add") val commentAdd: Boolean = false)

data class WorkCommentDto(
    val uuid: String = "",
    val body: String = "",
    val subject: WorkCommentSubjectDto = WorkCommentSubjectDto(),
    /** The version of the file it is about. */
    val version: Int? = null,
    @SerializedName("author_side") val authorSide: String = "client",
    val mine: Boolean = false,
    @SerializedName("edited_at") val editedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val can: WorkCommentCanDto = WorkCommentCanDto(),
)

data class WorkCommentSubjectDto(val type: String = "file", val uuid: String = "")

data class WorkCommentCanDto(
    @SerializedName("comment_edit") val commentEdit: Boolean = false,
    @SerializedName("comment_delete") val commentDelete: Boolean = false,
)

data class WorkCommentAnswerDto(val comment: WorkCommentDto = WorkCommentDto())

data class WorkCommentRequest(val file: String? = null, val delivery: String? = null, val version: Int? = null, val body: String)

data class WorkCommentBodyRequest(val body: String)

// ---- decisions ----

data class WorkDecisionsDto(
    val decisions: List<WorkDecisionDto> = emptyList(),
    val summary: WorkDecisionSummaryDto = WorkDecisionSummaryDto(),
    @SerializedName("read_only") val readOnly: Boolean = false,
    val can: WorkDecisionsCanDto = WorkDecisionsCanDto(),
)

data class WorkDecisionSummaryDto(
    val total: Int = 0,
    val proposed: Int = 0,
    val agreed: Int = 0,
    val declined: Int = 0,
    val superseded: Int = 0,
    @SerializedName("waiting_for_me") val waitingForMe: Int = 0,
)

data class WorkDecisionsCanDto(@SerializedName("decision_create") val decisionCreate: Boolean = false)

/** `status` = proposed | agreed | declined | superseded. The one who proposes is agreed by that act; the other side answers. */
data class WorkDecisionDto(
    val uuid: String = "",
    val title: String = "",
    val body: String = "",
    val status: String = "proposed",
    val milestone: WorkPartDto? = null,
    @SerializedName("proposed_by_side") val proposedBySide: String = "client",
    val mine: Boolean = false,
    @SerializedName("client_agreed") val clientAgreed: Boolean = false,
    @SerializedName("freelancer_agreed") val freelancerAgreed: Boolean = false,
    @SerializedName("answer_note") val answerNote: String? = null,
    val supersedes: WorkDecisionRefDto? = null,
    @SerializedName("superseded_by") val supersededBy: WorkDecisionRefDto? = null,
    @SerializedName("answered_at") val answeredAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
    val can: WorkDecisionCanDto = WorkDecisionCanDto(),
)

data class WorkDecisionRefDto(val uuid: String = "", val title: String = "")

data class WorkDecisionCanDto(
    @SerializedName("decision_edit") val decisionEdit: Boolean = false,
    @SerializedName("decision_withdraw") val decisionWithdraw: Boolean = false,
    @SerializedName("decision_agree") val decisionAgree: Boolean = false,
    @SerializedName("decision_decline") val decisionDecline: Boolean = false,
    @SerializedName("decision_supersede") val decisionSupersede: Boolean = false,
)

data class WorkDecisionAnswerDto(val decision: WorkDecisionDto = WorkDecisionDto(), val summary: WorkDecisionSummaryDto = WorkDecisionSummaryDto())

data class WorkNoteRequest(val note: String? = null)

// ---- timeline ----

data class WorkTimelineDto(val events: List<WorkEventDto> = emptyList())

/** One step of the project: [label] is ready text in my language; `kind` = task | file | comment | decision | agreement | milestone | dispute | change | review | rights. */
data class WorkEventDto(
    val key: String = "",
    val source: String = "workroom",
    val kind: String = "",
    val action: String = "",
    val label: String = "",
    val actor: String? = null,
    val mine: Boolean = false,
    val by: WorkEventByDto? = null,
    val subject: WorkEventSubjectDto? = null,
    val at: String? = null,
)

/** Who took the step when somebody of a team did: `team` is always true (a flag, not a name). */
data class WorkEventByDto(val name: String? = null, val team: Boolean = false)

/** What the step is about; a note names the file or delivery it is on in [on]. */
data class WorkEventSubjectDto(val type: String? = null, val uuid: String? = null, val title: String? = null, val on: WorkEventSubjectDto? = null)
