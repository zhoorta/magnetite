import bars from './bars';
import goniometer from './goniometer';
import loudness from './loudness';
import magicEye from './magic-eye';
import radial from './radial';
import scope from './scope';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';
import vuReceiver from './vu-receiver';
import vuWide from './vu-wide';

export const visualizers = Object.fromEntries([bars, radial, scope, vuNeedle, vuReceiver, vuWide, vuLeds, magicEye, goniometer, loudness].map((v) => [v.id, v]));
