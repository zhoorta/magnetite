import SwiftUI

/// A meter the player can show. The ids are the web's, so the list saved in `users.meters` means the same on both.
struct MeterInfo { let id: String; let label: String; let height: CGFloat; let compact: CGFloat }

let meterCatalog = [
    MeterInfo(id: "vu-wide", label: "Wide meters", height: 150, compact: 64),
    MeterInfo(id: "vu-needle", label: "VU needles", height: 170, compact: 84),
    MeterInfo(id: "vu-leds", label: "LED meters", height: 60, compact: 40),
    MeterInfo(id: "magic-eye", label: "Magic eyes", height: 150, compact: 76),
    MeterInfo(id: "scope", label: "Scope", height: 90, compact: 56),
    MeterInfo(id: "goniometer", label: "Goniometer", height: 240, compact: 110),
    MeterInfo(id: "loudness", label: "Loudness (LUFS)", height: 190, compact: 100),
    MeterInfo(id: "third-octave", label: "Third-octave", height: 150, compact: 80),
    MeterInfo(id: "spectrogram", label: "Spectrogram", height: 150, compact: 80),
]
let maxMeters = 4

/// The dark recessed panel every meter sits in.
struct MeterPanel<Content: View>: View {
    @ViewBuilder var content: Content
    var body: some View {
        content.padding(.horizontal, 10).padding(.vertical, 8)
            .background(Color(red: 0x0A / 255, green: 0x0C / 255, blue: 0x0B / 255))
            .clipShape(RoundedRectangle(cornerRadius: 6))
    }
}

/// One meter by id; ids this version does not know draw nothing (the web ignores unknown ids the same way).
struct MeterView: View {
    let id: String
    var compact = false
    var fill = false
    @EnvironmentObject var player: PlayerController
    @State private var state = MeterState()
    @State private var active = true

    var body: some View {
        if let info = meterCatalog.first(where: { $0.id == id }) {
            // Redraws every frame while playing and for two seconds after, so needles and bars settle; then it stops.
            TimelineView(.animation(paused: !active)) { tl in
                Canvas { g, size in state.draw(id, g, size, tl.date, playing: player.isPlaying) }
            }
            .frame(height: fill ? nil : (compact ? info.compact : info.height))
            .onChange(of: player.isPlaying) { playing in
                if playing { active = true } else {
                    Task { try? await Task.sleep(for: .seconds(2)); if !player.isPlaying { active = false } }
                }
            }
            .onAppear { active = true; if !player.isPlaying { Task { try? await Task.sleep(for: .seconds(2)); if !player.isPlaying { active = false } } } }
        }
    }
}

// MARK: - State and ballistics

/// Ballistics of one meter channel, a port of `visualizers/meter.js`. `value` and `peak` are positions on the scale (0..1).
struct Ballistic {
    var value: Float = 0, peak: Float = 0, holdLeft: Float = 0, clipLeft: Float = 0
    mutating func update(rms: Float, pk: Float, dt: Float, min: Float, max: Float, attack: Float, release: Float, hold: Float, fall: Float, clipDb: Float = -0.5) {
        func at(_ lin: Float) -> Float { Swift.min(Swift.max((db(lin) - min) / (max - min), 0), 1) }
        let target = at(rms)
        let tau = (target > value ? attack : release) / 4.6
        value += (target - value) * (1 - exp(-dt / tau))
        let p = at(pk)
        if p >= peak { peak = p; holdLeft = hold } else if holdLeft > 0 { holdLeft -= dt } else { peak = Swift.max(p, peak - fall * dt) }
        clipLeft = db(pk) >= clipDb ? 0.6 : Swift.max(0, clipLeft - dt)
    }
}

func db(_ x: Float) -> Float { 20 * log10(max(x, 1e-6)) }

private let cols = 400, rows = 64, thirds = 31

/// Everything the meters keep between frames. A reference type: the Canvas closure updates it while drawing.
final class MeterState {
    private var last: Date?
    private var wideL = Ballistic(), wideR = Ballistic(), needleL = Ballistic(), needleR = Ballistic(), eyeL = Ballistic(), eyeR = Ballistic()
    private var barRms: [Float] = [0, 0], barPeak: [Float] = [0, 0]
    private var thirdSpectrum = Spectrum(bands: thirds, n: 8192, lo: 25, topHz: 20000)
    private var thirdRaw = [Float](repeating: -120, count: thirds)
    private var thirdValue = [Float](repeating: -120, count: thirds)
    private var thirdPeak = [Float](repeating: -120, count: thirds)
    private var thirdHold = [Float](repeating: 0, count: thirds)
    private var specSpectrum = Spectrum(bands: rows, n: 4096, lo: 25, topHz: 20000)
    private var specRaw = [Float](repeating: -120, count: rows)
    private var pixels = [UInt32](repeating: 0xFF000000, count: cols * rows)
    private var specPos: Float = 0
    private var specImage: CGImage?
    private var scopeBuf = [Float](repeating: 0, count: 1024)
    private var gonioL = [Float](repeating: 0, count: 1024)
    private var gonioR = [Float](repeating: 0, count: 1024)
    private let lut: [UInt32] = {
        let stops: [(Float, (Float, Float, Float))] = [(0, (0, 0, 0)), (0.4, (0x39, 0xFF, 0x6A)), (0.75, (0xB6, 0xFF, 0x3A)), (1, (0xB6, 0xFF, 0xBD))]
        return (0..<256).map { i in
            let t = Float(i) / 255
            var k = 1
            while k < stops.count - 1 && t > stops[k].0 { k += 1 }
            let (t0, c0) = stops[k - 1], (t1, c1) = stops[k]
            let f = min(max((t - t0) / (t1 - t0), 0), 1)
            func ch(_ a: Float, _ b: Float) -> UInt32 { UInt32(a + (b - a) * f) }
            return 0xFF000000 | ch(c0.2, c1.2) << 16 | ch(c0.1, c1.1) << 8 | ch(c0.0, c1.0)
        }
    }()

    private struct Lv { var rmsL: Float, rmsR: Float, peakL: Float, peakR: Float, live: Bool }
    private var lv = Lv(rmsL: 0, rmsR: 0, peakL: 0, peakR: 0, live: false)
    private var dt: Float = 0

    func draw(_ id: String, _ g: GraphicsContext, _ size: CGSize, _ date: Date, playing: Bool) {
        dt = last.map { min(0.1, Float(date.timeIntervalSince($0))) } ?? 0
        last = date
        let engine = MeterEngine.shared
        if playing && engine.live {
            let l = engine.snapshot()
            lv = Lv(rmsL: l.rmsL, rmsR: l.rmsR, peakL: l.peakL, peakR: l.peakR, live: true)
        } else { lv = Lv(rmsL: 0, rmsR: 0, peakL: 0, peakR: 0, live: false) }
        switch id {
        case "vu-wide": wideMeters(g, size)
        case "vu-needle": needleMeters(g, size)
        case "vu-leds": levelBars(g, size)
        case "magic-eye": magicEyes(g, size)
        case "scope": scope(g, size)
        case "goniometer": goniometer(g, size)
        case "loudness": loudness(g, size)
        case "third-octave": thirdOctave(g, size)
        case "spectrogram": spectrogram(g, size)
        default: break
        }
    }

    // MARK: Helpers

    private let ink = Terminal.accent
    private let panel = Terminal.surface

    private func text(_ g: GraphicsContext, _ s: String, _ x: CGFloat, _ y: CGFloat, _ size: CGFloat, _ color: Color, _ anchor: UnitPoint = .center) {
        g.draw(Text(s).font(.system(size: size, design: .monospaced)).foregroundColor(color), at: CGPoint(x: x, y: y), anchor: UnitPoint(x: anchor.x, y: 0.5))
    }

    private func line(_ g: GraphicsContext, _ a: CGPoint, _ b: CGPoint, _ color: Color, _ w: CGFloat) {
        var p = Path(); p.move(to: a); p.addLine(to: b)
        g.stroke(p, with: .color(color), lineWidth: w)
    }

    /// An arc around (cx, cy) with angles measured from straight up, clockwise.
    private func arc(_ g: GraphicsContext, _ cx: CGFloat, _ cy: CGFloat, _ r: CGFloat, _ a0: Double, _ a1: Double, _ color: Color, _ w: CGFloat) {
        var p = Path()
        let steps = 40
        for i in 0...steps {
            let a = a0 + (a1 - a0) * Double(i) / Double(steps)
            let pt = CGPoint(x: cx + CGFloat(sin(a)) * r, y: cy - CGFloat(cos(a)) * r)
            if i == 0 { p.move(to: pt) } else { p.addLine(to: pt) }
        }
        g.stroke(p, with: .color(color), lineWidth: w)
    }

    private func disc(_ g: GraphicsContext, _ c: CGPoint, _ r: CGFloat, _ color: Color) {
        g.fill(Path(ellipseIn: CGRect(x: c.x - r, y: c.y - r, width: r * 2, height: r * 2)), with: .color(color))
    }

    private func clipped(_ g: GraphicsContext, _ r: CGRect) -> GraphicsContext {
        var c = g; c.clip(to: Path(r)); return c
    }

    private let majorDb: Set<Int> = [-30, -20, -10, -6, -3, 0]

    // MARK: Needle dials (vu-needle)

    private func needleMeters(_ g: GraphicsContext, _ size: CGSize) {
        needleL.update(rms: lv.rmsL, pk: lv.peakL, dt: dt, min: -30, max: 3, attack: 0.3, release: 0.3, hold: 1, fall: 0.3)
        needleR.update(rms: lv.rmsR, pk: lv.peakR, dt: dt, min: -30, max: 3, attack: 0.3, release: 0.3, hold: 1, fall: 0.3)
        let aspect: CGFloat = 1.55
        let gap = min(size.width, size.height) * 0.04
        let boxW = min((size.width - gap * 3) / 2, (size.height - gap * 2) * aspect)
        let boxH = boxW / aspect
        let x0 = (size.width - (boxW * 2 + gap)) / 2, y0 = (size.height - boxH) / 2
        dial(g, x0, y0, boxW, boxH, "L", needleL)
        dial(g, x0 + boxW + gap, y0, boxW, boxH, "R", needleR)
    }

    private func dial(_ g0: GraphicsContext, _ x: CGFloat, _ y: CGFloat, _ w: CGFloat, _ h: CGFloat, _ name: String, _ m: Ballistic) {
        let g = clipped(g0, CGRect(x: x, y: y, width: w, height: h))
        let mn: Float = -30, mx: Float = 3, redFrom: Float = -6
        let sweep = 96 * Double.pi / 180
        let r = min(w * 0.6, h * 0.85)
        let cx = x + w / 2, cy = y + h * 0.93
        func angleOf(_ d: Float) -> Double { -sweep / 2 + sweep * Double((d - mn) / (mx - mn)) }
        func pt(_ d: Float, _ len: CGFloat) -> CGPoint { CGPoint(x: cx + CGFloat(sin(angleOf(d))) * len, y: cy - CGFloat(cos(angleOf(d))) * len) }
        g.fill(Path(roundedRect: CGRect(x: x, y: y, width: w, height: h), cornerRadius: min(w, h) * 0.06), with: .color(panel))
        arc(g, cx, cy, r * 0.93, angleOf(redFrom), angleOf(mx), Terminal.red, r * 0.05)
        var d = mn
        while d <= mx {
            let major = majorDb.contains(Int(d))
            line(g, pt(d, r * (major ? 0.8 : 0.85)), pt(d, r * 0.9), ink, r * (major ? 0.012 : 0.006) + 1)
            if major { let p = pt(d, r * 0.7); text(g, String(Int(d)), p.x, p.y, max(10, r * 0.085), ink) }
            d += 2
        }
        text(g, name, x + w * 0.1, y + h * 0.16, max(12, r * 0.14), ink)
        text(g, "dB", cx, cy - r * 0.36, max(10, r * 0.08), ink)
        disc(g, CGPoint(x: x + w * 0.9, y: y + h * 0.15), r * 0.045, Terminal.red.opacity(m.clipLeft > 0 ? 1 : 0.18))
        let a = -sweep / 2 + sweep * Double(m.value)
        line(g, CGPoint(x: cx - CGFloat(sin(a)) * r * 0.08, y: cy + CGFloat(cos(a)) * r * 0.08), CGPoint(x: cx + CGFloat(sin(a)) * r * 0.92, y: cy - CGFloat(cos(a)) * r * 0.92), ink, r * 0.018 + 1)
        disc(g, CGPoint(x: cx, y: cy), r * 0.045, ink)
    }

    // MARK: Wide meters (vu-wide)

    private func wideMeters(_ g: GraphicsContext, _ size: CGSize) {
        wideL.update(rms: lv.rmsL, pk: lv.peakL, dt: dt, min: -30, max: 3, attack: 0.3, release: 0.3, hold: 1, fall: 0.3)
        wideR.update(rms: lv.rmsR, pk: lv.peakR, dt: dt, min: -30, max: 3, attack: 0.3, release: 0.3, hold: 1, fall: 0.3)
        let aspect: CGFloat = 4.2
        let gap = min(size.width, size.height) * 0.06
        let side = min((size.width - gap * 3) / 2, (size.height - gap * 2) * aspect)
        let stacked = min(size.width - gap * 2, ((size.height - gap * 3) / 2) * aspect)
        let useStack = stacked > side
        let bw = useStack ? stacked : side, bh = bw / aspect
        if useStack {
            let y0 = (size.height - (bh * 2 + gap)) / 2, x0 = (size.width - bw) / 2
            wide(g, x0, y0, bw, bh, "L", wideL)
            wide(g, x0, y0 + bh + gap, bw, bh, "R", wideR)
        } else {
            let x0 = (size.width - (bw * 2 + gap)) / 2, y0 = (size.height - bh) / 2
            wide(g, x0, y0, bw, bh, "L", wideL)
            wide(g, x0 + bw + gap, y0, bw, bh, "R", wideR)
        }
    }

    private func wide(_ g0: GraphicsContext, _ x: CGFloat, _ y: CGFloat, _ w: CGFloat, _ h: CGFloat, _ name: String, _ m: Ballistic) {
        let g = clipped(g0, CGRect(x: x, y: y, width: w, height: h))
        let theta = 14 * Double.pi / 180
        let mn: Float = -30, mx: Float = 3
        let r = w * 0.43 / CGFloat(sin(theta))
        let cx = x + w / 2, py = y + h * 0.42 + r
        func angleOf(_ d: Float) -> Double { -theta + 2 * theta * Double((d - mn) / (mx - mn)) }
        func pt(_ d: Float, _ len: CGFloat) -> CGPoint { CGPoint(x: cx + CGFloat(sin(angleOf(d))) * len, y: py - CGFloat(cos(angleOf(d))) * len) }
        g.fill(Path(roundedRect: CGRect(x: x, y: y, width: w, height: h), cornerRadius: h * 0.1), with: .color(panel))
        arc(g, cx, py, r, angleOf(-6), angleOf(mx), Terminal.red, h * 0.07)
        var d = mn
        while d <= mx {
            let major = majorDb.contains(Int(d))
            line(g, pt(d, r - h * 0.05), pt(d, r - h * (major ? 0.16 : 0.11)), ink, h * (major ? 0.02 : 0.011) + 1)
            if major { let p = pt(d, r - h * 0.27); text(g, String(Int(d)), p.x, p.y, max(9, h * 0.17), ink) }
            d += 2
        }
        text(g, name, x + w * 0.035, y + h * 0.22, max(10, h * 0.24), ink, .leading)
        disc(g, CGPoint(x: x + w * 0.965, y: y + h * 0.2), h * 0.05, Terminal.red.opacity(m.clipLeft > 0 ? 1 : 0.18))
        let a = -theta + 2 * theta * Double(m.value)
        line(g, CGPoint(x: cx + CGFloat(sin(a)) * (r - h * 1.3), y: py - CGFloat(cos(a)) * (r - h * 1.3)), CGPoint(x: cx + CGFloat(sin(a)) * (r + h * 0.06), y: py - CGFloat(cos(a)) * (r + h * 0.06)), ink, max(1.5, h * 0.022))
    }

    // MARK: LED bars (vu-leds)

    private func levelBars(_ g: GraphicsContext, _ size: CGSize) {
        func level(_ v: Float) -> Float { v <= 0 ? 0 : min(max((db(v) + 60) / 60, 0), 1) }
        let tr = [lv.live ? level(lv.rmsL) : 0, lv.live ? level(lv.rmsR) : 0]
        let tp = [lv.live ? level(lv.peakL) : 0, lv.live ? level(lv.peakR) : 0]
        let fall = dt * 1.2
        for c in 0..<2 {
            barRms[c] = max(tr[c], barRms[c] - fall)
            barPeak[c] = max(tp[c], barPeak[c] - fall * 0.5)
        }
        let n = 40, gap: CGFloat = 2, labelW: CGFloat = 16
        let segW = (size.width - labelW - gap * CGFloat(n - 1)) / CGFloat(n)
        let barH = min(size.height, 56)
        let top = (size.height - barH) / 2
        let h = barH / 2 - 1.5
        let off = Color(red: 0x1E / 255, green: 0x26 / 255, blue: 0x22 / 255)
        for ch in 0..<2 {
            let y = top + CGFloat(ch) * (h + 3)
            text(g, ch == 0 ? "L" : "R", 5, y + h / 2, max(9, h * 0.8), Color(red: 0x8A / 255, green: 0x9A / 255, blue: 0x92 / 255))
            for i in 0..<n {
                let pos = Float(i + 1) / Float(n)
                let dB = -60 + pos * 60
                let color: Color = dB > -6 ? Color(red: 0xE5 / 255, green: 0x48 / 255, blue: 0x4D / 255) : dB > -18 ? Color(red: 0xF5 / 255, green: 0xA5 / 255, blue: 0x24 / 255) : Color(red: 0x3F / 255, green: 0xCF / 255, blue: 0x6D / 255)
                let lit = barRms[ch] >= pos - 0.5 / Float(n) || (barPeak[ch] >= pos - 0.5 / Float(n) && barPeak[ch] < pos + 0.5 / Float(n))
                g.fill(Path(CGRect(x: labelW + CGFloat(i) * (segW + gap), y: y, width: segW, height: h)), with: .color(lit ? color : off))
            }
        }
    }

    // MARK: Magic eyes

    private func magicEyes(_ g: GraphicsContext, _ size: CGSize) {
        eyeL.update(rms: lv.rmsL, pk: lv.peakL, dt: dt, min: -36, max: 0, attack: 0.12, release: 0.35, hold: 0.6, fall: 0.3)
        eyeR.update(rms: lv.rmsR, pk: lv.peakR, dt: dt, min: -36, max: 0, attack: 0.12, release: 0.35, hold: 0.6, fall: 0.3)
        let gap = min(size.width, size.height) * 0.12
        let d = max(20, min(size.height * 0.74, (size.width - gap * 3) / 2))
        let x0 = (size.width - (d * 2 + gap)) / 2, cy = size.height * 0.46
        for (i, (name, m)) in [("L", eyeL), ("R", eyeR)].enumerated() {
            let cx = x0 + d / 2 + CGFloat(i) * (d + gap)
            eye(g, cx, cy, d / 2, CGFloat(m.value))
            text(g, name, cx, cy + d / 2 + d * 0.16, max(10, d * 0.1), Terminal.muted)
        }
    }

    private func eye(_ g: GraphicsContext, _ cx: CGFloat, _ cy: CGFloat, _ r: CGFloat, _ v: CGFloat) {
        let glass = Color(red: 5 / 255, green: 8 / 255, blue: 6 / 255), phosphor = Terminal.accent2
        let half = 78 * (1 - Double(v))
        let target = r * 0.84, c = CGPoint(x: cx, y: cy)
        disc(g, c, r, glass)
        g.stroke(Path(ellipseIn: CGRect(x: cx - r, y: cy - r, width: r * 2, height: r * 2)), with: .color(Terminal.muted), lineWidth: r * 0.05)
        let glowR = target * (1.1 + 0.3 * v)
        for i in 0..<3 { disc(g, c, glowR * (1 - CGFloat(i) * 0.08), phosphor.opacity(Double((0.5 + 0.5 * v) * 0.24))) }
        disc(g, c, target, phosphor.opacity(Double(0.45 + 0.55 * v)))
        for i in 0..<2 { disc(g, c, target * (1 - CGFloat(i) * 0.35), Color.white.opacity(Double((0.3 + 0.7 * v) * 0.2))) }
        if half > 0.1 {
            // The dark wedge opens downwards as the level rises.
            var w = Path(); w.move(to: c)
            for k in 0...24 {
                let a = (90 - half + 2 * half * Double(k) / 24) * Double.pi / 180
                w.addLine(to: CGPoint(x: cx + CGFloat(cos(a)) * r, y: cy + CGFloat(sin(a)) * r))
            }
            w.closeSubpath()
            g.fill(w, with: .color(glass))
        }
        disc(g, c, r * 0.12, glass)
        g.stroke(Path(ellipseIn: CGRect(x: cx - r * 0.12, y: cy - r * 0.12, width: r * 0.24, height: r * 0.24)), with: .color(phosphor.opacity(Double(0.5 + 0.5 * v))), lineWidth: r * 0.02)
    }

    // MARK: Scope

    private func scope(_ g: GraphicsContext, _ size: CGSize) {
        if lv.live { scopeBuf.withUnsafeMutableBufferPointer { MeterEngine.shared.recent($0.baseAddress!, count: 1024) } } else { for i in 0..<1024 { scopeBuf[i] = 0 } }
        let mid = size.height / 2
        line(g, CGPoint(x: 0, y: mid), CGPoint(x: size.width, y: mid), Color(red: 0x1E / 255, green: 0x26 / 255, blue: 0x22 / 255), 1)
        // One point per ~3 px (the loudest sample of its bucket) looks the same as all 1024 and is cheaper.
        let points = min(max(Int(size.width / 3), 32), 1024)
        var path = Path()
        for p in 0..<points {
            let from = p * 1024 / points, to = max(from + 1, (p + 1) * 1024 / points)
            var v = scopeBuf[from]
            for i in (from + 1)..<max(to, from + 2) where i < 1024 && abs(scopeBuf[i]) > abs(v) { v = scopeBuf[i] }
            let pt = CGPoint(x: CGFloat(p) * size.width / CGFloat(points - 1), y: mid - CGFloat(min(max(v, -1), 1)) * mid)
            if p == 0 { path.move(to: pt) } else { path.addLine(to: pt) }
        }
        g.stroke(path, with: .color(ink), lineWidth: 2.5)
    }

    // MARK: Goniometer

    private func goniometer(_ g: GraphicsContext, _ size: CGSize) {
        let margin = min(size.width, size.height) * 0.06
        let font = max(10, min(size.height * 0.045, size.width * 0.03))
        let barH = max(6, font * 0.7)
        let strip = barH + font * 2.4
        let sz = min(size.width - margin * 2, size.height - strip - margin * 2)
        guard sz >= 20 else { return }
        let cx = size.width / 2, top = (size.height - sz - strip) / 2
        let cy = top + sz / 2, r = sz / 2
        let grid = Terminal.muted.opacity(0.4)
        g.stroke(Path(ellipseIn: CGRect(x: cx - r, y: cy - r, width: r * 2, height: r * 2)), with: .color(grid), lineWidth: 1)
        line(g, CGPoint(x: cx, y: cy - r), CGPoint(x: cx, y: cy + r), grid, 1)
        line(g, CGPoint(x: cx - r, y: cy), CGPoint(x: cx + r, y: cy), grid, 1)
        let q = r * 0.7071
        line(g, CGPoint(x: cx - q, y: cy - q), CGPoint(x: cx + q, y: cy + q), grid, 1)
        line(g, CGPoint(x: cx + q, y: cy - q), CGPoint(x: cx - q, y: cy + q), grid, 1)
        text(g, "M", cx, cy - r - font * 0.7, font, Terminal.muted)
        text(g, "L", cx - q - font * 0.9, cy - q - font * 0.3, font, Terminal.muted)
        text(g, "R", cx + q + font * 0.9, cy - q - font * 0.3, font, Terminal.muted)

        // Trace: the last 1024 samples. Mid is up, side across.
        let k = r * 0.7
        if lv.live {
            gonioL.withUnsafeMutableBufferPointer { l in gonioR.withUnsafeMutableBufferPointer { rr in MeterEngine.shared.recentStereo(left: l.baseAddress!, right: rr.baseAddress!, count: 1024) } }
            let s = max(1.5, sz / 200), root2 = CGFloat(2).squareRoot()
            var dots = Path()
            for n in 0..<1024 {
                let l = CGFloat(gonioL[n]), rr = CGFloat(gonioR[n])
                dots.addRect(CGRect(x: cx + (rr - l) / root2 * k - s / 2, y: cy - (l + rr) / root2 * k - s / 2, width: s, height: s))
            }
            g.fill(dots, with: .color(ink.opacity(0.6)))
        }

        let bw = min(size.width - margin * 2, sz * 1.5)
        let x0 = cx - bw / 2, y0 = cy + r + font * 0.9
        func at(_ v: CGFloat) -> CGFloat { x0 + (v + 1) / 2 * bw }
        let c = CGFloat(min(max(lv.live ? MeterEngine.shared.loudness.correlation : 0, -1), 1))
        g.fill(Path(CGRect(x: x0, y: y0, width: bw, height: barH)), with: .color(Terminal.muted.opacity(0.25)))
        g.fill(Path(CGRect(x: min(at(0), at(c)), y: y0, width: abs(at(c) - at(0)), height: barH)), with: .color(c < 0 ? Terminal.red : ink))
        text(g, "-1", at(-1), y0 + barH + font * 0.9, font * 0.9, Terminal.muted, .leading)
        text(g, "0", at(0), y0 + barH + font * 0.9, font * 0.9, Terminal.muted)
        text(g, "+1", at(1), y0 + barH + font * 0.9, font * 0.9, Terminal.muted, .trailing)
        text(g, "correlation \(c >= 0 ? "+" : "−")\(String(format: "%.2f", abs(c)))", cx, y0 + barH + font * 2.1, font, Terminal.text)
    }

    // MARK: Loudness

    private func fmt(_ v: Double) -> String {
        guard v.isFinite else { return "−−.-" }
        let tenths = Int((abs(v) * 10).rounded())
        return "\(v < 0 ? "−" : "+")\(tenths / 10).\(tenths % 10)"
    }

    private func loudness(_ g: GraphicsContext, _ size: CGSize) {
        let l = MeterEngine.shared.loudness
        let live = lv.live
        let margin = min(size.width, size.height) * 0.07
        let rowH = min((size.height - margin * 2) / 4.4, size.width * 0.12)
        let top = (size.height - (rowH * 4 + rowH * 0.4)) / 2
        let label = max(9, min(rowH * 0.22, size.width * 0.02))
        let big = max(14, min(rowH * 0.5, size.width * 0.07))
        let numW = big * 3.4
        let barW = size.width - margin * 2 - numW - label * 4.5
        func at(_ v: Double) -> CGFloat { CGFloat(min(max((v + 41) / 36, 0), 1)) }
        let rowsData: [(String, Double, Color)] = [("MOMENTARY", l.momentary, ink), ("SHORT-TERM", l.shortTerm, ink), ("INTEGRATED", l.integrated, Terminal.accent2)]
        for (i, (name, v, color)) in rowsData.enumerated() {
            let y = top + CGFloat(i) * rowH
            let barH = max(4, rowH * 0.16), barY = y + rowH * 0.62
            text(g, name, margin, y + label, label, Terminal.muted, .leading)
            g.fill(Path(CGRect(x: margin, y: barY, width: barW, height: barH)), with: .color(Terminal.muted.opacity(0.22)))
            g.fill(Path(CGRect(x: margin, y: barY, width: barW * at(v), height: barH)), with: .color(color))
            g.fill(Path(CGRect(x: margin + barW * at(-23) - 0.5, y: barY - barH * 0.35, width: 1.5, height: barH * 1.7)), with: .color(Terminal.text))
            text(g, fmt(v), size.width - margin - label * 3.2, y + rowH * 0.45, big, Terminal.text, .trailing)
            text(g, "LUFS", size.width - margin, y + rowH * 0.45, label, Terminal.muted, .trailing)
        }
        let y = top + rowH * 3.4
        text(g, "TRUE PEAK", margin, y + label, label, Terminal.muted, .leading)
        for (i, (name, lin)) in [("L", l.truePeakL), ("R", l.truePeakR)].enumerated() {
            let d = lin > 0 ? Double(db(lin)) : -Double.infinity
            let x = margin + label * 7 + CGFloat(i) * (barW * 0.5)
            text(g, name, x, y + rowH * 0.4, label, Terminal.muted, .leading)
            text(g, fmt(d), x + label * 1.6, y + rowH * 0.4, big * 0.8, d > -1 ? Terminal.red : Terminal.text, .leading)
        }
        text(g, "dBTP", size.width - margin, y + rowH * 0.4, label, Terminal.muted, .trailing)
        _ = live
    }

    // MARK: Third-octave

    private func thirdOctave(_ g: GraphicsContext, _ size: CGSize) {
        if lv.live { thirdSpectrum.read(&thirdRaw) } else { for i in 0..<thirds { thirdRaw[i] = -120 } }
        for i in 0..<thirds {
            let d = thirdRaw[i]
            thirdValue[i] = d > thirdValue[i] ? d : d + (thirdValue[i] - d) * exp(-dt / (0.35 / 4.6))
            if d >= thirdPeak[i] { thirdPeak[i] = d; thirdHold[i] = 1.5 } else if thirdHold[i] > 0 { thirdHold[i] -= dt } else { thirdPeak[i] = max(d, thirdPeak[i] - 18 * dt) }
        }
        let mn: Float = -72, mx: Float = -12, segs = 20
        let font = max(9, min(size.height * 0.07, size.width * 0.03))
        let left = font * 2.6, bottom = size.height - font * 1.6
        let plotH = bottom - font * 0.5
        let slot = (size.width - left - font * 0.5) / CGFloat(thirds)
        let bw = slot * 0.72, segH = plotH / CGFloat(segs)
        func lit(_ d: Float) -> Int { min(max(Int((d - mn) / 3), 0), segs) }
        var greenOn = Path(), limeOn = Path(), greenOff = Path(), limeOff = Path()
        for i in 0..<thirds {
            let x = left + CGFloat(i) * slot + (slot - bw) / 2
            let n = lit(thirdValue[i]), pk = min(segs - 1, lit(thirdPeak[i]))
            for s in 0..<segs {
                let on = s < n, isPeak = s == pk && thirdPeak[i] > mn
                let useLime = (isPeak && s >= n) || Float(s) > Float(segs) * 0.85
                let rect = CGRect(x: x, y: bottom - CGFloat(s + 1) * segH + segH * 0.12, width: bw, height: segH * 0.76)
                if on || isPeak { if useLime { limeOn.addRect(rect) } else { greenOn.addRect(rect) } }
                else if useLime { limeOff.addRect(rect) } else { greenOff.addRect(rect) }
            }
        }
        g.fill(greenOff, with: .color(ink.opacity(0.1))); g.fill(limeOff, with: .color(Terminal.accent2.opacity(0.1)))
        g.fill(greenOn, with: .color(ink)); g.fill(limeOn, with: .color(Terminal.accent2))
        for d in [-60, -48, -36, -24, -12] { text(g, String(d), left - font * 0.3, bottom - CGFloat((Float(d) - mn) / (mx - mn)) * plotH, font, Terminal.muted, .trailing) }
        for (f, label) in [(31.5, "31"), (125.0, "125"), (500.0, "500"), (1000.0, "1k"), (4000.0, "4k"), (16000.0, "16k")] {
            let i = min(max(Int(log(f / 25) / log(20000.0 / 25) * Double(thirds)), 0), thirds - 1)
            text(g, label, left + CGFloat(i) * slot + slot / 2, size.height - font * 0.5, font, Terminal.muted)
        }
    }

    // MARK: Spectrogram

    private func spectrogram(_ g: GraphicsContext, _ size: CGSize) {
        if lv.live {
            specSpectrum.read(&specRaw)
            specPos += dt * Float(cols) / 13 // about 13 s from the right edge to the left; the fraction slides the picture between columns
            let n = min(cols, Int(specPos))
            if n >= 1 {
                specPos -= Float(n)
                pixels.withUnsafeMutableBufferPointer { px in
                    for row in 0..<rows {
                        let base = row * cols
                        for c in 0..<(cols - n) { px[base + c] = px[base + c + n] }
                        // the treble gets a little lift so music (about -3 dB per octave) does not fade to black
                        let f = 25 * pow(800, 1 - (Double(row) + 0.5) / Double(rows))
                        let v = min(max((specRaw[rows - 1 - row] + 95 + 2 * Float(log(f / 1000) / log(2))) / 85, 0), 1)
                        let c = lut[Int(v * 255)]
                        for k in 0..<n { px[base + cols - n + k] = c }
                    }
                }
                specImage = makeImage()
            }
        }
        let font = max(9, size.height * 0.055)
        let left = font * 3
        let colW = (size.width - left) / CGFloat(cols)
        if let img = specImage {
            let c = clipped(g, CGRect(x: left, y: 0, width: size.width - left, height: size.height))
            c.draw(Image(decorative: img, scale: 1).interpolation(.none), in: CGRect(x: left - CGFloat(specPos) * colW, y: 0, width: size.width - left, height: size.height))
        }
        for (f, label) in [(100.0, "100"), (1000.0, "1k"), (10000.0, "10k")] {
            let y = size.height * (1 - CGFloat(log(f / 25) / log(800)))
            text(g, label, left - 4, y, font, Terminal.muted, .trailing)
        }
    }

    private func makeImage() -> CGImage? {
        pixels.withUnsafeMutableBytes { raw in
            CGContext(data: raw.baseAddress, width: cols, height: rows, bitsPerComponent: 8, bytesPerRow: cols * 4,
                      space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)?.makeImage()
        }
    }
}
