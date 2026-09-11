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

  function resolveRedirect(defaultPath, role) {
    const normalizedRole = String(role || "").toLowerCase();
    if (normalizedRole === "admin") {
      return "admin-dashboard.php";
    }
    const next = String(window.GETWAY_NEXT || "").trim();
    if (next && /^[a-zA-Z0-9._/?=&-]+$/.test(next) && !next.startsWith("http")) {
      const decoded = decodeURIComponent(next);
      if (!/admin-dashboard/i.test(decoded)) {
        return decoded;
      }
    }
    return defaultPath || "part-two.php";
  }

  async function completeGoogleLogin(credential) {
    const alert = $("#auth-message");
    const out = await api("google-login", { credential: String(credential || "").trim() });
    window.location.href = resolveRedirect(out.redirect, out.role);
    return out;
  }

  function bindLogin() {
    const form = $("#login-form");
    if (!form) return;
    const alert = $("#auth-message");
    const googleSection = $("#google-auth-section");
    const roleInput = $("#login-role");
    const pinPanel = $("#pin-panel");
    const pinOpen = $("#pin-open-btn");
    const pinCancel = $("#pin-cancel-btn");
    const pinSubmit = $("#pin-login-btn");
    const pinDigits = Array.from(document.querySelectorAll("#pin-digits input"));

    document.querySelectorAll("[data-login-mode]").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll("[data-login-mode]").forEach((b) => {
          b.classList.toggle("is-active", b === btn);
          b.setAttribute("aria-selected", b === btn ? "true" : "false");
        });
        const mode = btn.getAttribute("data-login-mode") || "user";
        if (roleInput) roleInput.value = mode;
        document.body.classList.toggle("is-admin-mode", mode === "admin");
        const userInput = $("#username");
        const pass = $("#password");
        if (mode === "admin") {
          if (userInput) {
            userInput.placeholder = "admin";
            userInput.setAttribute("data-i18n-placeholder", "username_ph_admin");
            if (!userInput.value || userInput.dataset.autofilled === "1") {
              userInput.value = "admin";
              userInput.dataset.autofilled = "1";
            }
          }
          if (pass) {
            pass.placeholder = "Password";
            pass.setAttribute("data-i18n-placeholder", "password_ph_admin");
          }
        } else {
          if (userInput) {
            userInput.placeholder = "Phone number or full name";
            userInput.setAttribute("data-i18n-placeholder", "username_ph_user");
            if (userInput.dataset.autofilled === "1") {
              userInput.value = "";
              delete userInput.dataset.autofilled;
            }
          }
          if (pass) {
            pass.placeholder = "Your registered password";
            pass.setAttribute("data-i18n-placeholder", "password_ph_user");
          }
        }
      });
    });
    if (roleInput?.value === "admin") {
      document.body.classList.add("is-admin-mode");
      const userInput = $("#username");
      if (userInput && userInput.value === "admin") userInput.dataset.autofilled = "1";
    }

    function readPin() {
      return pinDigits.map((el) => String(el.value || "").replace(/\D/g, "")).join("");
    }

    pinDigits.forEach((field, index) => {
      field.addEventListener("input", () => {
        field.value = String(field.value || "").replace(/\D/g, "").slice(0, 1);
        if (field.value && pinDigits[index + 1]) pinDigits[index + 1].focus();
        if (readPin().length === 6) pinSubmit?.click();
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
        if (googleSection) googleSection.hidden = true;
        pinDigits[0]?.focus();
      }
    });

    pinCancel?.addEventListener("click", () => {
      if (pinPanel) {
        pinPanel.hidden = true;
        form.hidden = false;
        if (googleSection) googleSection.hidden = false;
        pinDigits.forEach((d) => { d.value = ""; });
      }
    });

    pinSubmit?.addEventListener("click", async () => {
      setMessage(alert, "", false);
      const pin = readPin();
      if (pin.length !== 6) {
        setMessage(alert, "Enter the 6-digit admin PIN.", false);
        return;
      }
      try {
        const out = await api("pin-login", {
          pin,
          role: roleInput?.value || "admin",
        });
        window.location.href = resolveRedirect(out.redirect, out.role);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setMessage(alert, "", false);
      const username = String($("#username", form)?.value || "").trim();
      const password = String($("#password", form)?.value || "").trim();
      const role = String(roleInput?.value || "user") === "admin" ? "admin" : "user";
      try {
        const out = await api("login", { username, password, role });
        window.location.href = resolveRedirect(out.redirect, out.role);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    });
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
        const wrap = btn.closest(".auth-password-wrap") || btn.closest(".mb-field--pass") || btn.closest(".mb-input-box--pass");
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

  function bindGoogleGis() {
    const target = document.getElementById("google-gis");
    const fallback = document.getElementById("google-login-fallback");
    const box = document.getElementById("google-login-box");
    if (!target && !fallback) return;

    const alert = document.getElementById("auth-message");
    const clientId = String(window.GETWAY_GOOGLE_CLIENT_ID || "").trim();
    let ready = false;

    async function onCredential(response) {
      const credential = String(response?.credential || "").trim();
      if (!credential) {
        setMessage(alert, "Google did not return a sign-in token. Try again.", false);
        return;
      }
      try {
        await completeGoogleLogin(credential);
      } catch (error) {
        setMessage(alert, error.message, false);
      }
    }

    function renderOfficialButton() {
      if (!clientId || !window.google?.accounts?.id || ready) return false;
      ready = true;
      target.hidden = false;
      window.google.accounts.id.initialize({
        client_id: clientId,
        auto_select: false,
        cancel_on_tap_outside: true,
        itp_support: true,
        use_fedcm_for_prompt: true,
        callback: onCredential,
      });
      const width = Math.max(
        240,
        Math.min(360, Math.floor((box?.clientWidth || target.clientWidth || 320)))
      );
      window.google.accounts.id.renderButton(target, {
        type: "standard",
        theme: "outline",
        size: "large",
        text: "continue_with",
        shape: "pill",
        logo_alignment: "left",
        width,
      });
      if (box) box.classList.add("is-gis-ready");
      return true;
    }

    fallback?.addEventListener("click", () => {
      if (!clientId) {
        setMessage(
          alert,
          "Google Sign-In is not configured. Add GOOGLE_CLIENT_ID in .env.",
          false
        );
        return;
      }
      if (window.google?.accounts?.id) {
        if (!ready) renderOfficialButton();
        window.google.accounts.id.prompt();
        return;
      }
      setMessage(alert, "Google Sign-In is still loading. Please try again.", false);
    });

    if (!clientId) return;

    let tries = 0;
    const timer = window.setInterval(() => {
      tries += 1;
      if (renderOfficialButton() || tries >= 50) {
        window.clearInterval(timer);
      }
    }, 100);
  }

  bindLogin();
  bindRegister();
  bindForgot();
  bindOtp();
  bindPasswordToggles();
  bindPinToggle();
  bindGoogleGis();
})();
