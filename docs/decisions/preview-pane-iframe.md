# Preview pane: can a draft render in a sandboxed iframe?

Date: 2026-10-07. Stage 0.4 S3. Status: **reasoned, not tested.** No browser site was
available, so nothing below was observed in a running WordPress.

## Question

Model-written content must not run script with wp-admin's origin. Can the pane show a draft
preview in `<iframe sandbox="allow-scripts">` (opaque origin, no `allow-same-origin`) and still
render it for the logged-in author?

## Rule

Never ship `allow-scripts` together with `allow-same-origin`. With both, the framed page can
remove its own sandbox. The pane's source is checked for the string in a Jest test, and the only
sandbox value comes from one constant (`PREVIEW_SANDBOX`).

## Reasoning (not observed)

- A draft preview is a front-end URL (`get_preview_post_link()`: `?p=ID&preview=true`, plus
  `preview_id` and `preview_nonce` for autosave previews). WordPress authorises it from the login
  cookie (`edit_post` on the previewed post), not from the URL alone.
- The document request for an iframe `src` is a navigation made from the wp-admin page, so
  browsers treat it as same-site and send the auth cookies, whatever the sandbox flags say.
  The sandbox changes the framed document's origin after it loads, not the navigation request.
  This is the part most likely to hold.
- Subresources the framed page requests (theme CSS, scripts, images) come from an opaque origin.
  Browsers may count those as cross-site and withhold SameSite=Lax cookies. Public theme assets
  don't need cookies. Anything private on the page would.
- Inside the framed document, `document.cookie`, `localStorage` and same-origin `fetch` to the
  REST API are blocked or fail (opaque origin). A theme or plugin that touches them without a
  guard may break or blank the page. This is the likely failure.
- WordPress sends `X-Frame-Options: SAMEORIGIN` on wp-admin and login pages, not on front-end
  pages. A security plugin or host that adds `frame-ancestors` or `X-Frame-Options` to the front
  end would block the frame outright.
- Links in the framed page can't navigate the top window (no `allow-top-navigation`) or open
  popups (no `allow-popups`). Acceptable for a preview.

## Conclusion

Not shown to work without a live test, so the pane ships the safe fallback:

- the result card (title, status, Edit and Preview links), plus
- "Open preview in a new tab". That has the same exposure as WordPress's own Preview button.

The iframe path exists behind `PREVIEW_IFRAME_ENABLED` in `assets/src/runs/previewConfig.js`,
default `false`. When on, it renders `sandbox="allow-scripts"` and `referrerpolicy="no-referrer"`.

## wp-env check needed before turning it on

1. Create a draft with a theme that uses scripts, open the Runs screen as its author, and enable
   the constant in a local build. Observe: does the draft render, with the theme's CSS?
2. Browser console: any blocked cookie/localStorage access errors from the framed page? Does the
   page still look right?
3. Repeat with a draft that has a newer autosave (preview nonce in the URL) and with a product.
4. Confirm in devtools that the frame's sandbox attribute is exactly `allow-scripts`.
5. Check response headers on the front-end preview for `X-Frame-Options` / `frame-ancestors`.
