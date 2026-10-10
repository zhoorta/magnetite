import SwiftUI

/// The Terminal theme of the web app (resources/js/magnetite/themes.json): black, phosphor green, monospace, square corners.
enum Terminal {
    static let bg = Color(red: 0, green: 0, blue: 0)
    static let surface = Color(red: 0x07 / 255, green: 0x14 / 255, blue: 0x0A / 255)
    static let text = Color(red: 0xB6 / 255, green: 0xFF / 255, blue: 0xBD / 255)
    static let muted = Color(red: 0x4F / 255, green: 0x8A / 255, blue: 0x57 / 255)
    static let accent = Color(red: 0x39 / 255, green: 0xFF / 255, blue: 0x6A / 255)
    static let accent2 = Color(red: 0xB6 / 255, green: 0xFF / 255, blue: 0x3A / 255)
    static let line = Color(red: 0x1F / 255, green: 0x6B / 255, blue: 0x33 / 255)
    static let red = Color(red: 1, green: 0x4D / 255, blue: 0x3D / 255)
}

extension Font {
    static func mono(_ size: CGFloat = 15, weight: Font.Weight = .regular) -> Font { .system(size: size, weight: weight, design: .monospaced) }
}

func clock(_ seconds: Double) -> String {
    guard seconds.isFinite, seconds >= 0 else { return "0:00" }
    let s = Int(seconds)
    return String(format: "%d:%02d", s / 60, s % 60)
}

struct TerminalButton: ButtonStyle {
    var filled = false
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.mono(15, weight: .semibold))
            .padding(.horizontal, 16).padding(.vertical, 12)
            .foregroundStyle(filled ? Terminal.bg : Terminal.accent)
            .background(filled ? Terminal.accent : Color.clear)
            .overlay(Rectangle().stroke(Terminal.accent, lineWidth: 1))
            .opacity(configuration.isPressed ? 0.6 : 1)
    }
}

extension View {
    func terminalScreen() -> some View {
        self.scrollContentBackground(.hidden)
            .background(Terminal.bg.ignoresSafeArea())
            .foregroundStyle(Terminal.text)
            .font(.mono())
            .tint(Terminal.accent)
    }
}

/// A line of the first/second row style shared by lists.
struct RowText: View {
    let title: String
    var subtitle: String?
    var dim = false
    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(.mono(15)).lineLimit(1)
            if let subtitle, !subtitle.isEmpty { Text(subtitle).font(.mono(12)).foregroundStyle(Terminal.muted).lineLimit(1) }
        }
        .opacity(dim ? 0.4 : 1)
    }
}

func setupAppearance() {
    let nav = UINavigationBarAppearance()
    nav.configureWithOpaqueBackground()
    nav.backgroundColor = .black
    let green = UIColor(Terminal.accent)
    nav.titleTextAttributes = [.foregroundColor: green, .font: UIFont.monospacedSystemFont(ofSize: 17, weight: .semibold)]
    nav.largeTitleTextAttributes = [.foregroundColor: green, .font: UIFont.monospacedSystemFont(ofSize: 30, weight: .bold)]
    nav.shadowColor = UIColor(Terminal.line)
    UINavigationBar.appearance().standardAppearance = nav
    UINavigationBar.appearance().scrollEdgeAppearance = nav
    UINavigationBar.appearance().tintColor = green
    let tab = UITabBarAppearance()
    tab.configureWithOpaqueBackground()
    tab.backgroundColor = .black
    tab.shadowColor = UIColor(Terminal.line)
    let item = UITabBarItemAppearance()
    item.normal.iconColor = UIColor(Terminal.muted)
    item.normal.titleTextAttributes = [.foregroundColor: UIColor(Terminal.muted), .font: UIFont.monospacedSystemFont(ofSize: 11, weight: .regular)]
    item.selected.iconColor = green
    item.selected.titleTextAttributes = [.foregroundColor: green, .font: UIFont.monospacedSystemFont(ofSize: 11, weight: .semibold)]
    tab.stackedLayoutAppearance = item
    UITabBar.appearance().standardAppearance = tab
    UITabBar.appearance().scrollEdgeAppearance = tab
}
