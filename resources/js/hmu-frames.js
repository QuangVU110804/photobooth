(function () {
  "use strict";

  var selector = document.getElementById("kioskFrameSelector");
  if (!selector) return;

  var selectedName = document.getElementById("hmuSelectedName");
  var frameCount = document.getElementById("hmuFrameCount");
  var heroFrame = document.getElementById("hmuHeroFrame");
  var errorMessage = document.getElementById("hmuFrameError");
  var storageKey = "hmu-photobooth-selected-frame";

  function frameTitle(filename) {
    var match = filename.match(/kiosk_frame_(\d+)/i);
    return match ? "Khung HMU " + match[1] : filename.replace(/\.png$/i, "").replace(/[_-]+/g, " ");
  }

  function frameUrl(filename) {
    return new URL("resources/img/frames/" + encodeURIComponent(filename), document.baseURI).href;
  }

  function selectFrame(filename, card, persist) {
    var url = frameUrl(filename);
    var title = frameTitle(filename);

    selector.querySelectorAll(".hmu-frame-card").forEach(function (item) {
      var isSelected = item === card;
      item.classList.toggle("is-selected", isSelected);
      item.setAttribute("aria-pressed", isSelected ? "true" : "false");
      var action = item.querySelector(".hmu-frame-select");
      if (action) {
        action.innerHTML = isSelected
          ? '<i class="fa fa-check" aria-hidden="true"></i><span>Đang chọn</span>'
          : "Chọn khung này";
      }
    });

    if (selectedName) selectedName.textContent = title;
    if (heroFrame) {
      heroFrame.src = url;
      heroFrame.alt = "Bản xem trước " + title;
    }

    document.documentElement.style.setProperty("--picture-frame", 'url("' + url + '")');
    document.documentElement.style.setProperty("--collage-frame", 'url("' + url + '")');
    document.querySelectorAll("#picture--frame, #collage--frame, #frame, .frameImage, #preview-frame, .live-preview-frame").forEach(function (element) {
      if (element.tagName.toLowerCase() === "img") element.src = url;
      else element.style.backgroundImage = 'url("' + url + '")';
    });

    if (!persist) return;

    try { window.localStorage.setItem(storageKey, filename); } catch (error) { /* Storage may be disabled in kiosk privacy mode. */ }

    var form = new FormData();
    form.append("frame", filename);
    fetch("api/setFrame.php", { method: "POST", body: form, credentials: "same-origin" })
      .then(function (response) {
        if (!response.ok) throw new Error("Frame request failed");
        return response.json();
      })
      .then(function (result) {
        if (!result || result.status !== "success") throw new Error("Frame could not be saved");
        if (errorMessage) errorMessage.hidden = true;
      })
      .catch(function () {
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
    card.setAttribute("aria-pressed", "false");

    var tag = document.createElement("span");
    tag.className = "hmu-frame-selected-tag";
    tag.innerHTML = '<i class="fa fa-check-circle" aria-hidden="true"></i> Đang chọn';
    card.appendChild(tag);

    var thumb = document.createElement("span");
    thumb.className = "hmu-frame-thumb";
    var image = document.createElement("img");
    image.src = frameUrl(filename);
    image.alt = "";
    image.loading = "lazy";
    image.decoding = "async";
    thumb.appendChild(image);
    card.appendChild(thumb);

    var meta = document.createElement("span");
    meta.className = "hmu-frame-meta";
    var info = document.createElement("span");
    var name = document.createElement("span");
    name.className = "hmu-frame-name";
    name.textContent = frameTitle(filename);
    var description = document.createElement("span");
    description.className = "hmu-frame-description";
    description.textContent = "Khung ảnh HMU";
    info.appendChild(name);
    info.appendChild(description);
    meta.appendChild(info);
    var size = document.createElement("span");
    size.className = "hmu-frame-size";
    size.textContent = "PNG";
    meta.appendChild(size);
    card.appendChild(meta);

    var action = document.createElement("span");
    action.className = "hmu-frame-select";
    action.textContent = "Chọn khung này";
    card.appendChild(action);

    card.addEventListener("click", function () { selectFrame(filename, card, true); });
    return card;
  }

  selector.setAttribute("aria-busy", "true");
  fetch("api/getFrames.php", { credentials: "same-origin" })
    .then(function (response) {
      if (!response.ok) throw new Error("Frame list request failed");
      return response.json();
    })
    .then(function (frames) {
      if (!Array.isArray(frames) || !frames.length) throw new Error("No frames available");

      frames.forEach(function (filename) { selector.appendChild(makeFrameCard(filename)); });
      if (frameCount) frameCount.textContent = String(frames.length);

      var savedFrame = null;
      try { savedFrame = window.localStorage.getItem(storageKey); } catch (error) { /* Use the first configured frame. */ }
      var initialFrame = frames.indexOf(savedFrame) !== -1 ? savedFrame : frames[0];
      var initialCard = Array.prototype.find.call(selector.querySelectorAll(".hmu-frame-card"), function (card) {
        return card.dataset.frame === initialFrame;
      });
      selectFrame(initialFrame, initialCard, true);
    })
    .catch(function () {
      selector.innerHTML = "";
      if (errorMessage) errorMessage.hidden = false;
    })
    .finally(function () { selector.setAttribute("aria-busy", "false"); });

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
