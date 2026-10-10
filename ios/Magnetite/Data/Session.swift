import Foundation
import Security

/// Server url and user name in UserDefaults; the token in the Keychain.
@MainActor
final class Session: ObservableObject {
    private let defaults = UserDefaults.standard
    private let service = "app.magnetite.ios.token"

    @Published private(set) var token: String?
    @Published var serverUrl: String { didSet { defaults.set(serverUrl, forKey: "server") } }
    @Published var username: String? { didSet { defaults.set(username, forKey: "username") } }
    /// Survives sign-out, to prefill the sign-in form.
    var lastUsername: String {
        get { defaults.string(forKey: "last_username") ?? "" }
        set { defaults.set(newValue, forKey: "last_username") }
    }

    init() {
        serverUrl = defaults.string(forKey: "server") ?? ""
        username = defaults.string(forKey: "username")
        token = Self.readToken(service: "app.magnetite.ios.token")
    }

    var signedIn: Bool { !(token ?? "").isEmpty && !serverUrl.isEmpty }

    func setServer(_ url: String) {
        var u = url.trimmingCharacters(in: .whitespacesAndNewlines)
        while u.hasSuffix("/") { u.removeLast() }
        if !u.contains("://") { u = "https://" + u }
        serverUrl = u
    }

    func signIn(token: String, username: String) {
        writeToken(token)
        self.token = token
        self.username = username
        lastUsername = username
    }

    func clear() {
        deleteToken()
        token = nil
        username = nil
    }

    private static func readToken(service: String) -> String? {
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
                                kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
        var out: AnyObject?
        guard SecItemCopyMatching(q as CFDictionary, &out) == errSecSuccess, let data = out as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    private func writeToken(_ value: String) {
        deleteToken()
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
                                kSecValueData as String: Data(value.utf8),
                                kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock]
        SecItemAdd(q as CFDictionary, nil)
    }

    private func deleteToken() {
        SecItemDelete([kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service] as CFDictionary)
    }
}
