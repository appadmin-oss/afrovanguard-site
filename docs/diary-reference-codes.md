# Diary reference codes

Every Diary entry carries a short, permanent identifier:

```
AVD-2608-0003
 │    │    └── serial within that month
 │    └─────── year and month of publication (August 2026)
 └──────────── Afrovanguard Diary
```

`AVD-2608-0003` is the third Diary entry published in August 2026.

## Why it exists

A slug is not an identifier. It gets rewritten for SEO, shortened, or corrected
after a typo — and the URL somebody wrote on a printout, quoted in a report, or
read out in a meeting stops resolving. A title is worse: it changes for editorial
reasons all the time.

A reference code is assigned **once, when the entry is created**, and never
changes. Rewrite the slug, the title, the category, the byline and the
publication date, and the code still points at the same entry.

## What it does not change for

Pinned in `tests/refcodes.test.php`, because the moment a code can change it
stops being an identifier and every place it was written down is wrong:

| Change | Code |
|---|---|
| Title corrected | unchanged |
| Publication date moved (even into another month) | unchanged |
| Category, byline, gradient, cover, format changed | unchanged |
| Slug renamed | unchanged — and the entry is renamed, not duplicated |
| Entry deleted and a new one created | a new code; codes are never reused |

The month in the code comes from the publication date **at the time of
creation**. Moving an August entry to December leaves it `AVD-2608-…`, which is
correct: the code records which entry it is, not when it currently claims to have
been published.

## Where to find and use one

| Surface | Behaviour |
|---|---|
| Studio → Diary entries | Shown on every row. The **Find** box matches by name, code, slug or category. |
| Studio → the editor | Shown in the sidebar for the entry being edited. |
| The article page | Shown in the meta block under **Reference**, selectable so it can be copied. |
| Site search (the search modal) | Searching a code returns that entry **first** — a code is an exact identifier, so it does not compete on keyword relevance. Searching a name works as before. |
| The Diary filter box | Typing a code narrows the list to that entry. |
| `/diary/AVD-2608-0003` | 301-redirects to the entry's canonical URL. A permanent short link that survives a slug rename. |

Codes are read the way a human will type them — any case, with or without the
`AVD` prefix, and with dashes, spaces or nothing between the groups:

```
AVD-2608-0003   avd-2608-0003   avd 2608 0003   AVD26080003   2608-0003   26080003
```

## Implementation notes

- **Storage:** `articles.ref_code VARCHAR(32)`, nullable, with a **unique**
  index. Nullable rather than `NOT NULL DEFAULT ''` because the index is unique
  and an empty string is a real value — two unassigned rows would collide. All
  three supported engines allow repeated NULLs in a unique index.
- **Assignment:** `DiaryRepository::save()` assigns on insert only, inside the
  transaction. The unique index is the real guarantee, not the generator.
- **Backfill is self-healing.** `refCodesEnabled()` probes for rows without a
  code and fills them, oldest first, so serials read chronologically within each
  month. It is a probe rather than a one-time flag on purpose: entries that
  arrive by a route that never calls `save()` — the installer's seed, a direct
  import, a hand-written `INSERT` — get codes too, instead of being permanently
  missed because a flag was already set.
- **Un-migrated engines degrade.** Card queries select `ref_code` only when the
  column exists, so a MySQL/Postgres target that has not run `db/migrate.php`
  keeps serving the Diary instead of failing every query.
- **The code is the update key.** `save()` matches on `ref_code` when the caller
  supplies one, falling back to the slug. This is what makes a slug rename a
  rename: previously the slug was the only key, so editing it silently created a
  second entry and left the original behind. Renaming onto a slug another entry
  already owns is refused with a reason (HTTP 422), not silently merged.

## Adding codes to something else

The generator is deliberately not shared yet — Academy courses would want their
own prefix (`AVA`), and a shared helper written for one caller usually fits the
second one badly. If courses need codes, lift `refMonth()` / `formatRef()` /
`normaliseRefCode()` out of `DiaryRepository` and parameterise the prefix at that
point, with the same unique index and the same self-healing backfill.
