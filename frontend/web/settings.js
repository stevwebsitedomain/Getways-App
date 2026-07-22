(function () {
  const form = document.getElementById("profile-form");
  const msg = document.getElementById("profile-message");
  const fileInput = document.getElementById("profile-avatar-input");
  const preview = document.getElementById("profile-avatar-preview");
  const fallback = document.getElementById("profile-avatar-fallback");
  const topAvatarImg = document.querySelector(".w-phone-profile-btn .w-phone-profile-avatar:not(.w-phone-profile-avatar--fallback)");
  const topAvatarBtn = document.querySelector(".w-phone-profile-btn");
  let avatarData = preview?.getAttribute("src") || "";

  function setMessage(text, ok) {
    if (!msg) return;
    msg.textContent = text || "";
    msg.className = "form-message" + (ok ? " is-ok" : text ? " is-error" : "");
  }

  function showPhoto(dataUrl) {
    if (!preview || !fallback) return;
    const hasPhoto = Boolean(String(dataUrl || "").trim());
    if (hasPhoto) {
      preview.src = dataUrl;
      preview.classList.remove("is-hidden");
      fallback.classList.add("is-hidden");
      fallback.setAttribute("aria-hidden", "true");
    } else {
      preview.removeAttribute("src");
      preview.classList.add("is-hidden");
      fallback.classList.remove("is-hidden");
      fallback.setAttribute("aria-hidden", "false");
    }
    syncTopAvatar(dataUrl);
  }

  function syncTopAvatar(dataUrl) {
    if (!topAvatarBtn) return;
    const hasPhoto = Boolean(String(dataUrl || "").trim());
    let img = topAvatarBtn.querySelector("img.w-phone-profile-avatar");
    const iconFallback = topAvatarBtn.querySelector(".w-phone-profile-avatar--fallback");
    if (hasPhoto) {
      if (!img) {
        img = document.createElement("img");
        img.className = "w-phone-profile-avatar";
        img.alt = "";
        topAvatarBtn.insertBefore(img, topAvatarBtn.firstChild);
      }
      img.src = dataUrl;
      img.hidden = false;
      if (iconFallback) iconFallback.remove();
      return;
    }
    if (img) img.remove();
    if (!iconFallback) {
      const span = document.createElement("span");
      span.className = "w-phone-profile-avatar w-phone-profile-avatar--fallback";
      span.setAttribute("aria-hidden", "true");
      span.innerHTML = '<i class="fa-solid fa-user" aria-hidden="true"></i>';
      topAvatarBtn.insertBefore(span, topAvatarBtn.firstChild);
    }
  }

  fileInput?.addEventListener("change", () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    if (file.size > 500000) {
      setMessage("Image is too large. Use a photo under 500KB.", false);
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      avatarData = String(reader.result || "");
      showPhoto(avatarData);
    };
    reader.readAsDataURL(file);
  });

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const fullName = String(document.getElementById("profile-name")?.value || "").trim();
    try {
      const res = await fetch("auth-api.php?action=update-profile", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ fullName, avatar: avatarData }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not save profile.");
      setMessage("Profile saved.", true);
      if (window.Swal) {
        window.Swal.fire({ toast: true, position: "top-end", icon: "success", title: "Profile updated", timer: 2500, showConfirmButton: false });
      }
    } catch (error) {
      setMessage(error.message || "Save failed.", false);
    }
  });

  if (preview && !preview.classList.contains("is-hidden")) {
    avatarData = preview.getAttribute("src") || "";
  }
})();
