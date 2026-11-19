import junction from '../../resources/js/magnetite/visualizers/poster-junction.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(junction, { id: 'poster-junction', needs: ['bands', 'levels'], prints: ['Mare Cheia', 'Ana Duarte', /^FLAC/] });
