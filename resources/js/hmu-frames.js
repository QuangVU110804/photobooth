(function () {
  "use strict";

  var selector = document.getElementById("kioskFrameSelector");
  if (!selector) return;

  var topicFilters = document.getElementById("hmuFrameTopics");
  var heroFrame = document.getElementById("hmuHeroFrame");
  var errorMessage = document.getElementById("hmuFrameError");
  var storageKey = "hmu-photobooth-selected-frame";
  var syncPromise = Promise.resolve();
  var selectionRevision = 0;
  var listPromise;
  window.hmuFrameSelection = {
    filename: null,
    get url() { return this.filename ? frameUrl(this.filename) : ""; },
    ready: function () { return listPromise.then(function () { return syncPromise; }); }
  };

  function frameUrl(filename) {
    return new URL("resources/img/frames/" + filename.split("/").map(encodeURIComponent).join("/"), document.baseURI).href;
  }

  function setCardSelection(card, isSelected) {
    card.classList.toggle("is-selected", isSelected);
    card.setAttribute("aria-pressed", String(isSelected));
    card.querySelector(".hmu-frame-select").innerHTML = isSelected
      ? '<i class="fa fa-check" aria-hidden="true"></i><span>Đang chọn</span>'
      : "Chọn khung này";
  }

  function selectFrame(filename, card) {
    var url = frameUrl(filename);

    selector.querySelectorAll(".hmu-frame-card").forEach(function (item) {
      setCardSelection(item, item === card);
    });

    if (heroFrame) heroFrame.src = url;

    document.documentElement.style.setProperty("--picture-frame", 'url("' + url + '")');
    document.querySelectorAll("#picture--frame, #frame, .frameImage, #preview-frame, .live-preview-frame").forEach(function (element) {
      if (element.tagName.toLowerCase() === "img") element.src = url;
      else element.style.backgroundImage = 'url("' + url + '")';
    });

    window.hmuFrameSelection.filename = filename;
    var revision = ++selectionRevision;

    try { window.localStorage.setItem(storageKey, filename); } catch (error) { /* Storage may be disabled in kiosk privacy mode. */ }

    var form = new FormData();
    form.append("frame", filename);
    // Serialize writes so the last selected frame also wins on the server.
    syncPromise = syncPromise.catch(function () {}).then(function () {
      if (revision !== selectionRevision) return;
      return fetch("api/setFrame.php", { method: "POST", body: form, credentials: "same-origin" })
      .then(function (response) {
        if (!response.ok) throw new Error("Frame request failed");
        return response.json();
      })
      .then(function (result) {
        if (!result || result.status !== "success") throw new Error("Frame could not be saved");
        if (revision !== selectionRevision) return;
        if (errorMessage) errorMessage.hidden = true;
      })
    });
    syncPromise.catch(function () {
        if (revision !== selectionRevision) return;
        if (errorMessage) {
          errorMessage.textContent = "Đã chọn khung để xem trước, nhưng chưa đồng bộ được với máy chủ. Hãy thử chọn lại khung.";
          errorMessage.hidden = false;
        }
      });
  }

  function makeFrameCard(filename) {
    var card = document.createElement("button");
    card.type = "button";
    card.className = "hmu-frame-card";
    card.dataset.frame = filename;
    card.setAttribute("aria-label", "Chọn khung " + filename.split("/").pop().slice(0, -4));

    var tag = document.createElement("span");
    tag.className = "hmu-frame-selected-tag";
    tag.innerHTML = '<i class="fa fa-check-circle" aria-hidden="true"></i> Đang chọn';
    card.appendChild(tag);

    var thumb = document.createElement("span");
    thumb.className = "hmu-frame-thumb";
    var image = document.createElement("img");
    image.src = new URL("api/framePreview.php?frame=" + encodeURIComponent(filename), document.baseURI).href;
    image.alt = "";
    image.loading = "lazy";
    image.decoding = "async";
    thumb.appendChild(image);
    card.appendChild(thumb);

    var meta = document.createElement("span");
    meta.className = "hmu-frame-meta";
    var description = document.createElement("span");
    description.className = "hmu-frame-description";
    description.textContent = "Khung ảnh HMU";
    meta.appendChild(description);
    var size = document.createElement("span");
    size.className = "hmu-frame-size";
    size.textContent = "PNG";
    meta.appendChild(size);
    card.appendChild(meta);

    var action = document.createElement("span");
    action.className = "hmu-frame-select";
    card.appendChild(action);

    setCardSelection(card, filename === window.hmuFrameSelection.filename);
    card.addEventListener("click", function () { selectFrame(filename, card); });
    return card;
  }

  selector.setAttribute("aria-busy", "true");
  listPromise = fetch("api/getFrames.php?grouped=1", { credentials: "same-origin" })
    .then(function (response) {
      if (!response.ok) throw new Error("Frame list request failed");
      return response.json();
    })
    .then(function (topics) {
      if (!Array.isArray(topics) || !topics.length) throw new Error("No frames available");

      var savedFrame = null;
      try { savedFrame = window.localStorage.getItem(storageKey); } catch (error) { /* Use the first configured frame. */ }
      var frames = topics.flatMap(function (topic) { return topic.frames; });
      if (savedFrame && !frames.includes(savedFrame)) {
        var basename = savedFrame.split("/").pop();
        var relocated = frames.filter(function (filename) { return filename.split("/").pop() === basename; });
        if (relocated.length === 1) savedFrame = relocated[0];
      }
      var savedTopic = topics.find(function (topic) { return topic.frames.includes(savedFrame); });
      var initialTopic = savedTopic || topics[0];
      var filters = topics.length > 1
        ? [{ label: "Tất cả khung", frames: frames }].concat(topics)
        : topics;
      var activeFilter;

      filters.forEach(function (topic) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "hmu-filter-chip";
        button.textContent = topic.label;
        button.setAttribute("aria-controls", selector.id);
        button.setAttribute("aria-pressed", "false");
        button.setAttribute("aria-label", topic.label + ", " + topic.frames.length + " khung");
        var count = document.createElement("b");
        count.textContent = topic.frames.length;
        count.setAttribute("aria-hidden", "true");
        button.appendChild(count);

        function showTopic() {
          if (activeFilter === button) return;
          if (activeFilter) activeFilter.setAttribute("aria-pressed", "false");
          activeFilter = button;
          button.setAttribute("aria-pressed", "true");
          selector.replaceChildren(...topic.frames.map(makeFrameCard));
          selector.scrollTop = 0;
        }
        button.addEventListener("click", showTopic);
        topicFilters.appendChild(button);
        if (topic === initialTopic) showTopic();
      });
      topicFilters.hidden = false;

      var initialFrame = savedTopic ? savedFrame : initialTopic.frames[0];
      var initialCard = Array.from(selector.children).find(function (card) {
        return card.dataset.frame === initialFrame;
      });
      selectFrame(initialFrame, initialCard);
    })
    .catch(function (error) {
      selector.innerHTML = "";
      topicFilters.hidden = true;
      if (errorMessage) errorMessage.hidden = false;
      throw error;
    })
    .finally(function () { selector.setAttribute("aria-busy", "false"); });
  listPromise.catch(function () {});

  var previewCaption = document.getElementById("hmuPreviewCaption") || document.querySelector(".hmu-preview-caption");
  if (previewCaption) {
    previewCaption.addEventListener("click", function (event) {
      var target = document.getElementById("hmu-templates") || document.querySelector(".hmu-templates");
      if (target) {
        event.preventDefault();
        target.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  }
})();
