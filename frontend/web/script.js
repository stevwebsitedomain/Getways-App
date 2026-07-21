function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (c) => {
    switch (c) {
      case "&":
        return "&amp;";
      case "<":
        return "&lt;";
      case ">":
        return "&gt;";
      case '"':
        return "&quot;";
      case "'":
        return "&#039;";
      default:
        return c;
    }
  });
}

function initTopNavbarMenu() {
  const navbars = document.querySelectorAll(".navbar");
  navbars.forEach((navbar) => {
    const toggle = navbar.querySelector("[data-nav-toggle]");
    const panel = navbar.querySelector("[data-nav-mobile-menu]");
    if (!toggle || !panel) return;

    toggle.addEventListener("click", () => {
      const willOpen = !navbar.classList.contains("is-mobile-menu-open");
      navbar.classList.toggle("is-mobile-menu-open", willOpen);
      toggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
    });

    panel.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => {
        navbar.classList.remove("is-mobile-menu-open");
        toggle.setAttribute("aria-expanded", "false");
      });
    });
  });
}

function normalizeResults(payload) {
  if (!payload) return [];
  if (Array.isArray(payload.data)) {
    // Batch mode shape: data: [{ query: string, results: [...] }, ...]
    if (payload.data.length && Array.isArray(payload.data[0]?.results)) {
      return payload.data.flatMap((group) => {
        const q = String(group?.query || "").trim();
        const items = Array.isArray(group?.results) ? group.results : [];
        return items.map((it) => ({ ...it, _batchQuery: q }));
      });
    }
    return payload.data;
  }
  // Party Two (/search) returns results under data.organic_results
  if (payload.data && Array.isArray(payload.data.organic_results)) return payload.data.organic_results;
  // Some variants may return organic_results at top-level
  if (Array.isArray(payload.organic_results)) return payload.organic_results;
  // google-search-master-mega returns organic
  if (Array.isArray(payload.organic)) return payload.organic;
  // real-time-news-data full-story-coverage often returns data.articles or data.news
  if (payload.data && Array.isArray(payload.data.articles)) return payload.data.articles;
  if (payload.data && Array.isArray(payload.data.news)) return payload.data.news;
  // real-time-news-data full-story-coverage actual fields
  if (payload.data && Array.isArray(payload.data.all_articles)) return payload.data.all_articles;
  if (payload.data && Array.isArray(payload.data.top_news)) return payload.data.top_news;
  if (Array.isArray(payload.results)) return payload.results;
  if (Array.isArray(payload.items)) return payload.items;
  return [];
}

function renderScrapeResult({ payload, mountEl, label }) {
  if (!mountEl) return;

  const urlRaw = String(payload?.url || payload?.input?.url || "").trim();
  const title = String(
    payload?.result?.title || payload?.title || payload?.data?.title || ""
  ).trim();
  const content =
    String(
      payload?.result?.content ||
        payload?.content ||
        payload?.data?.content ||
        payload?.text ||
        payload?.data?.text ||
        ""
    )
      .replace(/\s+/g, " ")
      .trim();
  const firstFoundUrl = String(payload?.result?.urls?.[0] || "").trim();

  let domainVal = "";
  if (urlRaw) {
    try {
      domainVal = new URL(urlRaw).hostname;
    } catch (_) {
      domainVal = "";
    }
  }

  const preview = content ? content.slice(0, 500) + (content.length > 500 ? "..." : "") : "";

  mountEl.innerHTML = `
    <div class="result-meta">
      <div><strong>URL:</strong> ${escapeHtml(label || urlRaw || "")}</div>
      <div class="muted">Scraped page details</div>
    </div>

    <div class="table-wrap">
      <table class="results-table" style="min-width: 0;">
        <thead>
          <tr>
            <th>Field</th>
            <th>Value</th>
          </tr>
        </thead>
        <tbody>
          <tr><td><strong>Title</strong></td><td>${escapeHtml(title || "-")}</td></tr>
          <tr><td><strong>Domain</strong></td><td>${escapeHtml(domainVal || "-")}</td></tr>
          <tr><td><strong>Link</strong></td><td>${
            urlRaw
              ? `<a href="${escapeHtml(urlRaw)}" target="_blank" rel="noopener noreferrer">Open</a>`
              : "-"
          }</td></tr>
          <tr><td><strong>Found URL</strong></td><td>${
            firstFoundUrl
              ? `<a href="${escapeHtml(firstFoundUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(firstFoundUrl)}</a>`
              : "-"
          }</td></tr>
          <tr><td><strong>Preview</strong></td><td>${escapeHtml(preview || "-")}</td></tr>
        </tbody>
      </table>
    </div>
  `;
}

function renderResultsTable({
  results,
  mountEl,
  label,
  showQueryColumn = false,
  watermark = false
}) {
  if (!mountEl) return;

  if (!Array.isArray(results) || results.length === 0) {
    mountEl.innerHTML = `<div class="error">No results found.</div>`;
    return;
  }

  const tableId = `results-${Math.random().toString(16).slice(2)}`;
  mountEl.__lastResults = results;
  mountEl.__lastLabel = label || "results";
  mountEl.__lastShowQueryColumn = showQueryColumn;

  const rows = results.slice(0, 10).map((r, i) => {
    const title = escapeHtml(r?.title || "-");
    const urlRaw = String(r?.url || r?.link || "").trim();
    let domainVal = String(r?.domain || "").trim();
    if (!domainVal && urlRaw) {
      try {
        domainVal = new URL(urlRaw).hostname;
      } catch (_) {
        domainVal = "";
      }
    }
    const domain = escapeHtml(domainVal || "-");
    const snippet = escapeHtml(r?.snippet || r?.description || "-");
    const q = escapeHtml(r?._batchQuery || "");
    const urlSafe = escapeHtml(urlRaw);
    const link = urlRaw
      ? `<a href="${urlSafe}" target="_blank" rel="noopener noreferrer">Open</a>`
      : "-";

    return `
      <tr>
        <td class="col-no">${i + 1}</td>
        ${showQueryColumn ? `<td class="col-query">${q || "-"}</td>` : ""}
        <td class="col-title">${title}</td>
        <td class="col-domain">${domain}</td>
        <td class="col-snippet">${snippet}</td>
        <td class="col-link">${link}</td>
      </tr>
    `;
  }).join("");

  mountEl.innerHTML = `
    <div class="result-meta">
      <div class="result-query"><strong>Index:</strong> ${escapeHtml(label || "")}</div>
      <div class="result-actions">
        <span class="muted">Showing top ${Math.min(10, results.length)} results</span>
        <button class="btn-download" type="button" data-export="csv" data-table="${tableId}">Download Excel (CSV)</button>
        <button class="btn-download" type="button" data-export="pdf" data-table="${tableId}">Download PDF</button>
      </div>
    </div>

    <div class="table-wrap${watermark ? " watermark" : ""}">
      ${watermark ? '<div class="table-scroll">' : ""}
      <table class="results-table" id="${tableId}">
        <thead>
          <tr>
            <th>#</th>
            ${showQueryColumn ? "<th>Query</th>" : ""}
            <th>Title</th>
            <th>Domain</th>
            <th>Snippet</th>
            <th>Link</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
      ${watermark ? '</div><div class="table-watermark-layer" aria-hidden="true"></div>' : ""}
    </div>
  `;

  // wire export buttons
  mountEl.querySelectorAll("button[data-export]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const type = btn.getAttribute("data-export");
      if (type === "csv") downloadCsvFromMount(mountEl);
      if (type === "pdf") printPdfFromMount(mountEl);
    });
  });
}

function toCsvValue(v) {
  const s = String(v ?? "");
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

function downloadCsvFromMount(mountEl) {
  const results = mountEl?.__lastResults || [];
  const showQueryColumn = !!mountEl?.__lastShowQueryColumn;
  const label = String(mountEl?.__lastLabel || "results")
    .replace(/[^\w\- ]+/g, "")
    .trim()
    .slice(0, 60);
  const filename = `${label || "results"}.csv`;

  const headers = [
    "#",
    ...(showQueryColumn ? ["Query"] : []),
    "Title",
    "Domain",
    "Snippet",
    "URL"
  ];

  const lines = [headers.map(toCsvValue).join(",")];
  results.slice(0, 50).forEach((r, idx) => {
    const url = String(r?.url || r?.link || "").trim();
    let domain = String(r?.domain || "").trim();
    if (!domain && url) {
      try {
        domain = new URL(url).hostname;
      } catch (_) {
        domain = "";
      }
    }
    const row = [
      idx + 1,
      ...(showQueryColumn ? [String(r?._batchQuery || "")] : []),
      String(r?.title || ""),
      domain,
      String(r?.snippet || r?.description || ""),
      url
    ];
    lines.push(row.map(toCsvValue).join(","));
  });

  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function printPdfFromMount(mountEl) {
  const label = String(mountEl?.__lastLabel || "results");
  const table = mountEl.querySelector("table");
  if (!table) return;

  const w = window.open("", "_blank", "noopener,noreferrer");
  if (!w) return;

  w.document.open();
  w.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${escapeHtml(label)}</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 18px; }
    h2 { margin: 0 0 10px; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
    th { background: #f3f3f3; text-align: left; }
    a { color: #003b73; }
  </style>
</head>
<body>
  <h2>${escapeHtml(label)}</h2>
  ${table.outerHTML}
  <script>
    window.onload = () => {
      window.print();
      setTimeout(() => window.close(), 300);
    };
  </script>
</body>
</html>`);
  w.document.close();
}

async function searchResult() {
  const index = document.getElementById("index").value.trim();
  const yearEl = document.getElementById("year");
  const levelEl = document.getElementById("level");
  const year = yearEl ? String(yearEl.value || "").trim() : "";
  const level = levelEl ? String(levelEl.value || "").trim() : "";
  const resultBox = document.getElementById("result");
  const loading = document.getElementById("loading");

  resultBox.innerHTML = "";

  if (!index) {
    resultBox.innerHTML = `<div class="error">Please enter an index number.</div>`;
    return;
  }

  loading.style.display = "block";

  try {
    // Part One: search using INDEX ONLY (do not attach year/level)
    const res = await fetch(`/api/search?q=${encodeURIComponent(index)}`);
    const data = await res.json();
    loading.style.display = "none";

    if (!res.ok || data?.error) {
      resultBox.innerHTML = `<div class="error">${escapeHtml(
        data?.error || "API request failed"
      )}</div>`;
      return;
    }

    const results = normalizeResults(data);
    renderResultsTable({ results, mountEl: resultBox, label: index, watermark: true });
  } catch (error) {
    loading.style.display = "none";
    resultBox.innerHTML = `<div class="error">Failed to connect to server.</div>`;
    console.error(error);
  }
}

async function searchPartyTwo() {
  const input = document.getElementById("partyTwoQuery");
  const queryText = input ? String(input.value || "").trim() : "";
  const resultBox = document.getElementById("result2");
  const loading = document.getElementById("loading2");

  if (resultBox) resultBox.innerHTML = "";

  if (!queryText) {
    if (resultBox) {
      resultBox.innerHTML = `<div class="error">Please enter at least one query.</div>`;
    }
    return;
  }

  loading.style.display = "block";

  try {
    // Party Two: real-time-news-data full-story-coverage (GET)
    const res = await fetch(`/api/party-two?story=${encodeURIComponent(queryText)}`);

    const data = await res.json();
    loading.style.display = "none";

    if (!res.ok || data?.error) {
      if (resultBox) {
        resultBox.innerHTML = `<div class="error">${escapeHtml(
          data?.error || "API request failed"
        )}</div>`;
      }
      return;
    }

    const results = normalizeResults(data).map((it) => {
      const title = it?.title || it?.headline || it?.name;
      const url = it?.link || it?.url;
      const snippet = it?.snippet || it?.summary || it?.description;
      const domain =
        it?.source_name ||
        it?.source ||
        it?.publisher ||
        it?.domain ||
        it?.source_url ||
        "";

      // Keep extra fields if needed later
      const photo = it?.photo_url || it?.thumbnail_url || "";

      return { title, url, link: url, snippet, domain, photo_url: photo };
    });

    renderResultsTable({
      results,
      mountEl: resultBox,
      label: `Party Two (story: ${queryText})`
    });
  } catch (error) {
    loading.style.display = "none";
    if (resultBox) {
      resultBox.innerHTML = `<div class="error">Failed to connect to server.</div>`;
    }
    console.error(error);
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initTopNavbarMenu);
} else {
  initTopNavbarMenu();
}