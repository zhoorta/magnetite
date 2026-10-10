import Foundation
import UIKit

struct ApiError: LocalizedError {
    let message: String
    let code: Int
    var errorDescription: String? { message }
}

/// Thin client for `/api/v1`. The bearer token goes on every request, including covers and streams.
@MainActor
final class Api {
    let session: Session
    /// Called when the server refuses the stored token: revoked, or the user is gone.
    var onUnauthorized: (() -> Void)?

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    init(session: Session) { self.session = session }

    func authorized(_ request: URLRequest) -> URLRequest {
        var r = request
        if let t = session.token { r.setValue("Bearer \(t)", forHTTPHeaderField: "Authorization") }
        return r
    }

    /// Runs a request with the token; a 401 on a signed-in session ends the session.
    func data(for request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let hadToken = session.token != nil
        let (data, response) = try await URLSession.shared.data(for: authorized(request))
        let http = response as! HTTPURLResponse
        if http.statusCode == 401 && hadToken { onUnauthorized?() }
        return (data, http)
    }

    private func call(_ request: URLRequest) async throws -> Data {
        let (data, http) = try await data(for: request)
        guard (200..<300).contains(http.statusCode) else {
            throw ApiError(message: Self.errorMessage(data) ?? "HTTP \(http.statusCode)", code: http.statusCode)
        }
        return data
    }

    /// Laravel validation errors come as `{message, errors: {field: [..]}}`.
    private static func errorMessage(_ data: Data) -> String? {
        guard let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        if let errors = obj["errors"] as? [String: [String]], let first = errors.values.first?.first { return first }
        return obj["message"] as? String
    }

    private func request(_ path: String, method: String = "GET", body: [String: Any?]? = nil) throws -> URLRequest {
        guard let url = URL(string: session.serverUrl + "/api/v1" + path) else { throw ApiError(message: "Invalid server address", code: 0) }
        var r = URLRequest(url: url)
        r.httpMethod = method
        r.timeoutInterval = 20
        r.setValue("application/json", forHTTPHeaderField: "Accept")
        if let body {
            r.setValue("application/json", forHTTPHeaderField: "Content-Type")
            r.httpBody = try JSONSerialization.data(withJSONObject: body.mapValues { $0 ?? NSNull() })
        }
        return r
    }

    private func decode<T: Decodable>(_ type: T.Type, _ data: Data) throws -> T {
        try decoder.decode(type, from: data)
    }

    func signIn(server: String, username: String, password: String) async throws {
        session.setServer(server)
        let body: [String: Any?] = ["username": username, "password": password, "device_name": UIDevice.current.name]
        let data = try await call(request("/token", method: "POST", body: body))
        let result = try decode(SignedIn.self, data)
        session.signIn(token: result.token, username: result.user.username)
    }

    func signOut() async {
        if let r = try? request("/token", method: "DELETE") { _ = try? await call(r) }
        session.clear()
    }

    func albums(page: Int) async throws -> Page<Album> { try decode(Page<Album>.self, try await call(request("/albums?page=\(page)"))) }
    func album(_ id: Int) async throws -> Album { try decode(Envelope<Album>.self, try await call(request("/albums/\(id)"))).data }
    func folders() async throws -> [Folder] { try decode(Envelope<[Folder]>.self, try await call(request("/folders"))).data }
    func playlists() async throws -> [PlaylistSummary] { try decode(Envelope<[PlaylistSummary]>.self, try await call(request("/playlists"))).data }
    func playlist(_ id: Int) async throws -> Playlist { try decode(Envelope<Playlist>.self, try await call(request("/playlists/\(id)"))).data }
    func shared() async throws -> Shared { try decode(Shared.self, try await call(request("/shared"))) }

    func createFolder(name: String, parentId: Int?) async throws {
        _ = try await call(request("/folders", method: "POST", body: ["name": name, "parent_id": parentId]))
    }
    func renameFolder(_ id: Int, name: String) async throws {
        _ = try await call(request("/folders/\(id)", method: "PATCH", body: ["name": name]))
    }
    /// Albums and subfolders move up to the parent; no music is deleted.
    func deleteFolder(_ id: Int) async throws { _ = try await call(request("/folders/\(id)", method: "DELETE")) }
    func moveAlbum(_ id: Int, folderId: Int?) async throws {
        _ = try await call(request("/albums/\(id)/folder", method: "PUT", body: ["folder_id": folderId]))
    }
    func reorderPlaylist(_ id: Int, trackIds: [Int]) async throws {
        _ = try await call(request("/playlists/\(id)/order", method: "PUT", body: ["ids": trackIds]))
    }

    // MARK: Playlists and search

    struct Created: Decodable { let id: Int; let name: String? }
    struct SearchResult: Decodable { var albums: [Album] = []; var tracks: [Track] = [] }

    func search(_ q: String) async throws -> SearchResult {
        let encoded = q.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? q
        return try decode(SearchResult.self, try await call(request("/search?q=\(encoded)")))
    }
    @discardableResult
    func createPlaylist(name: String, trackId: Int? = nil, albumId: Int? = nil) async throws -> Int {
        let data = try await call(request("/playlists", method: "POST", body: ["name": name, "track_id": trackId, "album_id": albumId].compactMapValues { $0 }.mapValues { $0 as Any? }))
        return try decode(Created.self, data).id
    }
    func renamePlaylist(_ id: Int, name: String) async throws { _ = try await call(request("/playlists/\(id)", method: "PATCH", body: ["name": name])) }
    func deletePlaylist(_ id: Int) async throws { _ = try await call(request("/playlists/\(id)", method: "DELETE")) }
    func addToPlaylist(_ id: Int, trackId: Int? = nil, albumId: Int? = nil) async throws {
        let body: [String: Any?] = trackId != nil ? ["track_id": trackId] : ["album_id": albumId]
        _ = try await call(request("/playlists/\(id)/tracks", method: "POST", body: body))
    }
    func removeFromPlaylist(_ id: Int, trackId: Int) async throws { _ = try await call(request("/playlists/\(id)/tracks/\(trackId)", method: "DELETE")) }
    /// A copy of a shared playlist in your own playlists.
    @discardableResult
    func copyPlaylist(_ id: Int) async throws -> Int { try decode(Created.self, try await call(request("/playlists/\(id)/copy", method: "POST"))).id }

    // MARK: Meters (users.meters, shared with the web player)

    /// The user's meter list in panel order; the server default is one wide meter.
    func meters() async throws -> [String] {
        let obj = try JSONSerialization.jsonObject(with: try await call(request("/me"))) as? [String: Any]
        return (obj?["meters"] as? [String]) ?? ["vu-wide"]
    }
    func saveMeters(_ ids: [String]) async throws { _ = try await call(request("/settings/meters", method: "PUT", body: ["ids": ids])) }
}
