/*
 * Conversations page: infinite scroll. The first blocks are server-rendered; as
 * the reader nears the foot we fetch the next page as an HTML fragment and append
 * it, then rebind the shared vote arrows on the new nodes. Vanilla JS, no build.
 *
 * Progressive enhancement: the foot already holds a real "next page" link that
 * works with JS off. We take it over only when IntersectionObserver + fetch are
 * available, and leave the link clickable if a fetch ever fails.
 */
(function () {
  'use strict';

  var blocks = document.getElementById('conv-blocks');
  var more   = document.getElementById('conv-more');
  if (!blocks || !more) { return; }
  if (!('IntersectionObserver' in window) || !window.fetch) { return; }

  var loading = false;
  var done    = false;

  function currentCursor() {
    return more.getAttribute('data-conv-next') || '';
  }

  function setLoading(on) {
    var link = more.querySelector('.conv-nextpage');
    if (link) { link.textContent = on ? 'loading more...' : 'next page ->'; }
    more.classList.toggle('loading', !!on);
  }

  function finish() {
    done = true;
    observer.disconnect();
    if (more.parentNode) { more.parentNode.removeChild(more); }
  }

  function loadMore() {
    if (loading || done) { return; }
    var cursor = currentCursor();
    var base   = more.getAttribute('data-conv-base');
    if (!cursor || !base) { finish(); return; }

    loading = true;
    setLoading(true);

    var url = base + '?after=' + encodeURIComponent(cursor) + '&partial=1';
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (resp) {
        if (!resp.ok) { throw new Error('load failed: ' + resp.status); }
        var next = resp.headers.get('X-Conv-Next') || '';
        return resp.text().then(function (html) { return { html: html, next: next }; });
      })
      .then(function (res) {
        var frag = document.createElement('div');
        frag.innerHTML = res.html;
        // Move each rendered block into the live list.
        while (frag.firstChild) {
          var node = frag.firstChild;
          blocks.appendChild(node);
          if (node.nodeType === 1 && window.fedditBindVotes) {
            window.fedditBindVotes(node);
          }
        }
        loading = false;
        if (res.next) {
          more.setAttribute('data-conv-next', res.next);
          var link = more.querySelector('.conv-nextpage');
          if (link) { link.setAttribute('href', base + '?after=' + encodeURIComponent(res.next)); }
          setLoading(false);
          // If the sentinel is still on screen (a tall viewport), keep going.
          if (isVisible(more)) { loadMore(); }
        } else {
          finish();
        }
      })
      .catch(function () {
        // Leave the plain link in place so the reader can still page manually.
        loading = false;
        setLoading(false);
      });
  }

  function isVisible(el) {
    var r = el.getBoundingClientRect();
    return r.top < (window.innerHeight || document.documentElement.clientHeight) + 300;
  }

  var observer = new IntersectionObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      if (entries[i].isIntersecting) { loadMore(); }
    }
  }, { rootMargin: '400px 0px' });

  observer.observe(more);
})();
