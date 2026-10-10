import AVFoundation
import MediaPlayer
import SwiftUI

/// One AVPlayer for the whole app: queue, lock-screen controls, remote commands and local resume.
@MainActor
final class PlayerController: ObservableObject {
    @Published private(set) var queue: [Track] = []
    @Published private(set) var index = 0
    @Published private(set) var isPlaying = false
    @Published private(set) var position: Double = 0
    @Published private(set) var duration: Double = 0
    @Published private(set) var source: PlaySource?
    @Published var error: String?

    private let api: Api
    private let player = AVPlayer()
    private var timeObserver: Any?
    private var endObserver: NSObjectProtocol?
    private var statusObserver: NSKeyValueObservation?
    private var rateObserver: NSKeyValueObservation?
    /// A restored queue is paused and not loaded until the person presses play.
    private var prepared = false
    private var lastSave = Date.distantPast

    init(api: Api) {
        self.api = api
        player.automaticallyWaitsToMinimizeStalling = true
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .default)
        timeObserver = player.addPeriodicTimeObserver(forInterval: CMTime(seconds: 0.5, preferredTimescale: 600), queue: .main) { [weak self] t in
            MainActor.assumeIsolated { self?.tick(t.seconds) }
        }
        rateObserver = player.observe(\.timeControlStatus, options: [.new]) { [weak self] p, _ in
            let playing = p.timeControlStatus != .paused
            Task { @MainActor in self?.playingChanged(playing) }
        }
        setupRemoteCommands()
        NotificationCenter.default.addObserver(forName: AVAudioSession.interruptionNotification, object: nil, queue: .main) { [weak self] n in
            let raw = n.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt
            let resume = (n.userInfo?[AVAudioSessionInterruptionOptionKey] as? UInt).map { AVAudioSession.InterruptionOptions(rawValue: $0).contains(.shouldResume) } ?? false
            MainActor.assumeIsolated {
                if raw == AVAudioSession.InterruptionType.began.rawValue { self?.isPlaying = false }
                else if resume { self?.player.play() }
            }
        }
    }

    var current: Track? { queue.indices.contains(index) ? queue[index] : nil }
    var hasQueue: Bool { current != nil }

    // MARK: Queue

    /// Queues the open tracks of [tracks] and starts at [start] (an index into [tracks]).
    func play(_ tracks: [Track], start: Int, from source: PlaySource? = nil) {
        guard tracks.indices.contains(start), tracks[start].playable else { return }
        let target = tracks[start].id
        let open = tracks.filter(\.playable)
        queue = open
        index = open.firstIndex { $0.id == target } ?? 0
        self.source = source
        load(autoplay: true, at: 0)
    }

    func next() { guard index + 1 < queue.count else { return }; index += 1; load(autoplay: true, at: 0) }

    func previous() {
        if position > 3 || index == 0 { seek(to: 0) } else { index -= 1; load(autoplay: true, at: 0) }
    }

    func toggle() {
        guard hasQueue else { return }
        if !prepared { load(autoplay: true, at: position); return }
        if player.timeControlStatus == .paused { player.play() } else { player.pause() }
    }

    func seek(to seconds: Double) {
        guard prepared else { position = seconds; return }
        player.seek(to: CMTime(seconds: seconds, preferredTimescale: 600), toleranceBefore: .zero, toleranceAfter: .zero)
        position = seconds
        updateNowPlaying()
    }

    private func load(autoplay: Bool, at start: Double) {
        guard let track = current, let urlString = track.streamUrl, let url = URL(string: urlString) else { return }
        var headers: [String: String] = [:]
        if let t = api.session.token { headers["Authorization"] = "Bearer \(t)" }
        let asset = AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": headers])
        let item = AVPlayerItem(asset: asset)
        if let end = endObserver { NotificationCenter.default.removeObserver(end) }
        endObserver = NotificationCenter.default.addObserver(forName: .AVPlayerItemDidPlayToEndTime, object: item, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.finished() }
        }
        statusObserver = item.observe(\.status, options: [.new]) { [weak self] item, _ in
            if item.status == .failed {
                let message = item.error?.localizedDescription ?? "Playback failed"
                Task { @MainActor in self?.error = message }
            }
        }
        prepared = true
        error = nil
        MeterEngine.shared.reset()
        MeterEngine.shared.attach(to: item)
        player.replaceCurrentItem(with: item)
        position = start
        duration = track.duration ?? 0
        if start > 0 { player.seek(to: CMTime(seconds: start, preferredTimescale: 600), toleranceBefore: .zero, toleranceAfter: .zero) }
        if autoplay { try? AVAudioSession.sharedInstance().setActive(true); player.play() }
        updateNowPlaying()
        loadArtwork(track)
        save()
    }

    private func finished() {
        if index + 1 < queue.count { next() } else { isPlaying = false; position = 0; player.seek(to: .zero); save() }
    }

    private func tick(_ seconds: Double) {
        guard prepared, seconds.isFinite else { return }
        position = seconds
        if let d = player.currentItem?.duration.seconds, d.isFinite, d > 0 { duration = d }
        if isPlaying, Date().timeIntervalSince(lastSave) > 5 { save() }
        MPNowPlayingInfoCenter.default().nowPlayingInfo?[MPNowPlayingInfoPropertyElapsedPlaybackTime] = seconds
    }

    private func playingChanged(_ playing: Bool) {
        isPlaying = playing
        MPNowPlayingInfoCenter.default().playbackState = playing ? .playing : .paused
        updateNowPlaying()
        save()
    }

    // MARK: Lock screen

    private func setupRemoteCommands() {
        let c = MPRemoteCommandCenter.shared()
        c.playCommand.addTarget { [weak self] _ in MainActor.assumeIsolated { if self?.isPlaying == false { self?.toggle() } }; return .success }
        c.pauseCommand.addTarget { [weak self] _ in MainActor.assumeIsolated { if self?.isPlaying == true { self?.toggle() } }; return .success }
        c.togglePlayPauseCommand.addTarget { [weak self] _ in MainActor.assumeIsolated { self?.toggle() }; return .success }
        c.nextTrackCommand.addTarget { [weak self] _ in MainActor.assumeIsolated { self?.next() }; return .success }
        c.previousTrackCommand.addTarget { [weak self] _ in MainActor.assumeIsolated { self?.previous() }; return .success }
        c.changePlaybackPositionCommand.addTarget { [weak self] e in
            guard let e = e as? MPChangePlaybackPositionCommandEvent else { return .commandFailed }
            MainActor.assumeIsolated { self?.seek(to: e.positionTime) }
            return .success
        }
    }

    private func updateNowPlaying() {
        guard let t = current else { MPNowPlayingInfoCenter.default().nowPlayingInfo = nil; return }
        var info = MPNowPlayingInfoCenter.default().nowPlayingInfo ?? [:]
        info[MPMediaItemPropertyTitle] = t.title
        info[MPMediaItemPropertyArtist] = t.artist
        info[MPMediaItemPropertyAlbumTitle] = t.album
        info[MPMediaItemPropertyPlaybackDuration] = duration
        info[MPNowPlayingInfoPropertyElapsedPlaybackTime] = position
        info[MPNowPlayingInfoPropertyPlaybackRate] = isPlaying ? 1.0 : 0.0
        MPNowPlayingInfoCenter.default().nowPlayingInfo = info
    }

    private func loadArtwork(_ track: Track) {
        guard let url = track.coverUrl else { return }
        Task {
            guard let image = await CoverLoader.shared.image(url), current?.id == track.id else { return }
            let art = MPMediaItemArtwork(boundsSize: image.size) { _ in image }
            MPNowPlayingInfoCenter.default().nowPlayingInfo?[MPMediaItemPropertyArtwork] = art
        }
    }

    // MARK: Resume (local to the device, per user, like the web player)

    private struct Saved: Codable { var queue: [Track]; var index: Int; var position: Double; var source: PlaySource? }
    private var key: String { "playback." + (api.session.username ?? "") }

    private func save() {
        lastSave = Date()
        guard api.session.username != nil, hasQueue else { return }
        let saved = Saved(queue: queue, index: index, position: position, source: source)
        if let data = try? JSONEncoder().encode(saved) { UserDefaults.standard.set(data, forKey: key) }
    }

    /// Comes back paused; nothing is loaded and no sound starts by itself.
    func restore() {
        guard !hasQueue, let data = UserDefaults.standard.data(forKey: key), let s = try? JSONDecoder().decode(Saved.self, from: data),
              s.queue.indices.contains(s.index) else { return }
        queue = s.queue
        index = s.index
        position = s.position
        source = s.source
        duration = s.queue[s.index].duration ?? 0
        prepared = false
        updateNowPlaying()
        loadArtwork(s.queue[s.index])
    }

    /// Ends the session: stops, empties the queue and forgets the saved state.
    func signOut() {
        let k = key
        player.pause()
        player.replaceCurrentItem(with: nil)
        queue = []; index = 0; position = 0; duration = 0; isPlaying = false; prepared = false; source = nil
        MPNowPlayingInfoCenter.default().nowPlayingInfo = nil
        UserDefaults.standard.removeObject(forKey: k)
    }
}
