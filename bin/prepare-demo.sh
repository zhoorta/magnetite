#!/usr/bin/env bash
# Builds the demo excerpts for the start page from their free (CC0) sources: downloads each original,
# cuts `length` seconds from `start`, fades the edges and writes FLAC to storage/app/demo (or $1).
# The audio is not in git: copy that folder to the server (docs/bare-metal.md). Needs curl, ffmpeg and jq.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/storage/app/demo}"
manifest="$root/resources/demo/tracks.json"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$out"

jq -c '.tracks[]' "$manifest" | while read -r t; do
    id=$(jq -r .id <<<"$t"); url=$(jq -r .download_url <<<"$t"); file=$(jq -r .file <<<"$t")
    start=$(jq -r .start <<<"$t"); length=$(jq -r .length <<<"$t")
    src="$work/$id.${url##*.}"
    echo "== $id"
    curl -fsSL --retry 3 -A "Mozilla/5.0" -o "$src" "$url"
    ffmpeg -nostdin -loglevel error -y -ss "$start" -t "$length" -i "$src" \
        -af "afade=t=in:d=0.5,afade=t=out:st=$(echo "$length - 2" | bc):d=2" \
        -map_metadata -1 -sample_fmt s16 -c:a flac -compression_level 8 "$out/$file"
    rm -f "$src"
done
ls -lh "$out"
