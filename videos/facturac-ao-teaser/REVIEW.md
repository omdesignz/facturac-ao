# Review: "O ponto"

Checked from the rendered MP4, not only snapshots: 1920 x 1080, 60 fps, 30.0 s, 25 MB. `hyperframes check` passes.

**Sound:** -14.2 LUFS, true peak -2.1 dB, no sample over full scale, measured. Not heard: the mix still needs a person's ears.

**Review passes**

| Pass | Found | Done |
|---|---|---|
| Sound | Whooshes peaked 0.5 s late; the riser peaked almost 3 s before the impact; 92% of the music's energy under 150 Hz, which plays as nothing on a phone | Every effect is placed by its measured peak, one frame after the picture. `assets/bgm/track-phone.mp3` is the track with less sub and more mid and treble, plus a little harmonic content so the bass line reads on small speakers (85% under 150 Hz here, 79% in the vertical) |
| Picture | "Paga." sat still for over a second; camera moves smeared too much; "Notas de crédito" could not be read in one beat; the certificate number left too soon; a plain cut into the end card | The dot bounces twice on the beat; shutter down to 120; "Clientes" replaces the long word; the number stays through both certificate lines; the final full stop leaps out of frame and the end card's dot falls in |
| Screens | A `.test` email on the Today screen, and "Boa noite" | Local demo customers now have `.ao` emails (no mail is sent). The greeting and clock on the two Today captures are changed in the capture script to "Bom dia" and 09:46 |
| Rendered file | The gold dot sat on the gold buttons and hid their labels; the waiting dot sat on the iPhone for seconds; the shots felt static during holds; the sign-up line was small | The dot lands, fades in 0.2 s and leaves a white ring, so it reads as a tap; the dot stays hidden until it falls; slow pushes during holds; sign-up line up to 64 px |
| Master | The mix came out at -18 LUFS; ffmpeg's AAC encoder put clicks of up to +5 dB in the exports | `../tools/master_audio.sh` sets -14 LUFS with a limiter, using Apple's AAC encoder |

Earlier, at the user's request: Century Gothic throughout, and SAF-T removed.

**Known and accepted**
- `check` warns about the phones' status bars being under the type floor: they are part of the device, not copy.
- "21 províncias" holds one beat, under its reading floor: one word group a beat is the montage's point.
- The fastest camera frames are streaked. They last about 0.1 s and read as motion.

**Font licence:** the film uses the Century Gothic Bold installed on the machine (`ufonts.com_century-gothic-bold.ttf`). Rendering needs it installed, and the licence for commercial video should be confirmed.

**Rebuilding:** `python3 tools/build.py`, `npx hyperframes render --fps 60 -q delivery --crf 12 -o renders/raw.mp4`, then `bash ../tools/master_audio.sh renders/raw.mp4 renders/o-ponto-1080p60.mp4`. Screens in `assets/screens` are the real app with a local demo company.
