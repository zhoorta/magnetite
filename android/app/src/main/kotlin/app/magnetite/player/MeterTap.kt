package app.magnetite.player

import androidx.media3.common.audio.AudioProcessor
import androidx.media3.common.audio.BaseAudioProcessor
import androidx.media3.common.C
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.math.abs
import kotlin.math.sqrt

/**
 * Passes audio through untouched and measures it on the way: per-channel peak and RMS of the latest block.
 * It lives in the player's audio sink (in [PlaybackService]); the UI, in the same process, reads [snapshot].
 * Formats it cannot read are passed through unmeasured, never refused, so playback is never at risk.
 */
object MeterTap : BaseAudioProcessor() {
    /** Latest block: peak and RMS for left and right (0..1, full scale = 1), and when it was measured. */
    class Levels(val peakL: Float, val peakR: Float, val rmsL: Float, val rmsR: Float, val atNanos: Long)

    @Volatile private var latest = Levels(0f, 0f, 0f, 0f, 0L)

    fun snapshot(): Levels = latest

    private const val RING = 8192
    private val ring = FloatArray(RING)
    @Volatile private var written = 0L

    /** Sample rate of the audio being measured, for the spectrum's frequency axis. */
    @Volatile var sampleRate = 44100
        private set

    /** Copies the most recent `out.size` mono samples (left and right averaged), oldest first. Read without a lock: a torn read only shows as a glitch in a picture. */
    fun recent(out: FloatArray) {
        val end = written
        for (i in out.indices) out[i] = ring[((end - out.size + i) and (RING - 1).toLong()).toInt()]
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
        measure(inputBuffer.duplicate().order(ByteOrder.LITTLE_ENDIAN))
        replaceOutputBuffer(size).put(inputBuffer).flip()
    }

    override fun onReset() {
        latest = Levels(0f, 0f, 0f, 0f, 0L)
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
        val pk = FloatArray(2)
        val sq = DoubleArray(2)
        val start = b.position()
        var w = written
        for (f in 0 until frames) {
            var mono = 0f
            var fl = 0f
            var fr = 0f
            for (c in 0 until channels) {
                val at = start + (f * channels + c) * step
                val v = when (encoding) {
                    C.ENCODING_PCM_16BIT -> b.getShort(at) / 32768f
                    C.ENCODING_PCM_FLOAT -> b.getFloat(at)
                    C.ENCODING_PCM_24BIT -> (((b.get(at + 2).toInt() shl 16) or ((b.get(at + 1).toInt() and 0xff) shl 8) or (b.get(at).toInt() and 0xff)) / 8388608f)
                    else -> b.getInt(at) / 2147483648f
                }
                // Mono is shown on both sides; with more than two channels the first two are measured.
                if (c < 2) mono += v
                if (c == 0) { fl = v; if (channels == 1) fr = v } else if (c == 1) fr = v
                val side = if (channels == 1) -1 else c
                if (side > 1) continue
                for (s in (if (side < 0) 0..1 else side..side)) {
                    val a = abs(v)
                    if (a > pk[s]) pk[s] = a
                    sq[s] += (v * v).toDouble()
                }
            }
            Loudness.process(fl, fr)
            ring[(w and (RING - 1).toLong()).toInt()] = mono / minOf(channels, 2)
            w++
        }
        written = w
        latest = Levels(pk[0], pk[1], sqrt(sq[0] / frames).toFloat(), sqrt(sq[1] / frames).toFloat(), System.nanoTime())
    }
}
