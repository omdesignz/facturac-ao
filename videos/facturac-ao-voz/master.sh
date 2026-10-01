#!/usr/bin/env bash
#
# Masters a rendered cut to the loudness social platforms expect.
#
# HyperFrames mixes the tracks at their declared volumes and stops there, so the
# render comes out wherever the sum of the sources lands — here about -11.7
# LUFS, hot enough to clip and hot enough for WhatsApp and Instagram to turn it
# down on playback anyway. This pass sets it to -14 LUFS with 1.5 dB of true
# peak headroom, which is what those platforms normalise to, so the film plays
# back at the level it was mixed at.
#
# Two passes: the first measures, the second applies the measured values, which
# lets loudnorm work as a fixed gain rather than riding the dynamics. The video
# stream is copied untouched.
#
# Usage: master.sh <in.mp4> <out.mp4>

set -euo pipefail

IN="$1"
OUT="$2"
I=-14
TP=-1.5
LRA=11

MEASURED=$(ffmpeg -nostats -i "$IN" \
    -af "loudnorm=I=${I}:TP=${TP}:LRA=${LRA}:print_format=json" \
    -f null - 2>&1 | sed -n '/^{/,/^}/p')

read -r M_I M_TP M_LRA M_THRESH M_OFFSET <<EOF
$(python3 -c "
import json,sys
d = json.load(sys.stdin)
print(d['input_i'], d['input_tp'], d['input_lra'], d['input_thresh'], d['target_offset'])
" <<<"$MEASURED")
EOF

# The bed is looped to length and simply stops at the last sample, which reads
# as the file running out rather than as the film ending. A short fade over the
# held final beat lets the soundtrack resolve with the picture.
DUR=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$IN")
FADE_AT=$(python3 -c "print(f'{$DUR - 1.2:.3f}')")

ffmpeg -v error -y -i "$IN" \
    -af "loudnorm=I=${I}:TP=${TP}:LRA=${LRA}:measured_I=${M_I}:measured_TP=${M_TP}:measured_LRA=${M_LRA}:measured_thresh=${M_THRESH}:offset=${M_OFFSET}:linear=true:print_format=summary,afade=t=out:st=${FADE_AT}:d=1.2" \
    -c:v copy -c:a aac -b:a 192k -t "$DUR" -movflags +faststart "$OUT"

echo "mastered $OUT  (was I=${M_I} TP=${M_TP} → target I=${I} TP=${TP})"
