import stacks from '../../resources/js/magnetite/visualizers/poster-stacks.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(stacks, { id: 'poster-stacks', needs: ['bands', 'levels'], prints: ['Mare Cheia', /^FLAC/, /Ana Duarte/] });
