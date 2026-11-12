/* config.js — filled in by tools/build-recorder.php. In a source checkout loaded
 * unpacked, __SITE__ is still here: set it to your local site to develop. */
export const SITE = '__SITE__'.replace(/\/+$/, '');
export const API = SITE + '/integrations/recorder.php';
export const CONNECT_URL = SITE + '/portal/recorder.php';
export const SEGMENT_SECONDS = 600;     // a standalone file every ten minutes
export const SLICE_MS = 5000;           // a crash loses at most five seconds
export const AUDIO_BITS = 32000;        // Opus speech: ~14 MB an hour
export const SILENCE_STOP_MIN = 10;     // stop after this long with nobody speaking
