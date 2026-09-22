# N45 static-analysis adoption

PHPStan is introduced as a deliberately narrow, blocking gate. The initial
scope contains shared fork boundaries that can be held at level 5 without an
ignore baseline:

- `n45/bootstrap.php`
- `functions/login_surface.php`
- `functions/mail_templates.php`
- `functions/ui.php`

`functions/sanitize.php` is scanned for legacy global helper symbols used by
the governed UI module; it is not yet part of the analysed level-5 scope.

The CI tool version, runner image, and external action commits are pinned. Every pull request into `next` runs the same
configuration, and `workflow_dispatch` provides exact-branch validation.

## Expansion rule

Add a file or cohesive module to `paths` only after its current findings are
corrected or reviewed. `scanFiles` may supply runtime symbols, but must not be
described as analysed coverage. Prefer types and code fixes over ignores. If a
future expansion needs a baseline, commit only the reviewed findings for that
expansion; do not regenerate an existing baseline to make an unrelated change
pass. The baseline must shrink or remain stable.

P2-04 remains in progress until the gate covers the fork-owned service/write
paths broadly enough to reject new findings in changed N45 code. JavaScript and
shell static-analysis gates remain separate follow-up work.

## Local command

With PHPStan 2.2.14 available:

```bash
phpstan analyse --configuration=phpstan.neon.dist --no-progress
```
