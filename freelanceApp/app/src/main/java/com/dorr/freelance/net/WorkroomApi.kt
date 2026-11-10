package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * FS-150e: a project's Workroom as the freelancer sees it — tasks, files (with versions and notes), the decisions log and the timeline.
 * Everything lives under `projects/{uuid}/workroom/…` and works while the agreement is `active` (write) or `completed` (read only). The
 * screens read once when they open, once on a live event (`freelance.workroom` on the project's channel) and once when the connection returns.
 *
 * A list answers with its rows, a `summary` (the numbers for the chips), what I `can` do and `read_only`; the pages are 30 rows (a full page
 * means another one follows, as everywhere in the app). A field I want cleared on an update (a part, a day) goes as an empty string.
 */
interface WorkroomApi {
    // ---- tasks ----

    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/tasks")
    suspend fun tasks(
        @Path("uuid") project: String,
        @Query("status") status: String? = null,
        @Query("milestone") milestone: String? = null,
        @Query("assignee") assignee: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("overdue") overdue: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): Response<ApiEnvelope<WorkTasksDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/tasks")
    suspend fun createTask(@Path("uuid") project: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<WorkTaskAnswerDto>>

    @PUT("${ApiClient.FREELANCE}projects/{uuid}/workroom/tasks/{task}")
    suspend fun updateTask(@Path("uuid") project: String, @Path("task") task: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<WorkTaskAnswerDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/tasks/{task}/status")
    suspend fun setTaskStatus(@Path("uuid") project: String, @Path("task") task: String, @Body body: WorkStatusRequest): Response<ApiEnvelope<WorkTaskAnswerDto>>

    @DELETE("${ApiClient.FREELANCE}projects/{uuid}/workroom/tasks/{task}")
    suspend fun deleteTask(@Path("uuid") project: String, @Path("task") task: String): Response<ApiEnvelope<WorkSummaryAnswerDto>>

    // ---- files ----

    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/files")
    suspend fun files(
        @Path("uuid") project: String,
        @Query("milestone") milestone: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("search") search: String? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): Response<ApiEnvelope<WorkFilesDto>>

    /** The file with all its versions (newest first). */
    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/files/{file}")
    suspend fun file(@Path("uuid") project: String, @Path("file") file: String): Response<ApiEnvelope<WorkFileAnswerDto>>

    /** Multipart: `file` and the text fields (`title`, `description`, `milestone`, `note`, `scope`). */
    @Multipart
    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/files")
    suspend fun uploadFile(@Path("uuid") project: String, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part file: MultipartBody.Part): Response<ApiEnvelope<WorkFileAnswerDto>>

    @PUT("${ApiClient.FREELANCE}projects/{uuid}/workroom/files/{file}")
    suspend fun updateFile(@Path("uuid") project: String, @Path("file") file: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<WorkFileAnswerDto>>

    /** A new version of a file (the owner only): `file` and an optional `note`. */
    @Multipart
    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/files/{file}/versions")
    suspend fun addVersion(@Path("uuid") project: String, @Path("file") file: String, @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>, @Part file_: MultipartBody.Part): Response<ApiEnvelope<WorkFileAnswerDto>>

    @DELETE("${ApiClient.FREELANCE}projects/{uuid}/workroom/files/{file}")
    suspend fun deleteFile(@Path("uuid") project: String, @Path("file") file: String): Response<ApiEnvelope<WorkSummaryAnswerDto>>

    /** One version's bytes (private, signed in): a picture shows in place, anything else is saved and opened. */
    @Streaming
    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/files/{file}/versions/{number}/download")
    suspend fun download(@Path("uuid") project: String, @Path("file") file: String, @Path("number") number: Int): Response<ResponseBody>

    // ---- notes on a file or a delivery ----

    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/comments")
    suspend fun comments(
        @Path("uuid") project: String,
        @Query("file") file: String? = null,
        @Query("delivery") delivery: String? = null,
        @Query("version") version: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): Response<ApiEnvelope<WorkCommentsDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/comments")
    suspend fun addComment(@Path("uuid") project: String, @Body body: WorkCommentRequest): Response<ApiEnvelope<WorkCommentAnswerDto>>

    @PUT("${ApiClient.FREELANCE}projects/{uuid}/workroom/comments/{comment}")
    suspend fun editComment(@Path("uuid") project: String, @Path("comment") comment: String, @Body body: WorkCommentBodyRequest): Response<ApiEnvelope<WorkCommentAnswerDto>>

    @DELETE("${ApiClient.FREELANCE}projects/{uuid}/workroom/comments/{comment}")
    suspend fun deleteComment(@Path("uuid") project: String, @Path("comment") comment: String): Response<ApiEnvelope<Any>>

    // ---- decisions ----

    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions")
    suspend fun decisions(
        @Path("uuid") project: String,
        @Query("status") status: String? = null,
        @Query("milestone") milestone: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("waiting") waiting: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): Response<ApiEnvelope<WorkDecisionsDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions")
    suspend fun proposeDecision(@Path("uuid") project: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<WorkDecisionAnswerDto>>

    @PUT("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions/{decision}")
    suspend fun updateDecision(@Path("uuid") project: String, @Path("decision") decision: String, @Body body: Map<String, @JvmSuppressWildcards Any?>): Response<ApiEnvelope<WorkDecisionAnswerDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions/{decision}/agree")
    suspend fun agreeDecision(@Path("uuid") project: String, @Path("decision") decision: String, @Body body: WorkNoteRequest): Response<ApiEnvelope<WorkDecisionAnswerDto>>

    @POST("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions/{decision}/decline")
    suspend fun declineDecision(@Path("uuid") project: String, @Path("decision") decision: String, @Body body: WorkNoteRequest): Response<ApiEnvelope<WorkDecisionAnswerDto>>

    /** Takes back my own proposal while it waits. */
    @DELETE("${ApiClient.FREELANCE}projects/{uuid}/workroom/decisions/{decision}")
    suspend fun withdrawDecision(@Path("uuid") project: String, @Path("decision") decision: String): Response<ApiEnvelope<WorkSummaryAnswerDto>>

    // ---- timeline ----

    @GET("${ApiClient.FREELANCE}projects/{uuid}/workroom/timeline")
    suspend fun timeline(
        @Path("uuid") project: String,
        @Query("kind") kind: String? = null,
        @Query("mine") mine: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("page") page: Int? = null,
    ): Response<ApiEnvelope<WorkTimelineDto>>
}

/** A part of the agreement a task, a file or a decision is tied to. */
data class WorkPartDto(val uuid: String = "", val title: String = "", val position: Int = 0)

data class WorkPersonDto(val uuid: String? = null, val name: String? = null)

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

/** `status` = todo | doing | blocked | done; `assignee_side` = client | freelancer; `scope` = shared | team (a team's inside task: the client never sees it). */
data class WorkTaskDto(
    val uuid: String = "",
    val title: String = "",
    val description: String? = null,
    val status: String = "todo",
    @SerializedName("due_on") val dueOn: String? = null,
    val overdue: Boolean = false,
    val milestone: WorkPartDto? = null,
    @SerializedName("assignee_side") val assigneeSide: String = "freelancer",
    val scope: String = "shared",
    /** The person of my team it is given to (FS-206b). */
    val assignee: WorkPersonDto? = null,
    @SerializedName("created_by_side") val createdBySide: String = "freelancer",
    /** It is on my side. */
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

/** What a delete answers: the new numbers (a task, a file or a decision — each reads the part it needs). */
data class WorkSummaryAnswerDto(val summary: com.google.gson.JsonObject? = null)

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
    @SerializedName("created_by_side") val createdBySide: String = "freelancer",
    val mine: Boolean = false,
    val scope: String = "shared",
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
    @SerializedName("preview_only") val previewOnly: Boolean = false,
    @SerializedName("by_side") val bySide: String = "freelancer",
    @SerializedName("created_at") val createdAt: String? = null,
    /** Where the bytes are read (signed in), relative to the freelancer API. */
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
    @SerializedName("author_side") val authorSide: String = "freelancer",
    val mine: Boolean = false,
    val scope: String = "shared",
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

/** `status` = proposed | agreed | declined | superseded. */
data class WorkDecisionDto(
    val uuid: String = "",
    val title: String = "",
    val body: String = "",
    val status: String = "proposed",
    val milestone: WorkPartDto? = null,
    @SerializedName("proposed_by_side") val proposedBySide: String = "freelancer",
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

data class WorkEventSubjectDto(val type: String? = null, val uuid: String? = null, val title: String? = null)
