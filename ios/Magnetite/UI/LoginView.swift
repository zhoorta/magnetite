import SwiftUI

struct LoginView: View {
    let api: Api
    @EnvironmentObject var session: Session
    @State private var server = ""
    @State private var username = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?
    @FocusState private var focus: Field?
    enum Field { case server, username, password }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                Image("Logo").resizable().scaledToFit().frame(maxWidth: 260).padding(.top, 40).padding(.bottom, 12)
                field("Server", text: $server, field: .server)
                    .keyboardType(.URL).textContentType(.URL)
                field("Username", text: $username, field: .username).textContentType(.username)
                SecureField("Password", text: $password)
                    .focused($focus, equals: .password).textContentType(.password)
                    .padding(12).overlay(Rectangle().stroke(Terminal.line)).submitLabel(.go).onSubmit(submit)
                if let error { Text(error).font(.mono(13)).foregroundStyle(Terminal.red).frame(maxWidth: .infinity, alignment: .leading) }
                Button(action: submit) { Text(busy ? "Signing in…" : "Sign in").frame(maxWidth: .infinity) }
                    .buttonStyle(TerminalButton(filled: true)).disabled(busy || server.isEmpty || username.isEmpty || password.isEmpty)
            }
            .padding(24).frame(maxWidth: 480)
        }
        .scrollDismissesKeyboard(.interactively)
        .onAppear {
            server = session.serverUrl
            username = session.lastUsername
            #if DEBUG
            // For checking on the simulator: MAGNETITE_AUTOLOGIN=server|user|password
            if let auto = ProcessInfo.processInfo.environment["MAGNETITE_AUTOLOGIN"]?.split(separator: "|", omittingEmptySubsequences: false), auto.count == 3 {
                server = String(auto[0]); username = String(auto[1]); password = String(auto[2]); submit()
            }
            #endif
        }
    }

    private func field(_ title: String, text: Binding<String>, field: Field) -> some View {
        TextField(title, text: text)
            .focused($focus, equals: field)
            .textInputAutocapitalization(.never).autocorrectionDisabled()
            .padding(12).overlay(Rectangle().stroke(Terminal.line))
    }

    private func submit() {
        guard !busy else { return }
        busy = true; error = nil
        Task {
            do { try await api.signIn(server: server, username: username, password: password); password = "" }
            catch { self.error = error.localizedDescription }
            busy = false
        }
    }
}
