---
name: time-sheets
description: Reconcile Timing.app activity with Tempo worklogs and prepare a reviewable pending timesheet. Use when the user asks to find unfiled time, create draft Timing time entries, identify missing project allocation or scheduling ambiguities, or prepare a daily/weekly Tempo timesheet for final user review.
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
  rounding, Timing.app entry creation, importer behavior, authorization, and
  database safety. Do not substitute guessed rules.
- Prefer an already configured official Timing MCP server as the Timing data
  interface, following the server-selection and fallback rules in the
  playbook. For the local Mac server registered as `timing`, call `start_here`
  before any other Timing tool except its status tools, and call it again after
  the Timing day boundary changes.

## Reconcile

1. Establish the requested date range and local timezone. State the dates being examined.
2. Read Timing.app activity and existing Timing entries for that range. Use the
   official Timing MCP read tools when available, or the read-only fallback
   specified in the project playbook. Keep Timing access read-only unless the
   current user request explicitly authorizes creating or changing local Timing
   entries.
3. Read Tempo worklogs for the same range. Compare against Jira issues, dates, duration, and descriptions using the playbook's matching rules.
4. Classify each coherent block as filed, missing, duplicate-risk, or uncertain.
   Do not infer an issue key merely from a nearby calendar event, browser tab,
   or generic project name. When a client-project block spans multiple issues
   and no issue clearly wins by supported duration and task/activity count, use
   the playbook's ordered Jira overhead-ticket fallback before flagging it.
   Map ordinary Drupal contribution to `CONTRIB-25` using the playbook's
   canonical general-Drupal, DDEV, or SVG Image Field title. Treat contribution
   associated with IU, `iu_paragraphs`, or Rivet as IULD8 work when a suitable
   IULD8 ticket is evidenced in the same timeframe or local day.
   Preserve separate evidence-backed work chunks at their observed times. Do
   not combine non-contiguous blocks merely to reduce their rounded total, and
   do not relocate time into an otherwise empty part of the day. Anchor each
   reconstructed entry at the start of its own supporting activity and round
   that block independently under the playbook. A small overlap created solely
   by that independent rounding is permissible when the raw evidence intervals
   remain distinct and are not double-counted.
5. When the current user request explicitly authorizes local Timing entry
   creation, create only the permitted, well-supported entries through the
   supported Timing MCP interface when available. Otherwise prepare proposals
   only. Preserve the original evidence and record the rationale for each
   created or proposed entry.

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
