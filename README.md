# vibeARS — audience response for Nextcloud

> **Vibe-coded:** vibeARS was written by AI coding agents (Anthropic's Claude
> Code), not by hand — the upstream app Pulse and this fork alike. The
> maintainers set the goals, made the product decisions and tried the app out
> in the browser; there has been no independent human code review and no
> security audit. Details: [How this app was built](#how-this-app-was-built-ai-disclosure).

vibeARS (vibe Audience Response System) brings live polls, word clouds and
quizzes for lectures and presentations into Nextcloud as a native app — a
self-hosted alternative to services such as Kahoot!, Mentimeter or Slido. The
presenter opens a room and projects a six-character code; the audience joins
through a public link without a Nextcloud account and votes from their phones.
Results update live through polling.

## About this fork

vibeARS is a fork of [Pulse](https://github.com/hotochan123/nextcloud-pulse)
by [hotochan123](https://github.com/hotochan123) (forked at Pulse 0.19.1,
October 2026). It has an app ID of its own, `vibears`, so it installs
alongside Pulse: both apps can run on one instance, each with its own rooms,
database tables (`vibears_*`), voter cookie (`vibears_vt`), routes
(`/apps/vibears/…`), app data and settings. Apart from those identifiers the
code is Pulse's, so upstream fixes can still be carried over by hand. What it
changes:

- **Name and icon.** The app is called vibeARS, its navigation entry "ARS",
  and the icon — a speech bubble from the audience with the response bars in
  it — is white, as Nextcloud's navigation bar expects (`img/app.svg`; the
  black copy `img/app-dark.svg` serves the settings sidebar).
- **Room retention is a setting.** Pulse deleted rooms nobody had used for 30
  days. vibeARS keeps every room until its owner deletes it; the administration
  can switch automatic deletion on and choose the number of days under
  Settings → Administration → ARS (see [Room retention](#room-retention)).
- **Decks as files.** Every room exports its questions as Markdown with a YAML
  header, and such a file imports as a new room — for a question bank in git,
  for sharing between lecturers, for writing a quiz in a text editor (see
  [Deck files](#deck-files-markdown-with-a-yaml-header)).
- **Quiz questions without a time limit.** "Time per question" offers "No
  limit": the question stays open until the moderator reveals it or moves on,
  and a correct answer earns the full points regardless of speed.

Issues and pull requests for the fork go to
[fre-ms/vibears](https://github.com/fre-ms/vibears/issues); everything that is
not about these changes is Pulse's work, and bug reports that affect Pulse as
well are best filed upstream too.

## How this app was built (AI disclosure)

vibeARS is vibe-coded, like Pulse before it. Pulse's maintainer
([hotochan123](https://github.com/hotochan123)) did not write the code by hand.
They set the goals, made the product decisions and tried the app out in the
browser. Everything else was written by AI coding agents:

- all code — PHP, JavaScript/Vue, CSS and the database migrations;
- the tests, the development tools (HTTP simulation, screenshot harness) and the
  throwaway scripts used for request fuzzing;
- the documentation, including this README, and the German translation.

The fork's maintainer ([fre.ms](https://github.com/fre-ms)) works the same
way: the changes listed under [About this fork](#about-this-fork) — code,
tests, translation and documentation — were written by AI coding agents in
Anthropic's Claude Code on the maintainer's instructions, reviewed by reading
the diffs and by trying the app out, not by an independent reviewer.

**Tool and models.** The agents ran in Anthropic's Claude Code. Pulse's
private development history has 87 commits, from 16 July to 29 September
2026, every one of them with a `Co-Authored-By` trailer naming the model:
Claude Opus 4.8 (37 commits), Claude Opus 5 (44) and Claude Opus 5.5 (6);
the commits in Pulse's public repository carry the same trailers. The fork's
commits carry no such trailer.

**Design.** The UI design explorations (mockups, design briefs) were also
produced with AI tools. They are not part of this repository.

**Verification.** The app is checked by a PHPUnit suite (over 1,300 tests),
unit tests for the self-paced rules, an HTTP simulation of complete poll and
quiz runs and a browser screenshot harness; during development it was also
fuzzed with throwaway request-fuzzing scripts. All of them were written by the
same AI agents, so they check the code against the agents' own reading of the
requirements. Not all of them run automatically: CI
(`.github/workflows/ci.yml`) only builds the bundle, checks that the committed
bundles in `js/` are exactly what the sources build to, checks the
translations and `info.xml` against the App Store schema, packs a test release
archive, runs the unit tests (`dev/unit`), `php -l` and the PHPUnit suite, and
checks that the dev scripts parse. The simulation (`dev/sim`) and the
screenshot harness (`dev/design-shots`) are run by hand — they need a running
Nextcloud instance — and the fuzzing scripts were one-offs that are not in this
repository. There has been no independent human code review and no security
audit. In September 2026 AI agents of the same kind reviewed Pulse's code for
security problems and fixed what they found (listed under "Security" in
[`CHANGELOG.md`](CHANGELOG.md)); what remains is described under
[Security notes](#security-notes). That review is no substitute for an
independent audit, and the fork's additions have not had one either. How to
report a problem: [`SECURITY.md`](SECURITY.md).

**History.** Pulse's public repository starts with one squashed commit; the
earlier history is kept private because early screenshots in it showed an
internal host name. The fork starts from that public history.

**What this means for you.** Evaluate vibeARS yourself before you rely on it,
especially for anything sensitive. Issues and reviews are welcome
([issue tracker](https://github.com/fre-ms/vibears/issues)).
vibeARS is licensed under AGPL-3.0-or-later and comes without any warranty.

## Features

- Two roles: **moderator** (signed in to Nextcloud) and **participant** (public,
  no account).
- Two room modes: **poll** (anonymous) and **quiz** (nickname, countdown,
  leaderboard).
- Question types — poll: multiple choice · word cloud · scale (single/spectrum/compass) ·
  ranking · **matching**. Quiz: multiple choice · true/false · multiple answers ·
  number guess · free text (with grading) · ranking · **matching**.
- A deck of several questions, an image per question, a projector view, QR code,
  practice run, and a summary with CSV export.
- **Formulas and markup** in questions and answers: `**bold**`, `*italic*`,
  `` `code` `` and KaTeX formulas between `$…$`, rendered everywhere the text
  is shown. See [Formatting and formulas](#formatting-and-formulas).
- **Item statistics** in the summary of a quiz: per question the difficulty
  and the discrimination, per answer option its own discrimination, also in
  the CSV. See [Item statistics](#item-statistics).
- **Floating results window:** the live result in a browser window that
  stays above every other window, a full-screen Impress or Keynote show
  included, so any presentation programme works. See
  [Floating results window](#floating-results-window).
- **Several addresses:** on an instance that is reachable under more than one
  name (`trusted_domains` in `config.php` — an internal name, a public one, a
  short alias for the lecture hall), the moderator chooses per room which
  address the join link and its QR code carry. Projector and PowerPoint add-in
  follow the choice. See [Join address](#join-address).
- **Self-paced** quizzes (the "Self-paced" toggle in a quiz deck's menu): instead
  of one question for everybody, each person works through the deck on their own
  phone — as a race in the room or as homework with a deadline. The moderator
  follows each person's progress, grades free-text answers and releases the
  results; the projector shows the race, never the questions. See below.
- An anonymous voter cookie (no link to a person) discourages double voting;
  whoever clears their cookies can vote again (see
  [Security notes](#security-notes)).
- Quiz questions run with a countdown of 10 to 300 seconds — or without one:
  "No limit" keeps the question open until the moderator reveals it or moves
  on, and a correct answer then earns the full points regardless of speed. In
  a self-paced quiz such a question can be skipped.
- **Decks as files:** "Export as Markdown" in a room's menu writes the
  questions, solutions and settings as Markdown with a YAML header — as a
  ZIP with the images when there are any; "Import a deck" on the start page
  turns such a file into a new room. "Save to Files" and "Import from Files"
  keep the file in Nextcloud Files, and a Markdown or ZIP file in the Files
  app offers "Import into vibeARS". See
  [Deck files](#deck-files-markdown-with-a-yaml-header).
- Rooms stay until their owner deletes them. The administration can let a
  daily background job delete rooms after a chosen number of days without any
  activity instead — see [Room retention](#room-retention). Deleting a
  Nextcloud account always deletes that account's rooms, with their questions,
  votes, players and images, and the daily job always deletes the rooms of
  accounts that no longer exist — for example ones deleted while vibeARS was
  disabled, which would otherwise pass to a new account with the same user ID
  — and question images that no question refers to any more.

## Self-paced quiz

1. **Switch:** in the quiz room, deck menu → tick "Self-paced". This clears
   participants, answers and leaderboard (after asking, once somebody has
   joined); the questions stay. The header shows "Quiz · self-paced".
2. **Open:** "Open quiz …" instead of "Start presenting". In the dialog, choose
   how it ends:
   - "When I close it" = **race**: by default with a timer and feedback after
     every question; closing releases the results at once (or use "Stop without
     releasing" to check free-text answers first).
   - "At a set time" = **homework**, deadline 1 minute to 30 days: by default
     without a timer, results only with the release; the deadline closes the
     quiz by itself, even with no tab open.

   Timer and feedback can be changed in the dialog; a practice run always gives
   feedback at once and never shows a leaderboard. Once the quiz is open, the
   questions are locked until the room is reset.
3. **Progress view** ("Show progress"): for each person the question, answers,
   points and last activity; next to it the join code with QR, a projector
   preview and free-text grading (without the list of accepted answers; slips
   can be undone under "Checked just now"). At the bottom exactly one main
   action: "Close quiz" → check open free-text answers → "Release results". In
   the menu: lock joining, change the end or reopen, show points (with feedback
   at the end), participants and answers as CSV, reset the quiz; people can be
   removed one by one. The page is private — what you project is the projector
   window.
4. **Phone:** name → start card → questions. "Next question" appears once the
   answer is final (a few seconds, costs no points); questions without a timer
   can be skipped. Reloading continues at the same place — but only in the same
   browser: switching devices starts over with a new name at question 1.
5. **Projector:** while open, the race (how many people are on which question,
   how many have finished), with a large join block for the first two minutes,
   then — with feedback after every question — the top eight; once closed, only
   the count; once released, the final standings.

Limits: at most 300 people per room who have started and 600 who have joined,
and 120 new names per address and room within ten minutes; an instance can
change the last two (all limits: [Limits](#limits)). Lock joining once the
class is in. Implementation notes with
test paths and open points:
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md).

## Item statistics

The summary of a quiz (the page behind "Show final standings" and "Back to
the deck → Summary") carries, per question, the figures an exam analysis
would report, computed from the stored verdicts of the answers and
independent of the speed points:

- **Answered:** how many people answered the question.
- **Difficulty:** the share of correct answers among those who answered,
  0 to 1. A high value means an easy question.
- **Discrimination:** the corrected item-total correlation, i.e. the
  point-biserial correlation between answering this question correctly and
  the number of correct answers on the *other* questions of the deck, over
  those who answered it. A question somebody did not answer counts as 0 in
  that rest score. The summary flags a value below 0.2, the cut-off that
  item analyses commonly use as a reason to look at the question again
  (Möltner, Schellberg and Jünger, *Grundlegende quantitative Analysen
  medizinischer Prüfungen*, GMS Z Med Ausbild 2006, 23(3): Doc53). The
  figure is undefined, and shown as a dash, with fewer than two answers,
  when everybody was right or wrong, or in a deck of one question.
- **Per answer option** (multiple choice, true/false, multiple answers): the
  same correlation for choosing that option, as "r = …" next to the bar. The
  key should be positive; a distractor that correlates positively attracts
  the strong players and is worth a second look.

The CSV export of a quiz carries the three figures as three more columns:
difficulty and discrimination on every row of a question, the option's own
discrimination on its row. A poll has no solutions and keeps the six columns.
The computation lives in `lib/Service/ItemStatistics.php`, database-free and
covered by `tests/unit/ItemStatisticsTest.php`.

## Room retention

By default nothing is deleted automatically: a lecturer's question bank is a
deck that may rest for a semester. Under Settings → Administration → ARS an
administrator can switch on **Delete unused rooms automatically** and choose
after how many days (1 to 3650; the upstream default was 30). "Unused" means:
no participant was there, and the owner did nothing with the room — did not
open it, edit the deck, present or look at the results — for that many days.
A deck prepared weeks ahead therefore stays as long as its owner keeps
working on it. Participants alone keep a room alive only while its owner has
used it within the last 180 days, so a room whose code keeps being polled
still goes about 180 days after its owner last used it. For a self-paced
quiz, opening, the deadline, closing and the release count as activity as
well, so a homework that is graded weeks after its deadline does not vanish.

The two settings are app config keys and can be set with occ as well:

```
occ config:app:set vibears retention_enabled --value 1 --type integer    # 0 = off (default)
occ config:app:set vibears retention_days --value 90 --type integer      # 1–3650, default 30
```

Whatever the setting says, the same daily job deletes the rooms of accounts
that no longer exist and image files that no question refers to any more,
and deleting a Nextcloud account deletes that account's rooms at once.

## Deck files (Markdown with a YAML header)

Every room's menu offers **Export as Markdown** (also under "My rooms", in a
room's menu), and the start page **Import a deck (Markdown or ZIP)**. The file
holds the questions with their solutions and settings — no votes and no
participants. A deck without images is a plain `.md`; as soon as a question
has an image, the export is a ZIP with the Markdown as `deck.md` and the
images next to it, see [Images in the deck file](#images-in-the-deck-file).
The same file can be saved into and imported from the account's Files, see
[Deck files in Nextcloud Files](#deck-files-in-nextcloud-files). Importing
creates a new room; the caps of the account and every rule of the composer
apply, and a file that fails on its seventh question leaves no room behind.
The format, version `vibears/1`:

```markdown
---
format: "vibears/1"
title: "Lecture 3"
mode: "quiz"          # poll | quiz
revealAtEnd: false    # quiz: reveal only at the end
pace: "live"          # quiz: live (moderated) | self (self-paced)
timed: true           # quiz, self-paced: the "Timer" default
feedback: "each"      # quiz, self-paced: each | end
---

## Which planet is the largest?
type: choice
time: 20
- [x] Jupiter
- [ ] Saturn
- [ ] Earth

## Water boils at 100 °C at sea level.
type: truefalse
time: 15
answer: true

## Which of these are prime numbers?
type: multi
time: none
- [x] 2
- [x] 3
- [ ] 4

## In which year did the Berlin Wall fall?
type: number
time: 30
target: 1989
tolerance: 1

## Who wrote "Faust"?
type: text
time: 60
- Goethe
- Johann Wolfgang von Goethe

## Order the planets, closest to the Sun first.
type: rank
time: 45
1. Mercury
2. Venus
3. Earth

## Match capital and country.
type: match
time: 60
- Paris → France
- Berlin → Germany
```

- The header is a flat YAML mapping; only `mode` matters for a poll, the
  other keys describe a quiz's flow and are optional (the composer's defaults
  apply). Quoted strings take the usual escapes; `yes`/`no`/`on`/`off` are
  booleans too.
- One question per `## ` heading. Below it, `key: value` settings and a
  list. Any list marker works (`-`, `*`, `+`, `1.`, `1)`); only the order and
  the `[x]` marks count. `- [x]` marks the correct option (one for
  `choice`, several for `multi`); an ordered list is the solution of a
  `rank` question in a quiz; `left → right` (also `->`, `=>`) is a
  `match` pair; the items of a `text` question are its accepted answers.
- `time` (quiz only) is a number of seconds, `30 s` works too, and `none`
  (or `0`) means no limit. Missing, the composer's default of 30 applies;
  anything else is clamped into 5–300 like in the composer.
- Poll types: `words` takes `maxWords`; `scale` takes `scaleMode` (`single`
  with `scaleMax`, `minLabel`, `maxLabel`; `spectrum` with `scaleMax` and a
  list of `aspect | low pole | high pole`; `compass` with `range`,
  `axisX`/`axisY` as `title | low pole | high pole`, the four labels
  `cornerBottomLeft`, `cornerBottomRight`, `cornerTopLeft`, `cornerTopRight`
  and `heatmapThreshold`).
- HTML comments (`<!-- … -->`) are ignored, as is a document title (`# …`)
  before the first question. An unknown setting, text that is neither a
  setting nor a list item, a pair without an arrow or two `[x]` on a
  single-choice question are refused with the line that holds them; whatever
  the composer would refuse (an empty question, one option, no correct
  answer) is refused with the question's number.
- Files are limited to 1 MB and must be UTF-8; a BOM and Windows line
  endings are fine.

- `image: images/3.png` names the question's image, relative to `deck.md`,
  in the ZIP export. A plain `.md` that names an image is refused with the
  question's number: only the ZIP carries it.

The export is stable: importing a file and exporting the new room gives the
same file again, so a deck kept in git shows only real changes in its diffs.

### Images in the deck file

As soon as a question has an image, "Export as Markdown" downloads
`vibears-CODE.zip` instead of `.md`: `deck.md` plus `images/1.png`,
`images/3.jpg`, … numbered by the question's position, each named by an
`image:` line. The import takes the ZIP like the Markdown — the file input,
"Import from Files" and the Files app's action all accept both — and each
image goes through the same checks, re-encoding and image budget as an
upload (`PollImageService::storeBytes`), so the stored bytes of a round trip
can differ while `deck.md` stays the same. A folder that was unpacked and
zipped again reads as well: one folder around everything is stripped, macOS
resource forks and `.DS_Store` are ignored, and a single `*.md` at the top
counts as the deck when it is not called `deck.md`. Limits: 50 MB for the
archive, 5 MB per image (the upload limit), 300 entries. `DeckArchive`
reads and writes it, `DeckExportService` decides between `.md` and `.zip`.

### Moodle GIFT

A quiz room's menu offers **Export for Moodle (GIFT)**: the deck as a
[GIFT](https://docs.moodle.org/en/GIFT_format) file (`vibears-CODE.gift`),
which Moodle's question bank imports (format: GIFT), and which "Import a
deck" and "Import from Files" take back — anything that is not the
Markdown deck file or its ZIP is read as GIFT and always makes a quiz.

| vibeARS | GIFT |
|---|---|
| multiple choice | `{=Jupiter ~Saturn ~Earth}` |
| true/false | `{TRUE}`, `{FALSE}` |
| multiple answers | `{~%50%2 ~%50%3 ~%-100%4}` (the share per key from Moodle's list, a wrong pick cancels the question) |
| number guess | `{#1989:1}` (target:tolerance); `{#1..5}` reads as the middle with half the span |
| free text | `{=Goethe =Johann Wolfgang von Goethe}` |
| matching | `{=Paris -> France =Berlin -> Germany}` |
| ranking | `{=1 -> Mercury =2 -> Venus}` — positions to items; it comes back as matching |

Question names (`::name::`), feedback (`#…`), categories and comments are
read and dropped; an `[html]` question loses its tags, a `[markdown]` one
keeps its markup, which vibeARS renders. GIFT has no time per question, so
every imported question gets the composer's default. A block that cannot be
a question here — a description without an answer block, an essay without
an answer to check, a choice without a key — is refused with its number
and line, and nothing is stored. The export refuses a poll: GIFT holds
graded questions. `DeckGift` writes and reads the format, tested in
`tests/unit/DeckGiftTest.php` against Moodle's own documentation examples.

### Deck files in Nextcloud Files

- **Save to Files** (a room's menu, in the deck and under "My rooms") writes
  the deck file into a folder of the account's Files — Nextcloud's own
  picker, so a folder shared with write access is in reach. The file is
  named after the room's title (`Lecture 3.md` or `.zip`; `vibears-CODE`
  without a title), and a taken name gets " (2)", " (3)", … like the Files
  app does.
- **Import from Files** (start page) makes a new room from a deck file in
  Files — a deck a colleague shared included.
- **Import into vibeARS** is an entry in the actions of a Markdown or ZIP
  file in the Files app (`FilesScriptsListener` loads `js/vibears-files.js`
  there, nowhere else). The server reads the file, so nothing passes through
  the browser; a Markdown file that is not a deck is refused with the line
  that is wrong.

Paths are relative to the account's root, as the Files app names them, and
stay inside it. The two menu entries use the server's legacy
`OC.dialogs.filepicker`, present in every supported server; where it is
missing they stay away, the file input and the Files app action remain.

## Formatting and formulas

A question text and every answer option (choice, true/false, multiple
answers, ranking, matching) can carry a small inline markup:

| You type | You get |
|---|---|
| `**bold**`, `*italic*` | **bold**, *italic* |
| `` `code` `` | `code` in a monospace box |
| `$x^2 + y^2 = r^2$` | the formula inline, typeset by [KaTeX](https://katex.org) |
| `$$\int_0^1 x\,dx$$` | the formula on a line of its own |
| `\$` | a literal dollar sign |

The dollar rules follow Pandoc: an opening `$` must be followed by a
non-space, a closing `$` must be preceded by a non-space and must not be
followed by a digit, so "$5 and $6" stays text. A marker without its partner
is literal, and nothing inside a formula or a code span is read as emphasis.
The composer shows a preview as soon as the draft uses markup. The markup
is rendered on the phone, the projector, in the moderator's deck and results
and in the PowerPoint add-in; the deck file and the CSV carry the source text.

Everything the author typed is HTML-escaped before the markup is applied
(`src/util/richtext.js`, tested without a browser by
`dev/unit/richtext.test.mjs`); the only tags that reach the page are
`<strong>`, `<em>`, `<code>` and KaTeX's own output, which never contains
scripts or links (`trust: false`). KaTeX itself is a chunk of its own,
`js/vibears-katex.js`, that a page fetches the first time it meets a formula
— most decks have none, and the participant bundle is loaded by every phone
in the hall — and its stylesheet and woff2 fonts are static files under
`css/katex/` (see [Building](#building)). Until the chunk is there, or if it
cannot be loaded, the formula shows its source.

## Join address

The join link, its QR code and the "code at …" hint use the address of the
page they are shown on. Where Nextcloud is reachable under several names, the
moderator's join panel (in the deck and in the presentation view) offers a
selector with every plain entry of `trusted_domains`, the current address
first; wildcard entries such as `*.example.com` cannot become a link and are
left out. The choice is stored with the room, so the projector window and
the PowerPoint add-in show the same address; it stays when
the room is reopened and is taken over when the room is copied. A stored
address that disappears from `trusted_domains` later falls back to the page's
own.

`trusted_domains` holds host names only, so the scheme is derived: the
current address keeps the scheme of the page, every other address gets
`overwriteprotocol` where that is set, otherwise `https` — except names that
only exist inside a network (`localhost`, loopback and private IP ranges,
`*.local`, `*.localhost`), which get `http`.

## Architecture

- **Public access** without login through `#[PublicPage]` + `#[NoCSRFRequired]` —
  the same mechanism as share links. The room code is the share token:
  `/apps/vibears/s/{code}`. Because that switches Nextcloud's CSRF check off, the
  participant API checks for itself that a vote comes from its own page (see
  [Security notes](#security-notes)).
- **Core decision on vote counting:** one database row per `(poll_id, voter_token)`
  (`UNIQUE` index), counted with `COUNT`/aggregates — no shared counter. A
  re-vote is an upsert (last write wins per person). Carried over from the
  original prototype so that simultaneous votes are not lost.
- **Realtime:** adaptive JSON polling. Moderated rooms poll every 1.2 to 2.5 s
  depending on view and phase, backing off to 4–5 s while nothing changes; in a
  self-paced quiz, phones poll every 0.7–10 s, the progress view every 2–10 s
  (`phoneDelay`/`progressDelay` in `src/util/pace.js`) and the deck status
  every 5–10 s. Polling pauses in hidden tabs, and an unchanged state is
  answered with an empty 204. In moderated rooms that check reads no votes: it
  compares a per-question change marker kept in Nextcloud's memory cache (the
  distributed one, such as Redis, where configured). Without any memory cache
  it falls back to reading the votes of the question. No High Performance
  Back-end needed.
- **Public views are capped:** phones and the projector get the top 100 words
  or free-text groups, at most 500 compass points and the first ten leaderboard
  rows plus the viewer's own row and neighbours, each with the full count. The
  moderator's results, the summary and the CSV export stay complete.

## Structure

```
lib/Controller/   PageController (moderator SPA), RoomApiController (moderator API,
                  deck file export/import included), PublicController (participant pages),
                  PublicVoteController (public API), AddinController (PowerPoint add-in
                  manifest), AdminSettingsController (the administration form)
lib/Db/           Room, Poll, Vote, Player, Presence, Progress + QBMapper
lib/Service/      RoomService (rooms), DeckService (questions), VoteService (votes),
                  StateService (read views + CSV), TallyService (tallying),
                  AnswerRules (per-type answer checks + grading), QuizService (points),
                  PollImageService (images), DemoService, CodeGenerator,
                  PaceService + PaceStateService (self-paced quiz: window, read views),
                  PublicPayload (capped public views), Limits (caps per account),
                  Retention (the administration's deletion setting),
                  JoinAddresses (the addresses the join link can use),
                  DeckMarkdown (the deck file: export and parse) + DeckImportService,
                  DeckArchive (the ZIP with images), DeckExportService (the file as
                  downloaded or saved), FilesService (deck files in the account's Files)
lib/Settings/     AdminSettings + AdminSection (Settings → Administration → ARS)
lib/SetupCheck/   EmbedFraming (setup check for the PowerPoint embed)
lib/BackgroundJob/ CleanupStaleRoomsJob (deletes unused rooms when the administration says so,
                  rooms of deleted accounts and question images nothing refers to)
lib/Listener/     UserDeletedListener (a deleted account takes its rooms with it),
                  FilesScriptsListener (loads the Files app action, src/files.js)
lib/Migration/    Schema (vibears_rooms, vibears_polls, vibears_votes, … vibears_progress)
src/              Moderator.vue (control desk), Participant.vue (phone), Screen.vue (projector),
                  components/StageRow.vue (stage row model), ResultsView.vue,
                  self-paced: components/Pace*.vue (moderator), StageRace.vue (projector),
                  mixins/pace-phone.js (phone), util/pace.js (pure rules, no browser),
                  util/question-types.js (composer contract per question type, no browser),
                  util/richtext.js (inline markup of questions and answers, no browser)
                  + components/RichText.vue (renders it, fetches the KaTeX chunk),
                  util/routes.js (every URL and request time limit of the Vue frontend;
                  js/vibears-embed.js builds its own),
                  styles.js (token layer without Vue — for the embed shell),
                  admin.js (the administration form, without Vue),
                  files.js ("Import into vibeARS" in the Files app, without Vue),
                  util/files.js (Nextcloud's file picker for the deck file)
templates/        index.php (moderator), public.php (phone, projector), embed.php, admin.php
css/              public.css, embed.css, admin.css; katex/ = KaTeX's stylesheet and woff2
                  fonts, vendored by build/vendor-katex.js
l10n/             Translations (de.json is maintained, de.js is generated from it)
build/            l10n-build.js (json -> js), l10n-check.js (coverage),
                  vendor-katex.js (KaTeX stylesheet + woff2 fonts -> css/katex/),
                  package.sh (release archive), certificate.sh (signing key)
tests/            PHPUnit (database-free) + run.sh
office-addin/     PowerPoint add-in (guide, Traefik example; the instance generates
                  the manifest itself)
dev/design-shots/ Screenshot harness for design reviews and store images (headless Firefox)
dev/sim/          HTTP simulation of complete runs against a running instance
.github/          CI: bundle (and that js/ matches it), PHPUnit, JS unit tests (dev/unit),
                  script syntax, translations, info.xml schema, test release archive
screenshots/      Images for the App Store page
.htaccess         Hides the development folders when a git checkout sits in the web root (Apache)
```

## References in code comments

Many code comments cite section numbers ("§2.4", "specification §1.6"),
acceptance criteria ("acceptance #7"), steps and gates of the build plan
("step 4.6", "gate 4.4b") or review IDs ("B1", "R2", "E1", "D6"). They point to
the working documents the AI agents worked from: the design briefs, handoffs,
review notes and screenshots of the July 2026 design rounds, and the
specification of the self-paced quiz from September 2026. None of these
documents is part of the public repository, so such a reference cannot be
looked up; read it as "this rule comes from the design notes". Files that cite
them say so in a note. The one exception is
[`docs/pace-2026-09/UI.md`](docs/pace-2026-09/UI.md), the implementation
record of the self-paced UI, which is included.

## Installation

vibeARS is not in the Nextcloud App Store; install it from this repository.
Its app ID is `vibears`, so it installs next to Pulse rather than in its
place: an instance can run both, and Pulse's rooms stay in Pulse (there is
no path to move them over — Pulse has no deck export). Pulse runs on
Nextcloud 34 and 35 and was tested there on SQLite, MariaDB, PostgreSQL and
MySQL; vibeARS has the same dependencies and the same schema under its own
table names, and has been checked by the same PHPUnit suite, not yet by an
installation on every database.

Do not clone the repository into the web root. A checkout carries the
development tree — tests, the simulation and screenshot harness, sources and,
after a build, `node_modules/` with demo pages of its packages — and the web
server hands out static files below `apps/` as they are, so all of it would be
public on your Nextcloud's own origin. Clone it somewhere else and copy only the
runtime files into Nextcloud. The built bundles in `js/` are part of the
repository, so this needs no build step, only Node.js for the translation
check:

```
git clone https://github.com/fre-ms/vibears.git ~/vibears-src
cd ~/vibears-src
SKIP_BUILD=1 sh build/package.sh        # -> build/vibears-<version>.tar.gz
tar -xzf build/vibears-<version>.tar.gz -C <nextcloud>/apps/
occ app:enable vibears
```

The archive holds a single folder `vibears/` (the directory has to be named after
the app ID) with only the runtime folders — `appinfo`, `css`, `img`, `js`,
`l10n`, `lib`, `templates`, `office-addin` — plus README, licence and changelog.
Without a key it is not signed, which Nextcloud accepts for an app installed by
hand. `ALLOW_UNRELEASED=1` is only needed between releases (a checkout of a
release tag packs without it). Copying those folders yourself, for example with
`rsync`, works just as well. To update, replace `<nextcloud>/apps/vibears` with
the new folder; when the version in `appinfo/info.xml` went up, run
`occ upgrade` afterwards (until then Nextcloud does not serve the app).

Running vibeARS straight from a git checkout inside `apps/` still works, and the
repository ships an `.htaccess` as a safety net for that case: on Apache (with
`mod_alias` and `AllowOverride` for the app folders, as in Nextcloud's own
setup) it answers 404 for `node_modules/`, `dev/`, `build/`, `tests/`, `src/`,
`tools/`, `docs/`, `.git/`, local `_*.php` scripts and the package files. nginx
ignores `.htaccess`; there, deploy the runtime files as above.

## Building

Build outside the web root as well, then deploy as above:

```
npm ci             # exactly the dependency versions of package-lock.json
npm run build      # produces js/vibears-main.js, js/vibears-public.js, js/vibears-styles.js, js/vibears-admin.js
                   # and the chunk js/vibears-katex.js (fetched only by pages that meet a formula)
```

KaTeX's stylesheet and woff2 fonts are not part of the bundle but static files
under `css/katex/`, copied from the npm package by `node build/vendor-katex.js`
(the `@font-face` rules are cut down to woff2). Run it after a KaTeX version
bump and commit the result; CI checks with `node build/vendor-katex.js --check`
that the directory matches the package.

Use Node.js 26.10.0, the version the committed bundles are built with: with it,
`npm ci` plus `npm run build` reproduces `js/` byte for byte (the output is
determined by the packages in `package-lock.json`, not by Node — 22.18.0 and
26.10.0 build identical bundles — but the byte-for-byte check needs one pinned
toolchain). CI builds the same way and fails when the committed `js/` differs
from a fresh build, so after a change to `src/` or to the dependencies, commit
the rebuilt `js/` along with it. `build/package.sh` without `SKIP_BUILD=1` runs the build itself.

## Languages

The source language is **English** — the code contains the English text, and
translations come from `l10n/`. That way the app does not suddenly show German
buttons outside German-speaking countries, and further languages need no code
change.

```
PHP:  $this->l10n->t('Room not found.')          // IL10N in the constructor
      $this->l10n->n('%n vote', '%n votes', $x)
Vue:  {{ t('vibears', 'Save') }}                    // Vue.prototype.t/n from main.js
      {{ n('vibears', 'answer', 'answers', total) }}
      t('vibears', 'Hello {name}', { name })        // placeholders in curly braces
```

Only `l10n/de.json` is maintained by hand; `l10n/de.js` (the file Nextcloud
loads in front of the bundle) is generated from it:

```
npm run l10n         # de.json -> de.js
npm run l10n:check   # reports missing and orphaned translations, exit 1 on gaps
```

Plurals live under the combined key `_singular_::_plural_` with an array as the
value — the same rule in PHP and JS. Numbers are formatted in the language of
the interface (`fmtNum()` in the frontend, `NumberFormatter` in the CSV export);
a hard-wired decimal comma would be a different number in an English interface.

## Data model

```
Poll  = { id, type, question, options, answerKey, maxWords, timeLimit, image, status }

# options holds something different per type (JSON in one column):
choice/multi/rank/truefalse -> [{id,label}, …]
scale                       -> {mode, min, max, aspects|axisX/axisY, …}   -> getScaleConfig()
match                       -> {items:[{id,label}], targets:[{id,label}]} -> getMatchConfig()

# answerKey is the quiz solution (never sent to participants before the reveal):
multi {correct:[id]} · rank {order:[id]} · match {map:{itemId:targetId}}
number {target,tolerance} · text {accepted:[],rejected:[]}

Vote  = { value: "<optionId>" }                  # choice/truefalse
Vote  = { value: ["word", "word"] }              # words (lower-cased when tallying)
Vote  = { value: ["id","id"] }                   # rank (complete permutation)
Vote  = { value: {"<itemId>": "<targetId>"} }    # match (every row assigned)
```

## Tests

```
tests/run.sh          # PHPUnit inside the Nextcloud container (see tests/README.md)
npm run l10n:check    # translation coverage, exit 1 on gaps
TZ=Europe/Berlin node dev/unit/pace.test.mjs   # self-paced rules (deadlines, phone cards, polling rate)
node dev/unit/format.test.mjs                  # shared formatting helpers (tall labels, "x min ago")
node dev/unit/question-types.test.mjs          # composer contract per question type, deck labels, loss texts
node dev/unit/routes.test.mjs                  # Vue frontend URLs and their call sites, checked against appinfo/routes.php
node dev/unit/composer-limits.test.mjs         # composer maxlength/min/max, checked against what DeckService keeps
node dev/unit/wordcloud.test.mjs               # projector word-cloud layout (long words fit), phone word length vs. server
node dev/unit/richtext.test.mjs                # inline markup of questions and answers (escaping, emphasis, formula placeholders)
node dev/unit/float.test.mjs                   # floating results window: browser capability, the card's content and layout
dev/sim/run.sh        # HTTP simulation of complete runs against the running instance (dev/sim/README.md)
```

Screenshots of every view (projector, phone, moderator — per question type, open
and revealed, light and dark) are taken automatically by `dev/design-shots/run.sh`;
see `dev/design-shots/README.md`. The run also measures every projector stage and
reports when content runs past the clipped kiosk — on a still image you only
notice that if you look for it.

Handoffs, implementation status and images of the July 2026 design rounds are
not part of the public repository; see
[References in code comments](#references-in-code-comments).

The unit suite stays database-free (grading, tallying, text normalisation,
parameter pass-through). Paths that really touch the database are exercised by
the HTTP simulation and by throwaway scripts against a running instance, which
are not part of the repository — see `tests/README.md`.

## Floating results window

For a lecture that runs in Impress, Keynote, Google Slides or a PDF viewer:
**Float results**, a corner button on the projector page (it appears when the
mouse moves) and an entry in the moderator's menu while presenting, opens the
live result in a small window that the browser keeps above every other
window — above a full-screen slide show too. Drag it into a corner of the
projector and present as usual; it follows the moderator's question
changes. Nothing is installed on the presenting computer.

How the window is made depends on the browser (`src/util/float.js`):

- **Chromium (Chrome, Edge, Brave …):** document picture-in-picture. The
  window holds the projector page itself, scaled down, so every stage, the
  countdown and the leaderboard look as they do on the big screen.
- **Safari:** video picture-in-picture. A compact card — question, the bars
  of a choice question with their letters and colours, answers, countdown —
  is painted on a canvas, streamed into a video and that video floated.
  Other question types show the question and the count; the full stage
  stays on the projector page.
- **Firefox** has no API for it, only its own picture-in-picture button on
  every video: the same card appears as a video on the page with a hint to
  use that button.

The window stays above full-screen applications on Windows and macOS; on
Linux it depends on the window manager, and the fallback there is Impress's
own "window" presentation type. The PowerPoint add-in remains the way to
have the result *inside* a slide.

## Embedding in PowerPoint

A custom content add-in (sideloaded, not from the Office Store). Each instance
serves the Office manifest itself — `/apps/vibears/addin/manifest.xml`, and
"PowerPoint add-in" in the deck menu. It carries the address it was downloaded
from; an Office manifest has no variables, so a shipped file would need a hand
edit on every installation. Guide: `office-addin/README.md`.

**Note:** Nextcloud sends `X-Frame-Options: SAMEORIGIN` from `.htaccess` and
`lib/base.php` — an app cannot override that. For `/apps/vibears/screen/` and
`/apps/vibears/embed` the header has to be removed in the web server or reverse
proxy; snippets for Apache, nginx and Traefik are in `office-addin/README.md`.
Whether it is still there is reported by the setup check "vibeARS: embedding in
PowerPoint" under Administration → Overview, separately for each address.

## Limits

**Per account and quiz room.** No user quota counts what vibeARS stores — question
images live in Nextcloud's app data, rooms and questions in the database — and
anonymous participants can add players to a quiz, so vibeARS keeps its own caps.
Each can be changed per instance, for example
`occ config:app:set vibears max_rooms_per_owner --value 500`. Only a positive
whole number overrides the default (anything else keeps it, so a typo never
means "unlimited"), and a change takes effect within a few seconds.

| App config key | Default | Limits |
|---|---|---|
| `max_rooms_per_owner` | 200 | rooms of one account |
| `max_polls_per_room` | 100 | questions in one room |
| `max_question_length` | 1000 | characters of a question text |
| `max_image_bytes_per_owner` | 209715200 (200 MB) | question images of all rooms of one account, counted as stored — vibeARS re-encodes every upload as PNG or JPEG, which can be several times the size of the upload |
| `max_players_per_room` | 600 | players who have joined one quiz: every player of a moderated quiz, started or not in a self-paced one (the self-paced cap of 300 who have started is fixed); the flood guard on joining grows with it |
| `max_new_players_per_address` | 120 | new names per address and self-paced quiz within ten minutes — raise it when a larger class or a lecture hall joins from behind one address |
| `rate_limit_create` | 30 | new rooms per account and minute |
| `rate_limit_duplicate` | 10 | room copies per account and minute |
| `rate_limit_add_poll` | 120 | new questions per account and minute |
| `rate_limit_upload_image` | 30 | image uploads per account and minute |
| `rate_limit_demo` | 30 | batches of demo votes per account and minute |

A cap answers with a message (400), a rate limit with "Too many attempts. Please
wait a moment." (429). Copying a room checks its questions and images against
the caps before it writes anything. Requests that arrive at the same moment
all check against what was stored before any of them wrote, so the image
budget and the caps on rooms and questions count once more after writing, and
a request that finds the account over takes its own room, question, copy or
image back — parallel requests may then all be refused, but they no longer get
past a cap together. The per-minute rate limits come from Nextcloud's rate
limiter, which is approximate under parallel requests: a burst can get a few
more through. Answer options are cut to 200 characters,
and a free-text question keeps at most 50 accepted answers.

**Per room and address.** Fixed in the code where no app config key is named;
"an address" is what Nextcloud counts as one — an IPv4 address or an IPv6 /56
network.

- Wrong room codes: after ten from one address within 30 minutes, further
  unknown codes get 429. While an address is blocked its misses are not
  counted, so the block ends 30 minutes after the last miss that counted, even
  if a phone keeps polling a deleted room. Existing rooms keep working.
- New anonymous identities (a vote or a join without the voter cookie): 300 per
  address and room within ten minutes.
- Presence ("N here", a player's last activity): 600 new entries per address
  and room within ten minutes. Beyond that a phone works as usual; it is
  counted as present once the window has moved on.
- Joining a quiz: 2,400 requests per address and room within ten minutes (four
  per possible player, more with a higher `max_players_per_room`), in both
  modes — only a guard against floods.
- Moderated quiz: at most 600 players (`max_players_per_room`).
- Self-paced quiz: at most 300 players who have started and 600 who have
  joined (`max_players_per_room`); at that ceiling, while the quiz is open,
  players who joined more than ten minutes ago and never started make room. At
  most 120 new names per address and room within ten minutes
  (`max_new_players_per_address`).
- Word cloud: a vote with more than twice the allowed number of words (at least
  20) is refused; long words and texts are cut before they are processed.

## Security notes

- **Anonymous participants.** A random voter token in a cookie discourages
  double voting without identifying anyone; whoever clears their cookies can
  vote again. In a quiz, participants only give a nickname.
- **Markup in questions.** Question texts and answer options are the only
  texts rendered with `v-html`, and only through `src/util/richtext.js`,
  which escapes everything before it applies its own markup (bold, italic,
  code, formula placeholders); KaTeX typesets the placeholders with
  `trust: false`, so no `\href` or `\url` becomes a link, and with bounded
  `maxSize` and `maxExpand`, so a macro bomb cannot freeze a phone. Texts
  from participants (names, free-text answers, words) never go through it.
- **Voter cookie.** On https with Nextcloud at the root of its domain the cookie
  is called `__Host-vibears_vt` (Secure, `Path=/`, no `Domain`, `HttpOnly`,
  `SameSite=Lax`). Browsers accept such a cookie only from this very origin, so
  another service on a sibling subdomain can neither plant a token it knows —
  and then read a participant's answers with it — nor overwrite it. Under http
  or a sub-path it stays `vibears_vt` without that protection. (Pulse's
  takeover of a cookie from before its `__Host-` change is kept in the code —
  a plain `vibears_vt` is adopted for 60 days after the first request under
  the `__Host-` name, governed by the app config key `voter_cookie_host_since`
  — but a fresh vibeARS has no such cookies.) A request that sends a cookie name twice counts as
  having no cookie. A name that PHP only turns into `__Host-vibears_vt` —
  `_.Host-vibears_vt`, which any sibling subdomain may set — is ignored, as PHP
  itself ignores it.
- **Votes only from the page itself.** Joining, voting and moving on in a
  self-paced quiz (`POST /s/{code}/join`, `/vote`, `/next`) need
  `X-Requested-With: XMLHttpRequest`, which the page's own script sends, or
  `Sec-Fetch-Site: same-origin`; anything else gets 403 and no cookie. A form
  on another site, a sibling subdomain included, can therefore neither vote or
  rename in a participant's name nor replace their cookie. Reading (`/state`,
  `/summary`, images) needs only the code.
- **Room codes** have six characters out of 32, about 10^9 codes. A wrong code
  counts towards Nextcloud's brute-force protection (action `vibearsRoomCode`):
  after ten misses from one address within 30 minutes, further unknown codes
  get 429. Only misses ever count, and an existing room always opens — a typo
  in the join form, a web page that embeds made-up codes or a room deleted
  under 30 polling phones cannot lock a school or a venue out of its own room.
  Requests from a blocked address are not counted, so phones still polling a
  deleted room neither prolong the block nor grow the list of attempts that
  Nextcloud reads for that address on every check, its login included.
  The price: an address can still tell existing from unknown codes, even while
  it is blocked; only a rate limit in front of Nextcloud (in the reverse
  proxy, for `/apps/vibears/s/` and `/apps/vibears/screen/`) bounds how fast. An
  admin lifts a block with `occ security:bruteforce:reset <address>`. The
  moderator API answers someone else's room exactly like an unknown code (404
  "Room not found."), so a logged-in account learns no more there.
- **Ballot stuffing.** Without an account, anyone with the code can vote again
  and again under new identities — another browser, a private window, or a
  script. Voter tokens are random values the server does not sign, so a script
  can make up its own; the limit on new identities (see [Limits](#limits))
  only slows down scripts that send no cookie at all. vibeARS cannot prevent
  this: the result of an anonymous poll is an impression, not a head count.
  What is bounded is the cost for everybody else: public views are capped (see
  [Architecture](#architecture)), an unchanged state costs no vote reads, a
  request without the cookie writes no presence row, and made-up cookies add
  at most 600 presence rows per address and room in ten minutes. Every
  accepted vote still makes each open phone and the projector fetch the state once more, and the
  server builds it from all votes of the question — a few hundred milliseconds
  per viewer at tens of thousands of votes. The owner's only remedy is
  resetting the room, which deletes the real results as well.
- **Names on the podium.** Anyone with the code can join a quiz under any free
  name and reach the projector's podium. The host can lock joining ("Lock
  joining" in the menus) and remove players — in a moderated quiz from the
  "Participants" list of the lecturer's view and under the standings, in
  a self-paced one from the progress view. A removed player's answers are
  deleted; they can join again from zero points unless joining is locked. In a
  moderated quiz a name is frozen once its player has answered or a question
  has ended. Names are compared without case, invisible characters (every
  character Unicode reserves as "default ignorable", unassigned ones included)
  and lookalike letters (fullwidth forms, Greek or Cyrillic letters that look
  Latin; with PHP's `intl` extension also pairs such as "PauI" and "Paul"), so
  nobody can pass as a player already in the room — not even by re-spelling
  their own name: "paui" may become "PAUI", but not "PauI" next to a "Paul". That occasionally refuses a
  genuine name close to a taken one ("Ian" next to "lan"); adding a letter
  helps.
- **Quiz solutions** (`answerKey`) are not sent to phones or the projector
  before the reveal. A self-paced quiz can still be scouted: a second identity
  that answers anything moves on at once (only an unanswered timed question
  holds it until the time is up), so it sees the whole timed deck before the
  main identity's clock starts, and the main identity still earns full speed
  points. With feedback "After each question" — the race default — leaving a
  question makes its answer final at once and shows whether it was right, so a
  few throwaway identities find the right option of every choice question. For
  anything graded, open the quiz with feedback "At the end", lock joining once
  the class is in and check the names against your class list. vibeARS cannot
  tie an identity to a person; where that matters, use a tool with a sign-in
  for every participant.
- **Framing.** `/apps/vibears/screen/{code}` (the projector view) and
  `/apps/vibears/embed` (the add-in shell) may be framed by any site —
  `frame-ancestors *`, and the web server drops `X-Frame-Options` for exactly
  these paths (see [Embedding in PowerPoint](#embedding-in-powerpoint)). That
  is what the PowerPoint add-in needs. Both are read-only; the projector view
  shows what anyone with the code sees anyway. The voting page `/s/{code}` and
  the rest of Nextcloud keep `SAMEORIGIN`.
- **office.js in your origin.** The add-in shell loads Microsoft's `office.js`
  from Microsoft's CDN into your Nextcloud's origin. Inside PowerPoint there
  is no Nextcloud session, but opened in a browser where you are logged in to
  Nextcloud, that third-party script has the reach of your session. Test the
  add-in address in a private window (see `office-addin/README.md`). The add-in
  manifest asks PowerPoint only for the `Restricted` permission — the document
  settings in which the room code travels with the `.pptx` — so script inside
  the add-in cannot read or change the presentation. A manifest downloaded
  before that change asked for more; download it again.
- **Development files** do not belong in the web root; see
  [Installation](#installation).
- **Logs.** Voter tokens, nicknames and answers are marked as sensitive
  parameters, so they do not end up in exception traces in `nextcloud.log`.
- **Retention.** Rooms, with their answers and nicknames, stay until their
  owner deletes them unless the administration switches the daily deletion
  on — see [Room retention](#room-retention). Without it, old room codes stay
  valid for as long as the room exists.
- As described [above](#how-this-app-was-built-ai-disclosure), the code has not
  had an independent human review or a security audit; the AI agents' own
  security review is summarised in the changelog. Reporting a problem:
  [`SECURITY.md`](SECURITY.md).

## Release

```
build/certificate.sh    # once: key + certificate request for the signature
build/package.sh        # builds, checks, signs and packs build/vibears-<version>.tar.gz
```

`docs/APPSTORE.md` describes the way Pulse took into the Nextcloud App Store —
public repository, certificate, app ID registration, version, signature,
upload. vibeARS is not published there; the document is kept for reference,
and the same steps would apply to the app ID `vibears`.

## Licence

AGPL-3.0-or-later, see [`LICENSE`](LICENSE). Every source file carries the
matching SPDX header (`hotochan123` for Pulse's code, `fre.ms` for the fork's
additions, both where a file was changed). The one exception inside a file: the `copy`, `more`,
`plus` and `close` icons in `src/components/ui/VibearsIcon.vue` are taken from
or adapted from [Feather Icons](https://github.com/feathericons/feather)
(`copy` with a slightly smaller front square), under the MIT License:

<details>
<summary>Feather Icons licence text</summary>

```text
The MIT License (MIT)

Copyright (c) 2013-2023 Cole Bemis

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

</details>

vibeARS is an independent project and is not affiliated with or endorsed by the
providers of Kahoot!, Mentimeter or Slido; these names are trademarks of their
respective owners.

## Known limits and possible extensions

- Languages: English and German. Further languages would go through
  Transifex and need Nextcloud's involvement.
- Nextcloud 34 and 35 only (`min-version="34" max-version="35"`; Pulse's
  test on 35 is described in `docs/APPSTORE.md`).
- Question type image hotspot (tap on an image instead of choosing an answer).
- Self-paced quiz: a rejoin code for switching devices, starting from the
  waiting state without a tap, a demo race and store images; plus small engine
  follow-ups (list in `docs/pace-2026-09/UI.md`).
- The floating results window shows the projector page itself only in
  Chromium browsers; Safari gets the painted card, Firefox the card as a
  video on the page. Whether it stays above a full-screen show on Linux
  depends on the window manager.
- Real push instead of polling — would need a WebSocket service of its own;
  `notify_push` is not an option because participants are anonymous and not
  signed in.
- Ballot stuffing: without an account, the voter cookie only discourages voting
  twice — a documented limit, not fixable without a login. Its cost for other
  viewers is bounded, but every accepted vote still has each open page rebuild
  the state from all votes of the question; a shared cache of the capped public
  results per change would make that one computation for all viewers. Number
  guesses still send one row per distinct value to phones and the projector.
- Self-paced quizzes are open to scouting with a second identity, and with
  feedback after each question to finding the right answers that way (see
  [Security notes](#security-notes)). Holding answered timed questions until
  their time is up, withholding verdicts until the end, or a question order per
  person would each narrow it.
- More than 60 people behind one address (a lecture hall on one NAT) who join
  the same self-paced quiz within ten minutes: the rest get "Too many attempts.
  Please wait a moment." until the window slides on.
- The add-in shell loads `office.js` even when it is opened outside Office;
  loading it only inside an Office host is still to do.
