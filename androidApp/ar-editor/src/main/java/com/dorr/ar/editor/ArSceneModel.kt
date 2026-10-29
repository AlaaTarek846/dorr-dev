package com.dorr.ar.editor

import org.json.JSONArray
import org.json.JSONObject

/**
 * The editor's own picture of the design, with no AR types in it so it can be reasoned about (and tested) on its own.
 * Positions are stored RELATIVE to the first placed item (the origin): an AR session's world coordinates change every time
 * the app starts, so only offsets from a reference are meaningful when the design is opened again later.
 */
class PlacedItem(
    val assetId: Int,
    val name: String,
    /** World position at placement time, metres. */
    var x: Float, var y: Float, var z: Float,
    /** Unit quaternion x, y, z, w. */
    var qx: Float = 0f, var qy: Float = 0f, var qz: Float = 0f, var qw: Float = 1f,
    var sx: Float = 1f, var sy: Float = 1f, var sz: Float = 1f,
)

object ArSceneModel {
    private const val MIN_SCALE = 0.01f
    private const val MAX_SCALE = 100f
    private const val MAX_ITEMS = 60

    /** Builds the body of `PUT user/v1/ai-ar/experiences/{id}/scene`. The first item is the origin. */
    fun toSceneJson(items: List<PlacedItem>, note: String? = null): JSONObject {
        val origin = items.firstOrNull()
        val list = JSONArray()
        items.take(MAX_ITEMS).forEach { i ->
            val transform = JSONObject()
                .put("position", JSONArray(listOf(i.x - (origin?.x ?: 0f), i.y - (origin?.y ?: 0f), i.z - (origin?.z ?: 0f)).map { it.toDouble() }))
                .put("rotation", JSONArray(listOf(i.qx, i.qy, i.qz, i.qw).map { clamp(it, -1f, 1f).toDouble() }))
                .put("scale", JSONArray(listOf(i.sx, i.sy, i.sz).map { clamp(it, MIN_SCALE, MAX_SCALE).toDouble() }))
            list.put(JSONObject().put("asset_id", i.assetId).put("transform", transform))
        }
        return JSONObject().put("items", list).apply { if (note != null) put("note", note) }
    }

    private fun clamp(v: Float, lo: Float, hi: Float) = if (v.isNaN()) lo.coerceAtLeast(0f).coerceAtMost(hi) else v.coerceIn(lo, hi)
}
