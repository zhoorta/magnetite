import bars from './bars';
import radial from './radial';
import scope from './scope';
import vuLeds from './vu-leds';
import vuNeedle from './vu-needle';

export const visualizers = Object.fromEntries([bars, radial, scope, vuNeedle, vuLeds].map((v) => [v.id, v]));
