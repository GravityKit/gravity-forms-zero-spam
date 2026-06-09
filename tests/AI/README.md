# AI Spam Review Evaluation Harness

This directory contains an on-demand real-provider evaluation harness for AI Spam Review.

It is not a CI test. It makes real AI provider calls, costs money, and can vary by provider model or connector configuration.

The harness forces the plugin's shipped default AI instructions for every run, so saved site-level prompt edits do not affect provider baselines.

## Run

From the plugin root:

```sh
wp --path=/Users/moi/dev/www/gvdev eval-file tests/AI/run-ai-review-eval.php providers=anthropic repeats=1 corpus=tests/AI/ai-review-corpus.sample.json
```

Prefer one provider per invocation. The WordPress AI Client performs slow live model discovery on the first text-generation support check and caches it per process; forcing several providers in one process can intermittently poison one provider as unsupported for the rest of the run.

```sh
for p in openai anthropic google; do
  wp --path=/Users/moi/dev/www/gvdev eval-file tests/AI/run-ai-review-eval.php providers=$p repeats=3 corpus=tests/AI/ai-review-corpus.json output=eval-$p.json
done
```

For rate-limited providers, pace calls and retry null verdicts:

```sh
wp --path=/Users/moi/dev/www/gvdev eval-file tests/AI/run-ai-review-eval.php \
  providers=anthropic repeats=2 delay_ms=2000 retries=5 backoff_ms=5000 \
  corpus=tests/AI/ai-review-corpus.json output=eval-anthropic.json
```

Multiple providers can be tested in one run, but this may be flaky. Prefer the per-provider form above.

```sh
wp --path=/Users/moi/dev/www/gvdev eval-file tests/AI/run-ai-review-eval.php providers=openai,anthropic,google repeats=3 corpus=tests/AI/ai-review-corpus.sample.json output=artifacts/ai-review-eval.json
```

## Arguments

- `providers=openai,anthropic,google`: comma-separated provider IDs to force through `gf_zero_spam_ai_provider`.
- `repeats=N`: number of calls per case/provider pair. Defaults to `1`.
- `delay_ms=N`: milliseconds to sleep after each case. Defaults to `0`.
- `retries=N`: retry attempts when a case produces no verdict. Defaults to `0`.
- `backoff_ms=N`: base retry backoff in milliseconds. Defaults to `2000`.
- `corpus=PATH`: corpus JSON path. Defaults to `tests/AI/ai-review-corpus.sample.json`.
- `output=PATH`: optional JSON report path. When omitted, the report is written to STDOUT.

## Corpus Schema

The corpus JSON document must contain a `cases` array. Each case has:

- `id`: stable case identifier.
- `label`: `spam` or `ham`.
- `scenario`: `review` or `rescue`.
- `form_title`: synthetic Gravity Forms form title.
- `source_path`: source path, such as `/contact/`.
- `fields`: array of `{ "label": "...", "type": "...", "value": "..." }`.
- `expected_review_is_spam`: boolean expected review decision.
- `expected_rescue`: boolean expected rescue decision for rescue calibration cases.
- `notes`: optional human-readable notes.
- `include_in_metrics`: optional boolean. Defaults to `true`.

The runner reports raw verdicts, final decisions, latency, confidence distribution, review recall, ham false-positive rate, and rescue-threshold calibration by provider. Accuracy metrics are for release judgment, not CI pass/fail.
