package app.magnetite.ui

import app.magnetite.data.Album
import app.magnetite.data.Flag
import app.magnetite.data.Track

/**
 * What the lists and the player say about a file: the port of `resources/js/magnetite/dense/info.js`.
 * `level` is the user's info setting: 0 nothing, 1 the format and DR, 2 also the findings.
 */
object Info {
    private const val MINUS = '−' // the typographic minus, as on the web

    fun dr(dr: Int?): String? = dr?.let { "DR$it" }

    fun drText(t: Track): String? = t.truth?.dr?.let { d -> "DR$d" + (t.truth.drClass?.let { " ($it)" } ?: "") }

    fun lufsText(t: Track): String? = t.truth?.lufs?.let { v -> (if (v < 0) "$MINUS" else "") + "%.1f LUFS".format(kotlin.math.abs(v)) }

    /** "FLAC · 24-bit / 96 kHz": the codec is known without measuring, the rest comes from the analysis. */
    fun formatText(t: Track): String {
        val truth = t.truth
        val bits = truth?.declaredBits ?: truth?.bits
        val khz = truth?.sampleRate?.let { "${"%.1f".format(it / 1000.0).removeSuffix(".0")} kHz" }
        val depth = when {
            bits != null && khz != null -> "$bits-bit / $khz"
            khz != null -> khz
            bits != null -> "$bits-bit"
            else -> null
        }
        return listOfNotNull(t.codec?.uppercase(), depth).joinToString(" · ")
    }

    /** The line under the title: format, dynamic range, loudness. Empty at level 0. The DR class word is left out: a phone's line is short. */
    fun line(t: Track, level: Int): String =
        if (level < 1) "" else listOfNotNull(formatText(t).ifEmpty { null }, dr(t.truth?.dr), lufsText(t)).joinToString(" · ")

    /** Problems and warnings, not notes; worst first. */
    fun findings(t: Track, max: Int = 2): List<Flag> =
        (t.truth?.flags ?: emptyList()).filter { it.level != "note" }.sortedBy { if (it.level == "problem") 0 else 1 }.take(max)

    fun hasProblem(t: Track) = findings(t, 1).isNotEmpty()

    /** The mean of a set of scores, rounded like the server does for an album. */
    fun mean(scores: List<Int>): Int? = if (scores.isEmpty()) null else Math.round(scores.average()).toInt()

    fun albumDr(a: Album): Int? = a.dr ?: mean(a.tracks.mapNotNull { it.truth?.dr })
}
