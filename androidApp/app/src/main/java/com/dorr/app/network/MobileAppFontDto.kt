package com.dorr.app.network

import com.google.gson.annotations.SerializedName

data class MobileAppFontDto(
    val id: Int = 0,
    val name: String? = null,
    val slug: String? = null,
    val status: Boolean = true,
    @SerializedName("is_default") val isDefault: Boolean = false,
    @SerializedName("sort_order") val sortOrder: Int = 0,
    @SerializedName("font_files") val fontFiles: List<MobileAppFontFileDto>? = null,
)

data class MobileAppFontFileDto(
    val id: Int = 0,
    @SerializedName("file_name") val fileName: String? = null,
    val url: String? = null,
    val weight: String? = null,
)
