import camera from '../../resources/js/magnetite/visualizers/poster-camera.js';
import { posterTests } from './poster-smoke.mjs';

posterTests(camera, { id: 'poster-camera', needs: ['bands', 'levels'], prints: ['MARE CHEIA', /^FLAC/, /ANA DUARTE/] });
