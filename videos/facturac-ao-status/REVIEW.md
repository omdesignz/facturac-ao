# Review: "O ponto", vertical cut for WhatsApp and Instagram

Recomposed for a phone, not cropped from the landscape film: 1080 x 1920, 30 fps, 30.0 s, the app's real mobile screens, one whole phone at a time. Every word sits between y 190 and y 1200, clear of the apps' overlays.

| File | Size | Use |
|---|---|---|
| `renders/o-ponto-whatsapp.mp4` | 720 x 1280, 30 fps, 10 MB | WhatsApp status |
| `renders/o-ponto-instagram.mp4` | 1080 x 1920, 30 fps, 10 MB | Instagram Reels and Stories |
| `renders/master-1080x1920.mp4` | 1080 x 1920, 30 fps, 18 MB | The source for both; re-export with `../tools/export_social.sh` |

**Measured (VMAF, 100 is identical):**
- After an emulated WhatsApp re-encode: tuned file 99.8, raw master 96.6. The tuned file is lightly sharpened, and VMAF rewards sharpening, so read this as a comparison, not an absolute.
- Instagram file against the master, before Instagram's own re-encode: 97.8.
- Sound: -14.5 LUFS, true peak -1.6 dB, no sample over full scale. Not heard: it needs a person's ears.

**Not tested:** real uploads to WhatsApp and Instagram. Both re-encode on their servers, and the emulation only approximates them.

**Review passes:** the same ones as the landscape film, listed in `../facturac-ao-teaser/REVIEW.md`. Here the music has a stronger phone treatment (79% of its energy under 150 Hz, down from 92%), the titles sit 16 px higher to clear the slow push, and the certificate number moved below the longer second line.

**Known and accepted:** `check` warns about the phones' status bars being under the type floor (part of the device), and "21 províncias" holds a little under its reading floor. The fastest camera frames are streaked for about 0.1 s and read as motion. Century Gothic Bold must be installed to re-render; see the licence note in `../facturac-ao-teaser/REVIEW.md`.

**Rebuilding:** `python3 tools/build.py`, `npx hyperframes render --fps 30 -q delivery --crf 12 -o renders/raw.mp4`, `bash ../tools/master_audio.sh renders/raw.mp4 renders/master-1080x1920.mp4`, then `bash ../tools/export_social.sh renders/master-1080x1920.mp4 renders o-ponto`.
