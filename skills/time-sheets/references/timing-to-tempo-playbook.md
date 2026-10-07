# Timing-to-Tempo operational playbook

Use this playbook when reconstructing Timing activity, preparing Timing JSON,
or importing worklogs. Treat time records as financial records: preserve the
source evidence, avoid false precision, and require explicit authorization for
remote writes.

## Use the official Timing MCP before database access

- Prefer Timing's official local Mac MCP server, registered in Codex as
  `timing`, whenever the host can run it. It exposes the richest local activity
  timeline, search, project, rule, and time-entry context. Use the hosted
  `timing-web` server only when it has been explicitly configured and
  authorized for a host that cannot use the Mac server. Do not configure or use
  both servers for the same workflow.
- For the local `timing` server, call `start_here` before every other Timing
  tool except the server's status tools, and call it again after the Timing day
  boundary changes. Follow the current tool and context-file guidance returned
  by `start_here`; do not rely on memorized tool schemas. Keep this repository's
  playbook authoritative for Jira/Tempo allocation, rounding, privacy, and
  authorization rules.
- For review, reconstruction, and unattended reporting, use only MCP tools
  marked read-only. Prefer the local server's activity hierarchy, timeline,
  slice/search, activity statistics, project lookup, and time-entry listing and
  statistics tools. Never file activities, change rules or projects, create or
  edit time entries, start or stop timers, or invoke any other Timing mutation
  during a read-only run.
- A request to review, reconcile, or prepare a pending timesheet does not by
  itself authorize Timing writes. When the current user request explicitly
  authorizes creating or updating local Timing entries, prefer the supported
  MCP time-entry operations over direct database editing. Before the first
  change, record the exact proposed entries or edits in the local review
  artifact, re-check duplicate and overlap risk, and follow the MCP server's
  confirmation requirements. Re-read the affected entries afterward and verify
  their project, title, notes, start, end, duration, and billing classification.
  Never delete entries or change projects, rules, activity assignments, or
  timers without separate explicit authorization.
- If the selected MCP server is missing, unhealthy, unauthorized, or does not
  expose the detail needed for a defensible reconstruction, report that
  limitation and use the documented read-only SQLite fallback. The hosted
  server's hierarchy is not equivalent to the Mac server's detailed local
  timeline and search; use read-only SQLite for missing fine-grained evidence
  rather than inventing precision. Do not bypass macOS privacy controls or
  silently switch between local and hosted servers.
- Timing MCP access does not replace Jira or Tempo access. Validate and enforce
  their read and write authorization boundaries separately.

## Reconstruct work one day at a time

1. Work on one local calendar day at a time. Review both automatic activity
   (including application, title, and path context) and existing manual time
   entries before assigning time. When SQLite fallback is required, those
   sources are `AppActivity` and `TaskActivity`, respectively.
2. Treat manual entries as evidence and anchors for classification, not as
   immutable start or end boundaries. Surrounding automatic activity can show
   that the same work began earlier, continued later, or was interrupted.
   Before analyzing gaps, evaluate pre-existing manual entries whose timestamps
   contain incidental seconds or fall off the applicable clean boundaries. For
   each candidate normalization, use this evidence-based boundary procedure:

   - Inspect the immediately preceding and following automatic activity plus
     adjacent manual entries, using the narrowest relevant local window rather
     than the whole day.
   - Derive the supported work interval from that neighboring evidence. Move a
     start earlier only when preceding activity supports continuous work on the
     same task or project; move it later only to remove unsupported lead-in or a
     conflict. Move an end later only when following activity supports continuous
     work on the same task or project; move it earlier only to remove an
     unsupported tail or a conflict.
   - Choose the nearest clean boundary consistent with the supported interval:
     the 15-minute clock grid for client work and at least the 5-minute clock
     grid for internal, Drupal-contrib, and personal work. Never move a boundary
     merely because it looks tidy.
   - Do not absorb activity supported by another task or project, introduce an
     overlap except under the rounding-only representation allowance below or
     the documented standup and managers-meeting client-task exceptions, or
     bridge an unexplained gap. A short continuity
     gap of less than five minutes may be included in the same entry only when
     direct evidence supports the same original task or project immediately
     before and immediately after the gap, and the gap contains no conflicting
     activity, adjacent manual entry, meeting boundary, or attributable
     alternate task. Treat a gap that meets all of those conditions as
     continuous work for boundary normalization and duration allocation. Never
     bridge a gap of five minutes or longer, or any gap with conflicting or
     ambiguous evidence; flag it instead. If two candidate boundaries remain
     plausible or the needed adjustment is more than minor, flag the entry
     rather than change it.
   - Before modifying the entry, record its old and proposed timestamps,
     duration delta, evidence window, and reason in the local review artifact.
     After each adjustment, re-check all adjacent coverage before continuing.

   Preserve each normalized entry's project, title, intent, and billing
   classification. Use only clearly supported normalized intervals as coverage
   for the remainder of the day's reconstruction.
   Retain one `BSP-536` entry covering the entire recurring standup. This is an
   explicit exception to the usual no-overlap rule: when clearly evidenced
   browser history, chats, or other activity establishes active work on a
   specific client project or task during standup, create a separate overlapping
   entry for that client task in supported 15-minute increments. Do not infer a
   specific task from weak context. Apply the analogous exception to an
   overarching managers-meeting entry: retain the full managers-meeting entry,
   but when direct browser history, chats, repository activity, Jira activity,
   or equivalent evidence establishes active work on a specific client task
   during the meeting, create a separate overlapping entry for that client task
   in supported 15-minute increments. Weak or generic context is insufficient;
   do not infer a client allocation. Neither exception authorizes a duplicate
   standup or managers-meeting entry. Follow-up that belongs to the meeting may
   extend the existing overarching entry rather than creating a duplicate.
3. Group short fragments only when they belong to one coherent block under the
   continuity rule above. A gap of five minutes or longer, or any intervening
   conflicting task, manual entry, meeting boundary, or attributable alternate
   activity, ends the block. Preserve later work as a separate block at its own
   observed start; never combine non-contiguous blocks merely to reduce their
   rounded total or move the combined time into an open slot elsewhere in the
   day.
   - Classify and total the raw supporting intervals before rounding. No raw
     evidence interval may be assigned to more than one ordinary entry.
   - Anchor each new reconstructed entry at the first directly supported
     timestamp for that block. Round its duration independently using the
     applicable increment; do not shift the entry earlier or later just to make
     it fit beside another row.
   - Independent upward rounding may make adjacent Timing entries overlap
     slightly. Keep that rounding-only overlap when it is the minimum expansion
     required by the applicable increment, both raw evidence intervals are
     distinct, and the review artifact records the raw intervals, normalized
     durations, and overlap. This is a representation allowance, not permission
     to double-count evidence or to infer work during an unsupported interval.
     If the raw evidence itself overlaps, the usual no-double-counting rule and
     the documented standup or managers-meeting exceptions still control.
   Exclude idle, duplicated, unrelated, or unsupported intervals so the
   reconstructed day does not double-count raw elapsed time.
4. Prefer direct evidence such as Jira keys in window titles, URLs, terminal
   paths, repository names, documents, messages, and manual notes. Use nearby
   context only when it forms a defensible sequence; mark ambiguity instead of
   inventing precision.
5. Private or incognito activity is not automatically non-billable. It may be
   client work when the surrounding project, ticket, repository, meeting, or
   adjacent activity establishes that classification.
6. Do not bill unattended Cursor or agent execution. Count time only when the
   user was actively prompting, reviewing output, testing, making decisions, or
   acting on the result. Background execution without user involvement is not
   work time.

## Escalate ambiguous evidence carefully

When Timing titles and local Timing data do not establish a Jira task, correlate
only the minimum additional local evidence needed to resolve the ambiguity:

- Standing access for this ChatGPT project includes everything beneath
  `/Users/jameswilson/App`, the Timing data needed for activity reconciliation,
  and the workflow's designated output and backup locations. Repositories,
  files, and Git history under that App folder do not require a case-by-case
  access request when they are needed as time-entry evidence.
- Keep App-folder evidence gathering minimal and relevant. Identify the narrow
  project and time window needed for the specific ambiguity; do not broadly
  scan unrelated projects merely because standing access exists. Git history is
  valuable corroboration for files and work completed when used within this
  scope.
- Request explicit access or authorization for Cursor, ChatGPT, Codex, or other
  non-App application-history storage and for browser, email, or calendar data.
  The request must identify the minimum source and time window needed.
- Treat Cursor, ChatGPT, Codex, and other app-local histories as
  privacy-sensitive. Inspect only the records and time window necessary for the
  disputed block. Except for the read-only Jira research authorized below, do
  not access cloud data, fetch messages, or read or modify remote records
  without explicit authorization.
- To complete reconciliation autonomously when possible, consult prior manual
  Timing entries and prior billing activity available within the standing or
  separately authorized sources. Earlier days or months can suggest likely Jira
  tickets, short-description conventions, and recurring project or epic
  mappings. Treat these as corroborating patterns only: never copy a ticket
  solely because it is common, and never override stronger current-day
  timestamps or context. If ambiguity remains, use the project epic fallback or
  flag the block for review.
- Conversation titles are weak evidence: they may lack a Jira key or may have
  been renamed after the work occurred. Never infer either the ticket or
  billability from an AI conversation title alone. Require timestamp-aligned
  surrounding activity and evidence that the user was actively involved.
- Record a concise provenance note describing the source types used, for example
  "Editor and repository activity corroborated local Cursor history." Keep exact
  evidence timestamps in the local review artifact when they are needed for
  reconciliation; do not repeat them in an entry or worklog note. Do not copy
  prompts, responses, message bodies, or other sensitive conversation content.
  Remember that this importer's `notes` become a remote Jira worklog comment, so
  include only provenance suitable for that audience in import JSON.
- If the evidence still supports client project work but remains mixed across
  tasks, use the project epic fallback described below. Otherwise flag the block
  for review and do not force a ticket assignment or billable classification.

## Assign projects, tickets, and increments

- Client work should use strict 15-minute increments whenever the evidence can
  support that allocation. Consolidate only fragments that form one coherent
  block under the continuity rule, then round that block **upward** to the next
  15-minute increment. Do not round a supported block down. Independently
  evidenced blocks at different times remain separate and are each rounded
  upward at their own observed start. For example, two separate blocks of more
  than 20 minutes become two 30-minute entries at their respective starts, not
  one 30-minute entry placed in a convenient gap.
- For a coherent standalone client block where direct evidence establishes one
  specific client task or project but the raw supported duration is less than
  15 minutes, create a 15-minute client entry only when the raw supported
  duration is at least 8 minutes. When the raw supported duration is under 8
  minutes, combine it with adjacent same-task or same-project activity only when
  the continuity rule above permits; otherwise flag the block rather than
  inflate it. This is the default and yields to any client-specific billing
  requirement. It does not relax the requirement for a well-supported project
  allocation or authorize an entry when the evidence is weak or ambiguous, an
  overlap is unsupported, or conflicting activity is present.
- Internal, Drupal-contrib, and personal work should use 5-minute increments.
  Preserve separate coherent blocks at their observed starts and round each
  supported block upward to the next 5-minute increment unless a more specific
  rule applies.
- Map Drupal contribution work to Jira issue `CONTRIB-25` by default. Use the
  exact canonical title that matches the supported workstream:

  - `CONTRIB-25 - drupal contrib work` for general Drupal contribution,
    including Drupal core patches, Drupal.org dashboard and issue-queue review,
    and cross-pollinating reviews or comments among Drupal.org issues. Notes
    should concisely name the concrete issues, modules, patches, reviews, or
    outcomes, following established patterns such as `Drupal core issues: ...`
    or `Drupal: ...; My dashboard` without copying timestamps.
  - `CONTRIB-25 - ddev contrib work` for work on DDEV, including DDEV tooling,
    `ddev-drupal-contrib`, DDEV documentation or sites, and DDEV issue, pull
    request, or workflow review. Notes should identify the DDEV component and
    concrete activity or outcome.
  - `CONTRIB-25 - svg image field contrib` for work on the user's
    `svg_image_field` contribution project, including its issue queue,
    maintenance, tests, releases, security/dependency work, documentation, and
    related SVG sanitizer integration. Notes should identify the concrete
    module work or outcome.

  Historical Timing entries are corroborating formatting evidence for these
  titles and note styles, but current-day evidence still controls the workstream
  and duration. When a block mixes workstreams, split it only when the evidence
  supports a defensible split; otherwise use the dominant supported workstream
  and describe the secondary activity in the notes.
- Apply an IULD8 override before the `CONTRIB-25` default when Drupal.org or
  repository activity is materially related to Indiana University, IU,
  `iu_paragraphs`, or Rivet. Inspect IULD8 activity in the same timeframe first
  and then elsewhere on the same local day, and assign the contribution to the
  most appropriate IULD8 ticket that was also being worked. Choose by direct
  task relationship and time alignment, not merely by a matching word or by a
  commonly used historical ticket. If several IULD8 tickets remain plausible,
  apply the normal evidence hierarchy and ordered overhead-ticket fallback; if
  no suitable IULD8 ticket is defensible, flag the block rather than defaulting
  it to `CONTRIB-25`. Once assigned to IULD8, treat the block as client work and
  apply the client increment and rounding rules.
- For a new reconstructed Timing entry, preserve the block's actual supported
  start timestamp, including its minute or incidental seconds when moving it to
  a clock grid would misrepresent when the work occurred. Normalize the
  duration upward to a 15-minute increment for client work or a 5-minute
  increment for internal, Drupal-contrib, and personal work. The start itself
  does not need to fall on that clock grid. Never relocate the entry into an
  empty slot merely to avoid a rounding overlap or make the day look tidy.
  Apply the earlier evidence-based boundary procedure when normalizing an
  existing entry; do not rewrite an existing boundary solely to conform to this
  new-entry placement rule.
- Give every importable row one primary Jira issue. Start its title with the
  issue key and a concise description, for example `CHEM-123 - Review migration
  results`.
- File a client meeting on that client's designated meeting issue. The default
  convention is the project's `-1` issue (for example `IULD8-1`, `CHEM-1`, or
  `CCC-1`) when that issue is the project's established Client Meetings ticket.
  Do not assume the convention blindly: when the correct meeting issue is not
  already established by the current evidence or prior verified entries, use
  authenticated read-only Jira research to inspect the relevant project for an
  issue of type `Meeting` and for issues in the `Meetings / Planning / Support`
  epic. Prefer the project-designated Client Meetings issue found there; flag
  the meeting if no defensible meeting issue can be identified. This rule
  applies to the meeting itself, not separately evidenced client-task work that
  is permitted to overlap an overarching meeting under the rules above.
- Use notes to summarize concrete surrounding activity: files or pages reviewed,
  changes made, tests run, decisions made, and relevant collaboration. For a
  mixed block, name every relevant ticket in the notes and identify which work
  belongs to the primary ticket.
- Do not repeat a project-name prefix in notes when the Timing project and
  title already make that context clear; for example, avoid `ARB: ...` on an
  Arboretum entry whose title already identifies the work. For a shared or
  cross-cutting ticket where the project alone is not enough context—especially
  `BSP-598`—begin the notes with the consistent issue-and-workstream prefix
  drawn from the title, such as `BSP-598 - AI Research: ...`, followed by the
  fuller activity description. Use this exception to disambiguate otherwise
  arbitrary detailed notes, not to duplicate labels mechanically.
- Omit the label `Private Browsing` from notes when creating or cleaning up
  Timing entries. It is an implementation detail, not useful work context, and
  can disclose more than the entry needs. Describe only the supported task,
  activity, outcome, and suitable provenance instead. Retain it only when the
  user explicitly asks to preserve that label for a specific entry.
- Do not include exact timestamps, clock times, or start/end time ranges in
  Timing entry titles, Timing notes, Jira or Tempo worklog comments, or
  ticket-facing descriptions created by this workflow. Entry and worklog
  metadata already records when the work occurred. Use concise task, activity,
  outcome, and suitable provenance text without duplicating clock ranges. Apply
  this formatting rule prospectively; do not edit existing Timing, Jira, or
  Tempo records solely to remove previously recorded times.
- Split mixed work among tickets when the evidence supports a defensible split.
- When current evidence does not establish the appropriate issue, authenticated,
  read-only Jira research is authorized. Validate the configured Jira identity
  and live read access before relying on it. For a specific candidate issue,
  inspect its description, full comments, and changelog entries around the date
  being reconstructed.
- Treat current Jira comments and changelog entries as corroboration, not
  conclusive proof that time was worked.
- When a coherent client-project block spans multiple Jira issues, first compare
  the statistical distribution of supported duration and task/activity counts.
  If no single issue is a clear winner on that evidence, use authenticated
  read-only Jira research to search within the project for an applicable
  overhead ticket in this strict order: **Tech Lead**, **Sprint Planning**,
  **Support**, **Triage**, then **Estimates**. Use the first category with an
  existing ticket whose scope fits the observed work; do not select a ticket
  from its name alone or skip a fitting higher-priority category for a lower
  one. This fallback does not override a clear primary issue established by
  direct evidence. If none of the ordered categories yields a defensible
  ticket, continue to the project-epic fallback below or flag the block.
- When client project work is supported but no single ticket is defensible, use
  the relevant project epic as the primary issue and explain the mixed project
  context and relevant ticket keys in the notes. If neither a specific ticket
  nor the epic is defensible, flag the block for review.
- This read authorization does not permit creating or editing Jira issues,
  comments, or worklogs. Never submit worklogs to Jira or time to Tempo without
  separate explicit authorization.

## Respect the importer's actual behavior

The current script is a fixed-input Jira worklog importer, not a Timing reader
or a Tempo-specific client:

- It reads `files/All Activities.json`; there are no command-line options for
  choosing an input file.
- It expects Timing-style `project`, `title`, `notes`, `duration`, and
  `startDate` fields. `duration` must be `HH:MM:SS`, and `startDate` must be an
  ISO 8601 timestamp.
- It chooses the first Jira key found in `title`, then `project`, then `notes`.
  A mixed-ticket row still creates only one worklog, on that primary issue.
- Its worklog comment comes from `notes`, then `title`, then `project`. A leading
  issue key is stripped from the comment.
- `jira_hours_format()` rounds **each input row upward to the next 15 minutes**.
  Therefore, consolidate only continuity-eligible fragments within each
  coherent client block and normalize each independent row before import. Do
  not merge blocks from different times merely to reduce rounding. Do not run
  5-minute internal, Drupal-contrib, or personal entries through this script:
  it would inflate them to 15 minutes. Keep those entries in Timing or use a
  separately authorized destination workflow that preserves 5-minute values.
- With `--dry-run`, the script skips the authentication preflight and Jira's
  `addWorklog()` API while preserving its parsing and output behavior. Without
  that flag, it performs the preflight and calls `addWorklog()` for every valid
  row. Those Jira worklogs may be consumed or displayed by Tempo, but this code
  does not call a Tempo API.

Never execute the importer merely to validate an export. First review the JSON
locally, then run `php jira-worklog-import.php --dry-run` and verify that every
parsed line has the intended date, issue, duration, and comment. Run without
`--dry-run` only when the user specifically authorizes modifying remote Jira
worklogs or submitting time to Tempo. Do not create, edit, or delete other
remote Jira records as part of reconstruction.

## Protect the Timing database

Prefer the official Timing MCP for supported reads and explicitly authorized
time-entry writes. When MCP is unavailable or lacks the fine-grained local
activity evidence needed for reconciliation, use SQLite's read-only mode
against `~/Library/Application Support/info.eurocomp.Timing2/SQLite.db`.

Direct database editing is an exceptional fallback. Use it only when the user
has explicitly authorized the specific local Timing changes and the supported
MCP write interface is unavailable or cannot perform them. Before any direct
edit:

- To avoid disrupting work on the active macOS desktop, prefer assigning
  Timing to a dedicated empty Space before the maintenance window and reopen it
  there for verification. Treat Space placement only as focus isolation: a
  window on another Space, a hidden window, or an app with no visible windows
  may still hold the database open and never satisfies the quit requirement.
  Verify that the Timing process has exited before checkpointing or backing up
  the database. Prefer a persistent macOS app-to-Space assignment over scripted
  Mission Control keystrokes, which can steal focus or move the wrong window.
  Do not create, switch, reorder, or remove Spaces during active user work
  unless the user has explicitly authorized that UI action. Reopen Timing on
  its assigned Space and perform the required in-app verification there; Space
  isolation does not replace any backup, integrity, or readback step below.

1. Quit Timing and verify that no Timing process is using the database.
2. Record the database path and inspect the current schema; do not assume column
   names or relationships from an older Timing version.
3. Checkpoint the write-ahead log, then create a timestamped SQLite backup with
   the SQLite backup mechanism. Preserve any existing `-wal` and `-shm` files
   until the operation is fully verified.
4. Run `PRAGMA integrity_check` against the backup and require an `ok` result.
   Record a checksum and keep this backup as the rollback copy.
5. Preview the exact rows and expected row count. Perform the smallest edit in a
   single explicit transaction (`BEGIN IMMEDIATE`); roll back if the affected
   rows or resulting values differ from expectations.
6. After commit, query the changed rows again and run `PRAGMA integrity_check`
   against the live database. Reopen Timing only after it returns `ok`.
7. Confirm the edited entries in Timing and retain the verified rollback backup.
   Never delete rollback backups as part of the edit session.

Timing MCP writes, direct database editing, and remote submission are separate
authorization boundaries. Permission to repair local Timing entries does not
authorize Jira or Tempo changes, and permission to submit approved worklogs
does not authorize Timing MCP or direct database changes.
