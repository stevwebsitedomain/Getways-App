function formatControlNumberAmount(value) {
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(value || 0));
}

function escapeControlNumberHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function bindControlNumberForm() {
  const form = document.getElementById("control-number-form");
  if (!form) return;

  const messageEl = document.getElementById("control-number-message");
  const resultEl = document.getElementById("control-number-result");
  const submitBtn = form.querySelector('button[type="submit"]');
  const apiBase = window.CLICKPESA_API_BASE || `${window.location.origin}/Getways-App/frontend/web/api/clickpesa`;
  const primaryUrl = `${apiBase}/control-number`;
  const fallbackUrl = `${window.location.origin}/api/clickpesa/control-number`;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (submitBtn) submitBtn.disabled = true;
    if (messageEl) {
      messageEl.textContent = "Creating control number...";
      messageEl.className = "form-message";
    }
    if (resultEl) {
      resultEl.hidden = true;
      resultEl.innerHTML = "";
    }

    try {
      const payload = Object.fromEntries(new FormData(form).entries());
      let response = await fetch(primaryUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "ngrok-skip-browser-warning": "true",
        },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      });

      if (!response.ok && response.status === 404) {
        response = await fetch(fallbackUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "ngrok-skip-browser-warning": "true",
          },
          credentials: "same-origin",
          body: JSON.stringify(payload),
        });
      }

      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success === false) {
        throw new Error(data.message || "Could not create control number.");
      }

      if (messageEl) {
        messageEl.textContent = "Control number created successfully.";
        messageEl.className = "form-message w-cn-msg is-ok";
      }

      if (resultEl) {
        resultEl.hidden = false;
        resultEl.innerHTML = `
          <p><strong>Control number:</strong> <code>${escapeControlNumberHtml(data.controlNumber || "")}</code></p>
          <p><strong>Reference:</strong> ${escapeControlNumberHtml(data.reference || data.orderReference || payload.order_id || "")}</p>
          <p><strong>Amount:</strong> TZS ${formatControlNumberAmount(data.amount || payload.amount)}</p>
          <p><strong>Status:</strong> ${escapeControlNumberHtml(data.status || "PENDING")}</p>
          <button type="button" class="w-cn-copy" data-copy-control-number="${escapeControlNumberHtml(data.controlNumber || "")}">Copy Control Number</button>
        `;

        resultEl.querySelector("[data-copy-control-number]")?.addEventListener("click", async (copyEvent) => {
          const button = copyEvent.currentTarget;
          const value = button.getAttribute("data-copy-control-number") || "";
          try {
            await navigator.clipboard?.writeText(value);
            button.textContent = "Copied";
          } catch (_) {
            button.textContent = "Copy failed";
          }
        });
      }
    } catch (error) {
      if (messageEl) {
        messageEl.textContent = error.message || "Failed to create control number.";
        messageEl.className = "form-message w-cn-msg is-err";
      }
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

bindControlNumberForm();
