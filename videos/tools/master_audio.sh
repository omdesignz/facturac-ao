#!/usr/bin/env bash
# Sets a render's final loudness and copies the picture untouched.
#
#   master_audio.sh <render.mp4> <out.mp4>
#
# Gains the mix to -14 LUFS, where WhatsApp and Instagram play it at full level, and a
# limiter catches the few peaks that would pass -3.5 dB, which is what keeps true peaks under -1 dB after AAC. No dynamic loudness: it pumps.
set -euo pipefail

in="$1"; out="$2"
# Apple's AAC encoder where there is one: ffmpeg's own can put clicks on bright transients.
encoder=aac; ffmpeg -hide_banner -encoders 2>/dev/null | grep -q aac_at && encoder=aac_at
measured="$(ffmpeg -hide_banner -nostats -i "$in" -af ebur128 -f null - 2>&1 | grep -E '^ +I:' | tail -1 | awk '{print $2}')"
gain="$(python3 -c "import sys;print(round(-14 - float(sys.argv[1]), 2))" "$measured")"
ffmpeg -v error -y -i "$in" -c:v copy \
  -af "volume=${gain}dB,alimiter=limit=0.67:level=0:attack=3:release=80" \
  -c:a "$encoder" -b:a 256k -ar 48000 -ac 2 -movflags +faststart "$out"
printf '%s  mix %s LUFS, gain %s dB\n' "$(basename "$out")" "$measured" "$gain"
