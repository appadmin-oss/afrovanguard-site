# Self-hosted TinyMCE (admin editor)

`admin/index.php` loads the rich-text editor from **`/assets/vendor/tinymce/tinymce.min.js`**
(same-origin) instead of a CDN, so it works under a strict `script-src 'self'`
Content-Security-Policy — no host/CSP change needed.

## How to install

1. Download the **TinyMCE 7 self-hosted (community / GPL) package**:
   - https://www.tiny.cloud/get-tiny/self-hosted/ , or
   - the npm tarball: `npm pack tinymce@7` → extract the `package/` folder.
2. Upload/extract it here so this folder ends up containing at least:
   ```
   assets/vendor/tinymce/
     tinymce.min.js
     icons/
     models/
     plugins/
     skins/
     themes/
   ```
   TinyMCE auto-derives its base URL from the script path, so once
   `tinymce.min.js` is at this location it loads its skins, plugins and models
   from the sibling folders automatically.
3. Hard-refresh the admin page — the toolbar returns.

## Notes

- The editor is initialised in `admin/app.js` with `license_key: 'gpl'` and the
  `link lists image media table code autolink quickbars wordcount fullscreen`
  plugins — make sure those plugin folders are present in `plugins/`.
- If this package is absent, `admin/app.js` degrades gracefully to a plain
  `<textarea>`, so posts can still be written and saved.
- The package files are intentionally **not committed** to the repo (they're a
  large third-party vendor drop); upload them on the server.
