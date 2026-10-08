import bars from './bars';
import magicEye from './magic-eye';
import radial from './radial';
import scope from './scope';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';
import vuReceiver from './vu-receiver';
import vuWide from './vu-wide';

export const visualizers = Object.fromEntries([bars, radial, scope, vuNeedle, vuReceiver, vuWide, vuLeds, magicEye].map((v) => [v.id, v]));
