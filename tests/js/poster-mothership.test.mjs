import mothership from '../../resources/js/magnetite/visualizers/poster-mothership.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(mothership, { id: 'poster-mothership', needs: ['bands'], prints: ['NOW PLAYING', /^ANA DUARTE - ILHAS \(2024\)$/, 'TRACK 03'] });
