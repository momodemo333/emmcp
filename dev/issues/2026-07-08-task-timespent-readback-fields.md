# MCP improvement: task time-spent readback and field filtering

Date: 2026-07-08

## Context

While using the remote HTTP EM-MCP endpoint from Hermes for task `TK2602-0009`, adding time spent worked through the dedicated tool:

```json
{
  "tool": "dolibarr_add_time_spent",
  "taskId": 18,
  "date": "2026-07-08 10:14:34",
  "duration": 7200,
  "productId": 10,
  "progress": 100
}
```

The API returned success and the task summary confirmed `duration_effective = 7200`, `progress = 100`, `status = 3`.

## Observed friction

The follow-up verification call was initially made as:

```json
{
  "tool": "dolibarr_get",
  "resource": "tasks",
  "id": 18,
  "subresource": "timespent",
  "fields": "id,task_date,task_duration,note,fk_product"
}
```

It returned:

```json
[
  []
]
```

This looked like the `/tasks/{id}/timespent` sub-endpoint was empty or broken, but that was misleading. Re-reading without `fields` returned the created line. The real field names in this Dolibarr endpoint are prefixed, for example:

- `timespent_line_id`
- `timespent_line_date`
- `timespent_line_datehour`
- `timespent_line_duration`
- `timespent_line_fk_user`
- `timespent_line_fk_product`
- `timespent_line_note`
- plus task/project/thirdparty context fields such as `task_ref`, `project_ref`, `thirdparty_name`.

## Why this matters

- An LLM will naturally guess generic names such as `id`, `date`, `duration`, `note`, `fk_product`.
- The generic `fields` filter silently drops every unknown field, producing `[[]]` with no warning.
- This makes a good endpoint look broken and can lead to false reports or unnecessary debugging.

## Expected improvement

Implement one or more of these improvements in the bundled Dolibarr MCP server package used by EM-MCP:

1. Add a dedicated high-level readback tool, for example `dolibarr_get_task_timespent(task_id, compact=true)`, returning normalized compact keys:
   - `id`
   - `date`
   - `datehour`
   - `duration_seconds`
   - `user_id`
   - `product_id`
   - `note`
   - `task_ref`
   - `project_ref`
2. Improve `dolibarr_get(..., fields=...)` so that when every requested field is missing on an object it returns a warning such as `unknown_fields` / `available_fields_sample` instead of a silent empty object.
3. Document the correct `/tasks/{id}/timespent` field names in the MCP LLM guide and tool descriptions.

## GitHub tracking

Created GitHub issue: https://github.com/momodemo333/emmcp/issues/1

## Current workaround

For verification, either:

```json
{"resource":"tasks","id":18,"subresource":"timespent"}
```

or request the actual endpoint field names:

```json
{
  "resource": "tasks",
  "id": 18,
  "subresource": "timespent",
  "fields": "timespent_line_id,timespent_line_datehour,timespent_line_duration,timespent_line_fk_user,timespent_line_fk_product,timespent_line_note"
}
```

## Classification

- MCP usage/documentation friction
- Field-filter UX problem
- Candidate for a compact high-level task time-spent readback tool
