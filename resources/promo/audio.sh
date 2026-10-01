#!/usr/bin/env bash
#
# Builds the soundtrack for a promo film.
#
# Every sound here is synthesised from oscillators and filtered noise, so there
# is nothing to license and nothing to clear — which matters for something that
# goes out on social. It is sound *design* rather than music: a low bed for
# presence, a tick as each line lands, and one hero sound, the stamp, which is
# the moment the whole film is built around.
#
# Cue times come from the film's own timeline in milliseconds, passed in, so the
# audio cannot drift out of step with the picture.
#
# The mix is normalised to -16 LUFS / -1.5 dBTP at the end: designed at these
# levels the raw mix lands near -42 dB, which is inaudible on a phone speaker
# and reads to the viewer as no sound at all.
#
# Usage: audio.sh <out.wav> <total-ms> <tick-ms,...> <thud-ms> <chime-ms> <swell-ms>

set -euo pipefail

OUT="$1"
TOTAL_MS="$2"
TICKS="$3"
THUD_MS="$4"
CHIME_MS="$5"
SWELL_MS="$6"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

R=48000
DUR_S=$(python3 -c "print(f'{$TOTAL_MS/1000:.3f}')")

# --- the bed -----------------------------------------------------------------
# A fifth held very low and very quiet. Below speech, so it reads as room tone
# rather than as music competing with the words on screen.
ffmpeg -v error -y -f lavfi -i "sine=frequency=55:duration=${DUR_S}:sample_rate=${R}" \
  -f lavfi -i "sine=frequency=82.5:duration=${DUR_S}:sample_rate=${R}" \
  -filter_complex "[0]volume=0.10[a];[1]volume=0.055[b];[a][b]amix=inputs=2:normalize=0,\
lowpass=f=220,afade=t=in:st=0:d=1.6,afade=t=out:st=$(python3 -c "print(f'{$TOTAL_MS/1000-1.8:.3f}')"):d=1.8" \
  "$WORK/bed.wav"

# --- a line landing ----------------------------------------------------------
# Short, soft, high. Enough to mark the beat without becoming a typewriter.
ffmpeg -v error -y -f lavfi -i "sine=frequency=1760:duration=0.16:sample_rate=${R}" \
  -af "volume='exp(-t*26)':eval=frame,highpass=f=900,volume=0.16" "$WORK/tick.wav"

# --- the stamp ---------------------------------------------------------------
# The hero. A low body with a fast decay for the weight of the press, plus a
# filtered noise transient for the contact — a rubber stamp hitting a desk, not
# a synthesised blip.
ffmpeg -v error -y -f lavfi -i "sine=frequency=92:duration=0.75:sample_rate=${R}" \
  -af "volume='exp(-t*9)':eval=frame,lowpass=f=260,volume=0.62" "$WORK/thud-body.wav"
ffmpeg -v error -y -f lavfi -i "anoisesrc=d=0.3:c=brown:r=${R}:a=0.7" \
  -af "volume='exp(-t*34)':eval=frame,lowpass=f=1500,highpass=f=120,volume=0.34" "$WORK/thud-tap.wav"
ffmpeg -v error -y -i "$WORK/thud-body.wav" -i "$WORK/thud-tap.wav" \
  -filter_complex "[0][1]amix=inputs=2:normalize=0" "$WORK/thud.wav"

# --- the confirmation --------------------------------------------------------
# Two notes rising a fourth: the shape of a question answered.
ffmpeg -v error -y -f lavfi -i "sine=frequency=659.25:duration=0.5:sample_rate=${R}" \
  -af "volume='exp(-t*7)':eval=frame,volume=0.2" "$WORK/ch1.wav"
ffmpeg -v error -y -f lavfi -i "sine=frequency=880:duration=0.9:sample_rate=${R}" \
  -af "volume='exp(-t*5)':eval=frame,volume=0.22" "$WORK/ch2.wav"
ffmpeg -v error -y -i "$WORK/ch1.wav" -i "$WORK/ch2.wav" \
  -filter_complex "[1]adelay=150|150[b];[0][b]amix=inputs=2:normalize=0" "$WORK/chime.wav"

# --- the lift into the sign-off ---------------------------------------------
ffmpeg -v error -y -f lavfi -i "anoisesrc=d=1.1:c=pink:r=${R}:a=0.5" \
  -af "volume='0.10*t*t':eval=frame,highpass=f=600,lowpass=f=5200,afade=t=out:st=0.9:d=0.2" \
  "$WORK/swell.wav"

# --- assemble ----------------------------------------------------------------
# Each cue is delayed to its millisecond and mixed in. `normalize=0` keeps the
# levels as designed; the limiter at the end catches any stacking.
INPUTS=(-i "$WORK/bed.wav")
FILTER=""
LABELS="[0:a]"
IDX=1

add_cue() {
    local file="$1" ms="$2"
    INPUTS+=(-i "$file")
    FILTER+="[${IDX}:a]adelay=${ms}|${ms}[c${IDX}];"
    LABELS+="[c${IDX}]"
    IDX=$((IDX + 1))
}

IFS=',' read -ra TICK_LIST <<< "$TICKS"
for ms in "${TICK_LIST[@]}"; do
    add_cue "$WORK/tick.wav" "$ms"
done

add_cue "$WORK/thud.wav" "$THUD_MS"
add_cue "$WORK/chime.wav" "$CHIME_MS"
add_cue "$WORK/swell.wav" "$SWELL_MS"

ffmpeg -v error -y "${INPUTS[@]}" \
  -filter_complex "${FILTER}${LABELS}amix=inputs=${IDX}:normalize=0:dropout_transition=0,\
alimiter=limit=0.92,atrim=0:${DUR_S},\
loudnorm=I=-16:LRA=11:TP=-1.5,\
aformat=sample_fmts=s16:sample_rates=${R}:channel_layouts=stereo" \
  "$OUT"

echo "wrote $OUT"
