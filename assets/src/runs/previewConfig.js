/**
 * Whether the preview pane renders the draft in an iframe.
 *
 * OFF by default. The sandboxed-iframe path (`sandbox="allow-scripts"`, no
 * `allow-same-origin`) is reasoned but not yet observed in a real site; see
 * docs/decisions/preview-pane-iframe.md. Flip this only after the wp-env
 * check listed there passes. Whatever its value, the iframe never carries
 * `allow-same-origin`: model-written content must not run script with
 * wp-admin's origin.
 */
export const PREVIEW_IFRAME_ENABLED = false;

/** The only sandbox tokens the pane may ever use. Never add `allow-same-origin`. */
export const PREVIEW_SANDBOX = 'allow-scripts';
