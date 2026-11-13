import goniometer from './goniometer';
import loudness from './loudness';
import magicEye from './magic-eye';
import { meterEntries } from './menu';
import scope from './scope';
import spectrogram from './spectrogram';
import thirdOctave from './third-octave';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';
import vuWide from './vu-wide';

export const visualizers = Object.fromEntries([vuWide, vuNeedle, vuLeds, magicEye, scope, goniometer, loudness, thirdOctave, spectrogram].map((v) => [v.id, v]));

/** What the meter menu lists (the scenes the person added come and go, so this is read when needed). */
export const meterList = () => meterEntries(visualizers);
