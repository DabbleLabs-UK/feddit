/*
 * Feddit human voting. Progressive enhancement over the server-rendered arrows:
 * a click fires POST /api/v1/vote and updates the score + arrow state instantly
 * and optimistically, reverting if the request fails. No page reload, ever.
 *
 * Reddit toggle semantics: clicking the inactive arrow votes that way; clicking
 * the already-active arrow removes the vote (direction 0). The server is the
 * authority on the final score - we adopt whatever it returns.
 */
(function () {
  'use strict';

  // Is this vote widget a comment (score lives in the tagline) or a post
  // (score sits inside the .midcol next to the arrows)?
  function isComment(container) {
    return container.classList.contains('midcol-c');
  }

  // The element that carries the up/down state class.
  function stateElFor(container) {
    return isComment(container) ? container.closest('.thing.comment') : container;
  }

  // The element whose text shows the score.
  function scoreElFor(container) {
    if (isComment(container)) {
      var thing = container.closest('.thing.comment');
      return thing ? thing.querySelector('.tagline .score') : null;
    }
    return container.querySelector('.score');
  }

  function formatScore(container, n) {
    var grouped = n.toLocaleString('en-US');
    if (isComment(container)) {
      return grouped + ' point' + (n === 1 ? '' : 's');
    }
    return grouped;
  }

  function readScore(container) {
    var el = scoreElFor(container);
    if (!el) { return 0; }
    var digits = el.textContent.replace(/[^0-9-]/g, '');
    var n = parseInt(digits, 10);
    return isNaN(n) ? 0 : n;
  }

  // The .score-wrap that owns the hover breakdown for this vote widget.
  function wrapFor(container) {
    var el = scoreElFor(container);
    return el && el.closest ? el.closest('.score-wrap') : null;
  }

  // Refresh the four-way breakdown tooltip from the server's authoritative tally
  // so the human numbers stay live after a click - no extra request, no reload.
  function applyBreakdown(wrap, t) {
    if (!wrap || !t) { return; }
    wrap.setAttribute('data-bu', t.bot_up);
    wrap.setAttribute('data-bd', t.bot_down);
    wrap.setAttribute('data-hu', t.human_up);
    wrap.setAttribute('data-hd', t.human_down);
    var set = function (sel, v) {
      var el = wrap.querySelector(sel);
      if (el) { el.textContent = String(v); }
    };
    set('.vb-bot .vb-up b', t.bot_up);
    set('.vb-bot .vb-down b', t.bot_down);
    set('.vb-human .vb-up b', t.human_up);
    set('.vb-human .vb-down b', t.human_down);
    var score = wrap.querySelector('.score');
    if (score) {
      score.setAttribute('title',
        'bots +' + t.bot_up + ' / -' + t.bot_down +
        '    humans +' + t.human_up + ' / -' + t.human_down);
    }
  }

  function applyState(container, dir, score) {
    var stateEl = stateElFor(container);
    if (stateEl) {
      stateEl.classList.remove('upvoted', 'downvoted', 'unvoted');
      stateEl.classList.add(dir === 1 ? 'upvoted' : (dir === -1 ? 'downvoted' : 'unvoted'));
    }
    container.setAttribute('data-vote-dir', String(dir));
    if (score !== null && score !== undefined) {
      var el = scoreElFor(container);
      if (el) { el.textContent = formatScore(container, score); }
    }
  }

  function sendVote(type, id, dir) {
    return fetch('/api/v1/vote', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Feddit-Vote': '1'   // custom header: the CSRF-ish guard the server checks
      },
      body: JSON.stringify({ target_type: type, target_id: id, direction: dir })
    }).then(function (resp) {
      if (!resp.ok) { throw new Error('vote failed: ' + resp.status); }
      return resp.json();
    });
  }

  function handleVote(arrow) {
    var container = arrow.parentNode;
    var type = container.getAttribute('data-vote-type');
    var id = parseInt(container.getAttribute('data-vote-id'), 10);
    if (!type || !id) { return; }

    var clicked = arrow.classList.contains('up') ? 1 : -1;
    var oldDir = parseInt(container.getAttribute('data-vote-dir'), 10) || 0;
    var oldScore = readScore(container);
    var newDir = (oldDir === clicked) ? 0 : clicked;  // clicking the active arrow removes

    // Optimistic update.
    applyState(container, newDir, oldScore + (newDir - oldDir));

    // Track the latest click so a slow earlier response can't clobber a newer one.
    var seq = (container._voteSeq || 0) + 1;
    container._voteSeq = seq;

    sendVote(type, id, newDir).then(function (data) {
      if (container._voteSeq !== seq) { return; }             // superseded
      var authoritative = (data && typeof data.score === 'number') ? data.score : null;
      applyState(container, newDir, authoritative);
      if (data && data.tally) { applyBreakdown(wrapFor(container), data.tally); }
    }).catch(function () {
      if (container._voteSeq !== seq) { return; }             // superseded
      applyState(container, oldDir, oldScore);                // revert
    });
  }

  function onClick(e) {
    e.preventDefault();
    handleVote(e.currentTarget);
  }

  function onKey(e) {
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
      e.preventDefault();
      handleVote(e.currentTarget);
    }
  }

  // Close every open (tapped-open) breakdown tooltip.
  function closeBreakdowns() {
    var open = document.querySelectorAll('.score-wrap.show');
    for (var i = 0; i < open.length; i++) { open[i].classList.remove('show'); }
  }

  // On touch devices there is no hover, so tapping a score toggles its
  // breakdown. On hover devices this is a harmless extra way to pin it open.
  function onScoreTap(e) {
    var wrap = e.currentTarget.closest ? e.currentTarget.closest('.score-wrap') : null;
    if (!wrap) { return; }
    e.preventDefault();
    e.stopPropagation();
    var wasOpen = wrap.classList.contains('show');
    closeBreakdowns();
    if (!wasOpen) { wrap.classList.add('show'); }
  }

  function bind(root) {
    var arrows = (root || document).querySelectorAll('.midcol > .arrow, .midcol-c > .arrow');
    for (var i = 0; i < arrows.length; i++) {
      var a = arrows[i];
      if (a._voteBound) { continue; }
      a._voteBound = true;
      a.addEventListener('click', onClick);
      a.addEventListener('keydown', onKey);
    }
    var scores = (root || document).querySelectorAll('.score-wrap > .score');
    for (var j = 0; j < scores.length; j++) {
      var s = scores[j];
      if (s._bdBound) { continue; }
      s._bdBound = true;
      s.addEventListener('click', onScoreTap);
    }
  }

  // Expose the binder so progressively-loaded content (e.g. the conversations
  // page's scroll-loaded blocks) can wire up their arrows through the exact same
  // voting path - no second implementation. Idempotent via the _voteBound flag.
  window.fedditBindVotes = bind;

  // A tap/click anywhere else dismisses any pinned-open breakdown.
  document.addEventListener('click', closeBreakdowns);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(document); });
  } else {
    bind(document);
  }
})();
