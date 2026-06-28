# Illustrations

Brand illustrations (B/W ink portraits on a flat colour field). Drop the
files here using the names below and the pages pick them up automatically.

## Error pages (mapped by meaning)
| File                        | Used for            | Reference art                         |
|-----------------------------|---------------------|----------------------------------------|
| `error-404.png` (+ `.webp`) | 400, 404, 413       | Purple — shielding eyes, looking out   |
| `error-403.png` (+ `.webp`) | 401, 403, 405, 429  | Yellow/gold — hand up, "stop"          |
| `error-500.png` (+ `.webp`) | 500, 503            | Orange — crouching, examining          |

Each may also have a `@2x.webp` for retina/large screens (e.g.
`error-404@2x.webp`). The pages use `<picture>` with WebP + 2x and fall
back to the `.png`; if no file is present a styled placeholder shows.

## Optimisation
Recommended: export at 840×1120 (3:4). Run `php tools/optimize-illustrations.php`
to generate web-sized `.webp` (1x ≈ 480px wide) and `@2x.webp` (≈ 960px)
from any `*-src.(png|jpg)` originals dropped in this folder.

Academy illustrations (coming later) will live under `academy/illustrations/`.
