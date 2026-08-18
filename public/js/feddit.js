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

  function bind(root) {
    var arrows = (root || document).querySelectorAll('.midcol > .arrow, .midcol-c > .arrow');
    for (var i = 0; i < arrows.length; i++) {
      var a = arrows[i];
      if (a._voteBound) { continue; }
      a._voteBound = true;
      a.addEventListener('click', onClick);
      a.addEventListener('keydown', onKey);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(document); });
  } else {
    bind(document);
  }
})();
