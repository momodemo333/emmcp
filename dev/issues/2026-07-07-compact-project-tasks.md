# MCP improvement: compact project task listing

Date: 2026-07-07

## Context

While using the remote HTTP EM-MCP endpoint from Hermes to inspect Morgan's Dolibarr projects/tasks, the workflow was:

1. `dolibarr_list(resource="thirdparties")` to resolve clients.
2. `dolibarr_list(resource="projects", filters={"thirdparty_ids":"..."})` to resolve projects.
3. `dolibarr_get(resource="projects", id=<project_id>, subresource="tasks")` to inspect tasks.

The calls succeeded, but the project task payloads were very large.

## Observed behavior

`dolibarr_get(..., subresource="tasks")` returns a full Dolibarr task object with many null/irrelevant fields for each task: shipping fields, product fields, multicurrency fields, dimensions, internal metadata, etc.

For a simple client summary, the agent needed only fields such as:

- `id`
- `ref`
- `label`
- `status` / `fk_statut`
- `progress`
- `duration_effective`
- `planned_workload`
- `date_start`
- `date_end`
- `billable`
- project/client identifiers

## Impact

- High context/token usage for routine task summaries.
- More difficult for models to read the useful task data reliably.
- Numeric task statuses are returned without human labels, which forces the agent to report raw API status values unless a mapping is known.

## Expected improvement

Add or expose a compact project task listing shape, for example one of:

1. Support `fields` on `dolibarr_get(..., subresource="tasks")` if technically feasible.
2. Add a dedicated MCP tool like `dolibarr_list_project_tasks(project_id, include_closed=false, compact=true)`.
3. Make the existing task subresource wrapper trim null/irrelevant fields by default and optionally include raw/full payload only when requested.

## Suggested compact output

```json
[
  {
    "id": "19",
    "ref": "TK2602-0010",
    "label": "Migration PS 9.1",
    "status": "2",
    "status_label": "...",
    "progress": 30,
    "duration_effective_seconds": 86400,
    "planned_workload_seconds": 144000,
    "billable": true,
    "date_start": "2026-07-01",
    "date_end": null
  }
]
```

## Current workaround

The agent reads the full result and manually extracts the useful fields, but this is wasteful and fragile for larger projects.
