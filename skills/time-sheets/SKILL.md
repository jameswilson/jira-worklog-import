---
name: time-sheets
description: Reconcile Timing.app activity with Tempo worklogs, create editable local Timing entries for bounded worklog requests, and learn from the user's reviewed Timing export. Use when the user asks to find unfiled time, create or reconstruct Timing entries, identify missing project allocation or scheduling ambiguities, prepare a daily or weekly Tempo timesheet, or process the reviewed All Activities.json export.
---

# Time Sheets

Prepare worklogs for review; do not submit, approve, or alter existing Tempo worklogs.

## Start in the right task

- Create or continue the dedicated BSP-598 Time Sheets project task when project execution is needed. Keep live voice conversation in the coordinator task.
- Resolve the real filesystem path of the loaded `SKILL.md`, following any
  symlinks. From the directory containing that resolved skill file, read the
  sibling reference `references/timing-to-tempo-playbook.md` completely before
  interpreting activity or creating entries.
- Verify that `references/timing-to-tempo-playbook.md` exists relative to the
  resolved skill directory before proceeding. If it does not, stop before any
  time analysis or write and report the missing skill reference. Do not rely on
  the process working directory, derive a project root, or search arbitrary
  parent directories.
- Treat the sibling reference as the authoritative source for matching,
  rounding, Timing.app entry creation, reviewed-export feedback, importer
  behavior, authorization, and database safety. Do not duplicate its policy in
  `AGENTS.md` or substitute guessed rules.
- Prefer an already configured official Timing MCP server as the Timing data
  interface, following the server-selection and fallback rules in the
  playbook. For the local Mac server registered as `timing`, call `start_here`
  before any other Timing tool except its status tools, and call it again after
  the Timing day boundary changes.

## Reconcile

1. Establish the requested date range and local timezone. State the dates being examined.
2. Read Timing.app activity and existing Timing entries for that range. Use the
   official Timing MCP read tools when available, or the read-only fallback
   specified in the project playbook. Apply the playbook's request-intent rules:
   a bounded request to create, reconstruct, fill, or make worklogs authorizes
   editable, manifest-tracked local Timing entries for those dates, while a
   request only to review, analyze, reconcile, or report remains read-only.
3. Read Tempo worklogs for the same range. Compare against Jira issues, dates, duration, and descriptions using the playbook's matching rules.
4. Classify each coherent block as filed, missing, duplicate-risk, or uncertain.
   Do not infer an issue key merely from a nearby calendar event, browser tab,
   or generic project name. When a client-project block spans multiple issues
   and no issue clearly wins by supported duration and task/activity count, use
   the playbook's ordered Jira overhead-ticket fallback before flagging it.
   Map ordinary Drupal contribution to `CONTRIB-25` using the playbook's
   canonical Jira-summary title and put the supported general-Drupal, DDEV, or
   SVG Image Field workstream and concrete activity in the notes. Treat
   contribution associated with IU, `iu_paragraphs`, or Rivet as IULD8 work
   when a suitable IULD8 ticket is evidenced in the same timeframe or local
   day.
   Preserve separate evidence-backed work chunks at their observed times. Do
   not combine non-contiguous blocks merely to reduce their rounded total, and
   do not relocate time into an otherwise empty part of the day. Anchor each
   reconstructed entry at the start of its own supporting activity and round
   that block independently under the playbook. A small overlap created solely
   by that independent rounding is permissible when the raw evidence intervals
   remain distinct and are not double-counted.
5. When the request authorizes local Timing entry creation under the playbook,
   create only the permitted, well-supported entries through the supported
   Timing MCP interface when available. Otherwise prepare proposals only.
   Preserve the original evidence and record the rationale for each created or
   proposed entry. Local Timing authorization never authorizes Jira or Tempo
   submission.

## Close the review loop

1. For every run that creates local Timing entries, preserve the original
   proposal as a pending manifest using the schema and storage rules in the
   playbook. Record the verified Timing entry identifiers after creation.
2. Before processing a newer `files/All Activities.json` export, match it to
   every pending run whose requested dates it covers. Treat that export as the
   user's reviewed result only after the playbook's freshness, coverage, and
   ambiguity checks pass.
3. Capture accepted, changed, deleted, split, merged, reassigned, and manually
   added entries as reviewed examples without requiring a separate learning
   request. Do not mark an ambiguous comparison as learned.
4. Load relevant reviewed examples automatically during later reconciliation.
   Current evidence, explicit user direction, and the playbook's financial and
   authorization safeguards always outrank historical preferences.
5. Feedback capture is local and may happen automatically. Importing worklogs
   into Jira or Tempo remains a separate explicit action and must follow the
   playbook's dry-run and duplicate safeguards. Use durable receipt checks once
   they are implemented; do not claim they exist before then.

## Flag before handoff

Flag rather than create an entry when any time block has an unresolved issue, including:

- no specific client/project, Jira issue, or permissible allocation;
- a plausible but non-unique issue match;
- an unsupported overlap, a gap bridged contrary to the continuity rule, raw
  evidence counted twice, or a conflict with an existing Timing or Tempo entry;
  a rounding-only overlap explicitly permitted by the playbook is not by itself
  a flag;
- a calendar, meeting, administrative, PTO, or idle block whose handling is not established by the playbook;
- an incomplete day, unusually long day, or duration that requires rounding outside the rules;
- insufficient Timing evidence to distinguish work from background activity.

## Review handoff

Return a concise pending-timesheet review containing:

- date range and timezone examined;
- each day’s filed Tempo total, supported draft total, and unresolved total;
- proposed entries with Jira key, project, duration, short description, and evidence basis;
- the raw interval and normalized duration for each separate work chunk, plus
  any rounding-only overlap retained to preserve its evidence-backed start;
- each flagged day/block, its duration, and the precise decision needed;
- confirmation that no Tempo submission or approval was performed.

Ask for user review whenever a flag remains. Only submit or approve a timesheet after the user explicitly authorizes that final action.
