# Publishing an addon to Packagist

Composer ignores a dependency's own `repositories` block. It is honoured only in the root project.
That single fact is why an addon that reaches its siblings through a VCS repository entry installs
for its author and for nobody else — and why every README install command in the family was untrue
until the packages resolved from Packagist.

`addon-lint` reports this as `release.resolvable-dependencies` (blocker).

## Before submitting

Everything here must be true, or the listing goes out broken and the first fix is a new tag:

- `addon-lint <path> --fail-on=major` is clean.
- CI is green on the tag you are about to publish, across the full matrix.
- A `LICENSE` file exists and matches the `license` key.
- The tag is annotated and semver (`git describe --tags` shows it).
- Every runtime dependency is already on Packagist. An addon is only as installable as its least
  available dependency, so publish bottom-up: foundations first, then whatever requires them.

## Submitting

This needs a browser login and cannot be done from the CLI without an API token.

1. Sign in at <https://packagist.org> as the GitHub account that owns the repository.
2. **Submit** → paste the repository URL → **Check** → **Submit**.
3. Packagist reads `composer.json` from the default branch and imports every semver tag.

## Keeping it updated

Packagist only re-reads on a hook. Without it a new tag exists on GitHub and does not exist for
Composer.

- Easiest: in the Packagist package page choose **Settings → GitHub Service Hook**, or install the
  Packagist GitHub App on the organisation once and every repository is covered.
- Manual alternative: the **Update** button on the package page.

Verify from a clean directory, not from the machine that has the path repository configured:

```bash
cd $(mktemp -d)
composer require goldnead/<package> --no-install --dry-run
```

If that resolves, a buyer can install it. If it 404s, the submission or the hook did not take.

## Order for this family

`brand-context` is the foundation — seven addons require it. Then `identity-contracts` and
`suppression`, which the audit recommends publishing as plain libraries rather than Marketplace
listings. Only after those three resolve can the addons that depend on them be installed at all,
regardless of how good their code is.
