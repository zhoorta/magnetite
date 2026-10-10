import Foundation

struct Track: Codable, Identifiable, Hashable {
    let id: Int
    var title: String
    var artist: String?
    var album: String?
    var albumId: Int?
    var number: Int?
    var disc: Int?
    var duration: Double?
    var codec: String?
    var coverUrl: String?
    var open: Bool?
    var streamUrl: String?
    var sharedBy: String?

    /// Tracks the viewer may not play carry no stream url (`open` is false).
    var playable: Bool { (open ?? true) && streamUrl != nil }
}

struct Album: Codable, Identifiable, Hashable {
    let id: Int
    var title: String
    var artist: String?
    var year: Int?
    var coverUrl: String?
    var trackCount: Int?
    var tracks: [Track]?
    var sharedBy: String?
    var folderId: Int?
}

struct Folder: Codable, Identifiable, Hashable {
    let id: Int
    var parentId: Int?
    var name: String
    var albumCount: Int?
}

struct PlaylistSummary: Codable, Identifiable, Hashable {
    let id: Int
    var name: String
    var trackCount: Int?
    var shared: Bool?
    var sharedBy: String?
}

struct Playlist: Codable, Identifiable, Hashable {
    let id: Int
    var name: String
    var mine: Bool?
    var tracks: [Track]?
}

struct Shared: Codable {
    var albums: [Album] = []
    var tracks: [Track] = []
    var playlists: [PlaylistSummary] = []
}

struct Envelope<T: Decodable>: Decodable { let data: T }
struct PageMeta: Decodable { let currentPage: Int; let lastPage: Int }
struct Page<T: Decodable>: Decodable { let data: [T]; let meta: PageMeta }
struct SignedIn: Decodable { let token: String; let user: UserInfo }
struct UserInfo: Decodable { let id: Int; let username: String }

/// Where a queue came from, so the player can offer to go back to it.
struct PlaySource: Codable, Hashable {
    enum Kind: String, Codable { case album, playlist }
    let kind: Kind
    let id: Int
}
