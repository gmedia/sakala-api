# Changelog

All notable changes to Sakala API will be documented in this file. The format follows Keep a Changelog and Semantic Versioning when releases begin.

## [Unreleased]

### Added

- `php artisan mail:preview` renders every email with sample data to HTML and text files without sending anything, and refuses to run in production. See `docs/EMAIL.md`.
- `SAKALA_LANDING_URL` (default `https://sakala.dev`): the site whose `/privasi` and `/syarat` every email footer links to.

### Changed

- All nine transactional emails (verification, password reset, existing account on register, the three email change notices, workspace invitation, project suspension, and account deletion) share one branded layout: a Sakala wordmark, a single 600 px column, one primary button with an Outlook-safe VML twin and a text link below it, readable light and dark colors that hold WCAG 2.2 AA contrast, and a footer that says why the address got the mail, who sends it, and links the privacy notice and terms. No images and no tracking pixels.
- Every email has a hidden preview text and a heading, and its copy was rewritten: the subject says what happened, the first sentence what to do, and links state their expiry in minutes or days and, where the time is exact, in WIB.
- The suspension email links to the project in the Console, and the invitation email explains what the offered role can do.

## [0.4.0] - 2026-11-12

### Added

- Workspaces own projects (#122). Every user has one personal workspace, created with the account and backfilled for existing users by migration, which also moves their projects into it; `projects.user_id` stays as the creator. Teams are workspaces of type `team` with roles `owner`, `member`, and `viewer`. Creating a project without `workspace_id` still uses the personal workspace, so solo use is unchanged.
- Workspace endpoints under `api/v1/app`: list, create, show, rename, and delete teams (`workspaces`), leave a team, list members, change roles, remove members, invite by email, list and revoke invitations, and list, accept, or decline invitations addressed to you (`workspace-invitations`). Users with a verified email may own up to `SAKALA_MAX_OWNED_TEAMS_PER_USER` teams (default 2); `users.max_owned_teams` raises it per user. The cap applies to creating a team, accepting an owner invitation, and being promoted to owner.
- Invitation tokens are single use, stored as a SHA-256 hash, valid for `SAKALA_WORKSPACE_INVITATION_TTL_HOURS` (default 168), sent through the existing mailer (`503 EMAIL_DELIVERY_UNAVAILABLE` while email is off), limited by `SAKALA_WORKSPACE_INVITATION_RATE_LIMIT` per hour, and pruned daily by `workspaces:prune-invitations`. An existing account with the invited verified address also gets an in-app notification in the new `workspace` category. Owner invitations are accepted only by the invited verified address (`403 INVITATION_EMAIL_MISMATCH`). An owner who leaves, is removed or demoted, or deletes the account revokes the invitations they sent.
- Limit profiles (`limit_profiles`): max projects, max running containers, max concurrent deployments, per-project memory and CPU caps, and `max_builds_per_hour` reserved for #124. A personal workspace without one keeps exactly the previous pilot limits. A team without one gets small team defaults (2 projects, 2 running containers, 1 deployment at a time, from `SAKALA_TEAM_MAX_*`), so creating teams does not multiply quota.
- New error codes: `INVITATION_EMAIL_MISMATCH`, `WORKSPACE_IS_PERSONAL`, `LAST_WORKSPACE_OWNER`, `WORKSPACE_NOT_EMPTY`, `TEAM_LIMIT_EXCEEDED`, `EMAIL_VERIFICATION_REQUIRED`, `ALREADY_WORKSPACE_MEMBER`, `INVITATION_EXPIRED`, and `ACTIVE_CONTAINER_LIMIT_EXCEEDED`. `ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED` gains `scope: workspace` for teams.
- Additive fields: `workspace` on project resources (detail, list, and create), `workspace_id` on project create and as a filter on the project list, and `workspace_id`, `limit_profile`, `quotas.max_active_containers`, and `quotas.current_active_containers_count` on `GET app/pilot-limits`, which also accepts `workspace_id`. Account export gains `account.workspaces`.
- Audit events for workspace creation, rename, and deletion, membership, role, invitation, and limit profile changes.
- Admin API for the platform console (sakala-console#97, covering #12 and #15), all under `api/v1/admin` and refused with `403` to anyone but admins: `overview` (totals, in-range counts with the previous window, daily user growth in WIB, deployment outcomes, failure categories and codes, new-user sources from onboarding, and pilot metrics, cached for 60 seconds); users (list, detail with workspaces, limits, and recent audit events, role changes, per-user team cap, personal workspace limit profile); workspaces (list, detail, limit profile); limit profiles (CRUD, refused with `409 LIMIT_PROFILE_IN_USE` while attached); projects (list and detail next to the existing stop, suspend, unsuspend, and reconcile); failed deployments across projects with category, code, project, and time filters, a failure summary, and any deployment's detail, events, and logs; runtime nodes (list, detail, command history with reports, provisioning and rotation that return the token once, and revocation); feedback with a status; and audit events with actor, action, subject, and time filters. Lists are paginated, searched, and filtered on the server. Time ranges are whole WIB days.
- Every admin mutation requires a `reason` and writes an audit event with it. New error codes `ADMIN_SELF_DEMOTION` and `LAST_ADMIN`; demoting locks the admin rows so two admins demoting each other cannot leave Sakala without one.
- `feedbacks.status` (`open`, `acknowledged`, `resolved`; existing feedback is `open`) and indexes on `users.created_at`, `agent_commands (agent_node_id, created_at)`, and `audit_events.created_at` for the admin queries.
- `GET api/v1/app/workspace-invitations/{invitation}?token=` reads an invitation for whoever holds its link, from a verified account: workspace, role, inviter, expiry, whether it is restricted to the invited address (owner invitations, without showing the address), and `can_accept` with the `accept_error_code` accepting would answer. It shares the proof, the order of answers, and the rate limit of accepting and declining.
- Invitation error codes for holders of the right token or the invited verified address: `INVITATION_ALREADY_ACCEPTED`, `INVITATION_DECLINED`, and `INVITATION_REVOKED` (revoked, replaced by a newer invitation, or taken along by an inviter who lost the owner role), next to the existing `INVITATION_EXPIRED` and `INVITATION_EMAIL_MISMATCH`. A wrong or missing token still gets a `404` identical to an ID that does not exist.
- `projects_count` and `active_deployments_count` on workspace resources, counted in the same query as the list.
- `invitation_id` and `workspace_id` on in-app notifications (list and broadcast); the invitation notice carries its invitation ID.
- `workspace` notifications when an invitation you sent is accepted, when an owner changes your role, and when an owner removes you from a team. `GET /notification-preferences` now returns the `workspace` category (default on) that `PATCH` already accepted. Invitation emails are sent regardless of it.
- `SAKALA_WORKSPACE_INVITATION_RETENTION_HOURS` (default 168): how long invitations are kept after their expiry before `workspaces:prune-invitations` deletes them.

### Changed

- Every route under `api/v1/admin` now passes the `can:admin` gate before its controller runs. Admins see no difference and other users still get `403`.
- Every project-scoped endpoint authorizes through workspace membership: any member reads, owners and members change and deploy, viewers never change anything or read a secret value. Non-members keep the generic 403. Admins keep their powers.
- Limits count per workspace under a row lock on the workspace instead of the user. Project creation locks the user and then the workspace; deployment creation locks the workspace and then the project.
- The project list without `workspace_id` covers every workspace the caller belongs to; for a user without teams that is the same list as before.
- Project notifications reach the workspace's owners and members (in a personal workspace, its owner, as before). Deployment notifications go to the person who requested the deployment while they can still act on the project.
- GitHub access for team projects rests on a current owner or member who is linked to the project's installation with a GitHub identity (the creator first, then the longest-standing owner, then member); with nobody left it is revoked. On an organisation installation that person only anchors the access; elsewhere their token must still read the repository. A repository removed from an installation now refuses the credential lease with `409 repository_access_revoked` instead of a server error.
- Deleting an account deletes the personal workspace and its projects, removes memberships, deletes empty teams the account owned alone, keeps team projects it created without a creator, and is refused with `409 LAST_WORKSPACE_OWNER` while it is the last owner of a team that still has projects. Every team the account owns is locked before sole ownership is decided, so two owners leaving at once cannot leave a team without one.
- A push to a team project deploys as its creator while they can deploy there, otherwise as the longest-standing owner.
- Accepting, declining, revoking, or replacing a workspace invitation closes it (`workspace_invitations.status`, `closed_at`) instead of deleting it, and the one-pending-invitation-per-address rule becomes a partial unique index on pending rows. Owner and invitee lists show pending invitations only. Declining an expired invitation now answers `409 INVITATION_EXPIRED`, and revoking one that is no longer pending answers `404`.

## [0.3.0] - 2026-11-11

### Added

- `detected_port` now has a writer. When an `InspectProject` result carries `exposed_ports` with exactly one TCP port, that port becomes `detected_port`, so a Dockerfile that ends in `nginx` with `EXPOSE 80` gets `PORT=80` and a probe on 80 instead of failing with `runtime_app_not_listening` on 3000. No port, several ports, or an inspection of another `root_directory` clear it. The effective port stays `configured_port`, then `detected_port`, then 3000. A result without `exposed_ports`, from an agent before the field, leaves `detected_port` unchanged, so older agents keep 3000. Changing `root_directory` clears `detected_port` until the next inspection.
- Asking to inspect a commit again re-runs a finished Dockerfile inspection whose result has no `exposed_ports`, instead of reusing it. Otherwise a project inspected by an agent from before the field kept port 3000 until its next commit, even after its node moved to a newer agent.

### Changed

- Dependencies updated through Dependabot: the composer minor and patch group (14 packages), lint-staged, the nginx-unprivileged base image, actions/checkout v7, and docker/setup-buildx-action v4.
- The copy test now checks every Indonesian sentence in `app/` and `lang/id`, not only failure texts, exceptions, and notifications, so `project` and `Anda` cannot come back in validation messages or action errors.

## [0.2.1] - 2026-11-11

### Changed

- A `StopProject` created because a project is deleted (project delete, account deletion, an attempt that succeeds after the delete, and the daily retry of `projects:purge-deleted`) now carries `{ "remove_images": true }`, so an agent with that support also removes the project's images. Every other stop still sends `{}` and keeps the images. Agents up to v0.3.0 ignore the field.

## [0.2.0] - 2026-11-11

### Security

- The session cookie no longer reaches tenant applications. It is host-only on the API, with `SESSION_COOKIE=__Host-sakala-api-session` in production, and a `SESSION_DOMAIN` covering `SAKALA_PROJECT_DEFAULT_DOMAIN` is ignored. Only `XSRF-TOKEN` keeps a parent domain, through `SAKALA_XSRF_COOKIE_DOMAIN`. Existing sessions end and users log in again.
- `/broadcasting/auth`, which Laravel registers without CSRF checks, now accepts only origins in `SANCTUM_STATEFUL_DOMAINS` (`403 ORIGIN_NOT_ALLOWED`), so a tenant page cannot use the session there.
- Revealing a secret value is recorded in `audit_events` before the value is returned: actor, project, variable name, never the value.
- Agent failure messages are redacted before they are stored on the command and the deployment.
- Updated the Symfony packages with published advisories, and raised the `laravel/framework` minimum to `^13.30` so its vulnerable ranges cannot return.
- A project owner removed from a repository on GitHub can no longer deploy it: access is rechecked at deploy, webhook, and credential lease. Failure codes `repository_access_revoked` and `repository_access_check_failed`, and error code `REPOSITORY_ACCESS_REVOKED`.
- `POST auth/register` always answers 202 and no longer confirms whether an email exists; the existing account's owner gets an email instead.
- The exact values of a project's secret environment variables, and their common encodings, are redacted from stored and broadcast logs and events.

### Added

- Terms acceptance: `POST /api/v1/auth/register` requires `accept_terms`, `terms_version`, and `acceptable_use_version`; `POST /api/v1/auth/legal-acceptance` covers provider logins and new versions; `UserResource.legal_acceptance_required`; `GET /api/v1/auth/options` gains `legal`; `403 LEGAL_ACCEPTANCE_REQUIRED` on creating projects, inspection, and deploys.
- `DELETE /api/v1/auth/account` with re-authentication, which stops workloads, removes personal data at once, and leaves projects without an owner until their purge.
- `GET /api/v1/auth/account/export`, a bounded and rate-limited JSON copy without secret values or tokens.
- `suspension` (`{ reason, suspended_at }` or `null`) on `ProjectResource` and `GET /api/v1/app/projects`, so the owner can read why a project is suspended without parsing a notification. It is `null` unless the project is suspended, and the list reads it in one extra query.
- The owner of a suspended project gets the reason and the abuse contact, in-app and by email, regardless of notification preferences. `POST /api/v1/admin/projects/{project}/unsuspend` lifts a suspension.
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
- `public_url` and `latest_deployment` (`id`, `sequence`, `status`, `branch`, `commit_sha`, `commit_message`, `created_at`, `finished_at`, or `null`) on `GET /api/v1/app/projects`, and `latest_deployment` on `ProjectResource`. The list needs one extra query per page.
- `deleted_project_retention_days` on `GET /api/v1/app/pilot-limits`.
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
- A manual deployment of a commit whose first line is longer than 500 characters no longer gets a 500 from PostgreSQL. `commit_message` is now the first line without control characters, cut to 500 characters, for manual and push deployments alike; a push used to keep the whole multi-line message.

[Unreleased]: https://github.com/gmedia/sakala-api/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/gmedia/sakala-api/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/gmedia/sakala-api/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/gmedia/sakala-api/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/gmedia/sakala-api/compare/v0.1.4...v0.2.0
