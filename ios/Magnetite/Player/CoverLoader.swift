import SwiftUI

/// Loads covers through the app's API client so the request carries the Bearer token.
@MainActor
final class CoverLoader {
    static let shared = CoverLoader()
    private let cache = NSCache<NSString, UIImage>()
    var api: Api?

    func image(_ urlString: String) async -> UIImage? {
        if let hit = cache.object(forKey: urlString as NSString) { return hit }
        guard let api, let url = URL(string: urlString) else { return nil }
        guard let (data, http) = try? await api.data(for: URLRequest(url: url)), http.statusCode == 200,
              let image = UIImage(data: data) else { return nil }
        cache.setObject(image, forKey: urlString as NSString)
        return image
    }
}

struct CoverView: View {
    let url: String?
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            Terminal.surface
            if let image {
                Image(uiImage: image).resizable().scaledToFill()
            } else {
                Image(systemName: "music.note").foregroundStyle(Terminal.muted)
            }
        }
        .clipped()
        .task(id: url) {
            guard let url else { image = nil; return }
            image = await CoverLoader.shared.image(url)
        }
    }
}
