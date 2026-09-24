# Upgrading Laravel Swarm — Filament

## v0.3.0

Core `^0.27` is supported alongside every previously supported `^0.19` through
`^0.26` range. Filament 5, PHP 8.4+ and Laravel 13 requirements remain. Core 0.27
uses official Laravel AI 1.x; follow core's own upgrade guide for application
changes. This companion neither migrates native tables nor rewrites history.

Token displays now preserve accounting uncertainty:

- Complete legacy `prompt_tokens` / `completion_tokens` or native
  `input_tokens` / `output_tokens` pairs produce a primary total. Each category
  must be a nonnegative integer. Numeric strings and other malformed values
  cannot make a known total.
- Known zero now displays `0`; it was previously suppressed as an em dash or
  omitted from the headline and graph.
- Missing, null, empty or incomplete usage now displays `Unavailable`; previous
  readers could silently treat missing categories as zero.
- A dual-pair all-null aggregate displays `Mixed or unavailable`. It may mean
  unknown accounting, not necessarily two observed generations. The recent
  Tokens stat withholds a total when runs mix generations or any run has unknown
  usage. An empty database remains zero runs and zero tokens.

These changes affect the existing Runs Tokens column, detail headline, recent
Tokens stat and workflow graph. Structural or unexecuted graph nodes do not
claim an invocation count. Cache/reasoning subset values are not added to
primary totals, and historical legacy totals are not reconstructed. Original
stored run and step evidence remains unchanged. No configuration change is
required; the existing public read contracts, authorization and sealed-field
masking still apply.

Until the ecosystem is published, the exact candidates in the README and CI
are compatibility evidence only. They are not instructions to add custom
repositories or version aliases to a production application.
