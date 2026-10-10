import Accelerate
import AVFoundation
import MediaToolbox
import os

/// Reads the audio that AVPlayer plays (an `MTAudioProcessingTap` on the item's audio track), keeps the last ~3 s of it
/// (left and right) for the meters and runs the loudness measurements. The tap sits right before the output, so no
/// playhead mapping is needed (unlike Android). The UI reads without a lock: a torn read only shows as a glitch in a picture.
final class MeterEngine: @unchecked Sendable {
    static let shared = MeterEngine()

    static let ring = 1 << 17
    private let ringL = UnsafeMutablePointer<Float>.allocate(capacity: MeterEngine.ring)
    private let ringR = UnsafeMutablePointer<Float>.allocate(capacity: MeterEngine.ring)
    private(set) var written = 0
    private(set) var sampleRate = 44100
    /// Uptime (ns) of the last block the tap saw; the meters treat anything older than 250 ms as silence.
    private(set) var lastBlock: UInt64 = 0
    let loudness = Loudness()

    private init() {
        ringL.initialize(repeating: 0, count: Self.ring)
        ringR.initialize(repeating: 0, count: Self.ring)
    }

    var live: Bool { DispatchTime.now().uptimeNanoseconds &- lastBlock < 250_000_000 }

    // MARK: Reading

    struct Levels { var peakL: Float, peakR: Float, rmsL: Float, rmsR: Float }

    /// Peak and RMS of the last 1024 frames.
    func snapshot() -> Levels {
        let window = 1024, mask = Self.ring - 1
        let end = written
        var pl: Float = 0, pr: Float = 0, sl: Float = 0, sr: Float = 0
        for i in 0..<window {
            let at = (end - window + i) & mask
            let l = ringL[at], r = ringR[at]
            pl = max(pl, abs(l)); pr = max(pr, abs(r))
            sl += l * l; sr += r * r
        }
        return Levels(peakL: pl, peakR: pr, rmsL: (sl / Float(window)).squareRoot(), rmsR: (sr / Float(window)).squareRoot())
    }

    /// The last `out.count` samples (left and right averaged), oldest first.
    func recent(_ out: UnsafeMutablePointer<Float>, count: Int) {
        let mask = Self.ring - 1, end = written
        for i in 0..<count { let at = (end - count + i) & mask; out[i] = (ringL[at] + ringR[at]) * 0.5 }
    }

    func recentStereo(left: UnsafeMutablePointer<Float>, right: UnsafeMutablePointer<Float>, count: Int) {
        let mask = Self.ring - 1, end = written
        for i in 0..<count { let at = (end - count + i) & mask; left[i] = ringL[at]; right[i] = ringR[at] }
    }

    // MARK: Tap

    /// Installs the tap on [item] once its audio track is known. Formats the tap cannot read play on without meters.
    @MainActor func attach(to item: AVPlayerItem) {
        Task {
            guard let track = try? await item.asset.loadTracks(withMediaType: .audio).first else { return }
            let params = AVMutableAudioMixInputParameters(track: track)
            var callbacks = MTAudioProcessingTapCallbacks(
                version: kMTAudioProcessingTapCallbacksVersion_0,
                clientInfo: nil,
                init: { _, _, storageOut in storageOut.pointee = nil },
                finalize: nil,
                prepare: { _, _, format in MeterEngine.shared.prepare(format.pointee) },
                unprepare: nil,
                process: { tap, frames, _, bufferList, framesOut, flagsOut in
                    guard MTAudioProcessingTapGetSourceAudio(tap, frames, bufferList, flagsOut, nil, framesOut) == noErr else { return }
                    MeterEngine.shared.process(bufferList, frames: Int(framesOut.pointee))
                })
            var tap: Unmanaged<MTAudioProcessingTap>?
            guard MTAudioProcessingTapCreate(kCFAllocatorDefault, &callbacks, kMTAudioProcessingTapCreationFlag_PostEffects, &tap) == noErr, let tap else { return }
            params.audioTapProcessor = tap.takeRetainedValue()
            let mix = AVMutableAudioMix()
            mix.inputParameters = [params]
            item.audioMix = mix
        }
    }

    private var isFloat = true
    private var interleaved = false
    private var bits = 32
    private var channels = 2

    fileprivate func prepare(_ f: AudioStreamBasicDescription) {
        isFloat = f.mFormatFlags & kAudioFormatFlagIsFloat != 0
        interleaved = f.mFormatFlags & kAudioFormatFlagIsNonInterleaved == 0
        bits = Int(f.mBitsPerChannel)
        channels = max(1, Int(f.mChannelsPerFrame))
        let rate = Int(f.mSampleRate)
        if rate != sampleRate || loudness.rate == 0 { loudness.configure(rate) }
        sampleRate = rate
    }

    fileprivate func process(_ list: UnsafeMutablePointer<AudioBufferList>, frames: Int) {
        guard frames > 0 else { return }
        let buffers = UnsafeMutableAudioBufferListPointer(list)
        guard let first = buffers.first, first.mData != nil else { return }
        let mask = Self.ring - 1
        var w = written
        for n in 0..<frames {
            let l: Float, r: Float
            if interleaved {
                let at = n * channels
                l = sample(first, at)
                r = channels > 1 ? sample(first, at + 1) : l
            } else {
                l = sample(first, n)
                r = buffers.count > 1 ? sample(buffers[1], n) : l
            }
            loudness.process(l, r)
            let i = w & mask
            ringL[i] = l; ringR[i] = r
            w += 1
        }
        written = w
        lastBlock = DispatchTime.now().uptimeNanoseconds
    }

    @inline(__always) private func sample(_ b: AudioBuffer, _ at: Int) -> Float {
        guard let data = b.mData else { return 0 }
        if isFloat { return data.assumingMemoryBound(to: Float.self)[at] }
        if bits == 16 { return Float(data.assumingMemoryBound(to: Int16.self)[at]) / 32768 }
        return Float(data.assumingMemoryBound(to: Int32.self)[at]) / 2147483648
    }

    /// New track: the integrated value starts again, like the web's reset.
    func reset() { loudness.reset() }
}

/// Loudness and stereo meters, a port of `worklet/meter-processor.js` (and of `Loudness.kt`): K-weighted LUFS (momentary
/// 400 ms, short-term 3 s, integrated with the absolute and relative gates), true peak with a 4x windowed-sinc
/// interpolator, phase correlation and the last 1024 samples for the goniometer.
final class Loudness: @unchecked Sendable {
    static let gonioLen = 1024
    private let absGate = -70.0, relGate = -10.0, offset = -0.691
    private let taps = 12 // per phase; shorter than the web's 48, flat enough for a phone meter
    private let ringSize = 32

    private(set) var momentary = -Double.infinity
    private(set) var shortTerm = -Double.infinity
    private(set) var integrated = -Double.infinity
    private(set) var truePeakL: Float = 0
    private(set) var truePeakR: Float = 0
    private(set) var correlation: Float = 0
    private(set) var rate = 0
    let gonio = UnsafeMutablePointer<Float>.allocate(capacity: Loudness.gonioLen * 2)

    private struct Biquad {
        var b0 = 0.0, b1 = 0.0, b2 = 0.0, a1 = 0.0, a2 = 0.0, z1 = 0.0, z2 = 0.0
        mutating func run(_ x: Double) -> Double {
            let y = b0 * x + z1
            z1 = b1 * x - a1 * y + z2
            z2 = b2 * x - a2 * y
            return y
        }
    }

    private static func stages(_ fs: Int) -> [Biquad] {
        let shelf: Biquad = {
            let k = tan(.pi * 1681.974450955533 / Double(fs)), q = 0.7071752369554196
            let vh = pow(10.0, 3.999843853973347 / 20), vb = pow(vh, 0.4996667741545416)
            let a0 = 1 + k / q + k * k
            return Biquad(b0: (vh + vb * k / q + k * k) / a0, b1: 2 * (k * k - vh) / a0, b2: (vh - vb * k / q + k * k) / a0, a1: 2 * (k * k - 1) / a0, a2: (1 - k / q + k * k) / a0)
        }()
        let high: Biquad = {
            let k = tan(.pi * 38.13547087602444 / Double(fs)), q = 0.5003270373238773
            let a0 = 1 + k / q + k * k
            return Biquad(b0: 1, b1: -2, b2: 1, a1: 2 * (k * k - 1) / a0, a2: (1 - k / q + k * k) / a0)
        }()
        return [shelf, high]
    }

    /// Phase p (1..3) of a Kaiser-windowed sinc; phase 0 is the input itself.
    private lazy var phases: [[Double]] = {
        func i0(_ x: Double) -> Double { var s = 1.0, t = 1.0; for k in 1..<30 { t *= pow(x / Double(2 * k), 2); s += t }; return s }
        let n = 4 * taps, mid = Double(n - 1) / 2, beta = 7.0
        var out = Array(repeating: Array(repeating: 0.0, count: taps), count: 4)
        for i in 0..<n {
            let x = (Double(i) - mid) / 4
            let sinc = x == 0 ? 1 : sin(.pi * x) / (.pi * x)
            let r = 2 * Double(i) / Double(n - 1) - 1
            out[i % 4][i / 4] = sinc * i0(beta * max(0, 1 - r * r).squareRoot()) / i0(beta)
        }
        for p in 0..<4 { let s = out[p].reduce(0, +); out[p] = out[p].map { $0 / s } }
        return out
    }()

    private var lock = os_unfair_lock()
    private var filtersL = Loudness.stages(44100)
    private var filtersR = Loudness.stages(44100)
    private var blockLen = 4410, blockSum = 0.0, blockCount = 0
    private var blocks = [Double](repeating: 0, count: 30)
    private var blockN = 0, blockPos = 0
    private var gated: [Double] = []
    private var sinceIntegrated = 0
    private var ringL = [Double](repeating: 0, count: 64)
    private var ringR = [Double](repeating: 0, count: 64)
    private var ringPos = 0
    private var tpL = 0.0, tpR = 0.0
    private var sLL = 0.0, sRR = 0.0, sLR = 0.0
    private var alpha = exp(-1.0 / (44100 * 0.3))
    private var gonioPos = 0

    init() { gonio.initialize(repeating: 0, count: Loudness.gonioLen * 2); _ = phases }

    private func toLufs(_ e: Double) -> Double { e > 0 ? offset + 10 * log10(e) : -Double.infinity }
    private func toEnergy(_ l: Double) -> Double { pow(10, (l - offset) / 10) }

    func configure(_ sampleRate: Int) {
        os_unfair_lock_lock(&lock); defer { os_unfair_lock_unlock(&lock) }
        rate = sampleRate
        filtersL = Self.stages(sampleRate); filtersR = Self.stages(sampleRate)
        blockLen = sampleRate / 10
        alpha = exp(-1.0 / (Double(sampleRate) * 0.3))
        resetLocked()
    }

    func reset() { os_unfair_lock_lock(&lock); resetLocked(); os_unfair_lock_unlock(&lock) }

    private func resetLocked() {
        blockSum = 0; blockCount = 0; blockN = 0; blockPos = 0; gated.removeAll(keepingCapacity: true); sinceIntegrated = 0
        tpL = 0; tpR = 0; sLL = 0; sRR = 0; sLR = 0
        for i in 0..<64 { ringL[i] = 0; ringR[i] = 0 }
        gonio.update(repeating: 0, count: Loudness.gonioLen * 2)
        for i in filtersL.indices { filtersL[i].z1 = 0; filtersL[i].z2 = 0; filtersR[i].z1 = 0; filtersR[i].z2 = 0 }
        momentary = -.infinity; shortTerm = -.infinity; integrated = -.infinity
        truePeakL = 0; truePeakR = 0; correlation = 0
    }

    func process(_ l: Float, _ r: Float) {
        os_unfair_lock_lock(&lock); defer { os_unfair_lock_unlock(&lock) }
        let kl = filtersL[1].run(filtersL[0].run(Double(l)))
        let kr = filtersR[1].run(filtersR[0].run(Double(r)))
        blockSum += kl * kl + kr * kr
        blockCount += 1
        if blockCount == blockLen { endBlock() }

        let p = ringPos
        ringL[p] = Double(l); ringL[p + ringSize] = Double(l)
        ringR[p] = Double(r); ringR[p + ringSize] = Double(r)
        ringPos = (p + 1) & (ringSize - 1)
        tpL = max(tpL, truePeak(ringL, p)); tpR = max(tpR, truePeak(ringR, p))

        let a = alpha
        sLL = sLL * a + Double(l * l) * (1 - a)
        sRR = sRR * a + Double(r * r) * (1 - a)
        sLR = sLR * a + Double(l * r) * (1 - a)
        let g = gonioPos
        gonio[g * 2] = l; gonio[g * 2 + 1] = r
        gonioPos = (g + 1) % Loudness.gonioLen
        if g % 256 == 0 {
            truePeakL = Float(tpL); truePeakR = Float(tpR)
            let d = (sLL * sRR).squareRoot()
            correlation = d > 1e-9 ? Float(sLR / d) : 0
        }
    }

    /// Largest of the interpolated values around the newest sample. Phase 0 is the input delayed by half the filter.
    private func truePeak(_ ring: [Double], _ pos: Int) -> Double {
        let base = pos + ringSize
        var peak = abs(ring[base - taps / 2])
        for ph in 1...3 {
            var y = 0.0
            phases[ph].withUnsafeBufferPointer { t in for k in 0..<taps { y += t[k] * ring[base - k] } }
            peak = max(peak, abs(y))
        }
        return peak
    }

    private func mean(_ count: Int) -> Double {
        var s = 0.0
        for i in 0..<count { s += blocks[(blockPos - 1 - i + 60) % 30] }
        return s / Double(count)
    }

    private func endBlock() {
        let e = blockSum / Double(blockLen)
        blockSum = 0; blockCount = 0
        blocks[blockPos] = e
        blockPos = (blockPos + 1) % 30
        if blockN < 30 { blockN += 1 }
        if blockN >= 4 {
            let e400 = mean(4)
            momentary = toLufs(e400)
            if toLufs(e400) > absGate { gated.append(e400) }
            sinceIntegrated += 1
            if sinceIntegrated >= 10 { sinceIntegrated = 0; integrated = integratedLufs() }
        }
        if blockN >= 30 { shortTerm = toLufs(mean(30)) }
    }

    private func integratedLufs() -> Double {
        guard !gated.isEmpty else { return -.infinity }
        let threshold = toEnergy(toLufs(gated.reduce(0, +) / Double(gated.count)) + relGate)
        var sum = 0.0, n = 0
        for e in gated where e > threshold { sum += e; n += 1 }
        return n > 0 ? toLufs(sum / Double(n)) : -.infinity
    }
}

/// Log-spaced spectrum bands (dBFS) of the latest audio. One instance per meter; not thread-safe.
final class Spectrum {
    let bands: Int, n: Int, lo: Double, topHz: Double
    private let log2n: vDSP_Length
    private let setup: FFTSetup
    private let window: [Float]
    private var samples: [Float]
    private var real: [Float], imag: [Float]
    private var first: [Int], last: [Int]
    private var edgesRate = 0

    init(bands: Int = 48, n: Int = 2048, lo: Double = 40, topHz: Double = 16000) {
        self.bands = bands; self.n = n; self.lo = lo; self.topHz = topHz
        log2n = vDSP_Length(log2(Double(n)))
        setup = vDSP_create_fftsetup(log2n, FFTRadix(kFFTRadix2))!
        window = (0..<n).map { Float(0.5 - 0.5 * cos(2 * Double.pi * Double($0) / Double(n - 1))) }
        samples = [Float](repeating: 0, count: n)
        real = [Float](repeating: 0, count: n / 2); imag = [Float](repeating: 0, count: n / 2)
        first = [Int](repeating: 1, count: bands); last = [Int](repeating: 1, count: bands)
    }

    deinit { vDSP_destroy_fftsetup(setup) }

    private func edges(_ rate: Int) {
        guard rate != edgesRate else { return }
        edgesRate = rate
        let hi = min(topHz, Double(rate) / 2), binHz = Double(rate) / Double(n)
        for b in 0..<bands {
            let f0 = lo * pow(hi / lo, Double(b) / Double(bands)), f1 = lo * pow(hi / lo, Double(b + 1) / Double(bands))
            first[b] = min(max(Int(f0 / binHz), 1), n / 2 - 1)
            last[b] = min(max(first[b], Int(f1 / binHz)), n / 2 - 1)
        }
    }

    /// Fills [out] (size = bands) with dBFS per band, floor -120.
    func read(_ out: inout [Float]) {
        let engine = MeterEngine.shared
        samples.withUnsafeMutableBufferPointer { engine.recent($0.baseAddress!, count: n) }
        vDSP_vmul(samples, 1, window, 1, &samples, 1, vDSP_Length(n))
        edges(engine.sampleRate)
        real.withUnsafeMutableBufferPointer { rp in
            imag.withUnsafeMutableBufferPointer { ip in
                var split = DSPSplitComplex(realp: rp.baseAddress!, imagp: ip.baseAddress!)
                samples.withUnsafeBufferPointer { s in
                    s.baseAddress!.withMemoryRebound(to: DSPComplex.self, capacity: n / 2) { vDSP_ctoz($0, 2, &split, 1, vDSP_Length(n / 2)) }
                }
                vDSP_fft_zrip(setup, &split, 1, log2n, FFTDirection(FFT_FORWARD))
                for b in 0..<bands {
                    var peak: Float = 0
                    for i in first[b]...last[b] { peak = max(peak, rp[i] * rp[i] + ip[i] * ip[i]) }
                    // zrip returns twice the DFT; Hann has coherent gain 0.5, so a full-scale sine reads 0 dB.
                    let amp = peak.squareRoot() * 2 / Float(n)
                    out[b] = amp <= 1e-6 ? -120 : 20 * log10(amp)
                }
            }
        }
    }
}
