(() => {
  'use strict';

  const input = document.getElementById('files');
  const drop = document.getElementById('drop');
  const bar = document.getElementById('bar');
  const track = document.querySelector('.track');
  const stats = document.getElementById('stats');
  const current = document.getElementById('current');
  const history = document.getElementById('history');
  const pause = document.getElementById('pause');
  const queue = [];
  let total = 0, done = 0, failed = 0, running = false, paused = false;
  const maxSide = 1600;

  function update(progress = 0) {
    const completed = done + failed;
    const percent = total ? Math.min(100, 100 * (completed + progress) / total) : 0;
    bar.style.width = percent + '%';
    track.setAttribute('aria-valuenow', String(Math.round(percent)));
    stats.textContent = `${completed}/${total} töödeldud · ${done} lisatud · ${failed} viga · ${queue.length} ootel`;
    pause.disabled = !running && queue.length === 0;
    pause.textContent = paused ? 'Jätka üleslaadimist' : 'Peata järjekord';
  }

  function note(message, success) {
    const item = document.createElement('li');
    item.className = success ? 'ok' : 'err';
    item.textContent = (success ? '✓ ' : '✕ ') + message;
    history.prepend(item);
    while (history.children.length > 16) history.lastElementChild.remove();
  }

  // Iga foto vähendatakse eraldi, alles siis alustatakse selle üleslaadimist.
  // Nii jääb nii telefonis kui ka Pi-s mälukasutus mõistlikuks.
  async function optimize(file) {
    if (file.size > 64 * 1024 * 1024) throw new Error('Fail on suurem kui 64 MiB');
    const url = URL.createObjectURL(file);
    let img;
    try {
      img = await new Promise((resolve, reject) => {
        const preview = new Image();
        preview.onload = () => resolve(preview);
        preview.onerror = () => reject(new Error('Fotot ei õnnestunud avada'));
        preview.src = url;
      });
    } finally {
      URL.revokeObjectURL(url);
    }

    const width = img.naturalWidth;
    const height = img.naturalHeight;
    if (!width || !height) throw new Error('Fotol puudub korrektne suurus');
    const scale = Math.min(1, maxSide / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Brauser ei saa pilti töödelda');
    ctx.fillStyle = '#0c1016';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
    canvas.width = 0;
    canvas.height = 0;
    if (!blob) throw new Error('JPEG teisendamine ebaõnnestus');
    return blob;
  }

  function send(blob, filename) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php');
      xhr.timeout = 300000;
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) update(e.loaded / e.total);
      };
      xhr.onload = () => {
        let response;
        try { response = JSON.parse(xhr.responseText); }
        catch { reject(new Error(`HTTP ${xhr.status}: vigane serveri vastus`)); return; }
        if (xhr.status >= 200 && xhr.status < 300 && response.ok) resolve(response.message);
        else reject(new Error(response.message || `HTTP ${xhr.status}`));
      };
      xhr.onerror = () => reject(new Error('Võrguühendus katkes'));
      xhr.ontimeout = () => reject(new Error('Üleslaadimine aegus'));
      const body = new FormData();
      body.append('foto', blob, filename);
      xhr.send(body);
    });
  }

  async function run() {
    if (running || paused) return;
    running = true;
    update();
    while (queue.length && !paused) {
      const file = queue.shift();
      current.textContent = 'Valmistan ette: ' + file.name;
      try {
        const blob = await optimize(file);
        current.textContent = 'Laen üles: ' + file.name;
        const base = file.name.replace(/\.[^.]*$/, '') || 'foto';
        const result = await send(blob, base + '.jpg');
        done++;
        note(file.name + ' – ' + result, true);
      } catch (error) {
        failed++;
        note(file.name + ' – ' + error.message, false);
      }
      update();
    }
    running = false;
    current.textContent = paused ? 'Järjekord on peatatud' : 'Valmis! Pildiraam leiab fotod automaatselt.';
    update();
  }

  function addFiles(files) {
    let added = 0;
    for (const file of files) {
      if (!/\.(jpe?g|png|webp)$/i.test(file.name)) {
        note(file.name + ': sobimatu formaat (JPG, PNG või WebP)', false);
        continue;
      }
      queue.push(file);
      total++;
      added++;
    }
    if (added) {
      paused = false;
      update();
      run();
    }
  }

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });
  input.addEventListener('change', () => {
    addFiles(input.files);
    input.value = '';
  });
  ['dragenter', 'dragover'].forEach(event => drop.addEventListener(event, e => {
    e.preventDefault();
    drop.classList.add('drag');
  }));
  ['dragleave', 'drop'].forEach(event => drop.addEventListener(event, e => {
    e.preventDefault();
    drop.classList.remove('drag');
  }));
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  pause.addEventListener('click', () => {
    paused = !paused;
    update();
    if (!paused) run();
  });
  update();
})();
