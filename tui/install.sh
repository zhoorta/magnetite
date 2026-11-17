#!/bin/sh
# Installs the Magnetite terminal client from the latest GitHub release.
#
#   curl -fsSL https://raw.githubusercontent.com/zhoorta/magnetite/main/tui/install.sh | sh
#
# Options (environment):
#   MAGNETITE_VERSION      a tag such as v0.4.12 (default: the latest release)
#   MAGNETITE_INSTALL_DIR  where to put the binary (default: ~/.local/bin)
set -eu

repo="zhoorta/magnetite"
dir="${MAGNETITE_INSTALL_DIR:-$HOME/.local/bin}"

say() { printf '%s\n' "$*"; }
fail() { printf 'error: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "$1 is required"; }

need curl
need tar

case "$(uname -s)" in
  Darwin) os=macos ;;
  Linux) os=linux ;;
  *) fail "unsupported system $(uname -s); build from source (see docs/guide/clients.md)" ;;
esac
case "$(uname -m)" in
  arm64 | aarch64) arch=$([ "$os" = macos ] && echo arm64 || echo aarch64) ;;
  x86_64 | amd64) arch=x86_64 ;;
  *) fail "unsupported architecture $(uname -m)" ;;
esac

tag="${MAGNETITE_VERSION:-}"
if [ -z "$tag" ]; then
  # The redirect of /releases/latest avoids the API and its rate limit.
  tag=$(curl -fsSLI -o /dev/null -w '%{url_effective}' "https://github.com/$repo/releases/latest") || fail "could not reach GitHub"
  tag=${tag##*/}
fi
case "$tag" in v*) ;; *) fail "no release found (got '$tag')" ;; esac

name="magnetite-tui-$tag-$os-$arch"
base="https://github.com/$repo/releases/download/$tag"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

say "Downloading $name"
curl -fsSL -o "$tmp/$name.tar.gz" "$base/$name.tar.gz" || fail "download failed: $base/$name.tar.gz"
curl -fsSL -o "$tmp/$name.tar.gz.sha256" "$base/$name.tar.gz.sha256" || fail "checksum download failed"

want=$(cut -d ' ' -f 1 "$tmp/$name.tar.gz.sha256")
if command -v sha256sum >/dev/null 2>&1; then
  got=$(sha256sum "$tmp/$name.tar.gz" | cut -d ' ' -f 1)
else
  got=$(shasum -a 256 "$tmp/$name.tar.gz" | cut -d ' ' -f 1)
fi
[ "$want" = "$got" ] || fail "checksum mismatch"

tar xzf "$tmp/$name.tar.gz" -C "$tmp"
mkdir -p "$dir"
install -m 755 "$tmp/$name/magnetite" "$dir/magnetite"
[ "$os" = macos ] && xattr -d com.apple.quarantine "$dir/magnetite" 2>/dev/null || true

say "Installed $tag to $dir/magnetite"
case ":$PATH:" in
  *":$dir:"*) ;;
  *) say "Note: $dir is not on your PATH. Add it, e.g.  export PATH=\"$dir:\$PATH\"" ;;
esac
[ "$os" = linux ] && say "Sound output needs ALSA (libasound2)."
say "Next:  magnetite login https://your-server   then   magnetite"
