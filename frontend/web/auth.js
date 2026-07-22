(function () {
  const API = "auth-api.php";

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  async function api(action, payload) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(payload || {}),
    });
    const raw = await res.text();
    let data = {};
    try {
      data = raw ? JSON.parse(raw) : {};
    } catch (_error) {
      throw new Error(
        res.ok
          ? "Server returned an invalid response."
          : "Server error. Try again or upload the latest auth files to hosting."
      );
    }
    if (!res.ok || !data.ok) {
      throw new Error(data.message || "Request failed.");
    }
    return data;
  }

  function setMessage(el, message, ok) {
    if (!el) return;
    el.textContent = message || "";
    el.classList.toggle("is-error", !ok && !!message);
    el.classList.toggle("is-ok", !!ok && !!message);
  }

  function bindLogin() {
    const form = $("#login-form");
    if (!form) return;
    const alert = $("#auth-message");
    const googleBtn = $("#google-login-fallback");
    const roleInput = $("#login-role");
    const pinPanel = $("#pin-panel");
    const pinOpen = $("#pin-open-btn");
    const pinCancel = $("#pin-cancel-btn");
    const pinSubmit = $("#pin-login-btn");
    const pinDigits = Array.from(document.querySelectorAll("#pin-digits input"));

    function resolveRedirect(defaultPath) {
      const next = String(window.GETWAY_NEXT || "").trim();
      if (next && /^[a-zA-Z0-9._/?=&-]+$/.test(next) && !next.startsWith("http")) {
        return decodeURIComponent(next);
      }
      return defaultPath || "part-two.php";
    }

    document.querySelectorAll("[data-login-mode]").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll("[data-login-mode]").forEach((b) => {
          b.classList.toggle("is-active", b === btn);
          b.setAttribute("aria-selected", b === btn ? "true" : "false");
        });
        if (roleInput) roleInput.value = btn.getAttribute("data-login-mode") || "user";
        if (btn.getAttribute("data-login-mode") === "admin" && $("#username")) {
          $("#username").placeholder = "admin";
          if (!$("#username").value) $("#username").value = "admin";
          const pass = $("#password");
          if (pass) pass.placeholder = "Password (admin: 0000)";
        } else if ($("#username")) {
          $("#username").placeholder = "Phone number or full name";
          const pass = $("#password");
          if (pass) pass.placeholder = "Your registered password";
        }
      });
    });

    function readPin() {
      return pinDigits.map((el) => String(el.value || "").replace(/\D/g, "")).join("");
    }

    pinDigits.forEach((field, index) => {
      field.addEventListener("input", () => {
        field.value = String(field.value || "").replace(/\D/g, "").slice(0, 1);
        if (field.value && pinDigits[index + 1]) pinDigits[index + 1].focus();
        if (readPin().length === 4) pinSubmit?.click();
      });
      field.addEventListener("keydown", (event) => {
        if (event.key === "Backspace" && !field.value && pinDigits[index - 1]) {
          pinDigits[index - 1].focus();
        }
      });
    });

    pinOpen?.addEventListener("click", () => {
      if (pinPanel) {
        pinPanel.hidden = false;
        form.hidden = true;
        pinDigits[0]?.focus();
      }
    });

    pinCancel?.addEventListener("click", () => {
      if (pinPanel) {
        pinPanel.hidden = true;
        form.hidden = false;
        pinDigits.forEach((d) => { d.value = ""; });
      }
    });

    pinSubmit?.addEventListener("click", async () => {
      setMessage(alert, "", false);
      const pin = readPin();
      if (pin.length !== 4) {
        setMessage(alert, "Enter a 4-digit PIN.", false);
        return;
      }
      try {
        const out = await api("pin-login", {
          pin,
          role: roleInput?.value || "admin",
        });
        window.location.href = resolveRedirect(out.redirect);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setMessage(alert, "", false);
      const username = String($("#username", form)?.value || "").trim();
      const password = String($("#password", form)?.value || "").trim();
      let role = String(roleInput?.value || "user");
      if (password === "0000") {
        role = "admin";
        if (roleInput) roleInput.value = "admin";
        document.querySelectorAll("[data-login-mode]").forEach((btn) => {
          const isAdmin = btn.getAttribute("data-login-mode") === "admin";
          btn.classList.toggle("is-active", isAdmin);
          btn.setAttribute("aria-selected", isAdmin ? "true" : "false");
        });
      }
      try {
        const out = await api("login", { username, password, role });
        window.location.href = resolveRedirect(out.redirect);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });

    if (googleBtn) {
      googleBtn.addEventListener("click", async () => {
        const emailRaw = window.prompt("Enter your Google email to continue:");
        const email = String(emailRaw || "").trim().toLowerCase();
        if (!email) return;
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        if (!emailOk) {
          setMessage(alert, "Please enter a valid email address.", false);
          return;
        }
        const guessedName = email.split("@")[0].replace(/[._-]+/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
        try {
          const out = await api("google-login", { email, fullName: guessedName || "Google User" });
          window.location.href = resolveRedirect(out.redirect);
        } catch (error) {
          setMessage(alert, error.message, false);
        }
      });
    }
  }

  function updatePasswordToggleButton(btn, visible) {
    btn.setAttribute("aria-pressed", visible ? "true" : "false");
    btn.setAttribute("aria-label", visible ? "Hide password" : "Show password");
    btn.innerHTML = visible
      ? '<i class="fa-regular fa-eye-slash"></i>'
      : '<i class="fa-regular fa-eye"></i>';
  }

  function bindPasswordToggles() {
    document.querySelectorAll("[data-password-toggle]").forEach((btn) => {
      btn.addEventListener("mousedown", (event) => {
        event.preventDefault();
      });
      btn.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        const wrap = btn.closest(".auth-password-wrap") || btn.closest(".mb-field--pass");
        const input = wrap?.querySelector("input[type='password'], input[type='text']");
        if (!(input instanceof HTMLInputElement)) return;
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        updatePasswordToggleButton(btn, show);
        input.focus({ preventScroll: true });
        const len = input.value.length;
        if (typeof input.setSelectionRange === "function") {
          input.setSelectionRange(len, len);
        }
      });
    });
  }

  function bindPinToggle() {
    const btn = document.querySelector("[data-pin-toggle]");
    const row = document.getElementById("pin-digits");
    if (!btn || !row) return;
    const inputs = Array.from(row.querySelectorAll("input"));

    function setVisible(visible) {
      inputs.forEach((input) => {
        input.type = visible ? "text" : "password";
      });
      btn.setAttribute("aria-pressed", visible ? "true" : "false");
      btn.setAttribute("aria-label", visible ? "Hide PIN" : "Show PIN");
      btn.innerHTML = visible
        ? '<i class="fa-regular fa-eye-slash"></i>'
        : '<i class="fa-regular fa-eye"></i>';
    }

    btn.addEventListener("mousedown", (event) => {
      event.preventDefault();
    });
    btn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      const show = inputs[0]?.type === "password";
      setVisible(show);
    });
  }

  function bindRegister() {
    const form = $("#register-form");
    if (!form) return;
    const alert = $("#auth-message");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setMessage(alert, "", false);
      const fullName = String($("#fullName", form)?.value || "").trim();
      const phone = String($("#phone", form)?.value || "").trim();
      const password = String($("#password", form)?.value || "").trim();
      try {
        const out = await api("register-start", { fullName, phone, password });
        window.location.href = out.redirect || "lets-go.php";
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });
  }

  function bindForgot() {
    const form = $("#forgot-form");
    if (!form) return;
    const alert = $("#auth-message");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setMessage(alert, "", false);
      const phone = String($("#phone", form)?.value || "").trim();
      const newPassword = String($("#newPassword", form)?.value || "").trim();
      try {
        await api("forgot-start", { phone, newPassword });
        window.location.href = "otp-verify.php?flow=reset";
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });
  }

  function bindOtp() {
    const form = $("#otp-form");
    if (!form) return;
    const alert = $("#auth-message");
    const flowInput = $("#flow", form);
    const otpField = $("#otp", form);
    const digits = Array.from(form.querySelectorAll(".otp-row input, .mb-otp-row input"));
    const phoneHint = $("#otp-phone-hint");
    const flow = String(new URLSearchParams(window.location.search).get("flow") || "register").toLowerCase();
    if (flowInput) flowInput.value = flow;

    function syncOtp() {
      if (!otpField) return;
      if (digits.length) {
        otpField.value = digits.map((d) => String(d.value || "").replace(/\D/g, "")).join("");
      }
    }

    if (digits.length) {
      digits.forEach((field, index) => {
        field.addEventListener("input", () => {
          field.value = String(field.value || "").replace(/\D/g, "").slice(0, 1);
          syncOtp();
          if (field.value && digits[index + 1]) digits[index + 1].focus();
        });
        field.addEventListener("keydown", (event) => {
          if (event.key === "Backspace" && !field.value && digits[index - 1]) {
            digits[index - 1].focus();
          }
        });
      });
    }

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setMessage(alert, "", false);
      syncOtp();
      try {
        const out = await api("verify-otp", {
          flow,
          otp: String(otpField?.value || "").trim(),
        });
        setMessage(alert, out.message || "OTP verified.", true);
        window.setTimeout(() => {
          window.location.href = out.redirect || "part-two.php";
        }, 450);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });

    fetch(`auth-api.php?action=pending&flow=${encodeURIComponent(flow)}`, { cache: "no-store" })
      .then((res) => res.json())
      .then((data) => {
        if (!data || !data.ok) return;
        if (phoneHint) {
          if (flow === "login") {
            phoneHint.textContent = `Confirm password for ${data.phoneMasked || "your account"}`;
          } else {
            phoneHint.textContent = `Code sent to ${data.phoneMasked || "your phone"}`;
          }
        }
        if (flow === "login") return;
        // Demo: auto-fill OTP as if SMS was auto-read.
        const code = String(data.debugOtp || "");
        if (!/^\d{6}$/.test(code)) return;
        window.setTimeout(() => {
          digits.forEach((el, i) => {
            el.value = code[i] || "";
          });
          syncOtp();
        }, 900);
      })
      .catch(() => {});
  }

  function decodeJwtPayload(token) {
    const parts = String(token || "").split(".");
    if (parts.length < 2) return null;
    try {
      const b64 = parts[1].replace(/-/g, "+").replace(/_/g, "/");
      const json = decodeURIComponent(
        atob(b64)
          .split("")
          .map((c) => `%${(`00${c.charCodeAt(0).toString(16)}`).slice(-2)}`)
          .join("")
      );
      return JSON.parse(json);
    } catch (_) {
      return null;
    }
  }

  function bindGoogleGis() {
    const target = document.getElementById("google-gis");
    const fallback = document.getElementById("google-login-fallback");
    if (!target) return;
    const clientId = window.GETWAY_GOOGLE_CLIENT_ID || "";
    if (!clientId || !window.google || !window.google.accounts || !window.google.accounts.id) {
      return;
    }
    target.classList.remove("auth-hidden");
    if (fallback) fallback.classList.add("auth-hidden");
    window.google.accounts.id.initialize({
      client_id: clientId,
      callback: async (response) => {
        const payload = decodeJwtPayload(response.credential);
        const email = String(payload?.email || "").trim();
        const name = String(payload?.name || "Google User").trim();
        const avatar = String(payload?.picture || "").trim();
        if (!email) return;
        try {
          const out = await api("google-login", { email, fullName: name, avatar });
          const next = String(window.GETWAY_NEXT || "").trim();
          if (next && /^[a-zA-Z0-9._/?=&-]+$/.test(next) && !next.startsWith("http")) {
            window.location.href = decodeURIComponent(next);
          } else {
            window.location.href = out.redirect || "part-two.php";
          }
        } catch (error) {
          const alert = document.getElementById("auth-message");
          setMessage(alert, error.message, false);
        }
      },
    });
    window.google.accounts.id.renderButton(target, {
      theme: "outline",
      size: "large",
      width: 260,
      text: "continue_with",
    });
  }

  bindLogin();
  bindRegister();
  bindForgot();
  bindOtp();
  bindPasswordToggles();
  bindPinToggle();
  window.setTimeout(bindGoogleGis, 50);
})();
