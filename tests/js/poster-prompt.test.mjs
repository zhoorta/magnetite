import prompt from '../../resources/js/magnetite/visualizers/poster-prompt.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(prompt, { id: 'poster-prompt', needs: ['bands', 'levels'], prints: ['WIEDERGABE', 'IHRE WAHL ?', /^ILHAS \(2024\)$/, /^ANA DUARTE$/, /^PEGEL LINKS/, /^FORMAT +<FLAC>$/], shapesAreText: true });
