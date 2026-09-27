(function () {
  const $ = (id) => document.getElementById(id);
  const adminKey = "admin_password_local";
  let lastListTitle = "Tutorados";

  function setAuthHeader(opts = {}) {
    const pw = localStorage.getItem(adminKey);
    if (!pw) return opts;
    opts.headers = Object.assign(opts.headers || {}, {
      "X-Admin-Password": pw,
      Accept: "application/json",
    });
    return opts;
  }

  // Estado de carga en un botón: guarda su texto original y lo restaura.
  function setBtnLoading(btn, on, texto) {
    if (!btn) return;
    if (on) {
      if (!btn.dataset.txt) btn.dataset.txt = btn.textContent;
      btn.disabled = true;
      btn.textContent = texto || "Cargando…";
    } else {
      btn.disabled = false;
      if (btn.dataset.txt) {
        btn.textContent = btn.dataset.txt;
        delete btn.dataset.txt;
      }
    }
  }

  // Fila de carga (o de error) para los tbody que renderiza admin.js.
  function filaCarga(texto, colspan, error) {
    const icono = error ? "✕" : '<span class="spinner"></span>';
    return (
      '<tr><td class="celdaCarga" colspan="' + colspan + '">' + icono + " " + texto + "</td></tr>"
    );
  }

  async function ping() {
    const opts = setAuthHeader({ method: "GET" });
    const res = await fetch("/api/admin/ping", opts);
    return res.ok;
  }

  async function login() {
    // Ya hay un intento en curso (Enter + clic): no disparar dos pings.
    if ($("btnLogin").disabled) return;
    const pw = $("adminPassword").value.trim();
    if (!pw) {
      $("loginMsg").textContent = "Introduce contraseña";
      return;
    }
    localStorage.setItem(adminKey, pw);
    $("loginMsg").textContent = "";
    setBtnLoading($("btnLogin"), true, "Entrando…");
    let ok = false;
    try {
      ok = await ping();
    } catch (ex) {
      ok = false;
    } finally {
      setBtnLoading($("btnLogin"), false);
    }
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
    const tbody = document.querySelector("#tutoresTable tbody");
    setBtnLoading($("btnRefresh"), true, "Refrescando…");
    tbody.innerHTML = filaCarga("Cargando tutores…", 10);
    let res;
    try {
      res = await fetch(
        "/api/admin/tutores",
        setAuthHeader({ method: "GET" }),
      );
    } catch (ex) {
      res = null;
    }
    if (!res || !res.ok) {
      alert("Error al obtener tutores" + (res ? " (HTTP " + res.status + ")" : ""));
      tbody.innerHTML = filaCarga("No se pudieron cargar los tutores.", 10, true);
      setBtnLoading($("btnRefresh"), false);
      return;
    }
    const data = await res.json();
    renderResumen(data.resumen || {});
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
          <input type="file" data-id="${t.id}" class="fileInput" accept=".csv,.xls,.xlsx,text/csv" style="display:inline-block">
          <button data-id="${t.id}" data-nombre="${nm}" class="btnUpload" title="Enviar lista de preregistros" aria-label="Enviar lista"><svg class="ic" viewBox="0 0 24 24"><use href="#i-upload"></use></svg></button>
        </td>
        <td>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnSave" title="Guardar cambios" aria-label="Guardar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-save"></use></svg></button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnView" title="Ver tutorados" aria-label="Ver tutorados"><svg class="ic" viewBox="0 0 24 24"><use href="#i-eye"></use></svg></button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnDownload" title="Descargar respuestas (Excel)" aria-label="Descargar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-download"></use></svg></button>
          <button data-id="${t.id}" data-nombre="${nm}" class="btnDelete" title="Eliminar tutor" aria-label="Eliminar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-trash"></use></svg></button>
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
    setBtnLoading($("btnRefresh"), false);
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
    const btn = e.currentTarget;
    const id = btn.dataset.id;
    const input = document.querySelector('.fileInput[data-id="' + id + '"]');
    if (!input || !input.files || input.files.length === 0) {
      alert("Selecciona un archivo CSV, XLS o XLSX");
      return;
    }
    const f = input.files[0];
    // Confirmación previa: evita subir la lista al tutor equivocado. Todo lo
    // que hace el backend es crear/reasignar (nunca borra datos), pero
    // prevenir sale más barato que notificar el error después.
    if (!confirm('¿Subir "' + f.name + '" a ' + (btn.dataset.nombre || "este tutor") + '?')) {
      return;
    }
    const fd = new FormData();
    fd.append("file", f);
    const opts = setAuthHeader({ method: "POST", body: fd });
    // Estado de carga: el botón se bloquea y marca "Subiendo…" mientras el
    // archivo viaja (evita doble clic y subidas repetidas si la red tarda).
    // Se guarda innerHTML (no textContent) para restaurar el icono del botón.
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "Subiendo…";
    let data = null;
    let error = null;
    try {
      const res = await fetch("/api/admin/tutores/" + id + "/upload", opts);
      if (res.ok) data = await res.json();
      else error = await res.text();
    } catch (ex) {
      error = String(ex);
    } finally {
      btn.disabled = false;
      btn.innerHTML = original;
    }
    if (error !== null) {
      alert("Error upload: " + error);
      return;
    }
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
    resetModalView();
    $("modalTutorados").classList.add("hidden");
  }

  // Abre el modal con el listado de tutorados de un tutor.
  async function onView(e) {
    const id = e.currentTarget.dataset.id;
    const nombre = e.currentTarget.dataset.nombre || "";
    resetModalView();
    // Abre el modal enseguida con estado de carga: mientras llega el reporte
    // se ve el esqueleto en vez de una pantalla muerta.
    lastListTitle = "Tutorados de " + nombre;
    $("modalTitle").textContent = lastListTitle;
    $("modalStats").textContent = "Cargando…";
    document.querySelector("#tutoradosTable tbody").innerHTML = filaCarga(
      "Cargando tutorados…",
      6,
    );
    $("modalTutorados").classList.remove("hidden");
    let res;
    try {
      res = await fetch(
        "/api/admin/tutores/" + id + "/report",
        setAuthHeader({ method: "GET" }),
      );
      if (!res.ok) throw new Error("HTTP " + res.status);
    } catch (err) {
      alert(
        "Error al obtener los tutorados (" + ((err && err.message) || err) + ")",
      );
      closeModal();
      return;
    }
    const d = await res.json();
    lastListTitle = "Tutorados de " + nombre;
    $("modalTitle").textContent = lastListTitle;
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
        '<tr><td colspan="6">Sin tutorados asignados</td></tr>';
    }
    list.forEach((s, i) => {
      const ok = s.capturado == 1;
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${i + 1}</td>
        <td>${escapeHtml(s.numero_control || "")}</td>
        <td>${escapeHtml(s.nombre_completo || "(sin nombre)")}</td>
        <td><span class="badge ${ok ? "ok" : "pend"}">${ok ? "Respondió" : "Pendiente"}</span></td>
        <td>${escapeHtml(String(s.updated_at || ""))}</td>
        <td>${
          ok
            ? `<button class="btnVerResp" data-id="${id}" data-nc="${escapeHtml(
                s.numero_control || "",
              )}" data-nombre="${escapeHtml(s.nombre_completo || "")}" title="Ver respuestas" aria-label="Ver respuestas"><svg class="ic" viewBox="0 0 24 24"><use href="#i-eye"></use></svg></button>
              <button class="btnDelTut" data-id="${id}" data-nc="${escapeHtml(
                s.numero_control || "",
              )}" data-nombre="${escapeHtml(s.nombre_completo || "")}" title="Eliminar tutorado" aria-label="Eliminar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-trash"></use></svg></button>`
            : ""
        }</td>`;
      tbody.appendChild(tr);
    });
    tbody
      .querySelectorAll(".btnVerResp")
      .forEach((b) => (b.onclick = onVerRespuestas));
    tbody
      .querySelectorAll(".btnDelTut")
      .forEach((b) => (b.onclick = onDeleteTutorado));
    $("modalTutorados").classList.remove("hidden");
  }

  // Carga y muestra la ficha individual con las respuestas de un tutorado.
  async function onVerRespuestas(e) {
    // e.currentTarget solo existe durante la dispatch síncrona: se copia ya.
    const btn = e.currentTarget;
    const id = btn.dataset.id;
    const nc = btn.dataset.nc;
    const nombre = btn.dataset.nombre || "";
    // Estado de carga visible mientras llega la ficha.
    $("fichaView").innerHTML =
      '<div class="celdaCarga"><span class="spinner"></span> Cargando respuestas…</div>';
    $("fichaView").classList.remove("hidden");
    $("tutoradosLista").classList.add("hidden");
    $("btnBackFicha").classList.remove("hidden");
    try {
      const res = await fetch(
        "/api/admin/tutores/" + id + "/tutorados/" + encodeURIComponent(nc),
        setAuthHeader({ method: "GET" }),
      );
      if (!res.ok) throw new Error("HTTP " + res.status);
      renderFicha(await res.json(), nombre, id);
    } catch (err) {
      alert(
        "No se pudieron cargar las respuestas (" +
          ((err && err.message) || err) +
          ")",
      );
      backToList();
    }
  }

  // Pinta la ficha (datos básicos + 6 bloques) y cambia el modal a detalle.
  function renderFicha(d, fallbackNombre, tutorId) {
    const est = d.estudiante || {};
    const badge =
      est.capturado == 1
        ? '<span class="badge ok">Respondió</span>'
        : '<span class="badge pend">Pendiente</span>';
    const head = `
      <div class="fichaHead">
        <strong>${escapeHtml(est.nombre_completo || fallbackNombre || "(sin nombre)")}</strong>
        &nbsp; ${badge}
        <br>Número de control: ${escapeHtml(est.numero_control || "")}
        · Periodo: ${escapeHtml(est.periodo_captura || "—")}
        · Actualizado: ${escapeHtml(String(est.updated_at || "—"))}
        <br><button id="btnLimpiarCaptura" data-id="${tutorId}" data-nc="${escapeHtml(
          est.numero_control || "",
        )}">Limpiar captura</button>
      </div>`;
    const bloques = (d.bloques || [])
      .map((b) => {
        const filas = (b.campos || [])
          .map((c) => {
            const vacio = !c.valor;
            return `<tr class="${vacio ? "vac" : ""}"><th>${escapeHtml(
              c.label || "",
            )}</th><td>${vacio ? "—" : escapeHtml(c.valor)}</td></tr>`;
          })
          .join("");
        const aviso =
          b.presente === false
            ? '<p style="color:#b45309;font-size:12px;margin:4px 0 0">Sin registro en esta sección</p>'
            : "";
        return `<h4>${escapeHtml(b.titulo)}</h4>${aviso}<table class="fichaTabla">${filas}</table>`;
      })
      .join("");
    $("fichaView").innerHTML = head + bloques;
    $("btnLimpiarCaptura").onclick = onLimpiarCaptura;
    $("tutoradosLista").classList.add("hidden");
    $("fichaView").classList.remove("hidden");
    $("btnBackFicha").classList.remove("hidden");
    $("modalTitle").textContent =
      "Respuestas de " + (est.nombre_completo || fallbackNombre || "");
    document.querySelector(".modalBox").scrollTop = 0;
  }

  // Vuelve al listado (los datos del listado siguen en el DOM).
  function backToList() {
    resetModalView();
    $("modalTitle").textContent = lastListTitle;
  }

  // Deja el modal en el estado de listado (ficha oculta).
  function resetModalView() {
    $("fichaView").classList.add("hidden");
    $("fichaView").innerHTML = "";
    $("tutoradosLista").classList.remove("hidden");
    $("btnBackFicha").classList.add("hidden");
  }

  // Refresca el listado del modal volviendo a pedir el reporte del tutor
  // (siempre en estado de listado: resetModalView).
  function refrescarLista(id) {
    resetModalView();
    const btn = document.querySelector('.btnView[data-id="' + id + '"]');
    if (btn) btn.click();
  }

  // Vacía las respuestas del tutorado y lo deja Pendiente (capturado=0).
  async function onLimpiarCaptura(e) {
    const btn = e.currentTarget;
    const id = btn.dataset.id;
    const nc = btn.dataset.nc;
    const seguro = confirm(
      "¿Limpiar la captura de este tutorado?\n\n" +
        "Se borran todas sus respuestas y el tutorado vuelve a quedar como " +
        "Pendiente para rehacer la captura. No se puede deshacer.",
    );
    if (!seguro) return;
    try {
      const res = await fetch(
        "/api/admin/tutores/" +
          id +
          "/tutorados/" +
          encodeURIComponent(nc) +
          "/captura",
        setAuthHeader({ method: "DELETE" }),
      );
      if (!res.ok) throw new Error("HTTP " + res.status);
      refrescarLista(id);
    } catch (err) {
      alert(
        "No se pudo limpiar la captura (" + ((err && err.message) || err) + ")",
      );
    }
  }

  // Elimina por completo el tutorado (cascada); para registros de prueba.
  async function onDeleteTutorado(e) {
    const btn = e.currentTarget;
    const id = btn.dataset.id;
    const nc = btn.dataset.nc;
    const nombre = btn.dataset.nombre || nc;
    const seguro = confirm(
      "¿Eliminar por completo el tutorado " +
        nombre +
        " (" +
        nc +
        ")?\n\nSe borran también todas sus respuestas y no se puede deshacer.",
    );
    if (!seguro) return;
    try {
      const res = await fetch(
        "/api/admin/tutores/" + id + "/tutorados/" + encodeURIComponent(nc),
        setAuthHeader({ method: "DELETE" }),
      );
      if (!res.ok) throw new Error("HTTP " + res.status);
      refrescarLista(id);
    } catch (err) {
      alert(
        "No se pudo eliminar el tutorado (" + ((err && err.message) || err) + ")",
      );
    }
  }

  // Descarga el Excel con todas las respuestas de los tutorados del tutor.
  async function onDownload(e) {
    const id = e.currentTarget.dataset.id;
    const btn = e.currentTarget;
    // innerHTML (no textContent): el botón es de icono y hay que restaurarlo.
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = "Descargando…";
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
      btn.innerHTML = original;
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
    const tbody = document.querySelector("#settingsTable tbody");
    tbody.innerHTML = filaCarga("Cargando configuración…", 4);
    let res;
    try {
      res = await fetch("/api/admin/settings", setAuthHeader({ method: "GET" }));
    } catch (ex) {
      res = null;
    }
    if (!res || !res.ok) {
      settingsMsg("No se pudo cargar la configuración", true);
      tbody.innerHTML = filaCarga("No se pudo cargar la configuración.", 4, true);
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
          <button class="btnEditSet" data-key="${escapeHtml(s.key)}" title="Editar clave" aria-label="Editar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-edit"></use></svg></button>
          <button class="btnDelSet" data-key="${escapeHtml(s.key)}" title="Eliminar clave" aria-label="Eliminar"><svg class="ic" viewBox="0 0 24 24"><use href="#i-trash"></use></svg></button>
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
  // Enter en el campo de contraseña entra igual que el botón.
  $("adminPassword").onkeydown = (ev) => {
    if (ev.key === "Enter") login();
  };
  // Escape cierra el modal (el clic en el fondo ya está más abajo).
  document.onkeydown = (ev) => {
    if (
      (ev.key === "Escape" || ev.key === "Esc") &&
      !$("modalTutorados").classList.contains("hidden")
    ) {
      closeModal();
    }
  };
  $("btnLogout").onclick = logout;
  $("btnRefresh").onclick = loadTutors;
  $("btnCreate").onclick = createTutor;
  $("btnCloseModal").onclick = closeModal;
  $("btnBackFicha").onclick = backToList;
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
