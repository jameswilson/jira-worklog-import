# Recurring time-entry patterns

## Purpose and scope

This reference is a corroboration aid for the Time Sheets workflow. It summarizes
recurring manual Timing entries and current Jira overhead-ticket structures so a
reviewer can recognize likely patterns more quickly. It is not an assignment
engine: recurrence, a familiar title, or a matching project is never sufficient
by itself to select a Jira issue or create time.

Use this file only after applying the direct-evidence, duplicate, continuity,
rounding, meeting-overlap, and boundary rules in the authoritative playbook.
Direct evidence for the specific work period always wins.

## Evidence window and method

- Local date window: **2026-02-20 through 2026-08-19**, inclusive, in
  `America/Guayaquil`. This is the six-month rolling calendar interval ending on
  the evidence date.
- Timing source: the local Timing SQLite database, queried read-only. The sample
  contains **1,801** non-deleted `TaskActivity` rows totaling about **1,207.04
  hours**; 1,796 rows have a title and 1,460 have notes.
- Timing fields analyzed: `TaskActivity.projectID`, `title`, and `notes`, joined
  to the local `Project` table. Counts below are entry counts, not hours.
- Jira source: authenticated read-only Jira queries against the Bluespark Cloud
  site, observed on 2026-08-19. Exact issues were re-read for summary, type,
  parent, status, description, and comments where present.
- Similar titles were grouped only when the Jira key, intended project, and work
  category were consistent. Punctuation and capitalization variants were
  grouped; semantically different activities sharing a broad issue were kept as
  subpatterns.
- Examples are sanitized models. They intentionally omit names, private channel
  details, URLs, prompts, file paths, and clock data found in some source notes.

## Strong exact combinations

These exact Project + Title + Notes combinations recur often enough to be useful
as title or note-shape evidence. A blank note is evidence of historical usage,
not a recommendation to omit a useful outcome.

| Timing project | Exact title | Exact note form | Entries | Observed dates |
| --- | --- | --- | ---: | --- |
| BSP-536 - Standup / Team Planning | `BSP-536 - Standup` | blank | 100 | 2026-02-20 to 2026-08-18 |
| BSP - Bluespark Internal | `BSP-598 - Timesheets` | blank | 36 | 2026-02-23 to 2026-08-19 |
| BSP-598 - Internal Communication | `BSP-598 - slack activity` | blank | 23 | 2026-02-23 to 2026-08-11 |
| BSP-536 - Standup / Team Planning | `BSP-598 - managers meeting` | blank | 14 | 2026-03-11 to 2026-08-19 |
| CHEM - ChemEdX | `CHEM-4 - Tech Lead` | `S8 Board Triage` | 7 | 2026-04-15 to 2026-04-16 |
| IUL - Indiana University Libraries | `IULD8-1 - Client Checkin` | blank | 7 | 2026-02-24 to 2026-08-11 |
| Contrib | `CONTRIB-25 - drupal contrib work` | blank | 3 | 2026-03-23 to 2026-04-21 |
| IUL - Indiana University Libraries | `IULD8-11 - Lead dev` | `Board triage` | 2 | 2026-05-26 to 2026-06-02 |

## Verified recurring lookup

The count is the whole title family in the evidence window. “Jira parent” is the
verified parent or epic-like container when Jira returned one.

| Work pattern | Timing title family and sanitized note model | Project | Entries and span | Verified Jira issue and parent | Confidence |
| --- | --- | --- | --- | --- | --- |
| Internal standup | `BSP-536 - Standup`; “Reviewed project boards and team priorities.” The family includes six punctuation or wording variants. | BSP internal | 140; 2026-02-20 to 2026-08-19 | [BSP-536](https://bluespark.atlassian.net/browse/BSP-536), **Dev Stand Up** | High |
| Timesheet reconciliation | `BSP-598 - Timesheets`; “BSP-598 - Timesheets: Reconciled Timing activity and reviewed time-entry coverage.” | BSP internal | 42; 2026-02-23 to 2026-08-19 | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598), **BSP Meetings / Planning / Support (timesheets go here)**; parent [BSP-9](https://bluespark.atlassian.net/browse/BSP-9) | High |
| Internal communication | `BSP-598 - slack activity`; “BSP-598 - Slack Activity: Reviewed internal messages, team threads, and project coordination.” | BSP internal | 77; 2026-02-23 to 2026-08-11 | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598); parent BSP-9 | High |
| Managers meeting | `BSP-598 - managers meeting`; “BSP-598 - Managers Meeting: Discussed operational priorities and team planning.” | BSP internal | 24; 2026-03-04 to 2026-08-19 | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598); parent BSP-9 | High |
| Internal research | `BSP-598 - research` or `BSP-598 - AI research`; “BSP-598 - AI Research: Researched the documented technical topic and summarized findings.” Keep the supported workstream and subject in the note. | BSP internal | 50; 2026-02-26 to 2026-08-05 | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598); parent BSP-9 | High for the issue, medium for the sublabel |
| Local environment maintenance | `BSP-598 - local environment`; “BSP-598 - Local Environment: Maintained local development tooling and environment configuration.” | BSP internal | 29; 2026-02-24 to 2026-08-06 | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598); parent BSP-9 | High |
| Drupal and open-source contribution | `CONTRIB-25 - drupal contrib work`, `ddev contrib work`, `Github contrib`, or the named module subpattern; “Investigated the issue and prepared the open-source contribution.” Combine variants only when activity identifies the contribution. | Contrib | 99; 2026-02-23 to 2026-08-11 | [CONTRIB-25](https://bluespark.atlassian.net/browse/CONTRIB-25), **Drupal Contrib Work** | High |
| ChemEdX technical lead | `CHEM-4 - Tech Lead`; “Triaged the sprint board, reviewed estimates, and updated ticket status.” | CHEM | 24; 2026-03-06 to 2026-07-30 | [CHEM-4](https://bluespark.atlassian.net/browse/CHEM-4), **DIV: Tech Planning & Consulting**; parent [CHEM-8](https://bluespark.atlassian.net/browse/CHEM-8) | High |
| ChemEdX client meeting | `CHEM-1 - Client Meetings`; “Met with client stakeholders to review the sprint board and next steps.” | CHEM | 17; 2026-03-06 to 2026-08-14 | [CHEM-1](https://bluespark.atlassian.net/browse/CHEM-1), **DIV: Client Meetings**; parent CHEM-8 | Medium: four entries use a conflicting Timing project |
| ChemEdX internal coordination | `CHEM-2 - Internal Meetings`; “Coordinated internal project status and delivery planning.” | CHEM | 5; 2026-02-25 to 2026-03-12 | [CHEM-2](https://bluespark.atlassian.net/browse/CHEM-2), **DIV: AM / PM / Team Meetings / Coord**; parent CHEM-8 | High |
| IUL client meeting | `IULD8-1 - Client Checkin`; “Reviewed the client board, release plan, and follow-up actions.” | IUL | 10; 2026-02-24 to 2026-08-11 | [IULD8-1](https://bluespark.atlassian.net/browse/IULD8-1), **Client Meetings**; parent [IULD8-16](https://bluespark.atlassian.net/browse/IULD8-16) | High |
| IUL technical lead | `IULD8-11 - Lead dev`; “Triaged the board, estimated upcoming work, and coordinated support.” | IUL | 13; 2026-03-16 to 2026-07-28 | [IULD8-11](https://bluespark.atlassian.net/browse/IULD8-11), **Technical Lead & Developer Coordination Consulting**; parent IULD8-16 | High |
| ARB technical lead | `ARB-4 - Lead Dev` or `ARB-4 - Technical Strategy & Lead Dev`; “Reviewed the sprint board, estimates, release readiness, and client coordination.” | ARB | 18; 2026-06-08 to 2026-08-05 | [ARB-4](https://bluespark.atlassian.net/browse/ARB-4), **Technical Strategy & Lead Dev**; parent [ARB-11](https://bluespark.atlassian.net/browse/ARB-11) | High |
| CSM client meeting | `CSM-1 - Client checkin`; “Met with client stakeholders to review progress and decisions.” | CSM | 5; 2026-02-26 to 2026-06-11 | [CSM-1](https://bluespark.atlassian.net/browse/CSM-1), **Client Meetings**; parent [CSM-43](https://bluespark.atlassian.net/browse/CSM-43) | High |
| CSM internal coordination | `CSM-2 - Internal Meetings`; “Reviewed sprint scope and coordinated architecture and delivery decisions.” | CSM | 14; 2026-02-20 to 2026-06-15 | [CSM-2](https://bluespark.atlassian.net/browse/CSM-2), **Internal Meetings AM/PM**; parent CSM-43 | High |
| ABT technical leadership | `ABT-58 - Lead Dev`; “Reviewed tickets, release status, and client coordination.” | ABT | 7; 2026-03-13 to 2026-05-27 | [ABT-58](https://bluespark.atlassian.net/browse/ABT-58), **Technical Leadership & Coordination**; parent [ABT-723](https://bluespark.atlassian.net/browse/ABT-723) | High |
| SHD8 technical lead | `SHD8-11 - Tech Lead & Dev Coordination`; “Reviewed technical planning and developer coordination.” | SHD8 | 1; 2026-03-25 | [SHD8-11](https://bluespark.atlassian.net/browse/SHD8-11), **Technical Lead & Developer Coordination Consulting**; parent [SHD8-18](https://bluespark.atlassian.net/browse/SHD8-18) | Medium: Jira is verified but recurrence is not established |

## Verified Jira overhead map

This table verifies that the tickets exist and describes their current Jira
structure. It does not prove that a particular activity block belongs to them.

| Project | Client meetings | PM or internal coordination | Planning, lead, consulting, or support | Parent or current-state note |
| --- | --- | --- | --- | --- |
| BSP | — | [BSP-598](https://bluespark.atlassian.net/browse/BSP-598) for internal meetings, planning, support, and timesheets | [BSP-536](https://bluespark.atlassian.net/browse/BSP-536) for standup | BSP-598 is a sub-task of [BSP-9](https://bluespark.atlassian.net/browse/BSP-9) |
| CONTRIB | — | [CONTRIB-1](https://bluespark.atlassian.net/browse/CONTRIB-1), Meetings, Planning & Research | [CONTRIB-25](https://bluespark.atlassian.net/browse/CONTRIB-25), Drupal Contrib Work | CONTRIB-1 is Needs Work; CONTRIB-25 is New |
| CHEM | [CHEM-1](https://bluespark.atlassian.net/browse/CHEM-1) | [CHEM-2](https://bluespark.atlassian.net/browse/CHEM-2) | [CHEM-3](https://bluespark.atlassian.net/browse/CHEM-3) sprint planning; [CHEM-4](https://bluespark.atlassian.net/browse/CHEM-4) consulting; [CHEM-5](https://bluespark.atlassian.net/browse/CHEM-5) review and release prep | All are Meeting issues under CHEM-8 and New |
| IULD8 | [IULD8-1](https://bluespark.atlassian.net/browse/IULD8-1) | [IULD8-2](https://bluespark.atlassian.net/browse/IULD8-2), Project Management | [IULD8-11](https://bluespark.atlassian.net/browse/IULD8-11), technical lead and coordination | All are Meeting issues under IULD8-16; IULD8-2 is In Progress |
| ARB | [ARB-1](https://bluespark.atlassian.net/browse/ARB-1) | [ARB-2](https://bluespark.atlassian.net/browse/ARB-2), PM / Internal Meetings | [ARB-3](https://bluespark.atlassian.net/browse/ARB-3) sprint planning; [ARB-4](https://bluespark.atlassian.net/browse/ARB-4) technical strategy; [ARB-5](https://bluespark.atlassian.net/browse/ARB-5) review and testing | All are Meeting issues under ARB-11 and New |
| CSM | [CSM-1](https://bluespark.atlassian.net/browse/CSM-1) | [CSM-2](https://bluespark.atlassian.net/browse/CSM-2), Internal Meetings | [CSM-3](https://bluespark.atlassian.net/browse/CSM-3), Strategist; [CSM-4](https://bluespark.atlassian.net/browse/CSM-4), Tech Lead / Review / Release / Support | All are Meeting issues under CSM-43 and New |
| ABT | [ABT-60](https://bluespark.atlassian.net/browse/ABT-60) | [ABT-3](https://bluespark.atlassian.net/browse/ABT-3), Project Management | [ABT-58](https://bluespark.atlassian.net/browse/ABT-58), Technical Leadership & Coordination | All are Meeting issues under ABT-723 and New |
| BCCE | [BCCE-1](https://bluespark.atlassian.net/browse/BCCE-1) | [BCCE-2](https://bluespark.atlassian.net/browse/BCCE-2) | [BCCE-3](https://bluespark.atlassian.net/browse/BCCE-3) planning; [BCCE-4](https://bluespark.atlassian.net/browse/BCCE-4) consulting; [BCCE-5](https://bluespark.atlassian.net/browse/BCCE-5) review and release prep | Project and listed issues are archived or Closed; re-check before reuse |
| CCC | [CCC-1](https://bluespark.atlassian.net/browse/CCC-1) | [CCC-2](https://bluespark.atlassian.net/browse/CCC-2) | [CCC-3](https://bluespark.atlassian.net/browse/CCC-3) planning; [CCC-4](https://bluespark.atlassian.net/browse/CCC-4) consulting; [CCC-5](https://bluespark.atlassian.net/browse/CCC-5) review and testing | All are Meeting issues under CCC-7 and New; no material recurring Timing sample in this window |
| SHD8 | [SHD8-1](https://bluespark.atlassian.net/browse/SHD8-1) | [SHD8-2](https://bluespark.atlassian.net/browse/SHD8-2), Project Management | [SHD8-11](https://bluespark.atlassian.net/browse/SHD8-11), technical lead and coordination | SHD8-1 and SHD8-11 are New; SHD8-2 is Closed |

### The project `-1` client-meeting convention

Jira verifies `-1` as a Client Meetings issue for **CHEM, IULD8, ARB, CSM,
BCCE, CCC, and SHD8**. This is a project-specific convention, not a formula.
Always read the issue before using it. In particular, **ABT-1 is a closed,
unrelated content-type task**; ABT client meetings belong to **ABT-60**. The
historical Timing title `ABT-1 - Client Checkin` must therefore not be reused.
BCCE-1 is also Closed with the archived project and requires current-project
confirmation before any new allocation.

## Candidate patterns requiring review

| Candidate | Evidence | Required decision |
| --- | --- | --- |
| BCCE internal planning | `BCCE-2 - Internal Meetings / Coord / Planning` occurs 20 times, but only 10 entries are under the BCCE Timing project and 10 are under CHEM. Jira says BCCE-2 is Closed. | Confirm the actual client project and current billable ticket. Do not infer BCCE-2 from the title. |
| CHEM client meeting project drift | `CHEM-1 - Client Meetings` occurs 17 times; 13 are under CHEM, three under an archived DivCHED project, and one under BSP standup planning. | Require direct meeting and project evidence; keep the conflicting entries as historical anomalies. |
| Broad BSP research | `BSP-598 - research` and `BSP-598 - AI research` recur 50 times, but subjects and outcomes vary. | Use BSP-598 only for directly supported internal research. Preserve the specific subject in a sanitized note. |
| Broad BSP environment work | `BSP-598 - local environment` occurs 29 times across the BSP root, a nested local-environment project, and one Contrib project. | Distinguish internal environment maintenance from client or contrib work using the actual activity. |
| ABT client check-in title | One Timing entry uses `ABT-1 - Client Checkin`. Jira proves ABT-1 is unrelated and ABT-60 is Client Meetings. | Treat the historical title as invalid mapping evidence. Use ABT-60 only when the client meeting itself is directly supported. |
| Archived BCCE overhead tickets | BCCE-1 through BCCE-5 are real Meeting issues but are Closed with the archived project. | Do not reuse them without current Jira and project-state confirmation. |
| Low-frequency client overhead | ABT-60, CCC-1 through CCC-5, and SHD8 overhead tickets are Jira-verified but have little or no recurring Timing evidence in this window. | Treat them as lookup candidates, not established personal patterns. |
| Cross-project title mismatches | Other source rows include a client issue key under a different client's Timing project. | A key-looking title never repairs conflicting project evidence. Flag or correct the project only through the normal review workflow. |

## Decision hierarchy

1. Establish the coherent work interval from direct automatic activity, adjacent
   manual entries, browser or repository evidence, Jira context, and other
   authorized sources.
2. Identify the actual client or internal project before considering a recurring
   title. Conflicting project evidence blocks automatic assignment.
3. Prefer a directly evidenced specific task issue over any overhead ticket.
4. For genuine meetings, planning, PM, support, or technical-lead work, verify
   the current Jira issue summary, status, parent, and project. Newer Jira
   comments override stale descriptions or historical Timing wording.
5. Use this pattern map only as corroboration for title shape, likely lookup
   candidates, and sanitized note style.
6. Apply the playbook's rounding, continuity, overlap, duplicate, and boundary
   rules after the issue and project are supported.
7. If more than one issue remains plausible, or only recurrence supports the
   mapping, flag the block for review and create nothing.

## Note-format recommendations

- When the Timing project and issue-led title already identify the client or
  project, do not repeat a project-name prefix in the note. For example, an ARB
  entry titled `ARB-4 - Technical Strategy & Lead Dev` can begin “Reviewed the
  sprint board…” rather than “ARB: Reviewed the sprint board…”.
- For a shared or cross-cutting issue whose key alone does not identify the
  workstream, especially `BSP-598`, begin the note with the consistent
  issue-and-workstream prefix drawn from the title. For example:
  `BSP-598 - AI Research: Evaluated the documented approach and summarized the
  findings.` Follow the prefix with concrete activity or outcome text.
- The shared-ticket exception exists to disambiguate the workstream. Do not add
  a prefix mechanically when project and title already make the context clear.
- Never put the label `Private Browsing` in a new or cleaned-up note unless the
  user explicitly asks to retain it. Describe only the supported task,
  activity, outcome, and audience-safe provenance.

## Description, privacy, and timestamp rules

- Timing titles and notes, Jira or Tempo worklog comments, and ticket-facing
  descriptions created by this workflow must not include exact clock
  timestamps or start-end clock ranges. Timing and worklog metadata already
  carry that information.
- Write concise task, activity, outcome, and provenance text. Do not copy raw
  browser titles or notes containing private names, private channel names,
  message excerpts, prompts, local paths, tokens, credentials, or sensitive
  client details.
- Treat browser mode as an implementation detail. Omit `Private Browsing` and
  similar mode labels; the supported work context, not the browser mode, is the
  useful provenance.
- Analytics in this reference may show calendar-date spans and aggregate counts;
  those are evidence metadata, not reusable entry descriptions.
- A blank historical note does not justify a blank future note when a concise,
  evidence-backed outcome would make the entry clearer.

## Update protocol

1. Recompute the rolling six-month local-date window from non-deleted
   `TaskActivity` rows using read-only access and join each row to its Timing
   project.
2. Preserve exact Project + Title + Notes counts before proposing any
   near-equivalent family. Record the family count, date span, and all material
   project conflicts.
3. Re-authenticate to Jira and re-read every proposed overhead issue, including
   its summary, issue type, status, parent, description, and full available
   comment history. Treat newer Jira evidence as authoritative.
4. Promote a candidate to the verified table only when both the Timing project
   pattern and Jira mapping are supported. Demote closed, archived, renamed, or
   conflicting patterns immediately.
5. Sanitize examples and enforce the no-clock-data rule before committing the
   reference update.
6. Record the new evidence date, database population, Jira limitations, and any
   unresolved decisions. Never update Timing, Jira, Tempo, or importer records
   as part of maintaining this reference.

## Sources and limitations

- The Timing database passed a read-only integrity check. Manual entries can
  still contain historical misclassification, stale ticket titles, or copied
  notes; this reference calls out observed conflicts rather than normalizing
  them.
- Authenticated Jira access succeeded for the current user. Exact issues in the
  tables were fetched directly. Broader keyword searches exposed a limited
  result page, so this is a targeted map of common observed patterns rather than
  an exhaustive inventory of every overhead issue.
- Jira issue status and hierarchy can change after the evidence date. Re-read
  current Jira before assigning an issue.
- No Tempo records or importer data were needed for this pattern analysis. No
  Timing, Jira, Tempo, importer, database, or other remote records were changed.
