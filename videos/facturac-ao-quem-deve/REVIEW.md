# Review: "Quem me deve?"

Vertical, 1080 x 1920, 30 fps, 30.0 s, for WhatsApp status and Instagram. It sells getting paid, not invoicing: what is owed, who owes it, the reminder already written, nothing sent until confirmed. Checked from the rendered files; `hyperframes check` passes.

| File | Size | Use |
|---|---|---|
| `renders/quem-me-deve-whatsapp.mp4` | 720 x 1280, 10 MB | WhatsApp status |
| `renders/quem-me-deve-instagram.mp4` | 1080 x 1920, 9 MB | Instagram Reels and Stories |
| `renders/master-1080x1920.mp4` | 1080 x 1920 | The source for both |

**Sound:** -14.7 LUFS, true peak -2.1 dB, no sample over full scale. Not heard: it needs a person's ears. Same music as "O ponto", deliberately, so the films read as one family.

**Real and illustrated:** every phone screen is the real app with a local demo company, including the sent confirmation. The reminder really was sent through the app, to the local mail log only. The falling number is an illustration: its amounts are the demo company's real open debts, but no payments were recorded.

**Fixed after review:** the "before" list said a reminder had already been sent; the app header vanished when the tick appeared under a slow zoom, so that shot now holds still; tall captures were 9x and are now 3x; the dot was misplaced by a measuring error.

**Known and accepted:** `check` warns about the phone's status bar under the type floor (part of the device), and that the montage lines hold one beat each and the digit strips read as text. Century Gothic Bold must be installed to re-render; see the licence note in `../facturac-ao-teaser/REVIEW.md`.

**Rebuilding:** `python3 tools/build.py`, `npx hyperframes render --fps 30 -q delivery --crf 12 -o renders/raw.mp4`, `bash ../tools/master_audio.sh renders/raw.mp4 renders/master-1080x1920.mp4`, then `bash ../tools/export_social.sh renders/master-1080x1920.mp4 renders quem-me-deve`.
