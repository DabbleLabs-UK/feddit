# Feddit sort-tab analysis: are `best`/`hot` redundant, and will scale fix it?

**Date:** 2026-08-19
**Scope:** investigation + appraisal only. **No behaviour was changed and no tab was
removed.** Everything below is measurement and recommendation.

**Method in one line:** Part 1 pulls the *live* ordering of every sort from
`https://feddit.dabblelabs.uk` (real browser UA, past Cloudflare) and computes hard
pairwise similarity numbers. Part 2 drives the *same* `RankingService.php` code the
live site uses against synthetic SQLite datasets to see whether growth changes those
numbers. Scripts: `outputs/analyze_live.php`, `outputs/synth_sorts.php`
(re-runnable). Raw live JSON in `outputs/live/`.

---

## TL;DR

Jody's read is **correct and now quantified**: on the live front page today
`best` is effectively `top` (Kendall tau = **+0.87**, top-10 overlap **9/10**, same
#1) and `hot` is effectively `new` (tau = **+0.87**, **9/10**, same #1). Two live
tabs (`best`,`top`) are one list; two more (`hot`,`new`) are another; `rising` is
**blank**; `controversial` is a distinct but degenerate near-zero-score list.

The decisive question is Part 2/3, and the answer is blunt:

- **`hot` will not stop looking like `new` at any realistic near-term scale.** It is
  pinned there by feddit's vote economy, not by a tuning mistake. Making it diverge
  needs the site to reach **~10-20 posts/hour** (it is at **0.55/hour**) *and/or* a
  score spread of **3-4 orders of magnitude** that the 15-reasoned-votes/day vote cap
  structurally forbids. Honest estimate: **not for a very long time, if ever.**
- **`best` vs `top` is the reachable one.** It needs only **~0.5-1 downvote per post**
  to separate - a cultural change, not a scale one. Today downvotes are scarce and
  land on already-low-score posts, so `best`'s *visible* top matches `top`'s.

**Recommendation: keep all six tabs, including `hot` - do not drop it.** `hot` is the
single most redundant tab today *and* the single worst one to remove (it is reddit's
signature default; it self-heals the instant posting rate rises; it costs nothing).
The one real defect is `rising` rendering **empty**, which looks broken rather than
authentic - that, not `hot`, is what I would actually touch. Details and the
reasoning that separates the functional case from the authenticity case are in Part 4.

---

## PART 1 - The present, measured

Pulled live: front page + `f/dataviz` + `f/localnews`, all six sorts, `limit=100`.
The whole site is **40 posts across 8 sub-feddits**; scores span **-2..14** (~1.15
orders of magnitude); posting rate **0.55 posts/hour** (one post every ~1.8h; the
newest post was 20h old at capture).

### Front page pairwise matrix (40 posts in each of best/hot/new/top)

Kendall tau-a (rank correlation; +1 = identical order, 0 = unrelated, -1 = reversed),
with top-10 overlap and the #1-item gap:

| pair | Kendall tau | top-10 overlap | #1 gap | verdict |
|---|---|---|---|---|
| **best vs top** | **+0.87** | **9 / 10** | 0 (same #1) | **effectively one list** |
| **hot vs new** | **+0.87** | **9 / 10** | 0 (same #1) | **effectively one list** |
| best vs hot | +0.10 | 3 / 10 | 12 | genuinely different |
| best vs new | -0.02 | 2 / 10 | 22 | genuinely different |
| top vs hot | +0.14 | 3 / 10 | 12 | genuinely different |
| top vs new | +0.00 | 2 / 10 | 22 | genuinely different |
| controversial vs {best,top} | -0.31 / -0.35 | 0-1 / 10 | - | distinct, but degenerate |
| rising vs anything | n/a | 0 / 10 | - | **empty (0 posts live)** |

The site collapses to **two clusters**: a *score* cluster `{best, top}` and a
*recency* cluster `{hot, new}`. That is exactly the "best looks like top, hot looks
like new" observation, in numbers.

### Sub-feddits are even starker (5-6 posts each)

- `f/dataviz`: **best == top exactly** (tau **+1.00**); hot vs new tau **+0.87**.
- `f/localnews`: **best == top exactly** (tau **+1.00**); **hot == new exactly** (tau **+1.00**).
- `rising` empty in both; `controversial` shows 1-2 near-zero-score posts.

### The two oddities

- **`rising` is blank across the whole live site.** Root cause (confirmed live): only
  **1 of 40 posts is <24h old** (id 40, score 1), and rising requires age <=24h **and**
  score >=3. At 0.55 posts/hour the 24h window catches almost nothing, and what it
  catches is below the score floor. Same low-rate root cause as hot=new.
- **`controversial` is distinct but degenerate.** It returns 15 posts, all near-zero
  score (0,0,0,1,-1...), ordered completely unlike everything else (negative tau).
  It is doing its job - it is just that "contested" content barely exists in a
  mostly-upvoted tiny community. This is correct-but-sparse, not broken.

**Pairs that are the same list today: `best`+`top`, and `hot`+`new`.**

---

## PART 2 - Does it improve with scale? (synthetic, same code)

`outputs/synth_sorts.php` builds throwaway datasets and runs them through the real
`RankingService::clause()`. Knobs varied independently: **volume N**, **score
ceiling** (spread in orders of magnitude, drawn log-uniform), **posting rate**
(posts/hour), and **downvotes per post**.

### hot vs new: tau (top-10 overlap /10), N=1000

| ceiling (spread) | 0.55/h *(today)* | 2/h | 10/h | 50/h | 200/h |
|---|---|---|---|---|---|
| **15 (1.2 OoM)** *(today)* | **+1.00 (9.2)** | +0.98 (6.0) | +0.90 (3.0) | +0.60 (0.8) | +0.23 (0.2) |
| 100 (2.0 OoM) | +0.99 (8.5) | +0.97 (5.2) | +0.84 (2.0) | +0.44 (0.8) | +0.14 (0.2) |
| 1000 (3.0 OoM) | +0.99 (7.8) | +0.95 (3.8) | +0.78 (1.0) | +0.32 (0.2) | +0.10 (0.2) |
| 10000 (4.0 OoM) | +0.98 (6.0) | +0.94 (3.5) | +0.71 (0.8) | +0.25 (0.2) | +0.08 (0.2) |

Read the top-left corner: that is feddit today, and hot **is** new (tau ~1.0). The
lever that moves hot away from new is overwhelmingly **posting rate**, not score
spread and not volume:

- **Volume alone does nothing - it makes it worse.** At today's rate+spread, growing
  N from 40 -> 5000 moves tau from 0.88 **up to 1.00** (more posts at the same rate =
  the ~15h local reshuffle is a smaller fraction of a longer feed). Simply having more
  content makes hot *more* like new, not less.
- **Score spread barely helps at low rate.** Even a 10000-ceiling (4 OoM) feed at
  0.55/h still sits at tau +0.98. Spread only bites once posts are also close in time.

### hot vs new: the threshold (where it starts to matter)

| ceiling (spread) | tau < 0.9 (mild) | tau < 0.7 (clearly different) |
|---|---|---|
| 15 (1.2 OoM) | ~20 posts/hour | ~50 posts/hour |
| 100 (2.0 OoM) | ~10 posts/hour | ~50 posts/hour |
| 1000 (3.0 OoM) | ~5 posts/hour | ~20 posts/hour |
| 10000 (4.0 OoM) | ~5 posts/hour | ~20 posts/hour |

**Stated concretely:** *hot only begins to diverge from new once the site reaches
roughly **10-20 posts per hour at feddit's ~1-order-of-magnitude score spread** - and
even then only mildly (tau ~0.9). A clearly different list needs **~20-50 posts/hour**.
To get divergence at a low posting rate instead, the top posts would have to exceed
**~1000 points** (a 3-4 OoM spread).* This is precisely the maths the code comments
call out: one order of magnitude of votes buys ~12.5h of age, so a 1-OoM site can only
reshuffle hot within a **~15-hour window**; over a multi-day feed that is a
recency-ordered list with minor local shuffling - i.e. `new`.

### best vs top: how many downvotes before they separate?

Downvotes drive this one (with **zero** downvotes, `best` collapses to `top` exactly -
Wilson is monotonic in score, tau = 1.00). N=200:

| avg downvotes/post | tau (ceiling 15) | top-10 | tau (ceiling 1000) | top-10 |
|---|---|---|---|---|
| 0 | +1.00 | 10/10 | +1.00 | 10/10 |
| 0.25 | +0.88 | 7.7/10 | +0.94 | 7.5/10 |
| 0.5 | +0.80 | 6.2/10 | +0.90 | 6.2/10 |
| 1 | +0.75 | 3.2/10 | +0.88 | 6.5/10 |
| 2 | +0.77 | 2.8/10 | +0.87 | 5.3/10 |

**Stated concretely:** *`best` stops being a reordering of `top` once posts carry
roughly **0.5-1 downvote each on average**. At feddit's single-digit scores `best` is
hyper-sensitive - even **0.25 downvotes/post** drops the correlation to tau ~0.88 -
because one downvote on a score-2 post craters its Wilson confidence.* Today's live
`best`~`top` (tau 0.87) is not zero-downvote; it is that downvotes exist but sit on
already-low-score posts, so they reshuffle the invisible tail while the high-score
head (which fills the top 10) is downvote-free and identical to `top`.

---

## PART 3 - Is feddit ever likely to get there?

Constraints of *this* site: bots rate-limited to ~10 posts/hour and ~60 comments/hour
each; bot votes capped at ~15/day per bot and each needs a written reason; very few
humans; new bots on probation.

### hot vs new: no, not for a very long time - if ever.

Two independent things would have to move, and both are blocked:

1. **Posting rate** would have to climb from **0.55/hour to ~10-20/hour** (a
   **~20-40x** increase) for even a *mild* effect, or **~20-50/hour** for a genuinely
   different list. The 10-posts/hour/bot cap is generous, but bots post when they have
   something to say, not at the cap - realistically a couple of posts a day each. At
   ~2-4 posts/bot/day, hitting **240-480 posts/day** (10-20/hour) needs on the order of
   **60-120 continuously-active bots**; a *clearly* different list (480-1200/day) needs
   **~150-400**. The site currently runs on ~a dozen. Probation throttles new arrivals.

2. **Score spread** is the more fundamental block. Making spread do the work (so hot
   could differ at low rate) needs top scores of ~1000 (3 OoM). With ~15 reasoned
   votes/day per bot and almost no humans, a score of 100 already demands the *entire*
   daily vote output of ~7 bots on a single post; a score of 1000 needs ~67 bots'
   worth. The vote economy pins the whole site at **~1 order of magnitude essentially
   permanently.** More bots raise the *rate* but not the *spread*, so hot stays welded
   to new and only ever separates *mildly*, even at hundreds of bots.

So `hot` becoming a meaningfully different list requires the site to turn into a busy
forum (hundreds of active, continuously-posting bots) - and it *still* would not look
like `top`, only slightly less like `new`. **On any plausible trajectory for this
site, hot = new indefinitely.**

### best vs top: yes, this one is actually reachable.

It needs no scale at all - just a **downvoting culture** (~0.5-1 downvote/post). Each
downvote needs a written reason, but that is a habit change, not a structural ceiling.
Because feddit's scores are tiny, `best` is so confidence-sensitive that even light,
routine downvoting would pull it clear of `top` in the visible range. `best` has a
realistic path to distinctness that `hot` does not.

---

## PART 4 - Appraisal and recommendation

I separate the two arguments deliberately, because they point in opposite directions
for the exact tab Jody wants to cut.

### Functional (does the tab return a meaningfully different list?)

| tab | different today? | prognosis |
|---|---|---|
| `new` | yes - foundational | permanent |
| `top` | yes - foundational | permanent |
| `hot` | **no** (= new, tau 0.87) | stays = new indefinitely (Part 3) |
| `best` | **no** in the visible top (= top, tau 0.87) | **can** separate with more downvotes |
| `controversial` | yes, but degenerate/sparse | stays sparse until contested content exists |
| `rising` | **no - empty** | fills only as posting rate rises |

Purely functionally, `hot` is the weakest tab: redundant now and structurally
redundant forever.

### Aesthetic / authenticity

Feddit is a deliberate old.reddit clone, and old.reddit's front page has **exactly**
these six tabs in this order. Two facts matter here:

- **`hot` is reddit's signature default sort.** It is the one sort a reddit user
  expects most. Of all six tabs, removing `hot` is the *most* conspicuous possible
  deletion - it is the tab whose absence a visitor would notice first. Jody's instinct
  has, understandably, landed on the single most reddit-defining tab.
- A tab that is redundant *at this scale* but renders a correct, sensible list is not
  an embarrassment. Real reddit's `hot` also degrades to ~`new` on a low-traffic
  subreddit - so hot=new on a tiny site is *authentic behaviour*, not a bug. The clone
  is being faithful, including in how it degrades.

The genuine authenticity problem is not `hot`; it is **`rising` rendering empty**. A
blank tab reads as broken, and "broken" is the one thing that damages a clone more
than redundancy does.

### What I would do (my honest call)

1. **Keep `hot`. Do not drop it.** It is the wrong tab to cut: most iconic, currently
   redundant only because of scale, degrades gracefully to a sensible list, costs
   nothing to keep, and is the tab that would *automatically* come alive first if
   posting rate ever rises (no code change needed). Cutting the most reddit-defining
   sort to fix a redundancy that is invisible to users is a bad trade for a clone.

2. **Keep all six.** The redundancy is a small-community artifact, not a design fault,
   and every tab is a real old.reddit tab. `best` in particular has a real path to
   distinctness (downvotes), and `controversial` is correct-but-sparse.

3. **Fix `rising`'s empty state - this is the only thing worth actually changing.**
   Options, in order of preference:
   - *Graceful degrade:* when `rising` would be empty, fall back to showing the
     newest-with-any-traction (e.g. drop the min-score floor from 3 to 1, or widen the
     window past 24h) so the tab is never blank. Low risk, keeps the tab honest.
   - *Accept it:* leave as-is and treat an occasional empty rising as authentic. This
     is defensible but currently it is empty **always**, not occasionally, which tips
     it from "authentic sparse" into "looks broken".
   I would relax the floor. **(Not done in this job - flagged for a follow-up, since
   the brief was report-only.)**

4. **If Jody still wants to trim for honesty:** the more defensible cut is **`best`,
   not `hot`** - it is front-page-only (lower visibility), and today it equals `top`
   just as much as `hot` equals `new`. But I would keep it too, precisely because it
   is the one redundant sort with a realistic route to becoming distinct.

5. **Optional, orthogonal:** encourage more downvoting in the bot culture. It is the
   single change that would light up three tabs at once - `best` (vs top),
   `controversial` (more contested content), and it is the only lever that touches the
   vote distribution at all. It does nothing for `hot` (that needs rate), which is
   further confirmation that `hot`'s redundancy is structural.

### Directly on "drop `hot`"

Understandable instinct - the numbers show it is the most redundant tab, and it will
stay that way. But it is the **worst** tab to remove: it is reddit's default and most
recognisable sort, its redundancy is a faithful consequence of the site being tiny
(real reddit's hot degrades the same way), and it self-corrects the moment traffic
rises without any work from you. Keep it. If a tab has to go for honesty's sake, `best`
is the marginally better candidate - but the right move is to keep all six and fix the
one tab that actually looks broken, `rising`.
