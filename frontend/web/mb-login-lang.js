(function () {
  const STORAGE_KEY = "nectaAppLanguage";
  const FLAGS = {
    en: "images/flag-gb.svg?v=1",
    sw: "images/flag-tz.svg?v=4",
  };

  const AUTH_I18N = {
    en: {
      login_title: "Login to Mobile Banking",
      register_title: "Create Mobile Banking Account",
      forgot_title: "Forgot Mobile Banking Password",
      otp_title: "Mobile Banking Security Check",
      forgot_sub: "Enter your phone number and a new password. We will send an OTP to verify.",
      otp_sub: "Enter the OTP sent to your phone to reset your password.",
      otp_resend: "Did not receive the code?",
      otp_again: "Send again",
      user: "User",
      admin: "Admin",
      username: "Username",
      password: "Password",
      new_password: "New password",
      phone: "Phone number",
      full_name: "Full name",
      forgot_link: "FORGOT?",
      login_btn: "LOGIN",
      register_btn: "REGISTER",
      send_otp: "SEND OTP",
      confirm_otp: "CONFIRM OTP",
      pin_title: "Enter PIN",
      pin_hint: "Default admin PIN:",
      pin_login: "LOGIN WITH PIN",
      pin_cancel: "Cancel",
      temp_id: "Have a temporary User ID & Password?",
      register_here: "Register Here",
      have_account: "Already have an account?",
      login_here: "Login Here",
      remember_pw: "Remember your password?",
      back_login: "Back to Login",
      notify_title: "Notification",
      notify_body: "Stay safe, access wallet, BillPay control numbers & payouts with Getway.",
      show_password: "Show password",
      hide_password: "Hide password",
      show_pin: "Show PIN",
      hide_pin: "Hide PIN",
    },
    sw: {
      login_title: "Ingia kwenye Mobile Banking",
      register_title: "Fungua Akaunti ya Mobile Banking",
      forgot_title: "Umesahau Nenosiri la Mobile Banking",
      otp_title: "Uhakiki wa Usalama wa Mobile Banking",
      forgot_sub: "Weka namba ya simu na nenosiri jipya. Tutatuma OTP kwa uhakiki.",
      otp_sub: "Weka OTP iliyotumwa kwenye simu yako ili kubadilisha nenosiri.",
      otp_resend: "Hukupokea namba?",
      otp_again: "Tuma tena",
      user: "Mtumiaji",
      admin: "Msimamizi",
      username: "Jina la mtumiaji",
      password: "Nenosiri",
      new_password: "Nenosiri jipya",
      phone: "Namba ya simu",
      full_name: "Jina kamili",
      forgot_link: "SAHAU?",
      login_btn: "INGIA",
      register_btn: "JISAJILI",
      send_otp: "TUMA OTP",
      confirm_otp: "THIBITISHA OTP",
      pin_title: "Weka PIN",
      pin_hint: "PIN ya msimamizi (chaguo-msingi):",
      pin_login: "INGIA KWA PIN",
      pin_cancel: "Ghairi",
      temp_id: "Una User ID na Password ya muda?",
      register_here: "Jisajili Hapa",
      have_account: "Tayari una akaunti?",
      login_here: "Ingia Hapa",
      remember_pw: "Unakumbuka nenosiri?",
      back_login: "Rudi Kuingia",
      notify_title: "Taarifa",
      notify_body: "Kuwa salama, tumia wallet, namba za malipo na malipo ya Getway.",
      show_password: "Onyesha nenosiri",
      hide_password: "Ficha nenosiri",
      show_pin: "Onyesha PIN",
      hide_pin: "Ficha PIN",
    },
  };

  function currentLang() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY) || "en";
      return saved === "sw" ? "sw" : "en";
    } catch (_) {
      return "en";
    }
  }

  function applyLanguage(lang) {
    const code = lang === "sw" ? "sw" : "en";
    const dict = AUTH_I18N[code] || AUTH_I18N.en;
    document.documentElement.lang = code === "sw" ? "sw" : "en";

    document.querySelectorAll("[data-i18n]").forEach((node) => {
      const key = node.getAttribute("data-i18n") || "";
      if (!dict[key]) return;
      if (node.tagName === "INPUT" && node.hasAttribute("placeholder")) {
        node.placeholder = dict[key];
      } else {
        node.textContent = dict[key];
      }
    });

    const flagImg = document.querySelector("[data-mb-lang-flag]");
    if (flagImg) {
      flagImg.src = FLAGS[code];
      flagImg.alt = code === "sw" ? "Bendera ya Tanzania" : "Flag of the United Kingdom";
    }

    document.querySelectorAll("[data-mb-lang-option]").forEach((btn) => {
      const opt = btn.getAttribute("data-mb-lang-option");
      btn.classList.toggle("is-active", opt === code);
      btn.setAttribute("aria-selected", opt === code ? "true" : "false");
    });

    document.querySelectorAll("[data-password-toggle]").forEach((btn) => {
      const wrap = btn.closest(".mb-field--pass") || btn.closest(".auth-password-wrap");
      const input = wrap?.querySelector("input[type='password'], input[type='text']");
      const visible = input instanceof HTMLInputElement && input.type === "text";
      btn.setAttribute("aria-label", dict[visible ? "hide_password" : "show_password"] || "");
    });

    const pinToggle = document.querySelector("[data-pin-toggle]");
    if (pinToggle) {
      const pinInputs = document.querySelectorAll("#pin-digits input");
      const pinVisible = pinInputs[0] instanceof HTMLInputElement && pinInputs[0].type === "text";
      pinToggle.setAttribute("aria-label", dict[pinVisible ? "hide_pin" : "show_pin"] || "");
    }

    try {
      localStorage.setItem(STORAGE_KEY, code);
    } catch (_) {
      /* ignore */
    }
  }

  function initLangMenu() {
    const root = document.querySelector("[data-mb-lang]");
    const toggle = document.querySelector("[data-mb-lang-toggle]");
    const menu = document.querySelector("[data-mb-lang-menu]");
    if (!root || !toggle || !menu) return;

    function setOpen(open) {
      menu.hidden = !open;
      menu.classList.toggle("is-open", open);
      toggle.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }

    toggle.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      setOpen(menu.hidden);
    });

    menu.querySelectorAll("[data-mb-lang-option]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const lang = btn.getAttribute("data-mb-lang-option") === "sw" ? "sw" : "en";
        applyLanguage(lang);
        setOpen(false);
      });
    });

    document.addEventListener("click", (e) => {
      if (!(e.target instanceof Element)) return;
      if (e.target.closest("[data-mb-lang]")) return;
      setOpen(false);
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") setOpen(false);
    });

    applyLanguage(currentLang());
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initLangMenu);
  } else {
    initLangMenu();
  }
})();
