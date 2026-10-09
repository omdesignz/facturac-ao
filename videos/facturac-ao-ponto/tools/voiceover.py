#!/usr/bin/env python3
"""Lays the ElevenLabs narration over the finished film, as separate files.

The narration is one continuous read of the whole script (voice "Clara - Clear
and Dynamic", European Portuguese, eleven_v4), so the delivery carries from line
to line the way a voice artist's does. It is never generated a line at a time:
lines recorded in isolation each start cold and end on a full stop, and laid
side by side they sound like a machine reading captions.

The read is only ever cut in its own pauses. Each phrase keeps its place in the
performance and the pauses inside it; what changes is how long the film waits
between phrases, so that each one lands on its picture.

  split   find the speech runs in assets/vo/read.mp3. PHRASES says how many
          runs each phrase spans; the total must match the read or nothing is
          written.
  place   start each phrase so the word the picture is cut to lands on its cue.
          A phrase never starts before the one ahead of it has finished.
  level   highpass and gently compress the voice, then lift it to VOICE_LUFS;
          bring the original mix to BED_LUFS so all three films sit the same
          under the voice.
  duck    ride the bed down under each spoken passage and back up after it, on
          a drawn envelope that starts before the first word and bridges the
          short gaps, so the music moves in phrases instead of pumping per word.

The picture and the original mix are not touched: renders/video.mp4 stays as it
is, and the voiced cut is written next to it.

Outputs:
  assets/vo/voice.wav           the placed, levelled voice stem
  renders/video-voz.mp4         master (peak under -1 dBFS)
  renders/video-voz-social.mp4  lifted to -14 LUFS for phones
"""

import re
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
READ = HERE / "assets/vo/read.mp3"
VIDEO = HERE / "renders/video.mp4"
STEM = HERE / "assets/vo/voice.wav"
MASTER = HERE / "renders/video-voz.mp4"
SOCIAL = HERE / "renders/video-voz-social.mp4"

# (cue, lead, runs, what is said). The cue is the global second of the picture
# event; lead is how far into the phrase the word cut to that event starts, so
# the phrase begins at cue - lead. runs is how many speech runs of the read the
# phrase spans. Order matches the read.
PHRASES = [
    (2.0, 1.089, 2, "Todos os dias: facturação."),
    (3.053, 0.0, 1, "Tire o til."),
    (4.621, 0.0, 1, "Tire a cedilha."),
    (6.188, 0.0, 1, "Ponha um ponto."),
    (8.8, 0.0, 1, "É só isso."),
    (10.89, 0.1, 2, "Emitida, validada, paga."),
    (13.52, 0.0, 1, "Brevemente:"),
    (14.55, 0.0, 2, "facturac ponto á ó."),
]

VOICE_LUFS = -16.0
BED_LUFS = -19.0
DUCK_DB = -6.5
DUCK_ATTACK = 0.22
DUCK_RELEASE = 0.55
DUCK_BRIDGE = 1.5
MIN_GAP = 0.1
HEAD = 0.03
TAIL = 0.12
SOCIAL_LUFS = -14.0


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
    out = run(["ffmpeg", "-nostats", "-i", str(path), "-af", "silencedetect=noise=-40dB:d=0.12", "-f", "null", "-"])
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


def place(runs: list[tuple[float, float]]) -> list[dict]:
    """Where each phrase is cut from the read and where it starts in the film."""
    placed, index, previous_end = [], 0, 0.0
    for cue, lead, count, text in PHRASES:
        source_start, source_end = runs[index][0], runs[index + count - 1][1]
        index += count
        wanted = cue - lead
        start = max(wanted, previous_end + MIN_GAP, 0.0)
        previous_end = start + (source_end - source_start)
        placed.append({
            "text": text,
            "source": (source_start, source_end),
            "start": start,
            "end": previous_end,
            "late": start - wanted,
            "word": start + lead,
        })

    return placed


def write_stem(placed: list[dict], total: float, length: float, gain_db: float) -> None:
    parts, labels = [], []
    for index, phrase in enumerate(placed):
        source_start, source_end = phrase["source"]
        before = placed[index - 1]["source"][1] if index else 0.0
        after = placed[index + 1]["source"][0] if index + 1 < len(placed) else total
        head = min(HEAD, (source_start - before) / 2)
        tail = min(TAIL, (after - source_end) / 2)
        begin, stop = source_start - head, source_end + tail
        delay = max(0, int(round((phrase["start"] - head) * 1000)))
        fade_out = max(0.02, tail)
        parts.append(
            f"[s{index}]atrim=start={begin:.3f}:end={stop:.3f},asetpts=PTS-STARTPTS,"
            f"afade=t=in:d={max(0.008, head):.3f},afade=t=out:st={stop - begin - fade_out:.3f}:d={fade_out:.3f},"
            f"adelay={delay}:all=1[v{index}]"
        )
        labels.append(f"[v{index}]")

    split = f"[0:a]aresample=48000,asplit={len(placed)}" + "".join(f"[s{i}]" for i in range(len(placed)))
    graph = split + ";" + ";".join(parts) + (
        f";{''.join(labels)}amix=inputs={len(labels)}:normalize=0,"
        "highpass=f=80,acompressor=threshold=-22dB:ratio=2.5:attack=8:release=160:makeup=1.5,"
        f"volume={gain_db:.2f}dB,apad,atrim=0:{length:.3f}[out]"
    )
    run(["ffmpeg", "-y", "-i", str(READ), "-filter_complex", graph, "-map", "[out]",
         "-ac", "2", "-ar", "48000", str(STEM)])


def duck_spans(placed: list[dict]) -> list[tuple[float, float]]:
    """Spoken passages: phrases joined across gaps too short to bring the music back in."""
    spans: list[tuple[float, float]] = []
    for phrase in placed:
        if spans and phrase["start"] - spans[-1][1] < DUCK_BRIDGE:
            spans[-1] = (spans[-1][0], phrase["end"])
        else:
            spans.append((phrase["start"], phrase["end"]))

    return spans


def duck_envelope(spans: list[tuple[float, float]]) -> str:
    """A volume expression that is 1 between passages and DUCK_DB under them."""
    depth = 1 - 10 ** (DUCK_DB / 20)
    terms = [
        f"clip((t-{start - DUCK_ATTACK:.3f})/{DUCK_ATTACK},0,1)*clip(({end + DUCK_RELEASE:.3f}-t)/{DUCK_RELEASE},0,1)"
        for start, end in spans
    ]
    peak = terms[0]
    for term in terms[1:]:
        peak = f"max({peak},{term})"

    return f"1-{depth:.4f}*{peak}"


def main() -> int:
    runs = speech_runs(READ)
    expected = sum(count for _, _, count, _ in PHRASES)
    if len(runs) != expected:
        print(f"read has {len(runs)} speech runs, script spans {expected}:")
        for start, end in runs:
            print(f"  {start:7.3f} {end:7.3f}  {end - start:.2f}s")
        return 1

    length = duration(VIDEO)
    total = duration(READ)
    placed = place(runs)
    if placed[-1]["end"] > length:
        print(f"narration runs to {placed[-1]['end']:.2f}s, film is {length:.2f}s")
        return 1

    write_stem(placed, total, length, 0.0)
    voice_lufs, _ = loudness(STEM)
    write_stem(placed, total, length, VOICE_LUFS - voice_lufs)

    bed_lufs, _ = loudness(VIDEO)
    spans = duck_spans(placed)
    mix = (
        f"[0:a]volume={BED_LUFS - bed_lufs:.2f}dB,asetnsamples=n=256,"
        f"volume='{duck_envelope(spans)}':eval=frame[bed];"
        "[bed][1:a]amix=inputs=2:normalize=0[mix]"
    )
    limiter = "alimiter=limit={limit}:attack=3:release=60:level=disabled"

    def encode(target: Path, tail: str) -> tuple[float, float]:
        run(["ffmpeg", "-y", "-i", str(VIDEO), "-i", str(STEM), "-filter_complex", f"{mix};[mix]{tail}[a]",
             "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "256k",
             "-ar", "48000", "-t", f"{length:.3f}", "-movflags", "+faststart", str(target)])
        return loudness(target)

    master_lufs, master_peak = encode(MASTER, limiter.format(limit=0.85))
    print(f"{MASTER.name}: {master_lufs} LUFS, peak {master_peak} dBFS")
    lift = SOCIAL_LUFS - master_lufs
    social_lufs, social_peak = encode(SOCIAL, f"volume={lift:.2f}dB," + limiter.format(limit=0.8))
    print(f"{SOCIAL.name}: {social_lufs} LUFS, peak {social_peak} dBFS")

    for phrase in placed:
        late = f"  +{phrase['late']:.2f}s late" if phrase["late"] > 0.005 else ""
        print(f"{phrase['start']:6.2f} to {phrase['end']:5.2f}  cue word at {phrase['word']:5.2f}  {phrase['text']}{late}")
    print("music ducked over " + ", ".join(f"{start:.2f}-{end:.2f}" for start, end in spans))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
