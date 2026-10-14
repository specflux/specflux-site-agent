=== Specflux Site Agent ===
Contributors: specflux, stephen1204paul
Tags: ai, agents, automation, safety, approvals
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT or Cursor to WordPress, or run an agent inside wp-admin. Changes wait for your approval by default.

== Description ==

Specflux Site Agent gives an AI agent two ways to work on your WordPress site:

* **Connect an AI app.** Claude, ChatGPT, Claude Code, Cursor, VS Code and other MCP clients
  connect to your site through Specflux Site Agent's MCP server. The app brings its own model, so you don't
  need an API key.
* **Run the agent inside wp-admin.** Type a goal on the Specflux Site Agent screen and Specflux Site Agent drives a
  multi-step run with the AI provider your site has connected.

Either way, every tool is a WordPress Ability, and anything that changes the site waits for a
person to approve it first, unless you install Belayline Agent Governance and set your own rules.

**Connecting AI apps**

* **Off until you turn it on.** Switch on "Allow AI apps to connect" under Specflux Site Agent → Settings.
  The connector runs on WordPress's own [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)
  plugin (version 0.7.0 or newer), which you install separately.
* **One screen per app.** Specflux Site Agent → Connect an AI app has a card for each client. For Claude
  Code, Cursor, VS Code and Claude Desktop, it creates an application password and prints the
  exact config to paste, with a Test connection button. For Claude (web, desktop and mobile) and
  ChatGPT, you add the server URL in the app and sign in to WordPress; Specflux Site Agent is the OAuth 2.1
  server for that sign-in.
* **Read only unless you say otherwise.** Each connection is Read only or Read and write. A
  connection can never do more than the WordPress user who created it.
* **Writes wait for you.** Without Belayline Agent Governance, a read runs straight away. A write is held, the app
  is told to ask you, and you approve it on the Specflux Site Agent screen's From AI apps tab. The app
  then repeats the call and it runs once. An approval covers that exact call for 15 minutes.
  With Belayline Agent Governance active, its rules, audit log and undo apply instead.
* **An activity list** shows the last 30 days of calls per connection: when, which tool, and
  whether it ran, waited, was refused or failed. Arguments and results aren't stored.
* **Rate limited** to 60 calls a minute per connection, and revocable from the same screen.
* **What apps can use:** posts and pages (list, create, edit, publish, trash and restore,
  revisions, registered post meta), comments (list, reply, moderate), users (list and read; role
  changes are the highest tier), general, reading and discussion settings, menus, read-only
  lists of plugins and themes, media, and WooCommerce, FluentCart or SureCart store tools when
  those are active (store writes need Belayline Agent Governance). Trashing a post or page waits for approval,
  because it takes it off the site. You can also switch on up to 40 abilities registered by
  other plugins.
* **Runs inside wp-admin keep a smaller tool set.** The post listing, trash, revision, meta,
  comment, user, settings, menu and plugin/theme tools are for connected apps only, so runs
  stay fast.

**Runs inside wp-admin**

Specflux Site Agent runs an agent loop on behalf of a logged-in user: one goal, many
model turns, many tool calls — pausing for a human whenever a write needs
approval, whenever the model needs to ask a clarifying question, or before
its first side-effecting write — and resuming exactly where it left off.

* **Runs are session-bound.** A run acts as the user who started it; nothing
  runs in the background.
* **Tools are WordPress Abilities**, restricted per run to an allow-list
  supplied directly by the consumer, or derived from a registered **pack**
  (a named tool surface — pages, posts, site, or commerce, all bundled).
* **Two gate modes, resolved once per run.** If the **Belayline Agent Governance** plugin
  is active, every call is governed by its gate (packs, tiers, approvals)
  and audit trail. Without it, Specflux Site Agent falls back to a **built-in minimal
  gate**: every call that changes the site still stops for a person to
  approve it on Specflux Site Agent's own Runs screen before it runs; reads do not.
  A run's mode is pinned at start and never changes underneath it — if the
  environment's mode changes mid-run, the run fails cleanly instead of
  silently switching enforcement.
* **Three kinds of park.** An **approval park** (a governed write, resolved
  by a person), a **question park** (the model asks the user one structured
  question), and a **plan park** (the model proposes a numbered plan before
  its first write; once accepted, that plan's tool set is a fence — calls
  outside it are refused until a new plan is accepted).
* **Budgets bound every run.** Steps, tool calls, tokens, questions and
  plans. A consumer may only lower a registered ceiling; running out of
  questions is not a failure — the model loses the ability to ask and
  proceeds on stated assumptions.
* **Four bundled packs.** Pages and posts (create/update/publish, with
  publish handled as its own governed step), site (navigation, front page,
  homepage patterns), and commerce (WooCommerce, FluentCart or SureCart
  catalogue and order operations, when one of them is active). Every content write is validated
  before anything is persisted: unrenderable or non-round-tripping markup,
  blocks outside the allowed set, disallowed tags or attributes, unresolved
  `{{placeholder}}` text, an unrecognised pattern shape, a slot count out of
  range, a page-shape rule (e.g. hero must come first), or a slug/title
  collision with an existing page or post — all refuse the whole write and
  persist nothing.
* **Results you can read.** Each tool result shows as a card (a post with its Edit and Preview
  links, a product with price and stock, a list with its count) instead of raw JSON, with a
  preview pane beside the chat. Approval cards show what will change, current value against
  proposed.
* **Changes and undo.** The run report lists what the run changed. With Belayline Agent Governance 0.5 or newer,
  you can undo one change or the whole run from there; without it, changed posts link to
  WordPress's own revisions.
* **Guided setup.** The Specflux Site Agent screen walks you through connecting a model, choosing how
  changes are approved, and, with Belayline Agent Governance, turning Specflux Site Agent on for your role.
* **Not for multisite.** Specflux Site Agent refuses to activate on a WordPress
  multisite install.

== Installation ==

1. Install and activate Specflux Site Agent. It works standalone with a built-in
   approval gate.
2. To connect AI apps, install and activate the **MCP Adapter** plugin, switch on "Allow AI apps
   to connect" under Specflux Site Agent → Settings, then follow Specflux Site Agent → Connect an AI app.
3. To run the agent inside wp-admin, connect an AI provider under Settings → Connectors, then
   follow the setup steps on the Specflux Site Agent screen.
4. Optionally install and activate **Belayline Agent Governance** for its full audit
   trail, tiered approvals, and cross-plugin governance — Specflux Site Agent detects
   it automatically and switches gate modes on the next run started after
   activation.
5. Consumers (e.g. marketing-analytics-chat) detect `specflux_site_agent()` and offer
   multi-step runs automatically.

== Frequently Asked Questions ==

= Can the AI add scripts or code to my site? =

No. Specflux Site Agent never writes PHP, JavaScript or CSS files and never runs code
the model produces. A run only writes content: pages, posts, navigation,
products and store reports. Every piece of markup it writes is checked
against a fixed list of allowed blocks, tags and attributes before saving,
and anything outside that list (a `<script>`, an inline event handler, an
unknown block) refuses the whole write. Specflux Site Agent does this itself rather
than relying on WordPress's HTML filtering, because administrators are
allowed unfiltered HTML.

= Do I need an AI API key? =

Not to connect an AI app: Claude, ChatGPT or Cursor uses its own model and your subscription to
it. Runs inside wp-admin do need a provider connected under Settings → Connectors.

= Which AI apps can connect? =

Any MCP client that speaks the streamable HTTP transport. The Connect screen has step-by-step
cards for Claude (web, desktop and mobile), ChatGPT, Claude Code, Cursor, VS Code and Claude
Desktop, plus the bare server URL for anything else.

= Does it work without Belayline Agent Governance? =

Yes. Belayline Agent Governance is optional, not required. If it isn't active, Specflux Site Agent
uses its own built-in minimal gate: every call that changes the site still
stops for a person to approve it on Specflux Site Agent's Runs screen before it runs;
read-only calls do not. Installing Belayline Agent Governance later adds its full audit
trail, tiered approval thresholds, and governance shared across other
plugins — new runs pick it up automatically.

= Does a run keep going while I'm not looking? =

No. Runs are session-bound and driven from the browser; nothing executes in
the background. A parked run simply waits until someone with permission
resolves it.

= What happens to a run in progress when I upgrade from 0.2? =

A run that was still parked or running at the moment of the upgrade fails
on its next tick with a partial report and the reason "started under 0.2" —
0.2's `update-post` tier changed under it, so it cannot safely continue.
Completed 0.2 runs still render their full history and report. Nothing is
silently re-tiered or auto-resumed.

= Which post statuses can create-post and update-post write? =

`create-post` never publishes — it refuses a `publish` or `future` status
outright and only ever creates a draft. `update-post` covers draft-state
edits only; any transition to `publish`/`future`, or any edit to an
already-public post, goes through the separate `publish-post` ability,
which is a higher-tier, separately governed call.

= Will pages use my theme's design? =

On block themes, yes. Specflux Site Agent builds each section from one of your theme's
own patterns when one fits the content, and otherwise uses its own layouts
with your theme's spacing and colours. It never adds colours of its own to a
theme pattern. To use only Specflux Site Agent's layouts, untick "Use my theme's block
patterns" under Specflux Site Agent → Settings. Developers can drop individual patterns
with the `specflux_site_agent_theme_patterns` filter.

= Does Specflux Site Agent support WordPress multisite? =

No. Specflux Site Agent refuses to activate on a multisite install.

= Is it safe to let an AI app connect? =

The connector is off by default, every connection starts read-only, and writes wait for your
approval. Each connection uses its own credential, so you can revoke one app without touching
the others. Sign-in tokens are stored hashed, expire after an hour, and only work on Specflux Site Agent's
MCP endpoint, not the rest of your site. What an app reads is sent to that app and its model
provider, so connect only apps you trust with that content.

== External services ==

Specflux Site Agent itself makes the outbound requests below. The ones for runs happen only while a
human is actively driving a run from their own browser session — Specflux Site Agent never runs in the
background.

**Connected AI apps.** When you connect an AI app, the app calls your site; Specflux Site Agent doesn't
call the app. Whatever its tools read (posts, comments, settings, store data) goes to that app
and, from there, to its model provider, under that app's own terms. For example, Anthropic's
(https://www.anthropic.com/legal/consumer-terms, https://www.anthropic.com/legal/privacy) and
OpenAI's (https://openai.com/policies/row-terms-of-use/,
https://openai.com/policies/row-privacy-policy/).

**App identity documents (OAuth sign-in).** Some AI apps identify themselves during sign-in
with a URL to a small JSON document describing the app (an OAuth client ID metadata document).
Specflux Site Agent fetches that document from the app's own server to show you the app's name and check
where to send you back after you approve. The request carries no site data and no credentials;
the result is cached for an hour, and addresses on private networks are refused.

**Test connection.** The Connect screen's Test connection button sends one request from your
site to its own MCP endpoint. Nothing leaves your server.

**Model calls (AI Client).** Made through the WordPress AI Client (bundled
with WordPress 7.0+), using whichever provider your site has connected
under Settings → Connectors — commonly OpenAI, but any provider the AI
Client supports. On every model turn (at most one per tick), Specflux Site Agent
sends that provider:

* the run's full conversation history so far (the goal, every model
  message, and every tool result already produced in the run);
* a system instruction assembled from the harness's own operating rules,
  the active pack's skills (e.g. a pack's block-pattern vocabulary and
  copy constraints), and anything the consumer or `specflux_site_agent_system_instruction`
  filter contributes;
* the declared tool (Ability) schemas the run is allowed to call, and the
  results those tools return, which are fed back to the model on the next
  turn (capped at 32 KB per result by default, filterable via
  `specflux_site_agent_tool_result_max_bytes`).

No file, database or site content is sent beyond what a run's own tool
calls read and return as results. Sending and handling this data is
governed by the connected provider's own terms. The site owner chooses the
AI provider, so that provider's terms and privacy policy apply; see, for
example, OpenAI's:
https://openai.com/policies/row-terms-of-use/ and
https://openai.com/policies/row-privacy-policy/ — consult your specific
provider's terms if you have connected a different one.

**Stock photo search (Openverse).** When a run searches for a stock image
(pages/site packs), Specflux Site Agent sends the model-generated search text as a
query string to Openverse's public search API
(`https://api.openverse.org/v1/images/`) and to Openverse's individual
image-detail endpoint when fetching a chosen result. No account
credentials, site content, or personal data are sent — only the search
terms and the id of a selected result. This runs only during an active,
human-driven run. See Openverse's terms and privacy policy:
https://docs.openverse.org/terms_of_service.html and
https://openverse.org/privacy (Openverse is a WordPress.org project;
its privacy policy is https://wordpress.org/about/privacy/).

**Image downloads.** When a run imports a chosen stock photo or a generated
image, the plugin downloads the image file from the URL Openverse or the AI
provider returned (Openverse results are hosted by third-party sites such
as Flickr or Wikimedia Commons; an AI provider may instead return the image
data inline, in which case nothing is downloaded). Only the image URL is
requested, with no site data, and the file is then saved to your Media
Library.

== Privacy ==

Specflux Site Agent stores each run in two custom tables: the ID of the user who
started it, the goal they typed, the conversation with the AI model, the
tool calls and their results, and timestamps. Runs stay until you remove
them or delete the plugin with the opt-in below.

Runs are sent off-site to the AI provider you connected, and search terms
are sent to Openverse; see "External services" above for exactly what.

When the connector is on, Specflux Site Agent also stores:

* the connections each user creates (in their user meta), and the application passwords
  behind them, through WordPress core;
* signed-in apps and their tokens, hashed, in options; access tokens last an hour and refresh
  tokens 30 days;
* an activity row per AI app call (time, connection, user, tool, outcome) in a third custom
  table, deleted after 30 days;
* calls waiting for approval, with their arguments, until they're approved, used or expire.

Specflux Site Agent adds suggested text for your privacy policy under Settings →
Privacy → Policy Guide. It does not register a personal-data exporter or
eraser.

Deleting the plugin keeps your runs by default. To remove all Specflux Site Agent
data (all three tables, its options, transients, per-user flags and connector data) when the
plugin is deleted, opt in first: `wp option update specflux_site_agent_uninstall_delete_data 1`,
then delete the plugin.

== Developers ==

= Extension API =

`SPECFLUX_SITE_AGENT_API_VERSION` (currently 0.4.0, defined in specflux-site-agent.php) versions the declared
extension surface, independently of this plugin's own Stable tag. Semver promise: a removal
or signature change needs a major bump; an addition needs a minor bump — this applies starting
at 0.3.0, even below 1.0.

The `@api` list: the `Pack` abstract class's `@api`-tagged methods (name, roles,
abilityNamespaces, inputProperties, verbFor, objectIdKey/Prefix/ForWrite/ForRead,
roleCapabilities, withheldRoleNotice, verbMap, roleVerbs, ungrantableVerbs, governedNamespaces,
agentSafetyVerbMap, defaultBudget, skills, agentSafetyPack, validateCall, setupChecks,
runCapability, requiresAgentSafety, agentSafetyBindingError, guidesHash); the
`Api\LayoutVocabulary` facade (names, sectionSchema, validate, imageUrls, rulesLines); the
Skill/SkillSource/SetupCheck value types a pack constructs; the `Present\Presenter` interface;
and the `specflux_site_agent_packs`, `specflux_site_agent_run_skills`, `specflux_site_agent_result_presenters` and
`specflux_site_agent_default_budget` filters. Every other filter, including
`specflux_site_agent_can_tick` and `specflux_site_agent_http_consumers`, is not part of this surface and may change
without a version bump.

REST (`specflux-site-agent/v1`) is the public `@api` consumer surface; admin-ajax is this plugin's own private transport for its
bundled Runs screen and is not guaranteed to match REST's shape.

Deprecation: a break goes through `_deprecated_hook()`/`_deprecated_function()` for at least
one minor release before removal at the next major. None exist yet.

See the "Extension API" section of README.md in the public repository,
https://github.com/specflux/specflux-site-agent, for the full reference, including how to
regenerate the reflection snapshot (tests/Api/public-surface.json there) that
enforces this.

= Source code and build =

The JavaScript in `build/` is compiled from the sources in `assets/src/` in
the public repository, https://github.com/specflux/specflux-site-agent. To rebuild it:

1. `git clone https://github.com/specflux/specflux-site-agent && cd specflux-site-agent`
2. `npm ci`
3. `npm run build`

`npm run build` runs `wp-scripts build` and writes `build/`.

== Changelog ==

= 0.4.0 =

New:

* Connect AI apps over MCP. Specflux Site Agent runs its own MCP server on WordPress's MCP Adapter, off
  until you turn it on, with a Connect screen for Claude, ChatGPT, Claude Code, Cursor, VS Code
  and Claude Desktop.
* An OAuth 2.1 server for apps that sign in (Claude and ChatGPT): discovery, dynamic client
  registration, client ID metadata documents, PKCE (S256 only), hashed tokens with refresh
  rotation, and a consent screen with a read-only or read-and-write choice.
* Per-connection scope (Read only by default), a 30-day activity list, 60 calls a minute per
  connection, and revoke.
* Without Belayline Agent Governance, writes from AI apps wait for approval on the From AI apps tab and
  run once when the app repeats the call.
* New tools for connected AI apps: list, trash and restore posts, revisions, registered post
  meta, comments, users and roles, site settings, menus, and read-only plugin and theme lists.
  Trashing a post or page needs approval. In-admin runs don't get these tools.
* Abilities from other plugins: switch on up to 40 under Specflux Site Agent → Settings. They're off by
  default and governed like Specflux Site Agent's own.
* Result cards instead of raw JSON, a preview pane beside the chat, and current-versus-proposed
  values on approval cards.
* The run report lists what a run changed, with undo when Belayline Agent Governance 0.5 or newer is active.
* A run waits, instead of failing, when Belayline Agent Governance pauses agent access or a rate limit is hit.
* A guided setup panel on the Specflux Site Agent screen, and a combined Belayline Agent Governance content pack so one role
  binding covers pages, posts and site.
* FluentCart and SureCart support in the commerce pack.

Fixed:

* Everything in 0.3.3.

= 0.3.3 =

Changed:

* New name: Specflux Site Agent. Same features; new slug, text domain and identifiers.

= 0.3.2 =

Fixed:

* The bundled image patterns no longer reference a remote placeholder image; they use a small image shipped with the plugin.
* Media search and the missing-alt-text list now need the upload_files capability and only show images the current user may read or edit. Saving a store report, setting a featured or product image, and enabling a coupon check the matching post type and attachment capabilities too.
* A question the assistant asks is no longer cut off mid-word at 300 characters.
* A plan step now shows one plain-language badge (Read only, Makes changes, Needs approval) with the actions it will use, instead of the same badge once per action.
* A blank assistant turn no longer shows as an empty chat bubble, and a run still working in the background no longer greys out the Accept and Approve buttons on another run.

= 0.3.1 =

Fixed:

* Sanitize the decoded JSON bodies of the admin-ajax start (budget) and tick
  (park resolution) requests: answer text, choices and plan notes go through
  sanitize_textarea_field(), budget caps are kept only as non-negative
  integers.
* Point the Openverse terms-of-service link at its current location.

= 0.3.0 =

New:

* Two gate modes: Belayline Agent Governance when active, otherwise a built-in minimal
  gate that still pauses every governed write for human approval on the
  Runs screen. A run's mode is pinned at start.
* Three new packs alongside pages: posts, site (navigation, front page,
  homepage patterns), and commerce (WooCommerce catalogue/order
  operations).
* Stock photo search and alt-text/image-generation abilities via the
  WordPress AI Client and Openverse.
* The Runs screen moved out of Tools to its own top-level "Specflux Site Agent" menu
  and is now a React app (`assets/src/runs/`, built with
  `@wordpress/scripts`); `tools.php?page=specflux-site-agent-runs` redirects there.
* Extension API: `SPECFLUX_SITE_AGENT_API_VERSION`, a reflection-enforced `@api`
  surface for third-party packs (see "Extension API" above).
* Pages follow your theme. Each page layout (hero, text, text with image,
  services, FAQ, call to action) is built from the active theme's own
  block patterns where one fits, filled with the run's copy. Twenty
  Twenty-Five and Ollie have built-in mappings; other block themes are
  matched automatically, and anything that doesn't fit falls back to
  Specflux Site Agent's own layouts, using the theme's spacing and colours.
* Section tone: a section can sit on a contrast or accent band taken from
  the theme palette, only where the text keeps WCAG AA contrast.
* A Settings checkbox, "Use my theme's block patterns", to build every page
  from Specflux Site Agent's own layouts instead.

**Breaking changes from 0.2:**

* `specflux/update-post` is now draft-state edits only (Tier 1) — it
  refuses an already-public target (publish/future/private) and a
  requested publish/future transition. New `specflux/publish-post`
  (Tier 2) covers every publish/future transition and any edit to an
  already-public post. Update your integration to call `publish-post` for
  those cases.
* `specflux/create-post` now refuses `slug_collision` (409) when a
  non-trashed page or post of the same type already holds the requested
  slug, or matches the title case-insensitively.
* Belayline Agent Governance is no longer a hard requirement. 0.2 always failed closed
  without it; 0.3 falls back to a built-in minimal gate instead (see the
  FAQ above). If you relied on `specflux_site_agent_ungoverned` meaning "Belayline Agent Governance
  is missing," check the run's `gate_mode` instead.
* A run still parked or running under 0.2 at the moment you upgrade to 0.3
  fails on its next tick with reason "started under 0.2" instead of
  continuing — it is not migrated or resumed.
* `GET /runs` (and the Runs screen's run list) is now scoped to what the
  current viewer may see or resolve, instead of returning every run on the
  site to any user who could reach the endpoint.

= 0.2.0 =
* Schema v2: run status/step-kind enums, `resume` object replaces the
  0.1 `approval_action` parameter (breaking), `max_questions`/`max_plans`
  budget keys.
* Default budget ceilings raised to `max_steps` 60, `max_tool_calls` 30 and
  `max_tokens` 250000 — a live pages run costs 31-45 steps and up to 90k
  tokens once the model retries a refused write, and a consumer may only
  lower a ceiling.
* Question parks (`specflux/ask-user`) and plan parks
  (`specflux/propose-plan`) with an accept/veto fence around Tier ≥ 1
  writes.
* Packs: a named tool surface a run may start from (`pack` argument to
  `start()`), plus the bundled pages pack and its five polyfill Abilities.
* Skills and a rendered system-instruction pipeline
  (`specflux_site_agent_run_skills` filter), with a per-run disable list
  (`skills_disable`).
* Verification read-back and a per-run report surfaced on completion.
* Runs screen: new-run form with preflight, three park cards, polling
  detail view.

= 0.1.0 =
* Initial scaffold: hard-dependency gate against Agent Safety.

== Upgrade Notice ==

= 0.4.0 =
Adds an MCP connector for AI apps (off until you turn it on), result cards, undo with Belayline Agent Governance 0.5, and new tools for comments, users, settings and menus. Adds a database table.

= 0.3.3 =
New name, slug and text domain. No feature changes.

= 0.3.2 =
Tightens permission checks on media, store report and coupon abilities; fixes truncated questions and plan card badges.

= 0.3.1 =
Sanitizes the JSON payloads of the admin-ajax endpoints; fixes the Openverse terms link.

= 0.3.0 =
Breaking: update-post no longer publishes (use publish-post); create-post refuses slug/title collisions; Belayline Agent Governance is optional; runs still parked under 0.2 fail on their next tick; run list is per viewer; see the changelog.
