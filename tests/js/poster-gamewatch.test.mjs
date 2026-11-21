import gamewatch from '../../resources/js/magnetite/visualizers/poster-gamewatch.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(gamewatch, { id: 'poster-gamewatch', needs: ['bands', 'levels'], prints: ['MARE CHEIA', 'Mare Cheia', 'Ana Duarte', 'ILHAS', 'PLAY', 'GAME A'] });
