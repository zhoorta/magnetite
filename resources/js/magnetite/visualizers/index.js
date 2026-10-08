import bars from './bars';
import radial from './radial';
import scope from './scope';

export const visualizers = Object.fromEntries([bars, radial, scope].map((v) => [v.id, v]));
