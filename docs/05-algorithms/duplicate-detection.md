# Duplicate detection — Jaccard Duplicate Check (academic baseline)

Contract: `DuplicateStrategy`. Baseline class: `App\Modules\Automation\Strategies\Baseline\JaccardDuplicates` (`#[AcademicBaseline]`, `@deprecated` pointing to ADR-0023); word extraction in `App\Modules\Automation\Domain\Text\WordSet`. Replaceable after the defence ([ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)); richer candidate: [future/duplicate-detection-advanced.md](future/duplicate-detection-advanced.md). Requirement FR-AUT-06.

## Idea

Two tickets about the same problem tend to **share words**. Turn each ticket into a set of meaningful words and measure how much the two sets overlap with the **Jaccard similarity** [Manning et al.]. The same idea — compare the words of a new report with existing ones and show the closest — is where duplicate bug-report research started [Runeson et al.].

## Step 1 — words of a ticket

```text
function words(title, description):
    text ← lowercase(title + " " + description)
    text ← replace every character that is not a letter, digit or hyphen with a space
    tokens ← split text on spaces
    keep tokens with at least 3 characters that are not in the stop-word list
        and are not made of hyphens only
    return the set of remaining tokens (in order of first appearance)
```

The stop-word list is a short fixed file, `backend/resources/automation/stopwords-en.txt`: one lowercase word per line, `#` comments allowed. It holds 117 words (`the, and, for, with, after, before, from, this, that, are, was, were, have, has, had, you, your, our, but, not, can, will, please, hello, thanks, regards`, …). Words shorter than three characters are not listed because they are dropped anyway. Words that describe a problem are deliberately not listed: `cannot`, `error`, `fails`, `shows`. A different file can be loaded with `WordSet::fromFile($path)`.

| Token rule | Effect |
|---|---|
| Hyphens kept | error codes such as `err-401` survive |
| Hyphen-only tokens (`---`) | dropped |
| Letters | any Unicode letter plus combining marks (`\p{L}\p{M}`), so Devanagari words stay whole |
| Length | counted in characters, not bytes |

## Step 2 — which tickets to compare

The database returns at most 50 candidates from the **same tenant**, created in the last 30 days, not closed, ordered by PostgreSQL trigram similarity of the title. This is a database feature used only to keep the comparison small; the decision is made by our function below.

## Step 3 — similarity and decision

```text
J(A, B) = |A ∩ B| ÷ |A ∪ B|          (0 = nothing shared, 1 = same words)

suggest the candidate if J ≥ 0.35 (tenant setting); show the best five
```

Settings (`automation.duplicates.baseline`, class `DuplicateSettings`):

| Setting | Default | Rule | Used by |
|---|---|---|---|
| `threshold` | 0.35 | greater than 0, at most 1 | algorithm |
| `max_suggestions` | 5 | positive | algorithm |
| `candidate_limit` | 50 | positive | candidate loader |
| `window_days` | 30 | positive | candidate loader |

Invalid settings throw `InvalidStrategySettings`.

## Flow

```mermaid
flowchart TD
    A[New ticket title and description] --> B[Lowercase, split, drop stop words and short words]
    B --> C[Word set A]
    D[(Up to 50 recent tickets of the same tenant)] --> E[Word set B for each candidate]
    C --> F[J = shared words / all distinct words]
    E --> F
    F --> G{J at or above threshold?}
    G -- yes --> H[Keep candidate with score and shared words]
    G -- no --> I[Ignore]
    H --> J[Show the five highest scores to the agent]
```

## Algorithm

```text
function find(ticket, candidates):
    A ← words(ticket.title, ticket.description)
    results ← []
    for c in candidates:
        if c.id = ticket.id: skip                // a ticket is never its own duplicate
        B ← words(c.title, c.description)
        shared ← A ∩ B
        score ← |shared| / |A ∪ B|        // 0 when both sets are empty
        if score ≥ threshold:
            results.append(c, score, shared)
    sort results by (−score, newest first, id)  // undated candidates count as oldest
    return first 5 results
```

Scores are compared exactly by cross-multiplication of shared and union counts, so the threshold test and the order are not decided by float rounding. The explanation shows each score rounded to 4 decimals.

The explanation shown to the agent is the score and the list of shared words. `DuplicateResult::explanation()` returns `strategy` (`jaccard_duplicates`), `strategy_version` (`1.0.0`), `words` (the new ticket's set), `candidates_compared`, `settings` and `matches` (ticket id, score, shared words sorted alphabetically, shared count, union count).

**Complexity:** O(L) to build a word set of text length L, and O(K × W) to compare with K ≤ 50 candidates of about W words each — a few thousand set operations per new ticket.

## Worked example

New ticket: *"Cannot login after password reset"* — *"Login page shows error ERR-401 after I reset my password."*
A = {cannot, login, password, reset, page, shows, error, err-401} (8 words)

| Candidate | Words (B) | Shared | Union | J | Decision |
|---|---|---|---|---|---|
| #1031 *"Login fails after resetting password"* — *"Error ERR-401 on login page."* | {login, fails, resetting, password, error, err-401, page} | login, password, error, err-401, page (5) | 10 | **0.50** | suggested |
| #1002 *"Invoice PDF is blank"* — *"The invoice download shows an empty page."* | {invoice, pdf, blank, download, shows, empty, page} | shows, page (2) | 13 | **0.15** | ignored |

"reset" and "resetting" do not match because the baseline does no stemming — a limitation the replacement can fix.

## Tests

Word extraction (case, punctuation, hyphenated codes, short words, stop words); Jaccard at 0, 1 and the example; empty texts; threshold boundary; ordering and limit of five; candidates from another tenant never appear (isolation test with the candidate loader, M2); same input gives the same output (contract test).

## Evaluation (experiment E2)

300 labelled ticket pairs (100 duplicates, 200 non-duplicates, 50 of them from the same category). For thresholds from 0.10 to 0.90: true/false positives and negatives, precision, recall and F1; choose the threshold with the best F1 and report Recall@5 of the suggestion list. Compare title-only and title-plus-description word sets.

## Limitations and replacement

No stemming, synonyms or word weighting; common words in a tenant's domain inflate scores; paraphrases with different words are missed. Replacement options: TF-IDF or BM25 ranking with stemming, embeddings with pgvector, or a hybrid with a learned threshold ([future](future/README.md)).
