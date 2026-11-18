(function () {
  "use strict";

  const singlePanel = document.getElementById("hmuFramePicker");
  if (!singlePanel) return;

  const multiPanel = document.getElementById("hmuMultiFramePicker");
  const modeBar = document.getElementById("hmu-capture-mode");
  const layout = document.getElementById("hmu-multishot-layout");
  const hero = document.getElementById("hmuHeroFrame");
  const initialHero = hero?.src;
  const title = document.getElementById("hmu-templates-title");
  const singleTitle = title?.textContent;
  const storageKey = "hmu-photobooth-selected-frame";
  const multiStorageKey = "hmu-photobooth-selected-set-frame";
  let mode = "single";
  let modeRevision = 0;
  let selectedMulti = null;
  let multiFrames;
  let multiReady;
  let syncPromise = Promise.resolve();
  let selectionRevision = 0;

  function frameUrl(filename) {
    return new URL("resources/img/frames/" + filename.split("/").map(encodeURIComponent).join("/"), document.baseURI).href;
  }

  function readPreference(key) {
    try { return localStorage.getItem(key); } catch (error) { return null; }
  }

  function savePreference(key, value) {
    try { localStorage.setItem(key, value); } catch (error) { /* Optional for kiosk mode. */ }
  }

  async function json(url) {
    const response = await fetch(url, { credentials: "same-origin" });
    if (!response.ok) throw new Error("Frame list request failed");
    return response.json();
  }

  function updateHero() {
    if (!hero) return;
    const url = mode === "multi" ? selectedMulti?.preview : (hmuFrameSelection.url || initialHero);
    hero.hidden = !url;
    if (url && hero.src !== new URL(url, document.baseURI).href) hero.src = url;
  }

  function makePicker(gridId, topicsId, describe, select, selectedId) {
    const grid = document.getElementById(gridId);
    const chips = document.getElementById(topicsId);

    function markSelection() {
      const current = selectedId();
      grid.querySelectorAll(".hmu-frame-card").forEach(card => {
        const selected = card.dataset.frame === current;
        card.classList.toggle("is-selected", selected);
        card.setAttribute("aria-pressed", String(selected));
        card.querySelector(".hmu-frame-select").innerHTML = selected
          ? '<i class="fa fa-check" aria-hidden="true"></i><span>Đang chọn</span>'
          : "Chọn khung này";
      });
    }

    function makeCard(frame) {
      const details = describe(frame);
      const card = document.createElement("button");
      card.type = "button";
      card.className = "hmu-frame-card rotaryfocus";
      card.dataset.frame = details.id;
      card.setAttribute("aria-label", "Chọn khung " + details.name);
      card.innerHTML = '<span class="hmu-frame-selected-tag"><i class="fa fa-check-circle" aria-hidden="true"></i> Đang chọn</span>' +
        '<span class="hmu-frame-thumb"><img alt="" loading="lazy" decoding="async"></span>' +
        '<span class="hmu-frame-meta"><span class="hmu-frame-description"></span><span class="hmu-frame-size"></span></span>' +
        '<span class="hmu-frame-select"></span>';
      card.querySelector("img").src = details.preview;
      card.querySelector(".hmu-frame-description").textContent = details.description;
      card.querySelector(".hmu-frame-size").textContent = details.badge;
      card.addEventListener("click", () => { select(frame); markSelection(); });
      return card;
    }

    function render(topics, initialTopic) {
      const all = topics.flatMap(topic => topic.frames);
      const filters = topics.length > 1 ? [{ label: "Tất cả khung", frames: all }, ...topics] : topics;
      let activeFilter;
      grid.replaceChildren();
      chips.replaceChildren();
      for (const topic of filters) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "hmu-filter-chip rotaryfocus";
        button.textContent = topic.label;
        button.setAttribute("aria-controls", grid.id);
        button.setAttribute("aria-pressed", "false");
        button.setAttribute("aria-label", topic.label + ", " + topic.frames.length + " khung");
        const count = document.createElement("b");
        count.textContent = topic.frames.length;
        count.setAttribute("aria-hidden", "true");
        button.append(count);
        function showTopic() {
          if (activeFilter === button) return;
          activeFilter?.setAttribute("aria-pressed", "false");
          activeFilter = button;
          button.setAttribute("aria-pressed", "true");
          grid.replaceChildren(...topic.frames.map(makeCard));
          markSelection();
          grid.scrollTop = 0;
        }
        button.addEventListener("click", showTopic);
        chips.append(button);
        if (topic === (initialTopic || filters[0])) showTopic();
      }
      chips.hidden = !all.length;
    }
    return { grid, render, markSelection };
  }

  const hmuFrameSelection = window.hmuFrameSelection = {
    filename: null,
    get url() { return this.filename ? frameUrl(this.filename) : ""; },
    ready() { return listPromise.then(() => syncPromise); },
  };
  const singleError = document.getElementById("hmuFrameError");
  function selectSingle(filename) {
    const url = frameUrl(filename);
    hmuFrameSelection.filename = filename;
    updateHero();
    document.documentElement.style.setProperty("--picture-frame", 'url("' + url + '")');
    document.querySelectorAll("#picture--frame, #frame, .frameImage, #preview-frame, .live-preview-frame").forEach(element => {
      if (element.tagName === "IMG") element.src = url;
      else element.style.backgroundImage = 'url("' + url + '")';
    });
    savePreference(storageKey, filename);
    const revision = ++selectionRevision;
    const form = new FormData();
    form.append("frame", filename);
    // Serialize the writes to the single-photo active frame across rapid clicks.
    syncPromise = syncPromise.catch(() => {}).then(async () => {
      if (revision !== selectionRevision) return;
      const response = await fetch("api/setFrame.php", { method: "POST", body: form, credentials: "same-origin" });
      if (!response.ok || (await response.json())?.status !== "success") throw new Error("Frame could not be saved");
      if (revision === selectionRevision && singleError) singleError.hidden = true;
    });
    syncPromise.catch(() => {
      if (revision !== selectionRevision || !singleError) return;
      singleError.textContent = "Đã chọn khung để xem trước, nhưng chưa đồng bộ được với máy chủ. Hãy thử chọn lại khung.";
      singleError.hidden = false;
    });
  }
  const single = makePicker("kioskFrameSelector", "hmuFrameTopics", filename => ({
    id: filename,
    name: filename.split("/").pop().slice(0, -4),
    preview: "api/framePreview.php?frame=" + encodeURIComponent(filename),
    description: "Khung ảnh HMU",
    badge: "PNG",
  }), selectSingle, () => hmuFrameSelection.filename);

  const listPromise = json("api/getFrames.php?grouped=1").then(topics => {
    if (!Array.isArray(topics) || !topics.length) throw new Error("No frames available");
    let savedFrame = readPreference(storageKey);
    const frames = topics.flatMap(topic => topic.frames);
    if (savedFrame && !frames.includes(savedFrame)) {
      const basename = savedFrame.split("/").pop();
      const relocated = frames.filter(filename => filename.split("/").pop() === basename);
      if (relocated.length === 1) savedFrame = relocated[0];
    }
    const savedTopic = topics.find(topic => topic.frames.includes(savedFrame));
    const initialTopic = savedTopic || topics[0];
    selectSingle(savedTopic ? savedFrame : initialTopic.frames[0]);
    single.render(topics, initialTopic);
  }).catch(error => {
    single.render([]);
    if (singleError) singleError.hidden = false;
    throw error;
  }).finally(() => single.grid.setAttribute("aria-busy", "false"));
  listPromise.catch(() => {});

  document.getElementById("hmuPreviewCaption")?.addEventListener("click", event => {
    const target = document.getElementById("hmu-templates");
    if (target) {
      event.preventDefault();
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  });

  if (!modeBar || !multiPanel) return;
  const multiError = document.getElementById("hmuMultiFrameError");
  const multi = makePicker("hmuMultiFrameSelector", "hmuMultiFrameTopics", frame => ({
    id: frame.id,
    name: frame.name,
    preview: frame.preview,
    description: frame.name,
    badge: frame.layout.replace("x", "×"),
  }), selectMulti, () => selectedMulti?.id);

  function selectMulti(frame) {
    selectedMulti = frame;
    if (frame) savePreference(multiStorageKey, frame.id);
    updateHero();
  }

  function renderMulti() {
    const visible = multiFrames.filter(frame => !layout.value || frame.layout === layout.value);
    const grouped = new Map();
    for (const frame of visible) {
      if (!grouped.has(frame.topic)) grouped.set(frame.topic, { label: frame.topic, frames: [] });
      grouped.get(frame.topic).frames.push(frame);
    }
    const preferred = selectedMulti?.id || readPreference(multiStorageKey);
    // Changing the layout picks a new frame only when the previous one cannot fit the filter.
    if (!visible.some(frame => frame.id === preferred) || !selectedMulti) {
      selectMulti(visible.find(frame => frame.id === preferred) || visible[0] || null);
    }
    multi.render([...grouped.values()], grouped.get(selectedMulti?.topic));
    multiError.hidden = !!visible.length;
    if (!visible.length) multiError.textContent = "Chưa có khung phù hợp. Hãy chọn bố cục khác.";
  }

  function loadMulti() {
    if (multiReady) return multiReady;
    multi.grid.setAttribute("aria-busy", "true");
    multiError.hidden = true;
    multiReady = json("api/multishot.php?action=frames").then(result => {
      if (!Array.isArray(result.frames)) throw new Error("Invalid multishot catalog");
      multiFrames = result.frames;
      renderMulti();
    }).catch(error => {
      multiReady = null;
      multiError.textContent = "Không tải được khung bộ ảnh. Hãy chọn lại Bộ ảnh để thử lại.";
      multiError.hidden = false;
      throw error;
    }).finally(() => multi.grid.setAttribute("aria-busy", "false"));
    return multiReady;
  }

  function selectMode(next) {
    if (!["single", "multi"].includes(next) || window.photoBooth?.takingPic || window.hmuFilterStage?.active) return false;
    if (mode !== next) modeRevision++;
    mode = next;
    singlePanel.hidden = mode === "multi";
    multiPanel.hidden = mode === "single";
    modeBar.querySelectorAll("button").forEach(button =>
      button.setAttribute("aria-pressed", String(button.dataset.captureMode === mode)));
    if (title) title.textContent = mode === "multi" ? "Chọn khung bộ ảnh" : singleTitle;
    updateHero();
    if (mode === "multi") loadMulti().catch(() => {});
    return true;
  }

  function focusSelection() {
    const grid = (mode === "multi" ? multi : single).grid;
    (grid.querySelector('[aria-pressed="true"]') || grid).focus();
  }

  layout.addEventListener("change", () => { if (multiFrames) renderMulti(); });
  modeBar.addEventListener("click", event => {
    const button = event.target.closest("button[data-capture-mode]");
    if (button) selectMode(button.dataset.captureMode);
  });
  modeBar.addEventListener("keydown", event => {
    if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    const next = event.key === "ArrowLeft" || event.key === "Home" ? "single" : "multi";
    if (selectMode(next)) modeBar.querySelector('[data-capture-mode="' + next + '"]').focus();
  });

  window.hmuFrameMode = {
    get current() { return mode; },
    select: selectMode,
    focusSelection,
    async openCapture() {
      const revision = modeRevision;
      try { await loadMulti(); } catch (error) {
        if (mode === "multi" && revision === modeRevision) focusSelection();
        return;
      }
      if (mode !== "multi" || revision !== modeRevision || window.photoBooth?.takingPic || window.hmuFilterStage?.active) return;
      if (selectedMulti) window.hmuMultishot.open(selectedMulti);
      else focusSelection();
    },
  };
})();
