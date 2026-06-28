# Diary export

The Diary can be downloaded/printed in several formats via `diary/export.php`
(rendered by `lib/DiaryExport.php`):

| `?format=` | Output | Delivery |
|---|---|---|
| `book` (default) | **The Journal** — a print-ready book: title page, contents, chaptered with roman numerals, drop caps, justified serif, page breaks. *Print → Save as PDF* for a bound copy. | inline HTML |
| `html` | A clean standalone reader (also printable). | inline HTML |
| `md`   | Markdown (headings/bold/links/lists converted from the stored HTML). | download |
| `json` | Structured JSON (`html` + derived plain `text` per entry). | download |

## Scope

- **Public Diary** (default): published articles only, oldest→newest for `book`.
  Linked from the Diary's subscribe band.
- **A member's own journal** (`?scope=mine`): the signed-in member's own
  `diary_entries` (incl. private), linked from `/diary/me`. Requires login.

Per-IP rate limited (30 / 10 min). No external assets — the HTML/book pages are
fully self-contained, so the browser's Print-to-PDF produces a clean file.
