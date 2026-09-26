# Branching policy

Account Manager is maintained as **two permanent editions on two branches**, not one codebase behind a runtime mode flag. Part B (the multi-user/SaaS work) touches `db()` routing, every list and search query, and the auth tables — running two logical paths through all of that, in a project with no automated test suite, would double the failure surface for a distinction that is structural, not something a request-time setting should decide.

## The two editions

| | `single-user` | `main` |
|---|---|---|
| Audience | One person, one installation, no team | Teams, multiple workspaces, hosted or self-hosted SaaS |
| Versioning | `v1.x.x` | `v2.x.x` |
| Status | Feature-frozen — bug fixes and security fixes only | Active development |
| Data model | One SQLite file | Central platform database + one SQLite file per workspace |
| Presented as | A permanent, supported edition | "The" latest release |

## Rules

1. **`single-user` never receives new features.** It is frozen at the last commit before the multi-user/SaaS work began. Only bug fixes and security fixes land on it, indefinitely — it is not deprecated, it is a permanently maintained product for people who will never need multiple workspaces.
2. **Fixes land on `single-user` first, then merge forward into `main`.** `main` descends from `single-user`'s branch point, so `single-user` → `main` is a plain, mechanical merge along an actual ancestry line. The reverse (`main` → `single-user`) is not a merge at all — `main` has workspaces, memberships, and a central platform database that `single-user` does not, so a fix written against `main` cannot be replayed onto `single-user` without manually rewriting it against a different schema. Landing fixes on the older branch first and merging forward avoids doing that rewrite on every single fix.
3. **Version numbers say which edition, unambiguously.** `v1.x.x` is always `single-user`. `v2.x.x` is always the SaaS edition on `main`. Nobody needs to check a branch name to know which edition a version number describes.
4. **`main` is always presented as the current release.** Anyone asking "what's the latest version of Account Manager" gets `main`'s latest `v2.x.x` tag. `single-user` is offered as a deliberate, named alternative for people who want it — not hidden, not the default.

## Applying a fix

```bash
git checkout single-user
# ... make the fix, commit it ...
git tag v1.x.y
git checkout main
git merge single-user   # forward merge — mechanical, not a rewrite
# resolve any conflicts (should be rare, and only in code the two editions still share)
git push origin single-user main v1.x.y
```

If the affected file no longer exists in the same form on both branches (e.g. `db.php`'s workspace-routing logic), it is not a shared fix — write and commit it separately on each branch instead of merging.

## Releasing

Use `tools/build-release.sh <ref> <single-user|saas>` to produce the distributable zip for a given tag. See that script's own comments, and `.github/workflows/release.yml` for the optional automated version.
