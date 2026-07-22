(function () {
  const form = document.getElementById("profile-form");
  const msg = document.getElementById("profile-message");
  const fileInput = document.getElementById("profile-avatar-input");
  const preview = document.getElementById("profile-avatar-preview");
  const fallback = document.getElementById("profile-avatar-fallback");
  let avatarData = preview?.getAttribute("src") || "";

  function setMessage(text, ok) {
    if (!msg) return;
    msg.textContent = text || "";
    msg.className = "form-message" + (ok ? " is-ok" : text ? " is-error" : "");
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
      if (preview) {
        preview.src = avatarData;
        preview.hidden = false;
      }
      if (fallback) fallback.hidden = true;
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
})();
