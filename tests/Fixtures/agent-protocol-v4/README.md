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

- `commands/bucket-commands.json`, `heartbeat/object-storage-host.json`,
  `failures/bucket-failures.json` — managed buckets (ADR-021), copied byte for
  byte from `examples/` of **sakala-agent main `f1fbe78`** (PR #138), before a
  tagged release; **re-check them against the release tag**. The heartbeat file
  carries only the object storage fields; tests merge them into a full
  heartbeat. Command results are not in the agent example, so the lifecycle
  test writes them from the contract (section 3.4). `BucketFailureFixturesTest`
  replays every `fail` body and checks the API's handling per contract
  section 13: resend, `unknown`, or `failed`.

- `commands/deploy-project-processes.json`, `commands/run-job.json`,
  `failures/job-failures.json`, `failures/deploy-failures.json`,
  `heartbeat/worker-processes.json` — worker processes and scheduled jobs
  (ADR-022), copied byte for byte from `examples/` of **sakala-agent main
  `1f41585`** (PR #140), before a tagged release (expected v0.18.0);
  **re-check them against the release tag**. The heartbeat file is not a full
  heartbeat (it has no `resources` or `lifecycle_state`); tests merge its
  `capabilities` and `workloads` into one. `run-job.json` is a poll response
  (`data` list).

- `commands/valkey-commands.json`, `commands/deploy-project-valkey.json`,
  `commands/run-job-valkey.json`, `heartbeat/valkey-host.json`,
  `failures/valkey-failures.json` — managed Valkey (ADR-023), copied byte for
  byte from `examples/` of **sakala-agent main `8d108cb`** (PR #146), before a
  tagged release; **re-check them against the release tag**. The heartbeat file
  carries only the Valkey fields that tests merge into a full heartbeat. The
  command results are written from the contract in `docs/AGENT_API.md`
  ("Command Valkey") at that commit. `ValkeyCommandLifecycleTest` replays every
  `fail` body.

Do not "fix" these files to match the API; when the agent contract changes,
replace them from the new tag and adjust the API.
