# Changelog

All notable changes to Sakala API will be documented in this file. The format follows Keep a Changelog and Semantic Versioning when releases begin.

## [Unreleased]

### Added

- `sakala.email_delivery_enabled` (`SAKALA_EMAIL_DELIVERY_ENABLED`), which follows `mail.default` when unset, and `503 EMAIL_DELIVERY_UNAVAILABLE` on register, forgot-password, resend verification, and email change while it is off. Adding an SMTP provider needs env only.
- Public `GET /api/v1/auth/options` with `email_delivery_enabled` and `providers.github` / `providers.google`.
- `projects:purge-deleted`, run daily, permanently deletes a project `SAKALA_DELETED_PROJECT_RETENTION_DAYS` days (default 30) after its soft delete. Secrets go on time even while a node still has to stop the workload, and purged slugs stay reserved in `retired_project_slugs`.
- Sakala's own mail layout in `resources/views/vendor`: the product name instead of `APP_NAME`, a header that links to the console, and no framework salutation or footer.
- Laravel API-only control plane foundation.
- Sanctum, Socialite, Reverb, Sail, Pest, Pint, Larastan, and Scramble tooling.
- Versioned API status endpoint using Action, immutable DTO, and API Resource boundaries.
- OpenAPI 3.1 documentation UI, export command, analyzer, and access gate.
- Baseline security configuration and architecture tests.
- MVP database schema for users, OAuth accounts, projects, encrypted environment variables, deployments, agents, commands, events, logs, and audit history.
- UUIDv7 domain models, typed status enums, factories, and an idempotent local demo seeder.
- Database design documentation and persistence regression tests.
- `ProjectResource.active_deployment_id` and `effective_port`, and `DeploymentResource.is_active`, so the console can tell what is actually serving.
- `failure.detail`, `failure.stage`, and `failure.user_fixable` on deployments; detail and stage are stored on the deployment and survive log pruning.
- Agent failure codes `runtime_app_not_listening`, `runtime_app_unhealthy`, `runtime_app_crashed`, `runtime_app_out_of_memory`, and `runtime_port_conflict`.
- Optional heartbeat field `metadata.workloads.crashed_details` (protocol revision stays 4); it sets `runtime_status = crashed` for the active deployment and restores `running` when the crash is gone.
- Error code `PROJECT_SUSPENDED` and login error code `email_not_verified`.
- Indonesian validation messages in `lang/id`.

- Account security: change password, set a first password for OAuth accounts, change email through a signed link to the new address, forgot and reset password through the password broker. `UserResource` gains `has_password`, `pending_email`, and `created_at`.
- In-app notifications with per-category preferences, an unread count, broadcasts on `users.{id}`, and bounded retention.
- `order=desc` on `GET .../logs` with `per_page` up to 200, `log_sequence` on the `deployment.log.created` broadcast, and `Retry-After` in CORS exposed headers.
- `ProjectResource.serving_port`, the port the active deployment actually uses, stored as `deployments.container_port`.
- `ProjectResource.public_url`, from `SAKALA_PROJECT_URL_SCHEME` and `SAKALA_PROJECT_URL_PORT`.
- Optional `GITHUB_PUBLIC_API_TOKEN`, cached public GitHub reads with ETag revalidation, and `503 GITHUB_RATE_LIMITED` with `Retry-After`.
- `SAKALA_AVATAR_STORAGE=local` for development, and `503 AVATAR_STORAGE_UNAVAILABLE`.
- `POST .../deployments/{deployment}/cancel`, `cancel_requested_at`, `queue_state`, and the additive `cancel_requested` field on `GET /commands`.
- Project build configuration (`configured_port`, `build_command`, `start_command`, `root_directory`), sent to the agent as optional `DeployProject` fields, and `redeploy_required`.
- `POST app/projects/{project}/inspection`, the derived `stack` summary, and the `90_days` project filter.
- `GET app/projects/domain-preview`, `search` on installation repositories, `DELETE app/profile/avatar`, and the `student` and `mentor` onboarding profiles.
- `PATCH .../environment-variables/{id}`; non-secret values are readable in the list.
- Opt-in `auto_deploy`: a verified GitHub push creates a deployment with the pushed commit, idempotent per delivery.

### Changed

- User-visible API messages, failure hints, and validation attributes now say `proyek` and `kamu`, and admin and GitHub errors that were still English are Indonesian. Codes and keys did not change.
- Deployment status follows `deployment.analysis.started`, `deployment.health.started`, and `deployment.route.started`. `health_checking` now ranks before `routing`, and `deployment.runtime.ready` no longer changes status. Older agents skip these statuses and still succeed.
- `InspectProject` sends `root_directory` when set, and `stack` is null until the current directory has been inspected. Added failure code `runtime_root_directory_invalid`.
- The default limit for authenticated API routes is 240 requests per minute (`SAKALA_API_RATE_LIMIT`); guests stay at 60 (`SAKALA_API_GUEST_RATE_LIMIT`).
- `REVERB_ALLOWED_ORIGINS` accepts URLs and normalises them to hostnames, which is what Reverb compares.
- Empty event `metadata` serialises as `{}` instead of `[]`, and empty usage signal `tags` likewise.
- A heartbeat leaves `runtime_status` alone while a newer deployment runs, and one crash sends one notification. `auto_deploy` is refused for public URL projects. Sail's default Reverb port is now 8080.
- `runtime_cancelled` ends a command as `Cancelled` and its deployment as `cancelled`, not failed.
- Deleting a project stops its workload through the agent, cancels queued work, and asks running attempts to stop.
- GitHub App installation returns to `/projects/new` with the result, and its failures redirect to the console instead of an API error page.
- A failed redeploy keeps the project `running` while the previous deployment still serves; `failed` now means nothing is live. The migration moves existing projects to this meaning.
- A successful restart or wake of the current workload sets `running`.
- Rewrote failure summaries and hints in Indonesian: each code says what to do, and platform faults say they are not the student's fault. `runtime_cancelled` moved from category `unknown` to `node`.
- Business rule errors now carry Indonesian messages; their `code` values did not change. The default locale is `id`.
- The owner of a suspended project now gets `409 PROJECT_SUSPENDED` when deploying instead of a generic `403`.
- Login with the right password on an unverified email returns `403 email_not_verified` instead of `401`.

### Fixed

- `api-web` resolves the `api` upstream through Docker's DNS while running, so recreating only the `api` container no longer leaves nginx answering 502 until it reloads.
- A correctly signed GitHub webhook whose `X-GitHub-Delivery` is not a UUID gets `422` instead of a 500 from PostgreSQL.

### Security

- Agent failure messages are redacted before they are stored on the command and the deployment.
- Updated the Symfony packages with published advisories, and raised the `laravel/framework` minimum to `^13.30` so its vulnerable ranges cannot return.
- A project owner removed from a repository on GitHub can no longer deploy it: access is rechecked at deploy, webhook, and credential lease. Failure codes `repository_access_revoked` and `repository_access_check_failed`, and error code `REPOSITORY_ACCESS_REVOKED`.
- `POST auth/register` always answers 202 and no longer confirms whether an email exists; the existing account's owner gets an email instead.
- The exact values of a project's secret environment variables, and their common encodings, are redacted from stored and broadcast logs and events.
