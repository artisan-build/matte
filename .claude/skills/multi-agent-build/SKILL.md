---
name: multi-agent-build
description: "Use this skill to orchestrate building a planned, multi-PR feature with a coordinated subagent loop: one agent implements each PR, independent agents review and judge it, and THIS session is the coordinator that verifies the gate, arbitrates findings, and lands each PR. Trigger when the user has a multi-step/multi-PR implementation plan and asks to build it via the multi-agent / coordinated workflow, 'orchestrate this', 'have the agents build it', or 'run the loop'. Each PR runs implement -> gate (composer ready + touched-package suites) -> independent quality review (Codex) + acceptance judge (Claude) -> coordinator arbitration -> land. Matte is an EXPERIMENTAL project: the gate is the merge authority — on a green gate + clean CI, AUTO-MERGE and proceed; do NOT pause for human review per PR. Stop only on a hard blocker (3-attempt bail or a broken planning assumption). Requires Solo (for spawning/awaiting agents) and the Matte monorepo gate (composer ready + per-package pest/pint)."
license: MIT
metadata:
  author: artisan-build
  origin: "Adapted for the Matte monorepo from the Hone multi-agent-build skill. Key change: Matte is experimental, so the deterministic gate + clean CI is the merge authority (auto-merge on green); no per-PR human merge gate. See memory: autonomous-build-gate."
---

# Multi-Agent Build (Matte monorepo)

Orchestrate a planned, multi-PR feature build. **You are the coordinator** — you do NOT write the
feature code. You decompose the plan, spawn subagents to implement and review each PR, verify the
hard gate yourself, arbitrate findings, and land each PR.

**Bias: quality over speed, but DO NOT stall.** Sequential is the default; parallelize only PRs that
are genuinely independent (different surfaces, no shared files). Independent agents catch what a
single pass — or the deterministic gate alone — misses.

**Matte is experimental and runs UNATTENDED.** The merge gate is `composer ready` + the touched-package
suites + clean CI — NOT a human. On a green gate you merge and move to the next PR yourself. You stop
for the user ONLY on a hard blocker (a 3-attempt bail, or a planning assumption that broke). See the
`autonomous-build-gate` memory.

**Matte is a monorepo.** Most PRs touch one of `packages/matte-contracts`, `packages/matte-server`,
`packages/matte-client`, or the slim Matte app at the root. The gate and worktree steps below have
monorepo-specific rules — read them.

## When to use / not use

- **Use** when there is a real implementation plan (the Matte POC plan; run-log + PR backlog live in a
  Solo scratchpad in project `matte` id 11) and the build is to run via the coordinated loop.
- **Do NOT use** for single-file edits, quick fixes, or work the user wants inline. This spawns
  multiple subagents and is for substantial, plan-driven feature builds only.

## Roles & runtimes (deliberate model/harness decorrelation)

| Role | Who | How to run |
| --- | --- | --- |
| **Coordinator** | This Claude session | Decomposes PRs, writes acceptance criteria + critical test assertions, spawns/awaits agents, verifies the gate, arbitrates, lands the PR, keeps the run-log. |
| **Implementer** | OpenCode (Solo `agent_tool_id 2`) | Persistent Solo agent in the PR's worktree. Implements + writes tests until the gate is green. |
| **Quality reviewer** | Codex (`codex exec`, GPT-5.x) | One-shot, NOT a persistent Solo agent. Different CLI harness than OpenCode. |
| **Acceptance judge** | Claude (Solo `agent_tool_id 3`) | Judges strictly against the acceptance criteria; must read REAL test output, never vibe-judge. |

Different model lineages touch every PR so reviewers don't share the implementer's blind spots. The
coordinator (Claude) reads all the code when writing the report — a fourth, holistic pass.

**Spawn mechanics:** `spawn_agent(agent_tool_id, project_id=11)` → returns process_id + agent_instructions →
`send_input(process_id, project_id=11, input)`. Await with
`timer_fire_when_idle_any([pid], max_wait_ms, body, project_id=11)` (the idle-timer wakes this
coordinator session — the reliable hands-off mechanism). Capture output with `get_process_output`.

**Codex invocation (one-shot reviewer; runs from the Bash tool, sandbox disabled — Codex needs network):**
```
cd <worktree> && OPENAI_API_KEY="$(jq -r '.OPENAI_API_KEY' ~/.codex/auth.json)" \
  codex exec --skip-git-repo-check - < /tmp/<brief>.txt > /tmp/<review>.md 2>&1
```
The output file echoes the brief first — read the LAST `=== REVIEW START ===` block for the real verdict.

## Worktree setup (per PR)

Each PR gets its own git worktree off the latest `origin/main`, with its **own real `composer install`**:
```
git worktree add <path> -b <branch> origin/main
cd <path> && composer install --no-interaction   # + copy .env, touch database/database.sqlite, key:generate
```
**Never symlink (or `cp -R`) `vendor/` from the main checkout** — a symlinked vendor makes Composer's
`installed.php` resolve the project root to the MAIN checkout and the framework boot loads the wrong
files. A real `composer install` is the only reliable provisioning.

**Monorepo: each touched package needs its OWN `composer install` too** (packages are standalone-testable
with their own `vendor/`; the app's root vendor does NOT autoload a package's `tests/` namespace):
```
(cd <path>/packages/matte-<pkg> && composer install --no-interaction)
```

For **read-only reviewers**, give absolute paths and `git -C <worktree>`; only the IMPLEMENTER (OpenCode)
needs to be *in* the worktree (`extra_args=["<path>"]`).

## The hard gate (objective, non-negotiable — and the merge authority)

Two parts, both must be green:

1. **App gate — `composer ready`** at the repo root (ide-helper → rector → pint → phpstan → full Pest
   suite → `composer audit`). Always runs, even for package-only PRs, because the app autoloads the
   packages via path repository and must still boot and pass.
2. **Touched-package gate** — for EACH package the PR changed, inside `packages/matte-<pkg>`:
   `composer lint:test` (pint --test) **and** `composer test` (pest). `composer packages:check` at the
   root runs install + lint + test across ALL packages; use it for multi-package PRs.

Rules:
- **You verify the gate yourself, on the COMMITTED SHA, in a clean tree** — never trust the implementer's
  "it passed." Run the REAL `composer ready` (ide-helper runs FIRST and regenerates model docblocks; bare
  phpstan against a stale committed `_ide_helper_models.php` gives FALSE errors).
- The gate is a **precondition to review** — don't spawn reviewers until BOTH parts are green. A red gate
  bounces to the implementer and does NOT consume an attempt.
- The LLM judge is **additive on top of** the gate, never a substitute. The judge must inspect real test output.
- If a tracked generated file (`_ide_helper_models.php`) is stale, regenerate + commit it as part of the PR.

## The per-PR loop

```
1. PLAN      You decompose the PR into tasks + write acceptance criteria AND the critical
             acceptance-test assertions up front (TDD-leaning — so the implementer can't teach to a weak test).
2. BUILD     Spawn OpenCode in the PR worktree. Brief it fully (tasks, ACs, locked assertions, the gate
             INCLUDING the touched-package suites, "keep git status clean — only intended files", commit
             message footer, do NOT push/PR/merge). It loops on its own until the gate is green.
3. GATE      You re-verify `composer ready` AND each touched package's `composer test`/`lint:test` on the
             committed SHA in a clean tree. Red -> back to BUILD. Also confirm the working tree is clean.
4. REVIEW    Green -> launch Codex (quality/security/perf/standards, via codex exec) AND a Claude judge
             (acceptance), independent + blind to each other, both against the committed diff.
5. ARBITRATE You apply the severity rubric (below) and decide. Verify any blocking finding in the code
             yourself before acting on it.
6. REWORK    Blocking issue(s) -> send a tight rework brief to the SAME implementer (it's warm). Back to
             GATE. attempts < 3. Trivial coordinator-verifiable fix -> verify by inspection, no full re-review.
7. LAND      Gate green + no blocking -> read the full diff, write the implementation report, push the
             branch, open the GitHub PR with the report as the body, wait for CI, and on GREEN CI
             **merge it yourself** (squash) and pull main. NO human gate — Matte is experimental and the
             gate is the merge authority. Then start the next (now-unblocked) PR. Record the merge in the
             run-log. (If CI is red but the local gate was green, treat the CI failure as a red gate:
             back to BUILD; it does not consume a review attempt.)
```

**Bail:** after 3 attempts still blocking → stop, do not touch the next PR, write a **standalone
scratchpad** (branch, per-round diffs, surviving findings, what each attempt tried, a hypothesis about
which planning assumption broke) and ask the user for help. A bail means the PLAN, not just the code,
needs revisiting. This — and a broken foundational assumption — are the ONLY reasons to stop the loop.

## Severity rubric — YOU own the call

LLM reviewers always find *something*; without discipline the loop never terminates.

- **Reviewer `[BLOCKING]`/`[ADVISORY]` tags are advisory INPUT, not verdicts.** You decide true severity by
  weighing **real-world likelihood and impact**, not theoretical correctness.
- **BLOCKING** = a failing gate, a security/credential issue, an unmet acceptance criterion, a missing/weak
  test for required behavior, or data-integrity that can actually occur. Only blocking findings trigger a
  rework round and consume an attempt.
- **ADVISORY** = nits, edge cases that can't occur in practice, low-impact robustness. **Surface these in
  the PR body, do NOT spend a cycle.** Since the loop is unattended, file advisories as a checklist in the
  run-log scratchpad for the user to triage later; do not block the merge on them.
- Do NOT escalate a low-likelihood/low-impact issue to blocking just because a reviewer flagged it. Do NOT
  over-cycle: verify trivial changes by inspection instead of a fresh full review.

**Attempts:** initial implementation = attempt 1; each blocking-driven rework = +1; max 3. A red gate (or
red CI) the implementer is still resolving does NOT burn an attempt — only a *reviewed* round with
surviving blocking findings does. Infrastructure failures you caused do NOT count against the budget.

## Artifact contract

- **One run-log scratchpad** for the whole build (Solo project `matte` id 11) — append at every transition
  (spawn, gate result, findings, arbitration, attempt count, PR link, MERGE). This is the user's live
  window; they can interrupt anytime. The standing plan + advisory backlog live alongside it.
- **One Solo todo per PR** — body holds tasks + acceptance criteria; encode the PR dependency DAG with
  `todo_add_blocker`; status tracks loop state; mark complete after you merge.
- **The merge is the handoff.** On LAND, the PR body is the implementation report; the user reads merged
  PRs + run-log asynchronously.

## Split handoff (post-merge, monorepo-specific)

Splitting `packages/matte-*` to read-only mirror repos + Packagist is done via `kibble:split` on a tag
push (see `.github/workflows/release.yml`), NOT per-PR. It is not part of the gate.

## Implementation report (the PR body)

Read the full diff and write: **what shipped** vs the plan (1–2 lines); **deserves attention** (anything
complex, fragile, or a deviation forced by an in-flight discovery — and why); **findings disposition**
(blocking found + how fixed; advisories deferred, listed); **gate evidence** (`composer ready` clean +
which package suites passed; which ACs the judge confirmed; CI run link); **risk / next**.

## Hard-won rules (inherited from Hone run 1 — do not relearn these)

1. **Verify the gate on the committed SHA in a clean tree, with the real `composer ready` + touched-package
   suites.** Implementers leave uncommitted churn and report false greens; bare phpstan gives false reds on
   stale ide-helper; the app gate alone never runs the package tests.
2. **Per-worktree real `composer install` — at the root AND inside every touched package.** Never a
   symlinked/copied vendor at either level.
3. **You own severity.** Weigh likelihood; defer non-blocking to the run-log; don't over-escalate or over-cycle.
4. **Decorrelate harnesses** — OpenCode implements, Codex reviews, Claude judges. Don't collapse them.
5. **Idle-timers for hands-off resumption** — `timer_fire_when_idle_any` wakes you when an agent finishes.
6. The judge must read **real test output** and confirm the critical assertions genuinely exist (not just green).
7. **The envelope is the compatibility surface.** For any PR touching `matte-contracts`, the judge confirms
   the additive-within-major rule held (no field removed/repurposed) and round-trip + tolerance tests exist.
8. **Auto-merge means CI is load-bearing.** Never merge before CI is green even when the local gate passed;
   a red CI on a green local gate is a worktree/provisioning gap to fix, not a thing to override.
```
