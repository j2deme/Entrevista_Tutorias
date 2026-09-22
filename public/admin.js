(function () {
  const $ = (id) => document.getElementById(id);
  const adminKey = "admin_password_local";

  function setAuthHeader(opts = {}) {
    const pw = localStorage.getItem(adminKey);
    if (!pw) return opts;
    opts.headers = Object.assign(opts.headers || {}, {
      "X-Admin-Password": pw,
      Accept: "application/json",
    });
    return opts;
  }

  async function ping() {
    const opts = setAuthHeader({ method: "GET" });
    const res = await fetch("/api/admin/ping", opts);
    return res.ok;
  }

  async function login() {
    const pw = $("adminPassword").value.trim();
    if (!pw) {
      $("loginMsg").textContent = "Introduce contraseña";
      return;
    }
    localStorage.setItem(adminKey, pw);
    $("loginMsg").textContent = "";
    const ok = await ping();
    if (!ok) {
      localStorage.removeItem(adminKey);
      $("loginMsg").textContent = "Contraseña incorrecta";
      return;
    }
    showPanel();
  }

  function logout() {
    localStorage.removeItem(adminKey);
    closeModal();
    $("panel").classList.add("hidden");
    $("loginBox").classList.remove("hidden");
  }

  function showPanel() {
    $("loginBox").classList.add("hidden");
    $("panel").classList.remove("hidden");
    loadTutors();
    loadSettings();
  }

  async function loadTutors() {
    const opts = setAuthHeader({ method: "GET" });
    const res = await fetch("/api/admin/tutores", opts);
    if (!res.ok) {
      alert("Error al obtener tutores");
      return;
    }
    const data = await res.json();
    renderResumen(data.resumen || {});
    const tbody = document.querySelector("#tutoresTable tbody");
    tbody.innerHTML = "";
    data.tutores.forEach((t) => {
      const total = t.total || 0;
      const done = t.captured || 0;
      const pend = t.pending || 0;
      const pct = total ? Math.round((done / total) * 100) : 0;
      const nm = escapeHtml(t.nombre || "");
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${t.id}</td>
        <td><input data-id="${t.id}" class="editNombre" value="${nm}"></td>
        <td><input data-id="${t.id}" class="editEmail" value="${escapeHtml(t.email || "")}"></td>
        <td><input type="checkbox" data-id="${t.id}" class="editActive" ${t.active ? "checked" : ""}></td>
        <td>${total}</td>
        <td>${done}</td>
        <td>${pend}</td>
        <td>
          <div class="bar"><span style="width: ${pct}%"></span></div>
          <div class="pct">${pct}%</div>
        </td>
        <td>
          <input type="file" data-id="${t.id}" class="fileInput" accept="text/csv" style="display:inline-block">
          <button data-id="${t.id}" class="btnUpload">Enviar CSV</button>
        </td>
        <td>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnSave">Guardar</button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnView">Ver tutorados</button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnDownload">Descargar respuestas</button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnDelete">Eliminar</button>
        </td>`;
      tbody.appendChild(tr);
    });

    // attach events
    document.querySelectorAll(".btnSave").forEach((b) => (b.onclick = onSave));
    document
      .querySelectorAll(".btnDelete")
      .forEach((b) => (b.onclick = onDelete));
    document
      .querySelectorAll(".btnUpload")
      .forEach((b) => (b.onclick = onUpload));
    document.querySelectorAll(".btnView").forEach((b) => (b.onclick = onView));
    document
      .querySelectorAll(".btnDownload")
      .forEach((b) => (b.onclick = onDownload));
  }

  function escapeHtml(s) {
    return (s || "").replace(
      /[&<>\"]/g,
      (c) =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '\"': "&quot;" })[c] || c,
    );
  }

  async function onSave(e) {
    const id = e.currentTarget.dataset.id;
    const nombre = document
      .querySelector('.editNombre[data-id="' + id + '"]')
      .value.trim();
    const email = document
      .querySelector('.editEmail[data-id="' + id + '"]')
      .value.trim();
    const active = document.querySelector('.editActive[data-id="' + id + '"]')
      .checked
      ? 1
      : 0;
    if (!nombre) {
      alert("Nombre requerido");
      return;
    }
    const opts = setAuthHeader({
      method: "PUT",
      body: JSON.stringify({ nombre, email, active }),
      headers: { "Content-Type": "application/json" },
    });
    const res = await fetch("/api/admin/tutores/" + id, opts);
    if (!res.ok) {
      alert("Error al guardar");
      return;
    }
    alert("Guardado");
    loadTutors();
  }

  async function onDelete(e) {
    const nombre = e.currentTarget.dataset.nombre || "";
    if (!confirm('¿Dar de baja al tutor "' + nombre + '"? Sus tutorados quedarán sin asignar.')) return;
    const id = e.currentTarget.dataset.id;
    const opts = setAuthHeader({ method: "DELETE" });
    const res = await fetch("/api/admin/tutores/" + id, opts);
    if (!res.ok) {
      alert("Error al eliminar");
      return;
    }
    alert("Eliminado");
    loadTutors();
  }

  async function onUpload(e) {
    const id = e.currentTarget.dataset.id;
    const input = document.querySelector('.fileInput[data-id="' + id + '"]');
    if (!input || !input.files || input.files.length === 0) {
      alert("Selecciona un archivo CSV");
      return;
    }
    const f = input.files[0];
    const fd = new FormData();
    fd.append("file", f);
    const opts = setAuthHeader({ method: "POST", body: fd });
    const res = await fetch("/api/admin/tutores/" + id + "/upload", opts);
    if (!res.ok) {
      const t = await res.text();
      alert("Error upload: " + t);
      return;
    }
    const data = await res.json();
    alert("Upload: " + JSON.stringify(data));
    loadTutors();
  }

  async function createTutor() {
    const nombre = $("newNombre").value.trim();
    const email = $("newEmail").value.trim();
    if (!nombre) {
      alert("Nombre requerido");
      return;
    }
    const opts = setAuthHeader({
      method: "POST",
      body: new URLSearchParams({ nombre, email }),
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
    });
    const res = await fetch("/api/admin/tutores", opts);
    if (!res.ok) {
      alert("Error creando tutor");
      return;
    }
    $("newNombre").value = "";
    $("newEmail").value = "";
    loadTutors();
  }

  // Pinta las tarjetas de resumen y la barra de avance global.
  function renderResumen(r) {
    const total = r.total || 0;
    const done = r.captured || 0;
    const pct = total ? Math.round((done / total) * 100) : 0;
    $("kTotal").textContent = total;
    $("kCaptured").textContent = done;
    $("kPending").textContent = r.pending || 0;
    $("kSinTutor").textContent = r.sin_tutor || 0;
    $("kPct").textContent = pct + "%";
    $("avanceBarra").firstElementChild.style.width = pct + "%";
  }

  function closeModal() {
    $("modalTutorados").classList.add("hidden");
  }

  // Abre el modal con el listado de tutorados de un tutor.
  async function onView(e) {
    const id = e.currentTarget.dataset.id;
    const nombre = e.currentTarget.dataset.nombre || "";
    const res = await fetch(
      "/api/admin/tutores/" + id + "/report",
      setAuthHeader({ method: "GET" }),
    );
    if (!res.ok) {
      alert("Error al obtener los tutorados");
      return;
    }
    const d = await res.json();
    $("modalTitle").textContent = "Tutorados de " + nombre;
    $("modalStats").innerHTML =
      "<strong>Total:</strong> " +
      d.total +
      " · <strong>Respondieron:</strong> " +
      d.captured +
      " · <strong>Pendientes:</strong> " +
      d.pending;

    const tbody = document.querySelector("#tutoradosTable tbody");
    const list = d.list || [];
    tbody.innerHTML = "";
    if (!list.length) {
      tbody.innerHTML =
        '<tr><td colspan="5">Sin tutorados asignados</td></tr>';
    }
    list.forEach((s, i) => {
      const ok = s.capturado == 1;
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${i + 1}</td>
        <td>${escapeHtml(s.numero_control || "")}</td>
        <td>${escapeHtml(s.nombre_completo || "(sin nombre)")}</td>
        <td><span class="badge ${ok ? "ok" : "pend"}">${ok ? "Respondió" : "Pendiente"}</span></td>
        <td>${escapeHtml(String(s.updated_at || ""))}</td>`;
      tbody.appendChild(tr);
    });
    $("modalTutorados").classList.remove("hidden");
  }

  // Descarga el Excel con todas las respuestas de los tutorados del tutor.
  async function onDownload(e) {
    const id = e.currentTarget.dataset.id;
    const btn = e.currentTarget;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Descargando…";
    try {
      const res = await fetch(
        "/api/admin/tutores/" + id + "/exportar",
        setAuthHeader({ method: "GET" }),
      );
      if (!res.ok) throw new Error("HTTP " + res.status);
      const blob = await res.blob();
      const cd = res.headers.get("Content-Disposition") || "";
      const m = cd.match(/filename="?([^";]+)"?/);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = m ? m[1] : "respuestas.xlsx";
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (err) {
      alert("Error al descargar: " + err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = original;
    }
  }

  // --- Gestión de app_settings ------------------------------------------

  // key -> valor actual (para precargar el prompt de edición)
  let settingsCache = {};

  function settingsMsg(text, isError) {
    const el = $("settingsMsg");
    if (!el) return;
    el.textContent = text;
    el.style.color = isError ? "#b91c1c" : "#16a34a";
  }

  async function loadSettings() {
    const res = await fetch("/api/admin/settings", setAuthHeader({ method: "GET" }));
    if (!res.ok) {
      settingsMsg("No se pudo cargar la configuración", true);
      return;
    }
    const list = (await res.json()).settings || [];
    settingsCache = {};
    list.forEach((s) => (settingsCache[s.key] = s.value));
    const get = (k) => list.find((s) => s.key === k);

    const active = get("form_active");
    $("setFormActive").checked = !!(active && active.value === "1");
    $("setPeriodo").value = get("periodo")?.value || "";
    $("setRateMax").value = get("rate_limit_max")?.value || "";
    $("setRateWin").value = get("rate_limit_window_min")?.value || "";
    $("setAdminPw").value = "";
    const pw = get("admin_password");
    $("setAdminPw").placeholder =
      pw && pw.has_value
        ? "••••••••  (en blanco = no cambiar)"
        : "Sin contraseña definida";

    renderOtherSettings(list.filter((s) => !s.form));
  }

  function renderOtherSettings(otras) {
    const tbody = document.querySelector("#settingsTable tbody");
    tbody.innerHTML = "";
    if (!otras.length) {
      tbody.innerHTML =
        '<tr><td colspan="4" style="color:#666">No hay otras claves</td></tr>';
      return;
    }
    otras.forEach((s) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td><code>${escapeHtml(s.key)}</code></td>
        <td>${escapeHtml(s.value === null ? "" : String(s.value))}</td>
        <td>${escapeHtml(String(s.updated_at || ""))}</td>
        <td>
          <button class="btnEditSet" data-key="${escapeHtml(s.key)}">Editar</button>
          <button class="btnDelSet" data-key="${escapeHtml(s.key)}">Eliminar</button>
        </td>`;
      tbody.appendChild(tr);
    });
    tbody.querySelectorAll(".btnEditSet").forEach((b) => (b.onclick = onEditSetting));
    tbody.querySelectorAll(".btnDelSet").forEach((b) => (b.onclick = onDeleteSetting));
  }

  async function putSettings(settings) {
    const res = await fetch(
      "/api/admin/settings",
      setAuthHeader({
        method: "PUT",
        body: JSON.stringify({ settings }),
        headers: { "Content-Type": "application/json" },
      })
    );
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const detail = data.fields
        ? Object.entries(data.fields)
            .map(([k, v]) => k + ": " + v)
            .join(" · ")
        : data.error || "HTTP " + res.status;
      settingsMsg(detail, true);
      return false;
    }
    // Si cambió la contraseña, la actualizamos para no perder la sesión.
    if (settings.admin_password) {
      localStorage.setItem(adminKey, settings.admin_password);
    }
    settingsMsg("Configuración guardada ✓", false);
    await loadSettings();
    return true;
  }

  async function saveSettings() {
    const settings = {
      form_active: $("setFormActive").checked,
      periodo: $("setPeriodo").value.trim(),
      rate_limit_max: $("setRateMax").value.trim(),
      rate_limit_window_min: $("setRateWin").value.trim(),
    };
    const pw = $("setAdminPw").value;
    if (pw) settings.admin_password = pw;
    await putSettings(settings);
  }

  async function addSetting() {
    const key = $("newSetKey").value.trim();
    if (!key) {
      settingsMsg("Indica la clave", true);
      return;
    }
    if (await putSettings({ [key]: $("newSetVal").value })) {
      $("newSetKey").value = "";
      $("newSetVal").value = "";
    }
  }

  async function onEditSetting(e) {
    const key = e.currentTarget.dataset.key;
    const next = prompt('Nuevo valor para "' + key + '"', settingsCache[key] || "");
    if (next === null) return;
    await putSettings({ [key]: next });
  }

  async function onDeleteSetting(e) {
    const key = e.currentTarget.dataset.key;
    if (!confirm('¿Eliminar la clave "' + key + '"? Volverá a su valor por defecto.'))
      return;
    const res = await fetch(
      "/api/admin/settings/" + encodeURIComponent(key),
      setAuthHeader({ method: "DELETE" })
    );
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      settingsMsg(data.error || "HTTP " + res.status, true);
      return;
    }
    settingsMsg('Clave "' + key + '" restablecida ✓', false);
    await loadSettings();
  }

  // wire UI
  $("btnLogin").onclick = login;
  $("btnLogout").onclick = logout;
  $("btnRefresh").onclick = loadTutors;
  $("btnCreate").onclick = createTutor;
  $("btnCloseModal").onclick = closeModal;
  $("btnSaveSettings").onclick = saveSettings;
  $("btnAddSetting").onclick = addSetting;
  // cerrar al pulsar fuera del cuadro
  $("modalTutorados").onclick = (ev) => {
    if (ev.target === $("modalTutorados")) closeModal();
  };

  // auto-login if token exists
  (async function () {
    if (localStorage.getItem(adminKey)) {
      const ok = await ping();
      if (ok) showPanel();
    }
  })();
})();
