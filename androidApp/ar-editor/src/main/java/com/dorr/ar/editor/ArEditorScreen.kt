package com.dorr.ar.editor

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.google.ar.core.Frame
import com.google.ar.core.TrackingState
import io.github.sceneview.ar.ARScene
import io.github.sceneview.ar.node.AnchorNode
import io.github.sceneview.node.ModelNode
import io.github.sceneview.node.Node
import io.github.sceneview.rememberEngine
import io.github.sceneview.rememberModelLoader
import io.github.sceneview.rememberNodes
import io.github.sceneview.rememberOnGestureListener
import kotlinx.coroutines.launch
import java.nio.ByteBuffer

/**
 * NOTE: this is the only file that talks to SceneView. It was written against SceneView's Compose API without being
 * compiled here, so if the version you pick names something differently, fix it in this file only: everything else
 * (model, API client, activity) has no AR types in it.
 *
 * Flow: pick a model chip -> tap a detected surface -> a model appears (editable by touch) -> Save design.
 */
@Composable
internal fun ArEditorScreen(api: ArEditorApi, experienceId: Int, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val engine = rememberEngine()
    val modelLoader = rememberModelLoader(engine)
    val childNodes = rememberNodes()

    var assets by remember { mutableStateOf<List<ArEditorApi.AssetInfo>>(emptyList()) }
    var chosen by remember { mutableStateOf<ArEditorApi.AssetInfo?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var frame by remember { mutableStateOf<Frame?>(null) }
    // Parallel to the nodes we add: what each one is, so the scene can be saved.
    val placed = remember { mutableStateListOf<Pair<PlacedItem, Node>>() }

    LaunchedEffect(Unit) {
        assets = runCatching { api.assets() }.getOrElse { message = context.getString(R.string.are_failed, it.message.orEmpty()); emptyList() }
    }

    fun place(hitX: Float, hitY: Float) {
        val asset = chosen ?: return
        val hit = frame?.takeIf { it.camera.trackingState == TrackingState.TRACKING }?.hitTest(hitX, hitY)?.firstOrNull() ?: return
        val anchor = hit.createAnchor()
        scope.launch {
            busy = true
            runCatching {
                val bytes = api.download(api.downloadUrl(asset.id))
                val instance = modelLoader.createModelInstance(ByteBuffer.wrap(bytes))
                val anchorNode = AnchorNode(engine = engine, anchor = anchor)
                val model = ModelNode(modelInstance = instance, scaleToUnits = null).apply { isEditable = true }
                anchorNode.addChildNode(model)
                childNodes.add(anchorNode)
                placed.add(PlacedItem(asset.id, asset.name, 0f, 0f, 0f) to model)
            }.onFailure { message = context.getString(R.string.are_failed, it.message.orEmpty()) }
            busy = false
        }
    }

    /** Copies each node's current world transform into its item right before saving (the user may have moved it by touch). */
    fun snapshot(): List<PlacedItem> = placed.map { (item, node) ->
        val p = node.worldPosition
        val q = node.worldQuaternion
        val s = node.worldScale
        item.apply { x = p.x; y = p.y; z = p.z; qx = q.x; qy = q.y; qz = q.z; qw = q.w; sx = s.x; sy = s.y; sz = s.z }
    }

    Box(Modifier.fillMaxSize()) {
        ARScene(
            modifier = Modifier.fillMaxSize(),
            engine = engine,
            modelLoader = modelLoader,
            childNodes = childNodes,
            planeRenderer = true,
            onSessionUpdated = { _, f -> frame = f },
            onGestureListener = rememberOnGestureListener(
                onSingleTapConfirmed = { event, node -> if (node == null) place(event.x, event.y) },
            ),
        )

        Column(Modifier.align(Alignment.TopCenter).fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                assets.forEach { a -> FilterChip(selected = chosen?.id == a.id, onClick = { chosen = a }, label = { Text(a.name) }) }
            }
            Text(context.getString(if (chosen == null) R.string.are_pick_model else R.string.are_hint))
            message?.let { Text(it) }
        }

        Row(Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(context.getString(R.string.are_items, placed.size))
            OutlinedButton(enabled = placed.isNotEmpty() && !busy, onClick = {
                placed.removeLastOrNull()?.let { (_, node) -> node.parent?.let { childNodes.remove(it) }; node.destroy() }
            }) { Text(context.getString(R.string.are_undo)) }
            Button(enabled = placed.isNotEmpty() && !busy, onClick = {
                scope.launch {
                    busy = true
                    runCatching { api.saveScene(experienceId, ArSceneModel.toSceneJson(snapshot())) }
                        .onSuccess { message = context.getString(R.string.are_saved); onDone() }
                        .onFailure { message = context.getString(R.string.are_failed, it.message.orEmpty()) }
                    busy = false
                }
            }) { Text(context.getString(R.string.are_save)) }
        }
    }
}
