# GitHub Actions portability policy

Production release checks must not drift because a hosted runner alias or an external action tag moved after review.

## Required controls

- Linux jobs use `ubuntu-24.04`, not the moving `ubuntu-latest` label.
- Every external `uses:` reference is pinned to a full 40-character commit SHA.
- A trailing version comment records the reviewed release for maintainers.
- JavaScript actions use releases built for Node.js 24.
- Privileged workflows keep explicit least-privilege permissions and do not check out repository code.

The regression contract in `tests/github_actions_portability_contract_test.php` scans every workflow. It rejects moving Ubuntu aliases and mutable external action references.

## Updating an action

1. Review the upstream release notes and breaking changes.
2. Resolve the release tag to its exact commit.
3. Replace the immutable SHA and version comment together.
4. Run the full database/regression workflow on the exact branch head.
5. Record the run URL and exact commit in the remediation ledger.

Do not replace a pinned SHA with a floating major tag to make an update easier.
