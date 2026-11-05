package com.dorr.provider.ui.screens

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import java.io.File

/**
 * A paper picked from the gallery / files or photographed: a PDF is copied as it is, an image is scaled to
 * 2000px on its longest side and saved as JPEG (the server takes up to 8MB). Written to [name] in the cache; null when the
 * file is neither a JPG, PNG nor PDF or can't be read. Run it off the main thread.
 */
fun prepareDocFile(context: Context, uri: Uri, name: String): File? = runCatching {
    val resolver = context.contentResolver
    val type = resolver.getType(uri) ?: "image/jpeg"
    val dir = File(context.cacheDir, "docs").apply { mkdirs() }
    if (type == "application/pdf") {
        val file = File(dir, "$name.pdf")
        resolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
        return@runCatching file
    }
    if (type != "image/jpeg" && type != "image/png") return@runCatching null
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / sample > 2000) sample *= 2
    val bitmap = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
        ?: return@runCatching null
    val file = File(dir, "$name.jpg")
    file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 88, it) }
    bitmap.recycle()
    file
}.getOrNull()
