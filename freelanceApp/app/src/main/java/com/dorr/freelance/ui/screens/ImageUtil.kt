package com.dorr.freelance.ui.screens

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File

/**
 * Turns a picked or photographed image into the avatar upload part: scaled down to 1600px on its longest side and saved
 * as JPEG (the server accepts up to 5MB; a camera photo is far bigger), off the main thread. Null when it can't be read.
 */
fun avatarPart(context: Context, uri: Uri): MultipartBody.Part? = runCatching {
    val resolver = context.contentResolver
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / sample > 1600) sample *= 2
    val bitmap = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
        ?: return@runCatching null
    val file = File(context.cacheDir, "avatar_upload.jpg")
    file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 88, it) }
    bitmap.recycle()
    MultipartBody.Part.createFormData("avatar", "avatar.jpg", file.asRequestBody("image/jpeg".toMediaType()))
}.getOrNull()

/**
 * A verification document picked from the gallery / files or photographed: a PDF is copied as it is, an image is scaled to
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

/**
 * A portfolio image picked from the gallery or photographed: any image the phone can read (JPG, PNG, WebP…) scaled to 2000px
 * on its longest side and saved as JPEG in the cache (the server takes JPG / PNG / WebP up to 5MB). Null when it can't be read.
 * Run it off the main thread.
 */
fun preparePortfolioImage(context: Context, uri: Uri, name: String): File? = runCatching {
    val resolver = context.contentResolver
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
    if (bounds.outWidth <= 0) return@runCatching null
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / sample > 2000) sample *= 2
    val bitmap = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
        ?: return@runCatching null
    val file = File(File(context.cacheDir, "portfolio").apply { mkdirs() }, "$name.jpg")
    file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 88, it) }
    bitmap.recycle()
    file
}.getOrNull()
