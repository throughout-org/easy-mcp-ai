# Releasing RankOut Connector

The repository is named `rankout-connector`, but release packages deliberately
contain an `easy-mcp-ai/` top-level directory. Existing installations use that
WordPress plugin basename, so retaining it preserves activation and enables a
normal in-place update. Do not change the packaged directory or main PHP
filename without a separately tested migration release.

## One-time GitHub setup

1. Rename the GitHub repository to `rankout-connector` and make it public.
2. Protect `main`: require pull requests, passing checks, and reviewed changes.
3. Enable GitHub **immutable releases** under repository Settings → General →
   Releases. Published tags and assets then cannot be replaced.
4. Keep Actions permissions at the repository default of read-only; this
   release workflow declares only `contents: write`, `id-token: write`, and
   `attestations: write` for its release job.

## Publish a version

1. Update the `Version` header and `EASY_MCP_AI_VERSION` in `easy-mcp-ai.php`.
2. Add user-facing changes to `CHANGELOG.md`.
3. Merge the reviewed change to `main`.
4. Create and push an immutable semantic-version tag, such as `v2.0.1`.

The workflow rejects a tag that does not exactly match both versions in the
plugin file. It syntax-checks all PHP, builds `rankout-connector.zip`, creates a
SHA-256 file and build-provenance attestation, then publishes a GitHub Release.
Draft/prerelease entries are ignored by GitHub's `releases/latest` endpoint.

## WordPress update flow

WordPress checks the latest public GitHub Release through its normal plugin
update transient. The connector accepts only a semantic version and the exact
`rankout-connector.zip` release asset hosted on `github.com`. Before WordPress
extracts anything, the plugin downloads the asset and compares its SHA-256 with
GitHub's release-asset digest. A missing or mismatched digest fails closed.

After publishing, verify on a staging WordPress site running the previous
version: click **Plugins → Check for Updates**, inspect version details, perform
the one-click update, and confirm the plugin remains active and its settings and
database upgrade state are retained.
