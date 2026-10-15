<!--
  - SPDX-FileCopyrightText: 2026 fre.ms
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Roadmap: gaps compared with commercial audience response systems

Written 14 October 2026 against Kahoot! (EDU, 2025 and 2026 feature set),
Mentimeter, Slido, Wooclap, Poll Everywhere and the open-source Particify
(ARSnova). The point of view is a university lecture: a hall of a few hundred
students, questions of exam quality, decks kept for several semesters.

## Where vibeARS is already ahead

- Matching questions, and scales as a single slider, a spectrum (radar) and a
  two-axis compass with a heat map.
- Self-paced quizzes with a deadline (homework), progress view and free-text
  grading.
- Decks as Markdown files with a YAML header: they live in git and diff
  cleanly.
- PowerPoint add-in without a cloud service in between.
- Choice of the join address on an instance with several names.
- Self-hosted, no player cap per plan, no cost per seat, no third-party
  scripts on the participant's phone.

## What is missing

- **Content and media:** no content slides between questions, one image per
  question only, no images as answer options, no video or audio. ~~Images
  are lost on deck export.~~ (done, see rank 7) ~~No formulas or markup in
  the question text.~~ (done, see rank 3)
- **Question types:** no image hotspot, no open-text poll with idea collection
  and voting, no confidence rating on an answer, no fill-in-the-blank.
- **Audience interaction:** no audience questions with upvotes, no standing
  live feedback on the lecture's pace, no reactions.
- **Game mechanics:** no double or zero points per question, no streak bonus,
  no mode without speed bonus, no mode without leaderboard, no team mode, no
  podium, no sounds, no nickname generator, no name filter.
- **Analysis:** ~~only distributions and CSV, no figures per question.~~ (done,
  see rank 1) No report per person across several runs.
- **Author comfort:** no question bank with folders and tags inside the
  app, no import from Excel, Moodle or Kahoot, no AI generation, English
  and German only. ~~No sharing of decks with colleagues.~~ (done, see
  rank 6: deck files live in Nextcloud Files and travel with its shares)
- **Platform:** no LMS integration, no real-time push, no device switch in a
  self-paced quiz, no study modes such as flashcards.

## Prioritised list of what can be retrofitted

Effort: *small* means a few days, *medium* one to two weeks, *large* several
weeks. The order weighs the benefit in a lecture against effort and fit with
the architecture (anonymous participants, polling, Nextcloud conventions).

| Rank | Feature | Effort | Why here | Status |
|---|---|---|---|---|
| 1 | Item statistics per question in the results: difficulty, discrimination, distractor distribution, also in the CSV export | small | All the data is there. This goes beyond Kahoot's report and matches the practice of checked quality indices for exam questions. | done (unreleased) |
| 2 | Content slides in the deck, type `slide` with text and image | small to medium | A question type without voting; the projector shows the slide, the phone a hint. The Markdown format and the import are ready for it. | planned |
| 3 | Formulas and simple markup in the question text: KaTeX and a Markdown subset, bundled locally | small to medium | Indispensable for statistics and informatics. A local bundle stays within the CSP. | done (unreleased) |
| 4 | Live feedback widget on the pace, permanently open, aggregated on the projector | small to medium | Kahoot does not have it, Particify and ARSnova do. In a lecture worth more than any game mechanic. | planned |
| 5 | Scoring options: factor per question, streak bonus, mode without speed bonus, mode without leaderboard | small | Kahoot calls these the Accuracy and Lecture modes. The scoring is encapsulated in `AnswerRules` and `QuizService`. | planned |
| 6 | Open and save deck files in Nextcloud Files, including "Import into vibeARS" on a Markdown or ZIP file in the Files app | small to medium | Replaces Kahoot's question bank and shared folders with Nextcloud shares. The native way. | done (unreleased) |
| 7 | Images in the deck export, a ZIP with the Markdown and an image folder | small to medium | Closes the documented gap and makes rank 6 complete. | done (unreleased) |
| 8 | Audience questions with upvotes and moderation | medium | Slido's core feature. New entity, moderation view, projector display. | planned |
| 9 | Open-text poll with cards on the projector and optional voting | medium | Kahoot's Brainstorm and Open-ended. The release logic of `TextGrading` can be reused; moderation against abuse is a must. | planned |
| 10 | Import from Moodle GIFT, the Kahoot Excel template and CSV | small to medium per format | Everything maps onto the existing Markdown model. GIFT first, because university question banks live there. | planned |
| 11 | Images as answer options | medium | Storage budget and re-encoding exist. Touches phone, projector, results and export. | planned |
| 12 | Confidence rating on an answer | small to medium | Kahoot introduced it in 2026. Didactically rewarding, shows gaps in knowledge. | planned |
| 13 | Image hotspot, poll and quiz variant | medium | Coordinates, target region, heat map. The compass type's heat map can be reused. | planned |
| 14 | Rejoin code for switching devices in a self-paced quiz | small to medium | Listed as an open point in the README. | planned |
| 15 | Anonymous self-paced poll as a course evaluation | medium | Self-paced exists only in quiz mode with names. | planned |
| 16 | Nickname generator and name filter | small | Word lists per language. The spoof checker for confusable names exists. | planned |
| 17 | Podium, confetti, sounds | small | A matter of taste. Sounds need royalty-free files. | planned |
| 18 | Further languages | organisational | Through Transifex; needs translators. | planned |

## Not worth it, or only at a large cost

- **Video and audio in questions:** embedding YouTube contradicts the privacy
  stance. Media hosted in Nextcloud would be a medium effort, but streaming
  to several hundred phones loads the server.
- **AI question generator:** feasible through Nextcloud's TaskProcessing API,
  but it only helps instances with an LLM provider. The Markdown format is
  already LLM-friendly: a deck can be generated outside and imported today.
- **Team mode** with a shared device and discussion time: medium to large
  effort, little benefit in a lecture hall.
- **LMS integration** through LTI: large. Deck files and CSV are the
  pragmatic substitute.
- **Real-time push** over WebSockets: large, a service of its own. Polling
  is enough at lecture size.
- **Study modes** such as flashcards and self-tests: medium to large. The
  practice run of a self-paced quiz covers part of it.
- **Authenticated participation** for attendance records: technically medium,
  but a design decision against anonymity. If an attendance requirement
  needs it, a separate room mode with Nextcloud login would be the clean way.

## Sources

- [Kahoot! question types videos](https://support.kahoot.com/hc/en-us/articles/43381734820627-Kahoot-question-types-videos)
- [Kahoot! test matrix](https://kahoot.com/test-matrix/)
- [Kahoot! latest learning innovations, ISTELive 26](https://kahoot.com/blog/2026/06/23/latest-learning-innovations-istelive-26/)
- [Kahoot! EDU quarterly newsletter Q3 2026](https://support.kahoot.com/hc/en-us/articles/37500117906589)
- [Kahoot! EDU summit 2025 recap](https://kahoot.com/blog/2025/06/11/kahoot-edu-summit-2025-engaging-learning-for-all-lets-recap-the-key-takeaways/)
