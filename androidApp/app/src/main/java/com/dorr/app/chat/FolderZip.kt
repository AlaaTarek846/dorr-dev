package com.dorr.app.chat

import android.content.Context
import android.net.Uri
import android.provider.DocumentsContract
import com.dorr.app.ui.screens.chat.LocalFile
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.withContext
import java.io.File
import java.util.UUID
import java.util.zip.ZipEntry
import java.util.zip.ZipOutputStream
import kotlin.coroutines.coroutineContext

/**
 * A folder sent in a chat (spec 10): the folder the person picked (Storage Access Framework, no
 * storage permission) is zipped on the phone, sub-folders kept, and goes as one document with its
 * name and how many files were in it.
 */
object FolderZip {
    /** What came out: the zip, the folder's name and its file count. */
    data class Zipped(val file: LocalFile, val name: String, val count: Int)

    sealed interface Result {
        data class Ok(val zipped: Zipped) : Result
        data object Empty : Result
        /** More than [maxBytes] of files. */
        data object TooBig : Result
        data object Failed : Result
    }

    private const val MAX_FILES = 5_000
    private const val MAX_DEPTH = 12

    private data class Entry(val uri: Uri, val path: String, val size: Long)

    suspend fun zip(context: Context, tree: Uri, maxBytes: Long): Result = withContext(Dispatchers.IO) {
        runCatching {
            val resolver = context.contentResolver
            val rootId = DocumentsContract.getTreeDocumentId(tree)
            val name = displayName(context, DocumentsContract.buildDocumentUriUsingTree(tree, rootId))
                ?: rootId.substringAfterLast(':').substringAfterLast('/').ifBlank { "folder" }

            // Every file under it, with its path inside the folder.
            val entries = mutableListOf<Entry>()
            var total = 0L
            fun walk(docId: String, prefix: String, depth: Int) {
                if (depth > MAX_DEPTH || entries.size >= MAX_FILES) return
                val children = DocumentsContract.buildChildDocumentsUriUsingTree(tree, docId)
                resolver.query(
                    children,
                    arrayOf(DocumentsContract.Document.COLUMN_DOCUMENT_ID, DocumentsContract.Document.COLUMN_DISPLAY_NAME, DocumentsContract.Document.COLUMN_MIME_TYPE, DocumentsContract.Document.COLUMN_SIZE),
                    null, null, null,
                )?.use { c ->
                    while (c.moveToNext() && entries.size < MAX_FILES) {
                        val id = c.getString(0) ?: continue
                        val child = (c.getString(1) ?: id.substringAfterLast('/')).replace('/', '_')
                        if (c.getString(2) == DocumentsContract.Document.MIME_TYPE_DIR) walk(id, "$prefix$child/", depth + 1)
                        else {
                            val size = if (c.isNull(3)) 0L else c.getLong(3)
                            total += size
                            entries += Entry(DocumentsContract.buildDocumentUriUsingTree(tree, id), "$prefix$child", size)
                        }
                    }
                }
            }
            walk(rootId, "", 0)

            if (entries.isEmpty()) return@runCatching Result.Empty
            if (total > maxBytes) return@runCatching Result.TooBig

            val out = File(File(context.cacheDir, "chat-out").apply { mkdirs() }, "${UUID.randomUUID()}-folder.zip")
            val seen = mutableSetOf<String>()
            ZipOutputStream(out.outputStream().buffered()).use { zip ->
                for (entry in entries) {
                    coroutineContext.ensureActive()
                    // Two files of the same name (some providers allow it) get a number.
                    var path = entry.path
                    val dir = entry.path.substringBeforeLast('/', "").let { if (it.isEmpty()) "" else "$it/" }
                    val file = entry.path.substringAfterLast('/')
                    val ext = file.substringAfterLast('.', "").let { if (it.isEmpty()) "" else ".$it" }
                    var n = 1
                    while (!seen.add(path)) path = "$dir${file.removeSuffix(ext)} (${n++})$ext"
                    resolver.openInputStream(entry.uri)?.use { input ->
                        zip.putNextEntry(ZipEntry("$name/$path"))
                        input.copyTo(zip)
                        zip.closeEntry()
                    }
                }
            }
            // Zipping can grow already-compressed photos a little: check what's really sent.
            if (out.length() > maxBytes) {
                out.delete()
                return@runCatching Result.TooBig
            }
            Result.Ok(Zipped(LocalFile(out, "application/zip", "$name.zip"), name, entries.size))
        }.getOrElse { if (it is kotlinx.coroutines.CancellationException) throw it else Result.Failed }
    }

    private fun displayName(context: Context, uri: Uri): String? = runCatching {
        context.contentResolver.query(uri, arrayOf(DocumentsContract.Document.COLUMN_DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull()
}
