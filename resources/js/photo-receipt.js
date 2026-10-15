"use strict";

(() => {
  const dialog = document.getElementById("photo-dialog");
  if (dialog && typeof dialog.showModal === "function") {
    for (const id of ["view-photo", "photo-preview-link"]) {
      document.getElementById(id)?.addEventListener("click", (event) => {
        event.preventDefault();
        dialog.showModal();
      });
    }
    dialog.addEventListener("click", (event) => {
      const rect = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) {
        dialog.close();
      }
    });
  }

  const shareButton = document.getElementById("share-photo");
  const status = document.getElementById("receipt-status");
  if (!shareButton || !status || (!navigator.share && !navigator.clipboard?.writeText)) return;
  shareButton.hidden = false;
  shareButton.addEventListener("click", async () => {
    // Sharing always points back to this photo's receipt, never to a download action.
    const url = new URL(location.href);
    url.searchParams.delete("download");
    url.hash = "";
    shareButton.disabled = true;
    status.textContent = "";
    try {
      if (navigator.share) {
        await navigator.share({ title: "Ảnh của bạn · HMU Photobooth", url: url.href });
        status.textContent = "Đã chia sẻ liên kết ảnh.";
      } else {
        await navigator.clipboard.writeText(url.href);
        status.textContent = "Đã sao chép liên kết ảnh. Bạn có thể gửi cho bạn bè.";
      }
    } catch (error) {
      if (error.name !== "AbortError") {
        status.textContent = "Chưa thể chia sẻ. Bạn có thể sao chép liên kết từ thanh địa chỉ.";
      }
    } finally {
      shareButton.disabled = false;
    }
  });
})();
