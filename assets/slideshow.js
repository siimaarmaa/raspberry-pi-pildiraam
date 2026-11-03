(() => {
  'use strict';

  const config = window.PILDIRAAM;
  const slides = Array.from(document.querySelectorAll('.photo'));
  const empty = document.getElementById('empty');
  const progress = document.getElementById('progress-bar');
  const name = document.getElementById('photo-name');
  const count = document.getElementById('photo-count');
  const slideMs = Math.max(2000, config.slideMs || 12000);
  let photos = [];
  let current = -1;
  let visible = 0;
  let busy = false;

  document.documentElement.style.setProperty('--slide-time', slideMs + 'ms');

  function clock() {
    const now = new Date();
    document.getElementById('clock').textContent = now.toLocaleTimeString('et-EE', {
      hour: '2-digit', minute: '2-digit', timeZone: config.timezone
    });
    document.getElementById('date').textContent = now.toLocaleDateString('et-EE', {
      weekday: 'long', day: 'numeric', month: 'long', timeZone: config.timezone
    });
  }

  function updateLabel() {
    const item = photos[current];
    name.textContent = item ? item.name.replace(/[_-]/g, ' ') : '';
    count.textContent = item ? `${current + 1} / ${photos.length}` : '';
  }

  function restartBar() {
    progress.classList.remove('run');
    void progress.offsetWidth;
    progress.classList.add('run');
  }

  function show(index, failedAttempts = 0) {
    if (busy || !photos.length) return;
    index = (index + photos.length) % photos.length;
    const incoming = slides[1 - visible];
    const photo = photos[index];
    busy = true;
    incoming.onload = () => {
      slides[visible].classList.remove('visible');
      incoming.classList.add('visible');
      visible = 1 - visible;
      current = index;
      busy = false;
      empty.hidden = true;
      updateLabel();
      restartBar();
    };
    incoming.onerror = () => {
      busy = false;
      if (failedAttempts + 1 < photos.length) {
        show(index + 1, failedAttempts + 1);
      } else {
        empty.querySelector('strong').textContent = 'Fotosid ei saa avada';
        empty.querySelector('small').textContent = 'Kontrolli PHP GD laiendust ja pildifaile.';
        empty.hidden = false;
      }
    };
    incoming.src = photo.url;
  }

  async function poll() {
    try {
      const response = await fetch('api.php?t=' + Date.now(), { cache: 'no-store' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      if (data.version !== config.version) {
        location.replace('index.php?updated=' + Date.now());
        return;
      }

      const previous = photos[current];
      photos = data.photos;
      if (!photos.length) {
        slides.forEach(img => img.classList.remove('visible'));
        current = -1;
        empty.querySelector('strong').textContent = 'Ootan fotosid …';
        empty.querySelector('small').textContent = 'Lisa fotod kausta /home/saarmaa/pildid või ava telefonis /pildiraam/upload.php';
        empty.hidden = false;
        updateLabel();
        return;
      }
      if (!previous) {
        if (!busy) show(0);
        return;
      }
      const sameFile = photos.findIndex(p => p.file === previous.file);
      if (sameFile === -1) {
        current = -1;
        if (!busy) show(0);
      } else if (photos[sameFile].url !== previous.url) {
        current = -1;
        if (!busy) show(sameFile);
      } else {
        current = sameFile;
        updateLabel();
      }
    } catch (error) {
      console.warn('Fotode uuendamine ebaõnnestus:', error);
    }
  }

  clock();
  setInterval(clock, 10000);
  poll();
  setInterval(() => {
    if (photos.length > 1 && !busy) show(current + 1);
  }, slideMs);
  setInterval(poll, Math.max(5000, config.checkMs || 30000));
  setInterval(() => location.replace('index.php?periodic=' + Date.now()),
    Math.max(60000, config.reloadMs || 1800000));
})();
