(function () {
  const LAYOUT_STORAGE_KEY = "nectaWalletLayout";
  const SERVER_STATUS_STORAGE_KEY = "nectaServerStatus";
  const LANGUAGE_STORAGE_KEY = "nectaAppLanguage";
  const SWEETALERT_CDN = "https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js";
  const API_BASE = window.TIS_API_BASE || `${window.location.origin}/api/tis`;
  const API_HEADERS = { "ngrok-skip-browser-warning": "true" };
  let swalLoaderPromise = null;
  let monitorTimer = null;
  let serverStatus = "unknown";
  let offlineModalOpen = false;
  let styleInjected = false;
  const I18N_DICTIONARY = {
    en: {
      welcome: "Welcome",
      home_dashboard: "Home Dashboard",
      total_balance: "Total Balance",
      income: "Income",
      expense: "Expense",
      send: "Send",
      request: "Request",
      your_wallet: "Your wallet",
      connected_ready: "Connected and ready",
      add_float: "Add Float",
      autopay: "AutoPay",
      send_friend: "Send to Friend",
      transfer: "Transfer",
      spent: "Spent",
      top_categories: "Top Categories",
      status: "Status",
      analytics: "Analytics",
      last_29_days: "Last 29 days",
      top_up: "Top up",
      payments: "Payments",
      transaction_trend: "Transaction trend",
      last_14_days: "Last 14 days · count of payments & new checkouts",
      recent_transactions: "Recent transactions",
      see_all: "See all",
      home: "Home",
      history: "History",
      pay: "Pay",
      search: "Search",
      more: "More",
    },
    sw: {
      welcome: "Karibu",
      home_dashboard: "Dashibodi Kuu",
      total_balance: "Jumla ya Salio",
      income: "Mapato",
      expense: "Matumizi",
      send: "Tuma",
      request: "Omba",
      your_wallet: "Wallet yako",
      connected_ready: "Imeunganishwa tayari",
      add_float: "Ongeza Salio",
      autopay: "Lipa Oto",
      send_friend: "Tuma kwa Rafiki",
      transfer: "Hamisha",
      spent: "Matumizi",
      top_categories: "Kategoria Kuu",
      status: "Hali",
      analytics: "Takwimu",
      last_29_days: "Siku 29 zilizopita",
      top_up: "Weka Salio",
      payments: "Malipo",
      transaction_trend: "Mwenendo wa miamala",
      last_14_days: "Siku 14 zilizopita · idadi ya malipo na checkouts mpya",
      recent_transactions: "Miamala ya karibuni",
      see_all: "Ona zote",
      home: "Nyumbani",
      history: "Historia",
      pay: "Lipa",
      search: "Tafuta",
      more: "Zaidi",
    },
  };

  function applyLayout(mode) {
    const desktop = mode === "desktop";
    document.body.classList.toggle("layout-desktop", desktop);
    document.body.classList.toggle("layout-phone", !desktop);
    document.querySelectorAll("button[data-layout]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.layout === mode);
    });
    try {
      localStorage.setItem(LAYOUT_STORAGE_KEY, mode);
    } catch (_) {
      /* ignore */
    }
    try {
      document.dispatchEvent(
        new CustomEvent("necta-layout-changed", { detail: { mode } })
      );
    } catch (_) {
      /* ignore */
    }
  }

  function initWalletActionCardAnimations() {
    const selector = ".w-phone-shortcut-card, .w-quick-card";
    const motionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
    let replayTimer = null;

    function prefersReducedMotion() {
      return motionQuery.matches;
    }

    function getCards() {
      return Array.from(document.querySelectorAll(selector));
    }

    function resetCards(cards) {
      cards.forEach((card) => {
        card.classList.remove(
          "w-card-anim-pending",
          "w-card-anim-in",
          "w-card-anim-tap",
          "w-card-anim-hover",
          "is-ico-float"
        );
        card.style.removeProperty("--w-card-i");
      });
    }

    function playEntrance(cards) {
      if (!cards.length) return;
      resetCards(cards);
      if (prefersReducedMotion()) {
        cards.forEach((card) => card.classList.add("w-card-anim-in"));
        return;
      }

      cards.forEach((card, index) => {
        card.classList.add("w-card-anim-pending");
        card.style.setProperty("--w-card-i", String(index));
      });

      window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
          cards.forEach((card) => {
            card.classList.remove("w-card-anim-pending");
            card.classList.add("w-card-anim-in");
            const onEnd = (event) => {
              if (event.animationName !== "wActionCardIn") return;
              card.removeEventListener("animationend", onEnd);
              card.classList.add("is-ico-float");
            };
            card.addEventListener("animationend", onEnd);
          });
        });
      });
    }

    function bindInteractions(cards) {
      cards.forEach((card) => {
        if (card.dataset.animBound === "1") return;
        card.dataset.animBound = "1";

        card.addEventListener("click", () => {
          if (prefersReducedMotion()) return;
          card.classList.remove("w-card-anim-tap");
          void card.offsetWidth;
          card.classList.add("w-card-anim-tap");
        });

        card.addEventListener("mouseenter", () => {
          if (prefersReducedMotion()) return;
          card.classList.add("w-card-anim-hover");
        });

        card.addEventListener("mouseleave", () => {
          card.classList.remove("w-card-anim-hover");
        });

        card.addEventListener("focusin", () => {
          if (prefersReducedMotion()) return;
          card.classList.add("w-card-anim-hover");
        });

        card.addEventListener("focusout", () => {
          card.classList.remove("w-card-anim-hover");
        });
      });
    }

    function run() {
      const cards = getCards();
      bindInteractions(cards);
      playEntrance(cards);
    }

    function scheduleReplay(delayMs) {
      if (replayTimer) {
        window.clearTimeout(replayTimer);
      }
      replayTimer = window.setTimeout(run, delayMs);
    }

    run();
    document.addEventListener("necta-layout-changed", () => scheduleReplay(90));
    document.addEventListener("necta-wallet-cards-replay", () => scheduleReplay(40));

    if (typeof motionQuery.addEventListener === "function") {
      motionQuery.addEventListener("change", () => scheduleReplay(40));
    } else if (typeof motionQuery.addListener === "function") {
      motionQuery.addListener(() => scheduleReplay(40));
    }
  }

  function initLayoutButtons() {
    let saved = "phone";
    try {
      saved = localStorage.getItem(LAYOUT_STORAGE_KEY) || "phone";
    } catch (_) {
      /* ignore */
    }
    if (saved !== "desktop" && saved !== "phone") {
      saved = "phone";
    }
    applyLayout(saved);
    document.querySelectorAll("button[data-layout]").forEach((btn) => {
      btn.addEventListener("click", () => applyLayout(btn.dataset.layout));
    });
  }

  function initWalletDate() {
    const el = document.getElementById("wallet-date");
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleDateString("en-GB", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  }

  /** Filter sections marked .w-searchable inside .w-app by plain text match. */
  function initWalletGlobalSearch() {
    const input = document.getElementById("wallet-global-search");
    if (!input) return;
    const root = document.querySelector(".w-app");
    if (!root) return;

    function applyFilter() {
      const q = input.value.trim().toLowerCase();
      const sections = root.querySelectorAll(".w-searchable");
      if (!sections.length) return;
      sections.forEach((section) => {
        const hay = (section.textContent || "")
          .toLowerCase()
          .replace(/\s+/g, " ")
          .trim();
        const show = !q || hay.includes(q);
        section.classList.toggle("w-searchable--hidden", !show);
      });
    }

    input.addEventListener("input", applyFilter);
    document.addEventListener("necta-wallet-updated", applyFilter);
  }

  let pageSearchApi = null;

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function initBottomSearchToggle() {
    const trigger = document.querySelector("[data-search-toggle]");
    const root = document.querySelector(".w-app");
    if (!trigger || !root) return;

    const searchable = Array.from(root.querySelectorAll(".w-searchable"));
    if (!searchable.length) return;

    const overlay = document.createElement("div");
    overlay.className = "w-search-overlay";
    overlay.innerHTML = `
      <div class="w-search-overlay-top">
        <button type="button" class="w-search-overlay-close" aria-label="Close search">
          <i class="fa-solid fa-arrow-left"></i>
        </button>
        <input class="w-search-overlay-input" type="search" placeholder="Search on this page..." autocomplete="off" />
      </div>
      <ul class="w-search-overlay-list"></ul>
    `;
    document.body.appendChild(overlay);

    const closeBtn = overlay.querySelector(".w-search-overlay-close");
    const input = overlay.querySelector(".w-search-overlay-input");
    const list = overlay.querySelector(".w-search-overlay-list");

    function sectionTitle(section) {
      const pick = section.querySelector("h1, h2, h3, .w-quick-title, .rpc-label");
      return (pick?.textContent || "Result").replace(/\s+/g, " ").trim();
    }

    function sectionText(section) {
      return (section.textContent || "").replace(/\s+/g, " ").trim();
    }

    const index = searchable.map((section) => ({
      section,
      title: sectionTitle(section),
      hay: sectionText(section).toLowerCase(),
    }));

    function isPhoneLayout() {
      return document.body.classList.contains("layout-phone");
    }

    function setOpen(open) {
      overlay.classList.toggle("is-open", open);
      trigger.classList.toggle("is-active", open);
      if (open) {
        input.focus();
      } else {
        input.value = "";
        list.innerHTML = "";
      }
    }

    function renderResults() {
      const q = input.value.trim().toLowerCase();
      const matched = q ? index.filter((item) => item.hay.includes(q)).slice(0, 20) : [];
      if (!q) {
        list.innerHTML = `<li class="w-search-empty">Type to search. Results will appear here.</li>`;
        return;
      }
      if (!matched.length) {
        list.innerHTML = `<li class="w-search-empty">No matching results for "${q}".</li>`;
        return;
      }
      list.innerHTML = matched
        .map((item, idx) => {
          const preview = item.hay.slice(0, 120);
          return `<li>
            <button type="button" class="w-search-item" data-search-idx="${idx}">
              <span class="w-search-item-title">${item.title}</span>
              <span class="w-search-item-sub">${preview}...</span>
            </button>
          </li>`;
        })
        .join("");

      list.querySelectorAll("[data-search-idx]").forEach((btn) => {
        btn.addEventListener("click", () => {
          const i = Number(btn.getAttribute("data-search-idx"));
          const target = matched[i]?.section;
          if (!target) return;
          setOpen(false);
          target.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      });
    }

    function toggleSearch(forceOpen) {
      const shouldOpen =
        typeof forceOpen === "boolean" ? forceOpen : !overlay.classList.contains("is-open");
      setOpen(shouldOpen);
      if (shouldOpen) {
        renderResults();
      }
    }

    pageSearchApi = {
      open: () => toggleSearch(true),
      close: () => toggleSearch(false),
      toggle: () => toggleSearch(),
      isOpen: () => overlay.classList.contains("is-open"),
    };
    window.NectaPageSearch = pageSearchApi;

    trigger.addEventListener("click", (event) => {
      event.preventDefault();
      toggleSearch();
    });

    closeBtn.addEventListener("click", () => {
      setOpen(false);
    });

    input.addEventListener("input", renderResults);

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && overlay.classList.contains("is-open")) {
        setOpen(false);
      }
    });

    // Keep old behavior on desktop by opening as non-fullscreen quick search.
    if (!isPhoneLayout()) {
      overlay.style.maxWidth = "620px";
      overlay.style.left = "50%";
      overlay.style.right = "auto";
      overlay.style.transform = "translateX(-50%)";
      overlay.style.height = "auto";
      overlay.style.bottom = "auto";
      overlay.style.top = "84px";
      overlay.style.border = "1px solid #dbe5f0";
      overlay.style.borderRadius = "14px";
      overlay.style.boxShadow = "0 16px 44px rgba(15,23,42,0.22)";
    }
  }

  function initPhoneTopMenu() {
    const btn = document.querySelector("[data-phone-menu-toggle]");
    const panel = document.querySelector("[data-phone-menu]");
    if (!btn || !panel) return;

    function setOpen(open) {
      panel.classList.toggle("is-open", open);
      btn.classList.toggle("is-active", open);
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    }

    btn.addEventListener("click", () => {
      const appsPanel = document.querySelector("[data-top-apps-menu]");
      if (appsPanel?.classList.contains("is-open")) {
        appsPanel.hidden = true;
        appsPanel.classList.remove("is-open");
        document.querySelector("[data-top-action='apps']")?.classList.remove("is-active");
        document.querySelector("[data-top-action='apps']")?.setAttribute("aria-expanded", "false");
      }
      const isOpen = panel.classList.contains("is-open");
      setOpen(!isOpen);
    });

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest("[data-phone-menu-toggle]") || target.closest("[data-phone-menu]")) return;
      setOpen(false);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    });
  }

  function initTopAppsMenu() {
    const btn = document.querySelector("[data-top-action='apps']");
    const panel = document.querySelector("[data-top-apps-menu]");
    if (!btn || !panel) return;

    function setOpen(open) {
      panel.hidden = !open;
      panel.classList.toggle("is-open", open);
      btn.classList.toggle("is-active", open);
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    }

    btn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      const phoneMenu = document.querySelector("[data-phone-menu]");
      if (phoneMenu?.classList.contains("is-open")) {
        phoneMenu.classList.remove("is-open");
        document.querySelector("[data-phone-menu-toggle]")?.classList.remove("is-active");
        document
          .querySelector("[data-phone-menu-toggle]")
          ?.setAttribute("aria-expanded", "false");
      }
      setOpen(!panel.classList.contains("is-open"));
    });

    panel.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => setOpen(false));
    });

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest("[data-top-action='apps']") || target.closest("[data-top-apps-menu]")) {
        return;
      }
      setOpen(false);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    });
  }

  const LANGUAGE_FLAGS = {
    en: "images/flag-gb.svg?v=1",
    sw: "images/flag-tz.svg?v=4",
  };

  function initLanguageMenu() {
    const toggleBtn = document.querySelector("[data-language-toggle]");
    const flagEl = document.querySelector("[data-language-flag]");
    const panel = document.querySelector("[data-lang-menu]");
    if (!toggleBtn || !flagEl || !panel) return;

    function currentLanguage() {
      try {
        const saved = localStorage.getItem(LANGUAGE_STORAGE_KEY) || "en";
        return saved === "sw" ? "sw" : "en";
      } catch (_) {
        return "en";
      }
    }

    function setOpen(open) {
      panel.hidden = !open;
      panel.classList.toggle("is-open", open);
      toggleBtn.classList.toggle("is-active", open);
      toggleBtn.setAttribute("aria-expanded", open ? "true" : "false");
    }

    function applyLanguage(lang) {
      const dict = I18N_DICTIONARY[lang] || I18N_DICTIONARY.en;
      document.documentElement.setAttribute("lang", lang === "sw" ? "sw" : "en");
      document.querySelectorAll("[data-i18n]").forEach((node) => {
        const key = node.getAttribute("data-i18n") || "";
        if (dict[key]) {
          node.textContent = dict[key];
        }
      });
      flagEl.src = LANGUAGE_FLAGS[lang] || LANGUAGE_FLAGS.en;
      flagEl.alt = lang === "sw" ? "Bendera ya Tanzania" : "Flag of the United Kingdom";
      try {
        localStorage.setItem(LANGUAGE_STORAGE_KEY, lang);
      } catch (_) {
        /* ignore */
      }
      document.dispatchEvent(
        new CustomEvent("necta-language-changed", {
          detail: { language: lang },
        })
      );
    }

    toggleBtn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      const appsPanel = document.querySelector("[data-top-apps-menu]");
      if (appsPanel?.classList.contains("is-open")) {
        appsPanel.hidden = true;
        appsPanel.classList.remove("is-open");
        document.querySelector("[data-top-action='apps']")?.classList.remove("is-active");
        document.querySelector("[data-top-action='apps']")?.setAttribute("aria-expanded", "false");
      }
      setOpen(!panel.classList.contains("is-open"));
    });

    panel.querySelectorAll("[data-language-option]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const lang = String(btn.getAttribute("data-language-option") || "en").toLowerCase();
        applyLanguage(lang === "sw" ? "sw" : "en");
        setOpen(false);
      });
    });

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest("[data-language-toggle]") || target.closest("[data-lang-menu]")) return;
      setOpen(false);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    });

    applyLanguage(currentLanguage());
  }

  function initTopActionButtons() {
    const actions = document.querySelectorAll("[data-top-action]");
    if (!actions.length) return;

    actions.forEach((btn) => {
      btn.addEventListener("click", (event) => {
        const action = String(btn.getAttribute("data-top-action") || "").trim();
        if (action === "apps") {
          return;
        }
        event.preventDefault();
        if (action === "history") {
          window.location.href = "payment-details.php?type=success";
          return;
        }
        if (action === "search") {
          const appsPanel = document.querySelector("[data-top-apps-menu]");
          if (appsPanel?.classList.contains("is-open")) {
            appsPanel.hidden = true;
            appsPanel.classList.remove("is-open");
            document.querySelector("[data-top-action='apps']")?.classList.remove("is-active");
          }
          if (pageSearchApi) {
            pageSearchApi.open();
            return;
          }
          const trigger = document.querySelector("[data-search-toggle]");
          if (trigger instanceof HTMLElement) {
            trigger.click();
          }
        }
      });
    });
  }

  function loadSweetAlert() {
    if (window.Swal && typeof window.Swal.fire === "function") {
      return Promise.resolve(window.Swal);
    }
    if (swalLoaderPromise) {
      return swalLoaderPromise;
    }
    swalLoaderPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[src="${SWEETALERT_CDN}"]`);
      if (existing) {
        existing.addEventListener("load", () => resolve(window.Swal));
        existing.addEventListener("error", () => reject(new Error("Failed to load SweetAlert2.")));
        return;
      }
      const script = document.createElement("script");
      script.src = SWEETALERT_CDN;
      script.async = true;
      script.onload = () => resolve(window.Swal);
      script.onerror = () => reject(new Error("Failed to load SweetAlert2."));
      document.head.appendChild(script);
    });
    return swalLoaderPromise;
  }

  function ensureSweetAlertStyle() {
    if (styleInjected) return;
    const id = "necta-server-alert-style";
    if (document.getElementById(id)) {
      styleInjected = true;
      return;
    }
    const style = document.createElement("style");
    style.id = id;
    style.textContent = `
      .swal2-popup.necta-server-popup {
        border-radius: 16px !important;
        width: min(92vw, 420px) !important;
        padding: 1.1rem 1rem 1rem !important;
      }
      .swal2-title.necta-server-title {
        font-weight: 800 !important;
        font-size: 1.55rem !important;
      }
      .swal2-html-container.necta-server-html {
        margin-top: 0.25rem !important;
        font-size: 1rem !important;
      }
      .swal2-container.swal2-center > .necta-server-popup {
        margin: 0 auto !important;
      }
      .swal2-popup.necta-server-popup .swal2-icon {
        display: none !important;
      }
      .necta-alert-body {
        display: grid;
        justify-items: center;
        gap: 12px;
        padding: 4px 0 2px;
      }
      .necta-alert-icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 2rem;
      }
      .necta-alert-icon--online {
        color: #15803d;
        background: linear-gradient(145deg, #dcfce7 0%, #bbf7d0 100%);
        box-shadow: 0 10px 24px rgba(22, 163, 74, 0.28);
      }
      .necta-alert-icon--offline {
        color: #b91c1c;
        background: linear-gradient(145deg, #fee2e2 0%, #fecaca 100%);
        box-shadow: 0 10px 24px rgba(185, 28, 28, 0.22);
      }
      .necta-alert-msg {
        margin: 0;
        font-weight: 700;
        line-height: 1.45;
        text-align: center;
      }
      .necta-alert-msg--online {
        color: #166534;
      }
      .necta-alert-msg--offline {
        color: #991b1b;
      }
    `;
    document.head.appendChild(style);
    styleInjected = true;
  }

  function persistServerStatus(nextStatus) {
    try {
      localStorage.setItem(SERVER_STATUS_STORAGE_KEY, nextStatus);
    } catch (_) {
      /* ignore */
    }
  }

  async function showOfflineAlert(messageText) {
    try {
      const Swal = await loadSweetAlert();
      if (!Swal || offlineModalOpen) return;
      ensureSweetAlertStyle();
      offlineModalOpen = true;
      await Swal.fire({
        icon: false,
        title: "Server is offline",
        customClass: {
          popup: "necta-server-popup",
          title: "necta-server-title",
          htmlContainer: "necta-server-html",
        },
        width: "420px",
        html: `
          <div class="necta-alert-body">
            <div class="necta-alert-icon necta-alert-icon--offline" aria-hidden="true">
              <i class="fa-solid fa-tower-broadcast fa-shake"></i>
            </div>
            <p class="necta-alert-msg necta-alert-msg--offline">${escapeHtml(
              messageText || "Unable to load totals. Check tis-clickpesa server."
            )}</p>
          </div>
        `,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        backdrop: true,
      });
    } catch (_) {
      /* ignore */
    }
  }

  async function showOnlineAlert(messageText) {
    try {
      const Swal = await loadSweetAlert();
      if (!Swal) return;
      ensureSweetAlertStyle();
      if (offlineModalOpen) {
        Swal.close();
        offlineModalOpen = false;
      }
      await Swal.fire({
        icon: false,
        title: "Server is online",
        customClass: {
          popup: "necta-server-popup",
          title: "necta-server-title",
          htmlContainer: "necta-server-html",
        },
        width: "420px",
        html: `
          <div class="necta-alert-body">
            <div class="necta-alert-icon necta-alert-icon--online" aria-hidden="true">
              <i class="fa-solid fa-wifi fa-beat-fade"></i>
            </div>
            <p class="necta-alert-msg necta-alert-msg--online">${escapeHtml(
              messageText || "Connection restored. Server is back online."
            )}</p>
          </div>
        `,
        timer: 2200,
        timerProgressBar: true,
        showConfirmButton: false,
      });
    } catch (_) {
      /* ignore */
    }
  }

  async function probeServer() {
    const ctrl = new AbortController();
    const timeoutId = window.setTimeout(() => ctrl.abort(), 6000);
    try {
      let res = await fetch(`${API_BASE}/health`, {
        cache: "no-store",
        headers: API_HEADERS,
        signal: ctrl.signal,
      });
      if (!res.ok) {
        res = await fetch(`${API_BASE}/payments`, {
          cache: "no-store",
          headers: API_HEADERS,
          signal: ctrl.signal,
        });
      }
      return res.ok ? "online" : "offline";
    } catch (_) {
      return "offline";
    } finally {
      window.clearTimeout(timeoutId);
    }
  }

  async function updateServerStatus(nextStatus) {
    if (serverStatus === "unknown") {
      serverStatus = nextStatus;
      persistServerStatus(nextStatus);
      if (nextStatus === "offline") {
        await showOfflineAlert();
      }
      return;
    }
    if (nextStatus === serverStatus) return;
    serverStatus = nextStatus;
    persistServerStatus(nextStatus);
    if (nextStatus === "offline") {
      await showOfflineAlert();
      return;
    }
    await showOnlineAlert();
  }

  async function forceOfflineAlert(messageText) {
    if (serverStatus === "offline" && offlineModalOpen) {
      return;
    }
    serverStatus = "offline";
    persistServerStatus("offline");
    await showOfflineAlert(messageText);
  }

  async function forceOnlineAlert(messageText) {
    if (serverStatus === "online" && !offlineModalOpen) {
      return;
    }
    serverStatus = "online";
    persistServerStatus("online");
    await showOnlineAlert(messageText);
  }

  async function checkServerStatus() {
    if (!document.body.classList.contains("tis-wallet-dash")) return;
    const nextStatus = await probeServer();
    await updateServerStatus(nextStatus);
  }

  function initServerStatusAlerts() {
    try {
      const stored = localStorage.getItem(SERVER_STATUS_STORAGE_KEY);
      if (stored === "online" || stored === "offline") {
        serverStatus = stored;
      }
    } catch (_) {
      /* ignore */
    }

    checkServerStatus().catch(() => {});
    monitorTimer = window.setInterval(() => {
      checkServerStatus().catch(() => {});
    }, 10000);

    window.addEventListener("offline", () => {
      updateServerStatus("offline").catch(() => {});
    });

    window.addEventListener("online", () => {
      checkServerStatus().catch(() => {});
    });

    window.addEventListener("beforeunload", () => {
      if (monitorTimer) {
        window.clearInterval(monitorTimer);
      }
    });
  }

  function boot() {
    if (!document.body.classList.contains("tis-wallet-dash")) return;
    window.NectaServerAlerts = {
      setOffline: (messageText) => forceOfflineAlert(messageText).catch(() => {}),
      setOnline: (messageText) => forceOnlineAlert(messageText).catch(() => {}),
    };
    initLayoutButtons();
    initWalletDate();
    initPhoneTopMenu();
    initBottomSearchToggle();
    initTopAppsMenu();
    initLanguageMenu();
    initTopActionButtons();
    initWalletGlobalSearch();
    initWalletActionCardAnimations();
    initServerStatusAlerts();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
