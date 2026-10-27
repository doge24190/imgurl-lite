# ImgURL development workflow

These project rules apply to `doge24190/imgurl-lite` and persist across chats.

## Branches and review

- Treat `master` and `main` as protected release branches. Do not edit files,
  commit, push, force-push, or merge changes directly on these branches.
- Before changing any checkout, inspect its branch and working tree. Create a
  task branch from the current `origin/master`, such as `feat/<topic>`,
  `fix/<topic>`, or `docs/<topic>`, and make changes only on that branch.
- After the required checks pass, push only the task branch using an explicit
  branch name and open a pull request targeting `master`.
- The user reviews and merges the pull request. Do not merge it, enable
  auto-merge, or update a release branch on the user's behalf.
- Fetching release history to create or refresh a task branch is allowed.
  Preserve existing user changes; do not use destructive reset or cleanup to
  switch branches.

## Canonical development and test environment

- Build, run, and functionally test ImgURL on the user's Arch computer using
  the existing SSH alias `arch`.
- The persistent test-site checkout and Nginx document root are
  `/srv/http/imgurl-lite`. Services are `nginx` and `php-fpm`.
- Local editing is allowed, but application builds and runtime verification
  must use Arch. Check the branch and working tree on Arch as well; use the
  matching task branch rather than changing a release checkout in place.
- Automated regression tests may create disposable fixtures under `/tmp` on
  Arch. They supplement the persistent checkout; do not present a temporary
  directory as the user's actual test-site directory.
- Preserve the test site's database, uploaded images, installation lock,
  sessions, API tokens, and custom configuration. Never run a fresh install
  over the existing site. Keep test login helpers and destructive test data
  confined to disposable fixtures.
- The production site is separate. Production deployments, configuration
  changes, and data mutations require a separate user request; a request to
  implement and test a feature does not authorize deploying it to production.

## Verification and handoff

For application changes, run the relevant checks on Arch. The existing suite is:

```sh
python3 tests/security_smoke.py --php php
node tests/csrf.test.js
node --check static/js/branding.js
```

- Add meaningful coverage when changing behavior, and verify affected UI or
  API flows as appropriate. Run regression tests against their isolated
  fixture databases, never the persistent site's database.
- Documentation-only changes need review and static checks, not an application
  build. GitHub Actions provides additional compatibility checks and does not
  replace Arch runtime verification for application changes.
- Update user-facing documentation and OpenAPI definitions when API behavior
  changes.
- Report the task branch, commit, pull request, validation results, and any
  remaining limitations. Leave release merging and production rollout to the
  user.
