package com.dorr.ar.editor

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat

/**
 * Launched by the main app through reflection with: experience_id (Int), auth ("Bearer ..."), api_base (".../api/").
 * Asks for the camera first; AR availability itself is checked inside the screen.
 */
class ArEditorActivity : ComponentActivity() {
    private var granted by mutableStateOf(false)

    private val askCamera = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted = it }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val experienceId = intent.getIntExtra("experience_id", 0)
        val auth = intent.getStringExtra("auth").orEmpty()
        val base = intent.getStringExtra("api_base").orEmpty()

        granted = ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        if (!granted) askCamera.launch(Manifest.permission.CAMERA)

        setContent {
            MaterialTheme {
                if (experienceId == 0 || auth.isBlank() || base.isBlank()) Text(getString(R.string.are_failed, "missing launch data"))
                else if (!granted) Text(getString(R.string.are_camera))
                else ArEditorScreen(ArEditorApi(base, auth), experienceId, onDone = { finish() })
            }
        }
    }
}
