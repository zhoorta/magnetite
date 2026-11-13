import goniometer from './goniometer';
import loudness from './loudness';
import magicEye from './magic-eye';
import { meterEntries } from './menu';
import posterArches from './poster-arches';
import posterColumns from './poster-columns';
import posterDials from './poster-dials';
import posterFacets from './poster-facets';
import posterFan from './poster-fan';
import posterLens from './poster-lens';
import posterLenses from './poster-lenses';
import posterOvercome from './poster-overcome';
import posterPillars from './poster-pillars';
import posterHalftone from './poster-halftone';
import posterHeads from './poster-heads';
import posterRings from './poster-rings';
import posterSlides from './poster-slides';
import posterWarp from './poster-warp';
import scope from './scope';
import spectrogram from './spectrogram';
import thirdOctave from './third-octave';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';
import vuWide from './vu-wide';

export const visualizers = Object.fromEntries([vuWide, vuNeedle, vuLeds, magicEye, scope, goniometer, loudness, thirdOctave, spectrogram, posterLens, posterWarp, posterLenses, posterArches, posterFan, posterOvercome, posterPillars, posterFacets, posterDials, posterColumns, posterSlides, posterRings, posterHalftone, posterHeads].map((v) => [v.id, v]));

/** What the meter menu lists (the scenes the person added come and go, so this is read when needed). */
export const meterList = () => meterEntries(visualizers);
