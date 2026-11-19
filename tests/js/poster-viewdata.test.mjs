import viewdata from '../../resources/js/magnetite/visualizers/poster-viewdata.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(viewdata, { id: 'poster-viewdata', needs: ['bands', 'levels'], prints: ['MARE CHEIA', 'ANA DUARTE', 'COMPUTER', 'KEYPAD'] });
