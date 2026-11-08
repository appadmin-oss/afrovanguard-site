# Reports — how a report gets onto /reports/

_Owner: Afrovanguard's Chief Servant, or whoever they name in writing.
Procedure written 2026-11-05; review it when the 2026 annual report is published._

The public page is **https://afrovanguard.org.ng/reports/**. It lists every
report in `lib/Reports.php` (`Reports::ROWS`), newest year first. Nothing on
the page is typed into the page itself; changing the list changes the page.

## What the page promises

| Status | Shown as | Use it when |
|---|---|---|
| `published` | Read online · Download, with size and page count | The report is finished. |
| `partial` | The same, with a "Partly complete" badge and a note | It is useful but sections are missing. The note must say which. |
| `preparing` | "In preparation", the expected date, "Email me when it's out" | It is not written yet. No file. |

`kind` is `annual` (the whole organisation), `project` (one project's results)
or `document` (a plan or proposal, e.g. the LCASP project document). A
`document` is never shown as the "Latest" report: a plan is not a result.

`php tests/run.php` refuses a list that breaks these rules: a partial report
with no note, a report in preparation with a file or no date, a link that is
not https, or a file the site does not serve.

## Adding a report from a past year (2017–2025)

1. **Shrink the PDF first.** These files run to several megabytes, and many
   readers are on mobile data. Export from the design tool at "screen" or
   "web" quality, or run it through a PDF compressor, and aim for under 10 MB.
   The page shows the size next to every link.
2. **Put the file where the site can serve it.**
   - *Up to about 10 MB:* add it under `assets/docs/` and register it in
     `lib/DocStream.php` (`DOCS`: file, download name, title, page count).
     The site then serves it with resumable downloads and a cache stamp.
   - *Larger:* upload it to the organisation's Google Drive, set sharing to
     "Anyone with the link can view", and use that https link as `url`.
3. **Add a row** to `Reports::ROWS` in `lib/Reports.php`:
   ```php
   ['year' => 2024, 'title' => 'Afrovanguard Annual Report 2024', 'kind' => 'annual',
    'status' => 'published', 'doc' => 'annual-report-2024'],
   ```
   An incomplete one: `'status' => 'partial', 'note' => 'The finance section is still being audited.'`
4. Run `php tools/stamp-assets.php` and `php tests/run.php`, then commit and deploy.

## The 2026 annual report

It is already on the page as **In preparation, expected later in 2026**.

| When | Do |
|---|---|
| Now | Nothing. Readers who press "Email me when it's out" send a request to cacentre@afrovanguard.org.ng; keep those emails, they are the list to notify. |
| If the date moves | Change `'expected'` in its row (e.g. `'in January 2027'`). The page reads "Expected …". |
| When it is finished | Follow steps 1–4 above, and change its row to `'status' => 'published'` with its `doc` or `url`. Then reply to everyone who asked. |
| If only part is ready | Publish it as `partial` with a note saying what is still to come, rather than holding everything back. |

## Why it matters

Funders (Cisco among them) weigh whether an organisation "reports
transparently". A reader who finds the number on the home page and then the
report it came from trusts both. Keep every headline figure on the site
traceable to a report listed here.
