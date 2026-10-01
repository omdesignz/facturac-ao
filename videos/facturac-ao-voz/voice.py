#!/usr/bin/env python3
"""Prepares the narration takes: trim, level, and fit each take to its frame.

HeyGen returns takes that are quiet (-31 to -36 LUFS, inaudible on a phone under
any bed) and padded with about 0.2s of silence at the head. Both are fixed here
rather than in the composition, because `data-volume` caps at 1 and cannot lift
a take that was recorded 17 dB too low.

Three passes per take:

  trim   cut the leading silence back to a short, even lead-in, so the picture
         can be cued to the speech instead of to a variable gap.
  chain  highpass out the rumble that only muddies a phone speaker, compress so
         the loud and quiet syllables sit close enough for one fixed gain to
         reach the target, then limit what is left.
  fit    pad or cut the tail so the take is exactly as long as its frame. Frame
         5 is deliberately longer than its speech: that trailing silence is the
         beat the film ends on, so "Em breve" lands rather than gets cut off.

Durations here and in STORYBOARD.md must agree; they are the same numbers.
"""

import re
import subprocess
from pathlib import Path

TARGET_LUFS = -18.0

# (trim from, final length). The lengths sum to 30.0s — the WhatsApp Status
# ceiling — with frame 5 carrying a 1.3s tail after the last word.
TAKES = {
    "01": (0.108, 5.328),
    "02": (0.180, 3.660),
    "03": (0.125, 8.242),
    "04": (0.155, 6.565),
    "05": (0.160, 6.205),
}

CHAIN = (
    "highpass=f=85,"
    "acompressor=threshold=-21dB:ratio=3:attack=6:release=140:makeup=2,"
    "volume={gain:.2f}dB,"
    "alimiter=limit=0.85:level=disabled,"
    "apad"
)

HERE = Path(__file__).parent


def loudness(path: Path) -> float:
    out = subprocess.run(
        ["ffmpeg", "-nostats", "-i", str(path), "-af", "ebur128", "-f", "null", "-"],
        capture_output=True,
        text=True,
    ).stderr
    tail = out.split("Integrated loudness")[-1]
    return float(re.search(r"I:\s+(-?[\d.]+) LUFS", tail).group(1))


def main() -> int:
    for name, (trim, length) in TAKES.items():
        raw = HERE / "assets" / "voice" / "raw" / f"{name}.wav"
        out = HERE / "assets" / "voice" / f"{name}.wav"

        # How much the chain moves a take depends on that take's own dynamics,
        # so one predicted gain leaves the five takes several dB apart — audible
        # as the narrator changing distance between frames. Build once, measure
        # what actually came out, then correct. Two passes converge to within a
        # few tenths of a dB, which is inaudible.
        gain = TARGET_LUFS - loudness(raw) - 2.0
        for _ in range(2):
            subprocess.run(
                ["ffmpeg", "-v", "error", "-y", "-ss", str(trim), "-i", str(raw),
                 "-af", CHAIN.format(gain=gain), "-t", str(length),
                 "-c:a", "pcm_s16le", str(out)],
                check=True,
            )
            error = TARGET_LUFS - loudness(out)
            if abs(error) < 0.3:
                break
            gain += error

        actual = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "csv=p=0", str(out)],
            capture_output=True, text=True,
        ).stdout.strip()
        print(f"  {name}.wav  {loudness(out):6.1f} LUFS  {float(actual):.3f}s (target {length}s)")

    print(f"  total {sum(v[1] for v in TAKES.values()):.3f}s")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
