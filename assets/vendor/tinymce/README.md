# Self-hosted TinyMCE (admin editor)

`admin/index.php` loads the rich-text editor from **`/assets/vendor/tinymce/tinymce.min.js`**
(same-origin) instead of a CDN, so it works under a strict `script-src 'self'`
Content-Security-Policy — no host/CSP change needed.

## What's here

**TinyMCE 8.8.0 (community / GPL), self-hosted and committed** — `tinymce.min.js`
plus `icons/ models/ plugins/ skins/ themes/ langs/`. TinyMCE auto-derives its
base URL from the script path, so it loads its skins, plugins and models from
these sibling folders automatically.

## Notes

- The editor is initialised in `admin/app.js` with `license_key: 'gpl'` and the
  `link lists image media table code autolink quickbars wordcount fullscreen`
  plugins (all present in `plugins/`).
- If these files are ever missing, `admin/app.js` degrades gracefully to a plain
  `<textarea>`, so posts can still be written and saved.
- Same-origin on purpose: loading from `/assets/vendor/tinymce/` passes a strict
  `script-src 'self'` CSP with no host/CDN dependency.
- To upgrade: download a newer self-hosted package from
  https://www.tiny.cloud/get-tiny/self-hosted/ and replace the contents of this
  folder with its `js/tinymce/` directory.
