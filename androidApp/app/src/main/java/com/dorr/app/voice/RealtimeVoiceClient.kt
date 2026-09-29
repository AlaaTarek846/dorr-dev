package com.dorr.app.voice

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFocusRequest
import android.media.AudioManager
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import org.webrtc.AudioTrack
import org.webrtc.DataChannel
import org.webrtc.DefaultVideoDecoderFactory
import org.webrtc.DefaultVideoEncoderFactory
import org.webrtc.EglBase
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStream
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpReceiver
import org.webrtc.RtpTransceiver
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import java.nio.charset.StandardCharsets
import java.util.concurrent.TimeUnit

enum class RealtimeVoiceState { IDLE, CONNECTING, CONNECTED, ENDED, FAILED }

/**
 * Phase 7 (Realtime Voice): a direct WebRTC call to OpenAI's own edge
 * (`POST {webrtc_endpoint}` with a raw SDP offer, per
 * https://developers.openai.com/api/docs/guides/voice-webrtc — endpoint
 * `https://api.openai.com/v1/realtime/calls`, request Content-Type
 * `application/sdp`, response is a raw SDP answer, auth is the short-lived
 * `client_secret` our backend mints via AiRealtimeController/AiRealtimeService
 * and hands to this client — never the real OpenAI API key).
 *
 * IMPORTANT — unlike everything else built for this module this session,
 * this class was written and balance-checked but could NOT be run against a
 * real device/emulator or real OpenAI Realtime credentials from this
 * environment. WebRTC correctness (ICE/DTLS negotiation, audio routing,
 * native library behaviour) can only really be confirmed by an actual call
 * on a device. Treat this as a first, careful attempt that needs a real
 * test-and-report pass, not as verified-working code.
 *
 * One instance per call; not reused across calls. All public state is
 * Compose-observable so [com.dorr.app.ui.screens.aichat.AiVoiceScreen] can
 * just read it directly.
 */
class RealtimeVoiceClient(private val appContext: Context) {

    var state by mutableStateOf(RealtimeVoiceState.IDLE)
        private set
    var muted by mutableStateOf(false)
        private set
    var assistantSpeaking by mutableStateOf(false)
        private set
    var transcript by mutableStateOf("")
        private set
    var errorMessage by mutableStateOf<String?>(null)
        private set

    private val mainScope = CoroutineScope(Dispatchers.Main)
    private val ioScope = CoroutineScope(Dispatchers.IO)

    // Plain OkHttp client with no interceptors: this call goes straight to
    // OpenAI, never through ApiClient's backend-scoped interceptors (device
    // headers, 401-session-clear, ngrok bypass header) which assume a
    // response shape and semantics that only apply to our own backend.
    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    private var eglBase: EglBase? = null
    private var factory: PeerConnectionFactory? = null
    private var peerConnection: PeerConnection? = null
    private var localAudioTrack: AudioTrack? = null
    private var dataChannel: DataChannel? = null
    private var audioFocusRequest: AudioFocusRequest? = null
    private var factoryInitialized = false
    private var ended = false

    private fun ensureFactory() {
        if (factoryInitialized) return
        factoryInitialized = true
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(appContext.applicationContext)
                .createInitializationOptions(),
        )
        val egl = EglBase.create()
        eglBase = egl
        factory = PeerConnectionFactory.builder()
            .setOptions(PeerConnectionFactory.Options())
            .setVideoEncoderFactory(DefaultVideoEncoderFactory(egl.eglBaseContext, true, true))
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext))
            .createPeerConnectionFactory()
    }

    private fun postToMain(block: () -> Unit) {
        mainScope.launch { block() }
    }

    /**
     * Opens the call. [clientSecret] and [webrtcEndpoint] come straight from
     * `POST user/v1/ai-realtime/session` (AiRealtimeSessionDto) — this class
     * never talks to our own backend, only to [webrtcEndpoint].
     */
    fun connect(clientSecret: String, webrtcEndpoint: String) {
        if (state != RealtimeVoiceState.IDLE) return
        ensureFactory()
        state = RealtimeVoiceState.CONNECTING
        errorMessage = null

        requestAudioFocus()
        routeAudioForCall()

        val pcFactory = factory
        if (pcFactory == null) {
            failWith("تعذر تجهيز WebRTC")
            return
        }

        // Non-trickle: OpenAI's edge returns a complete SDP answer (candidates
        // already bundled in), so no ICE servers and no candidate exchange of
        // our own are needed here.
        val rtcConfig = PeerConnection.RTCConfiguration(emptyList<PeerConnection.IceServer>()).apply {
            sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN
            continualGatheringPolicy = PeerConnection.ContinualGatheringPolicy.GATHER_ONCE
        }

        val observer = object : PeerConnection.Observer {
            override fun onIceCandidate(candidate: IceCandidate?) {}
            override fun onIceCandidatesRemoved(candidates: Array<out IceCandidate>?) {}
            override fun onSignalingChange(newState: PeerConnection.SignalingState?) {}
            override fun onIceConnectionChange(newState: PeerConnection.IceConnectionState?) {
                postToMain {
                    when (newState) {
                        PeerConnection.IceConnectionState.CONNECTED,
                        PeerConnection.IceConnectionState.COMPLETED,
                        -> if (state == RealtimeVoiceState.CONNECTING) state = RealtimeVoiceState.CONNECTED
                        PeerConnection.IceConnectionState.FAILED -> failWith("انقطع الاتصال بالمكالمة الصوتية")
                        PeerConnection.IceConnectionState.DISCONNECTED,
                        PeerConnection.IceConnectionState.CLOSED,
                        -> if (!ended && state == RealtimeVoiceState.CONNECTED) failWith("انقطع الاتصال بالمكالمة الصوتية")
                        else -> {}
                    }
                }
            }
            override fun onIceConnectionReceivingChange(receiving: Boolean) {}
            override fun onIceGatheringChange(newState: PeerConnection.IceGatheringState?) {}
            override fun onAddStream(stream: MediaStream?) {}
            override fun onRemoveStream(stream: MediaStream?) {}
            override fun onDataChannel(channel: DataChannel?) {}
            override fun onRenegotiationNeeded() {}
            override fun onAddTrack(receiver: RtpReceiver?, streams: Array<out MediaStream>?) {
                // Remote (assistant) audio: WebRTC plays it through the active
                // audio route automatically once this track exists — no
                // manual renderer needed for audio-only.
            }
            override fun onTrack(transceiver: RtpTransceiver?) {}
        }

        val pc = pcFactory.createPeerConnection(rtcConfig, observer)
        if (pc == null) {
            failWith("تعذر إنشاء اتصال WebRTC")
            return
        }
        peerConnection = pc

        val audioSource = pcFactory.createAudioSource(MediaConstraints())
        val track = pcFactory.createAudioTrack("mic0", audioSource)
        track.setEnabled(true)
        localAudioTrack = track
        pc.addTrack(track, listOf("dorr-ai-voice"))

        val dc = pc.createDataChannel("oai-events", DataChannel.Init())
        dataChannel = dc
        dc?.registerObserver(object : DataChannel.Observer {
            override fun onBufferedAmountChange(previousAmount: Long) {}
            override fun onStateChange() {}
            override fun onMessage(buffer: DataChannel.Buffer?) {
                if (buffer == null) return
                val bytes = ByteArray(buffer.data.remaining())
                buffer.data.get(bytes)
                val text = String(bytes, StandardCharsets.UTF_8)
                postToMain { handleRealtimeEvent(text) }
            }
        })

        pc.createOffer(
            object : SdpObserver {
                override fun onCreateSuccess(desc: SessionDescription?) {
                    if (desc == null) {
                        postToMain { failWith("تعذر تجهيز عرض الاتصال (SDP)") }
                        return
                    }
                    pc.setLocalDescription(
                        object : SdpObserver {
                            override fun onCreateSuccess(ignored: SessionDescription?) {}
                            override fun onSetSuccess() {
                                exchangeSdp(desc, clientSecret, webrtcEndpoint, pc)
                            }
                            override fun onCreateFailure(reason: String?) {}
                            override fun onSetFailure(reason: String?) {
                                postToMain { failWith(reason ?: "فشل ضبط عرض الاتصال المحلي") }
                            }
                        },
                        desc,
                    )
                }
                override fun onSetSuccess() {}
                override fun onCreateFailure(reason: String?) {
                    postToMain { failWith(reason ?: "فشل إنشاء عرض الاتصال") }
                }
                override fun onSetFailure(reason: String?) {}
            },
            MediaConstraints(),
        )
    }

    private fun exchangeSdp(offer: SessionDescription, clientSecret: String, webrtcEndpoint: String, pc: PeerConnection) {
        ioScope.launch {
            try {
                val body = offer.description.toRequestBody("application/sdp".toMediaTypeOrNull())
                val request = Request.Builder()
                    .url(webrtcEndpoint)
                    .header("Authorization", "Bearer $clientSecret")
                    .header("Content-Type", "application/sdp")
                    .post(body)
                    .build()
                http.newCall(request).execute().use { response ->
                    val answerSdp = response.body?.string().orEmpty()
                    if (!response.isSuccessful || answerSdp.isBlank()) {
                        postToMain { failWith("رفض OpenAI الاتصال (${response.code})") }
                        return@launch
                    }
                    postToMain {
                        pc.setRemoteDescription(
                            object : SdpObserver {
                                override fun onCreateSuccess(ignored: SessionDescription?) {}
                                override fun onSetSuccess() {
                                    // ICE/DTLS finishes asynchronously; onIceConnectionChange(CONNECTED) above flips state.
                                }
                                override fun onCreateFailure(reason: String?) {}
                                override fun onSetFailure(reason: String?) {
                                    failWith(reason ?: "فشل ضبط رد الاتصال من OpenAI")
                                }
                            },
                            SessionDescription(SessionDescription.Type.ANSWER, answerSdp),
                        )
                    }
                }
            } catch (e: Exception) {
                postToMain { failWith(e.message ?: "تعذر الوصول لخادم OpenAI") }
            }
        }
    }

    /**
     * Defensive, best-effort parsing of Realtime server events — only the
     * handful this screen actually shows (live transcript + a speaking
     * indicator). The full event set is large and still evolving upstream,
     * so an unrecognized `type` is silently ignored rather than treated as
     * an error.
     */
    private fun handleRealtimeEvent(json: String) {
        runCatching {
            val obj = JSONObject(json)
            when (obj.optString("type")) {
                "response.audio_transcript.delta", "response.output_audio_transcript.delta" -> {
                    transcript += obj.optString("delta", "")
                    assistantSpeaking = true
                }
                "response.audio_transcript.done", "response.output_audio_transcript.done",
                "response.done", "response.audio.done", "response.output_audio.done",
                -> assistantSpeaking = false
                "input_audio_buffer.speech_started" -> transcript = ""
                "error" -> {
                    val message = obj.optJSONObject("error")?.optString("message")
                    if (!message.isNullOrBlank()) errorMessage = message
                }
                else -> {}
            }
        }
    }

    fun toggleMute() {
        muted = !muted
        localAudioTrack?.setEnabled(!muted)
    }

    private fun failWith(message: String) {
        if (ended) return
        state = RealtimeVoiceState.FAILED
        errorMessage = message
    }

    private fun requestAudioFocus() {
        val audioManager = appContext.getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
        val attrs = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION)
            .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
            .build()
        val request = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN_TRANSIENT_EXCLUSIVE)
            .setAudioAttributes(attrs)
            .build()
        audioFocusRequest = request
        runCatching { audioManager.requestAudioFocus(request) }
    }

    private fun routeAudioForCall() {
        val audioManager = appContext.getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
        runCatching {
            audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
            audioManager.isSpeakerphoneOn = true
        }
    }

    private fun restoreAudioRouting() {
        val audioManager = appContext.getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
        runCatching {
            audioManager.mode = AudioManager.MODE_NORMAL
            audioManager.isSpeakerphoneOn = false
            audioFocusRequest?.let { audioManager.abandonAudioFocusRequest(it) }
        }
    }

    /** Ends the call and releases all native WebRTC resources. Safe to call more than once. */
    fun disconnect() {
        if (ended) return
        ended = true
        state = RealtimeVoiceState.ENDED
        runCatching { dataChannel?.close() }
        runCatching { dataChannel?.dispose() }
        dataChannel = null
        runCatching { localAudioTrack?.setEnabled(false) }
        runCatching { localAudioTrack?.dispose() }
        localAudioTrack = null
        runCatching { peerConnection?.close() }
        runCatching { peerConnection?.dispose() }
        peerConnection = null
        restoreAudioRouting()
        runCatching { factory?.dispose() }
        factory = null
        runCatching { eglBase?.release() }
        eglBase = null
    }
}
