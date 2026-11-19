import swatches from '../../resources/js/magnetite/visualizers/poster-swatches.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(swatches, { id: 'poster-swatches', needs: ['bands'], prints: ['MARE', 'CHEIA', '2024', /^#[0-9a-f]{6}$/, 'NOW'] });
