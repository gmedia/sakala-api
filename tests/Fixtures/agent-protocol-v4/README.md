# Agent protocol v4 fixtures

Wire payloads taken from `sakala-agent` **v0.1.0** (protocol revision 4) and
replayed verbatim against the API in tests, so compatibility is executable
rather than enum-level.

- `commands/*.json` — copied from `examples/commands/` at tag `v0.1.0`.
- `heartbeat/docker-ready.json` — the heartbeat example in `docs/AGENT_API.md`
  at tag `v0.1.0` ("Heartbeat Payload"), byte-for-byte.
- `heartbeat/noop-degraded.json` — what the v0.1.0 binary emits with the
  default `NoopRuntimeExecutor`, derived from
  `crates/sakala-agent-core/src/heartbeat/worker.rs` at the tag: telemetry
  unavailable (`null`), `disk_pressure.state = "unknown"`,
  `runtime_dependencies = null`, status derived as `degraded`.

Do not "fix" these files to match the API; when the agent contract changes,
replace them from the new tag and adjust the API.
