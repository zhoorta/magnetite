import cassette from './cassette';
import goniometer from './goniometer';
import loudness from './loudness';
import magicEye from './magic-eye';
import scope from './scope';
import spectrogram from './spectrogram';
import { tests as sceneTests } from './scene';
import thirdOctave from './third-octave';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';
import vuWide from './vu-wide';

export const visualizers = Object.fromEntries([vuWide, cassette, vuNeedle, vuLeds, magicEye, scope, goniometer, loudness, thirdOctave, spectrogram, ...sceneTests].map((v) => [v.id, v]));
