# Sign-in illustrations (drop-zone)

The standalone sign-in page (`/login`) shows ONE illustration in its left
column, chosen **per browser session** (mirrors the Afrostrength pattern).
`av_auth_illustration()` in `lib/partials.php` globs this folder for `*.webp`,
sorts them, and picks one by day-of-year — stable within a visit, varied over
time. With no files here the page falls back to the brand gradient alone
(never broken).

## What to add

Drop the sign-in illustrations here as **WebP**. Any filenames work — they are
sorted alphabetically — but `auth-1.webp … auth-4.webp` is tidy:

```
assets/illustrations/auth/auth-1.webp
assets/illustrations/auth/auth-2.webp
assets/illustrations/auth/auth-3.webp
assets/illustrations/auth/auth-4.webp
```

- **Format:** `.webp` (the page only globs `*.webp`).
- **Orientation:** portrait — the aside is a tall column. The art is centred
  near the top (`object-position: 50% 22%`), so keep the subject's head/upper
  body in the top third.
- **Size:** ~1000×1500 or larger; a dark scrim is layered on top for legibility,
  so the bottom of the image can be busy.

No code change is needed — add the files, commit, and they appear automatically.
