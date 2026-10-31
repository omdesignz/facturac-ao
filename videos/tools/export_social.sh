#!/usr/bin/env bash
# Turns a high-quality master render into the files each platform should get.
#
#   export_social.sh <master.mp4> <out-dir> <name>
#
# Instagram keeps 1080x1920 at a high bitrate, so it gets the full-size file.
# WhatsApp status re-encodes everything to roughly 720p at a low bitrate, and does
# the downscale with a cheap filter. So the WhatsApp file is already 720x1280,
# scaled by us with Lanczos, with a touch of sharpening to survive the second
# encode, Main profile, 30 fps and under 16 MB: its own encoder then has little
# left to ruin. Measured with VMAF against an emulated WhatsApp re-encode.
set -euo pipefail

master="$1"; out="$2"; name="$3"
mkdir -p "$out"

common=(-pix_fmt yuv420p -color_primaries bt709 -color_trc bt709 -colorspace bt709 -color_range tv -movflags +faststart -r 30 -fps_mode cfr)

# Instagram / Reels / TikTok / Facebook: full size, generous bitrate.
ffmpeg -v error -y -i "$master" \
  -c:v libx264 -preset slow -tune animation -profile:v high -level 4.1 \
  -crf 16 -maxrate 14M -bufsize 28M -g 60 -bf 2 "${common[@]}" \
  -c:a aac -b:a 192k -ar 48000 -ac 2 \
  "$out/$name-instagram.mp4"

# WhatsApp status: pre-scaled to its own target, scaled well, sharpened a little.
ffmpeg -v error -y -i "$master" \
  -vf "scale=720:1280:flags=lanczos+accurate_rnd+full_chroma_int,unsharp=5:5:0.35:3:3:0.0" \
  -c:v libx264 -preset slow -tune animation -profile:v main -level 3.1 \
  -b:v 3500k -maxrate 4200k -bufsize 7000k -g 60 -bf 2 "${common[@]}" \
  -c:a aac -b:a 128k -ar 44100 -ac 2 \
  "$out/$name-whatsapp.mp4"

for f in "$out/$name-instagram.mp4" "$out/$name-whatsapp.mp4"; do
  printf '%s  %s MB  %s s\n' "$(basename "$f")" \
    "$(python3 -c "import os,sys;print(round(os.path.getsize(sys.argv[1])/1e6,1))" "$f")" \
    "$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f" | cut -c1-5)"
done
