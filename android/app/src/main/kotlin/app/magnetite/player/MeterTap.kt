package app.magnetite.player

import androidx.media3.common.audio.AudioProcessor
import androidx.media3.common.audio.BaseAudioProcessor
import androidx.media3.common.C
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.math.abs
import kotlin.math.sqrt

/**
 * Passes audio through untouched and keeps the last second or so of it (left and right) for the meters.
 * It lives in the player's audio sink (in [PlaybackService]); the UI, in the same process, reads it.
 *
 * The tap sits in front of the sink's buffer, so what it sees is heard a few hundred milliseconds later. The meters
 * therefore do not read the newest samples but the ones at the *playhead*: [PlaybackService]'s sink wrapper reports
 * the media position being heard ([noteSinkPosition]) and the media time of each buffer ([noteBuffer]), which
 * [playheadFrame] turns into an index in the ring. Peak and RMS are measured over a short window ending there, every
 * frame the screen draws, not once per decoded block.
 * Formats it cannot read are passed through unmeasured, never refused, so playback is never at risk.
 */
object MeterTap : BaseAudioProcessor() {
    /** Peak and RMS for left and right (0..1, full scale = 1) of the window at the playhead; [atNanos] is when the sink last reported its position (0 = never). */
    class Levels(val peakL: Float, val peakR: Float, val rmsL: Float, val rmsR: Float, val atNanos: Long)

    private const val RING = 1 shl 17
    private const val WINDOW = 1024
    private val ringL = FloatArray(RING)
    private val ringR = FloatArray(RING)
    @Volatile private var written = 0L

    /** Sample rate of the audio being measured, for the spectrum's frequency axis. */
    @Volatile var sampleRate = 44100
        private set

    // Media time (µs) of recent buffers and the ring index of their first frame. Written on the playback thread, read by the UI.
    private val anchors = Any()
    private val anchorPts = LongArray(64)
    private val anchorFrame = LongArray(64)
    private var anchorCount = 0
    private var anchorNext = 0
    private var pendingPts = Long.MIN_VALUE
    private var lastAnchorPts = Long.MIN_VALUE

    @Volatile private var sinkPosUs = 0L
    @Volatile private var sinkAtNanos = 0L

    /** Called by the sink wrapper just before a buffer is handed to the sink. */
    fun noteBuffer(presentationTimeUs: Long) {
        pendingPts = presentationTimeUs
    }

    /** Called by the sink wrapper with the media position the listener is hearing now. */
    fun noteSinkPosition(positionUs: Long) {
        if (positionUs == Long.MIN_VALUE) return
        sinkPosUs = positionUs
        sinkAtNanos = System.nanoTime()
    }

    /** Index (one past the last frame) of the audio being heard, or the newest frame when the position is not known yet. */
    private fun playheadFrame(): Long {
        val newest = written
        val at = sinkAtNanos
        if (at == 0L) return newest
        // The sink is asked for its position every few milliseconds; carry it forward by the time since, at most 100 ms.
        val pos = sinkPosUs + minOf(100_000L, (System.nanoTime() - at) / 1000)
        val rate = sampleRate
        var frame = -1L
        synchronized(anchors) {
            var best = -1
            for (i in 0 until anchorCount) if (anchorPts[i] <= pos && (best < 0 || anchorPts[i] > anchorPts[best])) best = i
            if (best >= 0) frame = anchorFrame[best] + (pos - anchorPts[best]) * rate / 1_000_000L
        }
        if (frame < 0) return newest
        return frame.coerceIn(newest - RING + 16384, newest)
    }

    /** Peak and RMS of the last [WINDOW] frames before the playhead. */
    fun snapshot(): Levels {
        val end = playheadFrame()
        var pl = 0f; var pr = 0f; var sl = 0.0; var sr = 0.0
        for (i in 0 until WINDOW) {
            val at = ((end - WINDOW + i) and (RING - 1).toLong()).toInt()
            val l = ringL[at]; val r = ringR[at]
            if (abs(l) > pl) pl = abs(l)
            if (abs(r) > pr) pr = abs(r)
            sl += (l * l).toDouble(); sr += (r * r).toDouble()
        }
        return Levels(pl, pr, sqrt(sl / WINDOW).toFloat(), sqrt(sr / WINDOW).toFloat(), sinkAtNanos)
    }

    /** Copies the `out.size` mono samples (left and right averaged) ending at the playhead, oldest first. Read without a lock: a torn read only shows as a glitch in a picture. */
    fun recent(out: FloatArray) {
        val end = playheadFrame()
        for (i in out.indices) {
            val at = ((end - out.size + i) and (RING - 1).toLong()).toInt()
            out[i] = (ringL[at] + ringR[at]) * 0.5f
        }
    }

    /** Same window, left and right apart (for the goniometer). */
    fun recentStereo(left: FloatArray, right: FloatArray) {
        val end = playheadFrame()
        for (i in left.indices) {
            val at = ((end - left.size + i) and (RING - 1).toLong()).toInt()
            left[i] = ringL[at]; right[i] = ringR[at]
        }
    }

    private var encoding = C.ENCODING_INVALID
    private var channels = 0

    override fun onConfigure(inputAudioFormat: AudioProcessor.AudioFormat): AudioProcessor.AudioFormat {
        encoding = inputAudioFormat.encoding
        channels = inputAudioFormat.channelCount
        if (sampleRate != inputAudioFormat.sampleRate || Loudness.rate == 0) Loudness.configure(inputAudioFormat.sampleRate)
        sampleRate = inputAudioFormat.sampleRate
        return inputAudioFormat
    }

    override fun queueInput(inputBuffer: ByteBuffer) {
        val size = inputBuffer.remaining()
        if (size == 0) return
        if (pendingPts != lastAnchorPts) {
            lastAnchorPts = pendingPts
            synchronized(anchors) {
                anchorPts[anchorNext] = pendingPts
                anchorFrame[anchorNext] = written
                anchorNext = (anchorNext + 1) % anchorPts.size
                if (anchorCount < anchorPts.size) anchorCount++
            }
        }
        measure(inputBuffer.duplicate().order(ByteOrder.LITTLE_ENDIAN))
        replaceOutputBuffer(size).put(inputBuffer).flip()
    }

    /** A seek or a new stream: earlier anchors no longer describe what comes next. */
    override fun onFlush() {
        synchronized(anchors) { anchorCount = 0; anchorNext = 0 }
        lastAnchorPts = Long.MIN_VALUE
    }

    override fun onReset() {
        onFlush()
        sinkAtNanos = 0L
    }

    private fun sample(b: ByteBuffer, at: Int): Float = when (encoding) {
        C.ENCODING_PCM_16BIT -> b.getShort(at) / 32768f
        C.ENCODING_PCM_FLOAT -> b.getFloat(at)
        C.ENCODING_PCM_24BIT -> (((b.get(at + 2).toInt() shl 16) or ((b.get(at + 1).toInt() and 0xff) shl 8) or (b.get(at).toInt() and 0xff)) / 8388608f)
        else -> b.getInt(at) / 2147483648f
    }

    private fun measure(b: ByteBuffer) {
        if (channels < 1) return
        val step = when (encoding) {
            C.ENCODING_PCM_16BIT -> 2
            C.ENCODING_PCM_FLOAT -> 4
            C.ENCODING_PCM_24BIT -> 3
            C.ENCODING_PCM_32BIT -> 4
            else -> return
        }
        val frames = b.remaining() / (step * channels)
        if (frames == 0) return
        val start = b.position()
        var w = written
        for (f in 0 until frames) {
            // Mono is shown on both sides; with more than two channels the first two are measured.
            val at = start + f * channels * step
            val l = sample(b, at)
            val r = if (channels > 1) sample(b, at + step) else l
            Loudness.process(l, r)
            val i = (w and (RING - 1).toLong()).toInt()
            ringL[i] = l
            ringR[i] = r
            w++
        }
        written = w
    }
}
