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
    $("panel").classList.add("hidden");
    $("loginBox").classList.remove("hidden");
  }

  function showPanel() {
    $("loginBox").classList.add("hidden");
    $("panel").classList.remove("hidden");
    loadTutors();
  }

  async function loadTutors() {
    const opts = setAuthHeader({ method: "GET" });
    const res = await fetch("/api/admin/tutores", opts);
    if (!res.ok) {
      alert("Error al obtener tutores");
      return;
    }
    const data = await res.json();
    const tbody = document.querySelector("#tutoresTable tbody");
    tbody.innerHTML = "";
    data.tutores.forEach((t) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${t.id}</td>
        <td><input data-id="${t.id}" class="editNombre" value="${escapeHtml(t.nombre || "")}"></td>
        <td><input data-id="${t.id}" class="editEmail" value="${escapeHtml(t.email || "")}"></td>
        <td><input type="checkbox" data-id="${t.id}" class="editActive" ${t.active ? "checked" : ""}></td>
        <td>
          <button data-id="${t.id}" class="btnSave">Guardar</button>
          <button data-id="${t.id}" class="btnDelete">Eliminar</button>
          <input type="file" data-id="${t.id}" class="fileInput" accept="text/csv" style="display:inline-block">
          <button data-id="${t.id}" class="btnUpload">Enviar CSV</button>
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
    if (!confirm("Eliminar tutor (soft)?")) return;
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

  // wire UI
  $("btnLogin").onclick = login;
  $("btnLogout").onclick = logout;
  $("btnRefresh").onclick = loadTutors;
  $("btnCreate").onclick = createTutor;

  // auto-login if token exists
  (async function () {
    if (localStorage.getItem(adminKey)) {
      const ok = await ping();
      if (ok) showPanel();
    }
  })();
})();
