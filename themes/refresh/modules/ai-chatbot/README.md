# AI Chatbot — a Claude assistant for BookStack

A chat assistant that answers questions from your wiki's own content, delivered
entirely through BookStack's theme module system. **No core file is modified**,
so `git pull` from upstream BookStack still merges cleanly.

It is not a general-purpose chatbot bolted onto the sidebar. It searches and
reads your pages before answering, cites what it used, and can only ever see
what the person asking is already allowed to see.

## Install

1. Put this folder at `themes/<your-theme>/modules/ai-chatbot/`.
2. Add your API key to the BookStack `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
```

3. Clear the config cache:

```bash
php artisan config:clear
```

That is the whole install. There is no build step, no migration, no npm, and no
Composer package. `.env.example` in this folder documents every other setting.

Until `ANTHROPIC_API_KEY` is set the module registers **nothing** — no route, no
asset, no widget — so an unconfigured install behaves exactly like stock
BookStack.

## How it answers

The assistant is given three tools and left to decide how to use them:

| Tool | What it does |
|------|--------------|
| `search_wiki` | Full-text search, including BookStack's `"phrase"`, `[tag]` and `-exclude` syntax |
| `read_page` | Reads one page in full, as Markdown |
| `list_books` | Lists books, for orienting on vague questions |

A typical exchange runs: search → read the two or three promising pages →
answer, naming the pages used. The UI shows each step as it happens, renders
images from those pages in the reply, and links every page that was opened
underneath the answer.

Letting the model drive its own retrieval, rather than stuffing one search
result set into a single prompt, is what makes follow-up questions work. "What
about the staging environment?" triggers a fresh, differently-worded search
instead of re-reading whatever the first question happened to match.

## Permissions

This is the part worth understanding before you enable it.

Every lookup runs through BookStack's own `SearchRunner` and `visible*` query
scopes, as the authenticated user. The assistant therefore cannot surface
anything the asker could not find by browsing the wiki themselves — restricted
books, other people's drafts, and recycle-bin items are all invisible to it.
There is no index, no cache and no vector store, so there is nothing that can
drift out of step with your permission rules.

Two consequences follow:

- **Two people can get different answers to the same question.** That is
  correct behaviour, not a bug.
- The system prompt tells the model that an empty result may mean "restricted"
  rather than "does not exist", so it says so instead of asserting the wiki is
  silent on a subject.

Guests are refused by default. On a public instance the guest user satisfies
BookStack's `auth` middleware, so without that check anonymous visitors could
spend your API credits. Set `AI_CHATBOT_ALLOW_GUESTS=true` only if you mean it.

## Cost control

Each question costs at most `AI_CHATBOT_MAX_STEPS` model calls. The last
call is reserved for writing the answer; earlier calls may search or read
pages. Practical levers, roughly in order of effect:

- `AI_CHATBOT_MODEL` — defaults to `claude-haiku-4-5`. `claude-sonnet-5`
  is better quality at higher cost; `claude-opus-5` reasons hardest.
- `AI_CHATBOT_PAGE_CHARS` — defaults to 4000. Page bodies dominate input
  tokens on a wiki with long pages.
- `AI_CHATBOT_SEARCH_RESULTS` — defaults to 5 results per search.
- `AI_CHATBOT_MAX_STEPS` — defaults to 6. Research-plus-answer budget; the
  last step is answer-only. Fewer steps means less research.
- `AI_CHATBOT_HISTORY_TURNS` — defaults to 6 prior messages.
- `AI_CHATBOT_MAX_TOKENS` — defaults to 1024 for shorter replies.
- `AI_CHATBOT_RATE_LIMIT` — per-user, per-minute ceiling.
- `AI_CHATBOT_ROLES` — restrict to specific roles while you evaluate.

## How it works

```
themes/<theme>/modules/ai-chatbot/
├── bookstack-module.json     module metadata
├── functions.php             autoloader, route + view registration
├── src/
│   ├── Config.php            settings, read from the environment
│   ├── Access.php            who may use the assistant
│   ├── Module.php            asset URLs
│   ├── Anthropic/            Messages API client, SSE parsing, tool-use replay
│   ├── Knowledge/            the three tools, over BookStack's own queries
│   ├── Chat/                 system prompt, history hygiene, the agent loop
│   └── Http/                 controller and SSE writer
├── views/ai-chatbot/         the widget mount point
├── lang/en/aichat.php        UI strings
├── public/ai-chatbot/        chat.css, chat.js
└── tests/                    ./tests/run.sh
```

Four theme-system mechanisms carry the whole feature:

**1. `ROUTES_REGISTER_WEB_AUTH`** registers `POST /ai-chat/message` inside
BookStack's `web` + `auth` middleware, so session auth, CSRF and email
confirmation are enforced by the framework rather than reimplemented.

**2. `THEME_REGISTER_VIEWS`** inserts the widget after
`layouts.parts.base-body-end`, which sits just inside `</body>` in the layout
every in-app page extends. Nothing is overridden, so there is no copied core
view to drift on upgrade. Export layouts do not include that partial, which is
why the widget never appears in PDF or HTML exports.

**3. The module `public/` folder** is served by core at
`/theme/<theme>/ai-chatbot/…`, so the CSS and JS need no build step.
`chat.js` must not contain HTML tag literals: BookStack's theme-asset MIME
sniffer runs `finfo` on the file, and a `.js` file that looks like HTML is
served as `text/plain`, which browsers then refuse to execute as a module.

**4. A PSR-4 autoloader** registered in `functions.php`, because theme modules
sit outside Composer's autoload map.

### Streaming

Replies stream token-by-token over server-sent events.

The wrinkle worth recording: Guzzle's `stream` option does **not** give
incremental reads under the cURL handler, which buffers the body into a sink
and only resolves once the transfer completes. What does work is passing a
custom `sink` — cURL's write callback invokes `$sink->write()` as bytes arrive.
`Anthropic\SseSink` is that sink, parsing events in place and forwarding them
upward. If you refactor the client, keep the sink: switching to
`'stream' => true` and reading the body will silently turn streaming into a
long pause followed by a complete answer.

Server-side, the response is a `StreamedResponse`, whose callback runs after
all middleware has returned. The session lock is therefore already released, so
a long answer does not block the user's other requests.

Production (Cloudflare + Cloudways nginx) will hold the body until the
assistant finishes unless every layer is told not to. `SseResponse` re-applies
`Cache-Control: … no-transform`, `Content-Encoding: identity` and
`X-Accel-Buffering: no` at send time, because BookStack's global
`PreventResponseCaching` middleware overwrites `Cache-Control` on the way out
and would strip `no-transform`. `identity` is the RFC encoding; `none` is
invalid and some edges strip it, then gzip/brotli the stream (which cannot
flush incrementally). A 32KB SSE-comment pad is flushed first so an 8k nginx
buffer cannot sit full and wait for close. Local Docker has none of those
layers, which is why tokens already appear there as they are written.

If production still dumps the whole answer at once after this, gzip is still
on in front of PHP: Cloudflare **Speed → Optimization → Brotli** off for the
host, or a Configuration Rule matching `https://wiki.globiz.jp/ai-chat/message`
that disables compression; on Cloudways, turn gzip off for that path (or
confirm nginx honours `X-Accel-Buffering: no`). The widget already paints
`delta` events as they arrive and does not wait for `done`.

### Conversation state

History lives in the browser's `sessionStorage` and is replayed with each
request. No database table, so nothing to migrate and nothing to clean up. It
is treated as untrusted input: `Chat\History` length-caps every message,
enforces user/assistant alternation, and merges same-role runs. A forged
history grants nothing, since retrieval is still permission-scoped to the
caller.

### Thinking models

Claude 5-generation models think by default. When a thinking model calls a
tool, the assistant turn must be replayed **including** its `thinking` blocks
and their signatures, or the API rejects the continuation. `MessageAccumulator`
reassembles blocks verbatim for exactly this reason — do not filter them out
when replaying. The widget shows "Thinking…" but never the reasoning itself.

## Tests

```bash
./tests/run.sh
```

No API key, database or network access needed — the streaming suite talks to a
local fake Anthropic endpoint, and the rest stub what they need. Nothing calls
the real API, so running it costs nothing.

| Suite | Covers |
|-------|--------|
| `wiring.php` | Theme events, view resolution, asset serving, route + middleware, translations |
| `units.php` | History hygiene, system prompt, config bounds |
| `streaming.php` | SSE parsing, incremental delivery, tool-use replay, thinking-block preservation, error mapping |
| `markdown.mjs` | The renderer, with an emphasis on injection safety |

`wiring.php` is the one to run after a BookStack upgrade: it asserts that every
core class, method, theme event and view path this module depends on is still
where it expects.

## Upgrading BookStack

Core is untouched, so upgrade normally. This module leans on the theme system,
which BookStack documents as *semi-stable*, so check these after a major
upgrade — `./tests/run.sh` checks all of it mechanically:

1. **The two theme events** — `ROUTES_REGISTER_WEB_AUTH` and
   `THEME_REGISTER_VIEWS`, plus the existence of
   `layouts/parts/base-body-end.blade.php`. If any is renamed the widget stops
   appearing.
2. **The queries in `Knowledge/KnowledgeBase.php`** — `SearchRunner`,
   `EntityQueries` and `PageQueries`. These are internal APIs and may change
   shape.

Everything else is additive and cannot conflict.

## Customising

- **Text** — override any string by copying `lang/en/aichat.php` to
  `themes/<theme>/lang/<locale>/aichat.php`. Partial files are fine; missing
  keys fall back.
- **Appearance** — `public/ai-chatbot/chat.css` reads the active theme's
  surface tokens (`--bg-surface`, `--text`, `--border`, …) where they exist and
  falls back to literals otherwise, so it inherits the `refresh` theme's
  palette and still looks right on stock BookStack. Accent colours come from
  `--color-primary` and `--color-link`, which administrators set in
  *Settings → Customization*.
- **Behaviour** — `AI_CHATBOT_SYSTEM_PROMPT` appends to the built-in prompt.
  Good for house terminology or nominating authoritative books.

## Limitations

- **No API route.** BookStack fires no `ROUTES_REGISTER_API` event, so the
  assistant is browser-only. Adding a REST endpoint would require a core patch.
- **Search is keyword-based**, inheriting BookStack's index. There are no
  embeddings, so a question phrased entirely unlike the documentation may miss.
  The prompt instructs the model to retry with different wording, which covers
  most of the gap.
- **Errors go to Sentry** when `SENTRY_LARAVEL_DSN` is set on the BookStack
  root `.env`. That includes stream/API failures and the browser catch-all
  (the pink "Something went wrong" banner), which otherwise never hits
  `storage/logs`.
- **No conversation history across tabs or sessions**, by design — see above.
- **Images come from pages the assistant actually reads** — gallery photos,
  draw.io diagrams and image attachments on that page, permission-scoped like
  the rest of the tools. At most a few per page and per answer. File
  attachments that are not images are not opened.
