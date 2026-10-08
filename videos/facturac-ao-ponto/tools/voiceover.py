#!/usr/bin/env python3
"""Lays the ElevenLabs narration over the finished film, as separate files.

The take is one ElevenLabs generation (voice "Clara - Clear and Dynamic",
European Portuguese, eleven_multilingual_v2) with a pause after every line, so
the lines can be cut apart on silence and each one placed on its own cue. The
picture and the original mix are not touched: renders/video.mp4 stays as it is,
and the voiced cut is written next to it.

  split   find the speech runs in assets/vo/take.mp3; there must be exactly one
          per cue, or the take does not match the script and nothing is written.
  place   trim each run with a short lead-in and tail, and delay it so its
          first syllable lands on the cue.
  level   highpass and compress the voice, then lift it to VOICE_LUFS; bring the
          original mix to BED_LUFS so all three films sit the same under the voice.
  duck    sidechain the bed off the voice, so music and effects dip while a
          line plays and come back between lines.

Outputs:
  assets/vo/voice.wav           the placed, levelled voice stem
  renders/video-voz.mp4         master (true peak under -1 dBTP)
  renders/video-voz-social.mp4  loudness-normalised for phones
"""

import re
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
TAKE = HERE / "assets/vo/take.mp3"
VIDEO = HERE / "renders/video.mp4"
STEM = HERE / "assets/vo/voice.wav"
MASTER = HERE / "renders/video-voz.mp4"
SOCIAL = HERE / "renders/video-voz-social.mp4"

# (global second the line starts, what is said). Order matches the take.
CUES = [
    (0.95, "Facturação."),
    (3.053, "Tire o til."),
    (4.62, "Tire a cedilha."),
    (6.188, "Ponha um ponto."),
    (8.80, "É só isso!"),
    (10.89, "Emitida, validada, paga."),
    (13.5, "Brevemente."),
    (14.95, "facturac ponto A O."),
]

VOICE_LUFS = -16.0
BED_LUFS = -19.0
LEAD = 0.04
TAIL = 0.14
SOCIAL_CHAIN = "loudnorm=I=-14:TP=-1.5:LRA=11,alimiter=limit=0.8:attack=3:release=60:level=disabled"


def run(args: list[str]) -> str:
    return subprocess.run(args, capture_output=True, text=True, check=True).stderr


def duration(path: Path) -> float:
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
        capture_output=True,
        text=True,
        check=True,
    ).stdout
    return float(out)


def loudness(path: Path) -> tuple[float, float]:
    out = run(["ffmpeg", "-nostats", "-i", str(path), "-af", "ebur128=peak=true", "-f", "null", "-"])
    tail = out.split("Summary:")[-1]
    integrated = float(re.search(r"I:\s+(-?[\d.]+) LUFS", tail).group(1))
    peak = float(re.search(r"Peak:\s+(-?[\d.]+) dBFS", tail).group(1))
    return integrated, peak


def speech_runs(path: Path) -> list[tuple[float, float]]:
    out = run(["ffmpeg", "-nostats", "-i", str(path), "-af", "silencedetect=noise=-38dB:d=0.35", "-f", "null", "-"])
    starts = [float(x) for x in re.findall(r"silence_start: (-?[\d.]+)", out)]
    ends = [float(x) for x in re.findall(r"silence_end: ([\d.]+)", out)]
    total = duration(path)

    runs, cursor = [], 0.0
    for start, end in zip(starts, ends + [total] * (len(starts) - len(ends))):
        if start > cursor + 0.05:
            runs.append((cursor, start))
        cursor = end
    if cursor < total - 0.05:
        runs.append((cursor, total))

    return runs


def place_voice(runs: list[tuple[float, float]], length: float, gain_db: float) -> None:
    parts, labels = [], []
    for index, ((start, end), (cue, _)) in enumerate(zip(runs, CUES)):
        begin = max(0.0, start - LEAD)
        stop = end + TAIL
        delay = int(round((cue - (start - begin)) * 1000))
        parts.append(
            f"[s{index}]atrim=start={begin:.3f}:end={stop:.3f},asetpts=PTS-STARTPTS,"
            f"afade=t=in:d=0.02,afade=t=out:st={stop - begin - 0.08:.3f}:d=0.08,"
            f"adelay={delay}|{delay}[v{index}]"
        )
        labels.append(f"[v{index}]")

    split = f"[0:a]asplit={len(runs)}" + "".join(f"[s{i}]" for i in range(len(runs)))
    graph = split + ";" + ";".join(parts) + (
        f";{''.join(labels)}amix=inputs={len(labels)}:normalize=0,"
        "highpass=f=90,acompressor=threshold=-20dB:ratio=3:attack=5:release=120:makeup=2,"
        f"volume={gain_db:.2f}dB,apad,atrim=0:{length:.3f}[out]"
    )
    run(["ffmpeg", "-y", "-i", str(TAKE), "-filter_complex", graph, "-map", "[out]",
         "-ac", "2", "-ar", "48000", str(STEM)])


def main() -> int:
    runs = speech_runs(TAKE)
    if len(runs) != len(CUES):
        print(f"take has {len(runs)} speech runs, script has {len(CUES)} lines: {runs}")
        return 1

    length = duration(VIDEO)
    place_voice(runs, length, 0.0)
    voice_lufs, _ = loudness(STEM)
    place_voice(runs, length, VOICE_LUFS - voice_lufs)

    bed_lufs, _ = loudness(VIDEO)
    bed_gain = BED_LUFS - bed_lufs
    mix = (
        f"[0:a]volume={bed_gain:.2f}dB[bed];"
        "[1:a]asplit=2[key][voice];"
        "[bed][key]sidechaincompress=threshold=0.025:ratio=5:attack=20:release=320:makeup=1[ducked];"
        "[ducked][voice]amix=inputs=2:normalize=0[mix]"
    )
    master = mix + ";[mix]alimiter=limit=0.85:attack=3:release=60:level=disabled[a]"
    social = mix + f";[mix]{SOCIAL_CHAIN}[a]"

    for target, graph in ((MASTER, master), (SOCIAL, social)):
        run(["ffmpeg", "-y", "-i", str(VIDEO), "-i", str(STEM), "-filter_complex", graph,
             "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "192k",
             "-ar", "48000", "-movflags", "+faststart", str(target)])
        integrated, peak = loudness(target)
        print(f"{target.name}: {integrated} LUFS, peak {peak} dBFS")

    for (start, end), (cue, text) in zip(runs, CUES):
        print(f"{cue:6.2f}s  {end - start:4.2f}s  {text}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
