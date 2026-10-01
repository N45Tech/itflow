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

P2-04 remains in progress until the PHP gate covers the fork-owned service/write
paths broadly enough to reject new findings in changed N45 code.

## JavaScript and shell gates

Every PR into `next` also runs the `JavaScript and shell static analysis` job.
It uses Node.js 24.19.0, ESLint 10.11.0, the checked-in npm lockfile, and
ShellCheck 0.10.0. The official ShellCheck Linux x86_64 release archive is
verified against SHA-256 `6c881ab0698e4e6ea235245f22832860544f17ba386442fe7e9d629f8cbedf87`
before extraction. External action revisions remain commit-pinned.

ESLint's recommended correctness rules cover all 26 application-owned
`js/*.js` files, including agent/portal controls, request helpers, Field Mode,
and Stripe scripts. Browser and vendored-library globals are explicit. The
public helper contract identifies each classic script that defines a shared
function; declaring it in its provider does not mask undefined names in callers.
Top-level helpers are consumed by PHP views, so unused-variable analysis applies
to local variables and unused trailing arguments. Deliberately handled catch
bindings are allowed. Inline rule suppression is disabled, and warnings fail
the job. Vendored JavaScript and inline PHP scripts are outside this gate.

ShellCheck covers every `*.sh` file in `deploy/psa/`, `scripts/`, and `tests/`
(currently five), using each file's shebang. Errors and warnings fail the job;
informational suggestions, including literal AWK expressions, do not. No
warning baseline or rule exclusions are used. This is static analysis; the
separate database job still executes the upgrade and ordered-lock harnesses.

## Local command

With PHPStan 2.2.14 available:

```bash
phpstan analyse --configuration=phpstan.neon.dist --no-progress
```

With Node.js 24.19.0 and ShellCheck 0.10.0 available:

```bash
npm ci --ignore-scripts --no-audit --no-fund
npm run lint:js
npm run lint:shell
```
