# Agent protocol v4 fixtures

Wire payloads taken from `sakala-agent` **v0.2.0** (protocol revision 4) and
replayed verbatim against the API in tests, so compatibility is executable
rather than enum-level.

- `commands/*.json` — copied from `examples/commands/` at tag `v0.2.0`.
- `heartbeat/docker-ready.json` — the heartbeat example in `docs/AGENT_API.md`
  at tag `v0.2.0` ("Heartbeat Payload"), byte-for-byte: `metadata.version`
  `0.2.0`, `detail_counts`, and `compatibility_issues`.
- `heartbeat/docker-recovered.json` — the same node after a restart with
  recovery detail, derived from `crates/sakala-agent-core/src/heartbeat/worker.rs`
  at the tag: `recovered_workloads`, an `orphans` item with `project_id: null`,
  `stale_routes` items with `deployment_id` set and `null` (legacy route),
  a `stale_images` item, and matching `detail_counts`.
- `heartbeat/noop-degraded.json` — what the v0.2.0 binary emits with the
  default `NoopRuntimeExecutor`, derived from the same builder: telemetry
  unavailable (`null`), `disk_pressure.state = "unknown"`,
  `runtime_dependencies = null`, status derived as `degraded`.
- `reports/events-batch.json`, `reports/logs-batch.json` — the batch bodies
  from `docs/AGENT_API.md` "Event and Log Payloads" at the tag. The agent
  sends each with an `Idempotency-Key` header (UUID v4, unique per request,
  reused on retry) and never sends single-object bodies any more.

Do not "fix" these files to match the API; when the agent contract changes,
replace them from the new tag and adjust the API.
