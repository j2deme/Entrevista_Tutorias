document.addEventListener("DOMContentLoaded", () => {
  // Obtener CSRF token desde el backend y colocarlo en el input oculto
  fetch("/api/csrf")
    .then((r) => r.json())
    .then((j) => {
      if (j && j.csrfToken) {
        const el = document.getElementById("csrfToken");
        if (el) el.value = j.csrfToken;
        window.__csrfToken = j.csrfToken;
      }
    })
    .catch(() => {});
  // Cargar lista de tutores activos para el select
  async function loadTutors() {
    const sel = document.getElementById("tutorSelect");
    if (!sel) return;
    try {
      const r = await fetch("/api/tutores");
      const j = await r.json();
      sel.innerHTML = "";
      if (j && Array.isArray(j.tutores) && j.tutores.length > 0) {
        sel.appendChild(new Option("Selecciona un tutor...", "", true, false));
        j.tutores.forEach((t) => {
          sel.appendChild(new Option(t.nombre, t.id));
        });
      } else {
        sel.appendChild(new Option("No hay tutores activos", "", true, false));
        sel.disabled = true;
      }
    } catch (err) {
      sel.innerHTML = "";
      sel.appendChild(new Option("Error cargando tutores", "", true, false));
      sel.disabled = true;
    }
  }
  loadTutors();
  // Tutor precargado desde el CSV: el selector queda bloqueado (no editable).
  // Un <select disabled> NO se envía en FormData; el submit lo inyecta aparte.
  function lockTutorSelect(id, nombre) {
    const sel = document.getElementById("tutorSelect");
    if (!sel) return;
    const val = String(id);
    if (!Array.from(sel.options).some((o) => o.value === val)) {
      sel.appendChild(new Option(nombre || "Tutor asignado", val));
    }
    sel.value = val;
    sel.disabled = true;
    sel.classList.add("bg-gray-100");
    sel.dataset.locked = "1";
  }
  function clearTutorSelectLock() {
    const sel = document.getElementById("tutorSelect");
    if (!sel || !sel.dataset.locked) return;
    delete sel.dataset.locked;
    sel.disabled = false;
    sel.classList.remove("bg-gray-100");
    loadTutors(); // restaura la lista original de tutores activos
  }
  // Manejar verificación de número de control antes de mostrar el formulario completo
  const verifyBtn = document.getElementById("verifyBtn");
  const verifyInput = document.getElementById("numeroControlVerify");
  const verifyMessage = document.getElementById("verifyMessage");
  const formSections = document.getElementById("formSections");
  let isSubmitting = false;
  let isVerifying = false;
  verifyBtn.addEventListener("click", async () => {
    if (isVerifying) return;
    isVerifying = true;
    verifyMessage.textContent = "";
    const prevVerifyText = verifyBtn.textContent;
    verifyBtn.disabled = true;
    verifyBtn.textContent = "Verificando...";
    const nc = (verifyInput.value || "").trim();
    if (!nc) {
      verifyMessage.textContent =
        "Introduce un número de control para verificar.";
      verifyBtn.disabled = false;
      verifyBtn.textContent = prevVerifyText;
      isVerifying = false;
      return;
    }
    try {
      const r = await fetch(
        "/api/estudiantes/check?numeroControl=" + encodeURIComponent(nc),
      );
      const j = await r.json();
      if (r.ok) {
        if (j.exists && j.capturado) {
          verifyMessage.textContent =
            "Ya existe un registro para ese número de control.";
          verifyMessage.classList.remove("text-green-600");
          verifyMessage.classList.add("text-red-600");
        } else {
          verifyMessage.textContent = j.exists
            ? "Preregistro encontrado. Completa tu expediente."
            : "Número de control libre. Puedes continuar.";
          verifyMessage.classList.remove("text-red-600");
          verifyMessage.classList.add("text-green-600");

          // Si el formulario ya estaba abierto y tenía un NC prefijado
          // distinto al que se acaba de verificar, reseteamos todo y volvemos al
          // paso 1 antes de prefijar el nuevo NC.
          const normalized = nc.replace(/\s+/g, "").toUpperCase();
          const innerExisting = document.getElementById("numeroControl");
          const formEl = document.getElementById("fichaForm");
          if (formSections && !formSections.classList.contains("hidden")) {
            if (
              innerExisting &&
              innerExisting.getAttribute("data-prefilled") === "1" &&
              innerExisting.value &&
              innerExisting.value !== normalized
            ) {
              if (formEl) {
                formEl.reset();
                aplicarMinEdadPadres(null);
              }
              if (window.__updatePrioritySelectors)
                window.__updatePrioritySelectors();
              clearFieldErrors();
              currentStep = 1;
              actualizarPaso();
            }
          }

          // Mostrar el formulario completo y prefijar el campo
          formSections.classList.remove("hidden");
          // Prefill the internal numeroControl input if exists and make it readonly
          const inner = document.getElementById("numeroControl");
          if (inner) {
            inner.value = normalized;
            inner.readOnly = true;
            inner.classList.add("bg-gray-100");
            inner.setAttribute("data-prefilled", "1");
          }
          // Precargado sin datos: bloquear el selector con su tutor asignado;
          // NC libre: devolver el selector a su estado normal.
          if (j.exists && j.tutor_id) lockTutorSelect(j.tutor_id, j.tutor_nombre);
          else clearTutorSelectLock();
          // Scroll to form
          formSections.scrollIntoView({ behavior: "smooth" });
        }
      } else {
        verifyMessage.textContent = j.error || "Error al verificar.";
        verifyMessage.classList.add("text-red-600");
      }
    } catch (err) {
      console.error(err);
      verifyMessage.textContent = "Error de conexión al verificar.";
      verifyMessage.classList.add("text-red-600");
    } finally {
      verifyBtn.disabled = false;
      verifyBtn.textContent = prevVerifyText;
      isVerifying = false;
    }
  });
  let currentStep = 1;
  const totalSteps = 5;

  const prevBtn = document.getElementById("prevBtn");
  const nextBtn = document.getElementById("nextBtn");
  const submitBtn = document.getElementById("submitBtn");
  const form = document.getElementById("fichaForm");

  // La edad de los padres nunca puede ser menor que la del tutorado: el min de
  // padre_edad/madre_edad se actualiza con la edad calculada (validarPasoActual
  // usa checkValidity, así que el Siguiente lo respeta). min=null lo retira.
  function aplicarMinEdadPadres(min) {
    ["padre_edad", "madre_edad"].forEach((n) => {
      const el = document.querySelector(`[name="${n}"]`);
      if (!el) return;
      if (min === null || min === undefined || min === "" || !(Number(min) >= 0)) {
        el.removeAttribute("min");
      } else {
        el.min = String(min);
      }
    });
  }

  // Lógica de cálculo automático de Edad
  document.getElementById("fechaNacimiento").addEventListener("change", (e) => {
    const nac = new Date(e.target.value);
    const hoy = new Date();
    let edad = hoy.getFullYear() - nac.getFullYear();
    const m = hoy.getMonth() - nac.getMonth();
    if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) {
      edad--;
    }
    document.getElementById("edad").value = edad >= 0 ? edad : 0;
    aplicarMinEdadPadres(e.target.value && edad >= 0 ? edad : null);
  });

  // Teléfono móvil: sólo dígitos, máximo 10 (máscara de entrada).
  // Con complementa maxlength/pattern/inputmode del input: aunque el
  // navegador permita teclear letras o pegar espacios, aquí se limpian
  // al instante y jamás pasan al FormData.
  const telInput = document.getElementById("telefonoMovil");
  if (telInput) {
    telInput.addEventListener("input", () => {
      telInput.value = telInput.value.replace(/\D/g, "").slice(0, 10);
    });
  }

  // Toggles Condicionales
  setupToggle("hablaOtraLengua", "divCualLengua");
  setupToggle("trabajaActualmente", "divDatosTrabajo");
  setupToggle("reprobadoCurso", "divCausaReprobacion");
  setupToggle("haEstadoBecado", "divGradoBeca");
  setupToggle("haEstadoBecado", "divTipoBeca");
  setupToggle("padeceEnfermedad", "divCualEnfermedad");
  setupToggle("condicionFisica", "divCualCondicion");
  setupToggle("tomaMedicacion", "divCualMedicacion");
  setupToggle("haSidoOperado", "divDeQueOperacion");
  setupToggle("deseaApoyo", "divTipoApoyo");
  // Mostrar campo adicional cuando se elige 'Otros' en con quién vives
  setupShowOnValue("vivesCon", "OTROS", "divVivesConOtro");
  // Mostrar campo adicional cuando se elige 'OTRA' en tipo de beca
  // Motivo de satisfacción: solo se muestra si el estudiante responde "No"
  setupShowOnValue("satisfechoResultados", "0", "divMotivoNoSatisfecho");
  setupShowOnValue("tipoBeca", "OTRA", "divTipoBecaOtro");
  // Campos "especificar otro/otra" que antes quedaban siempre ocultos
  setupShowOnValue("tipoVivienda", "OTRA", "divTipoViviendaOtro");
  // Selects nuevos con especificador "Otro"
  setupShowOnValue("situacionEspecial", "OTRO", "divSituacionOtro");
  setupShowOnValue("apoyoEconomico", "OTRO", "divApoyoOtro");
  setupShowOnValue("causaProblemasSel", "OTRO", "divCausaEstudioOtro");
  setupShowOnValue("motivoTrabajo", "OTRO", "divMotivoTrabajoOtro");
  setupShowOnValue("cualEnfermedad", "OTRA", "cualEnfermedadOtro");
  setupShowOnValue("cualCondicion", "OTRA", "cualCondicionOtro");
  setupShowOnValue("tipoApoyo", "OTRO", "tipoApoyoOtro");
  // Transporte público: tiempo de traslado y costo solo si aplica
  setupToggle("usaTransportePublico", "divTransportePublico");
  setupToggle("usaTransportePublico", "divCostoTransporte");

  function setupToggle(selectId, targetDivId) {
    const sel = document.getElementById(selectId);
    const target = document.getElementById(targetDivId);
    if (!sel || !target) return;

    sel.addEventListener("change", () => {
      if (sel.value === "1") {
        target.classList.remove("cond-off");
      } else {
        target.classList.add("cond-off");
      }
    });
  }

  // Mostrar/ocultar un div cuando el select tiene un valor concreto
  function setupShowOnValue(selectId, valueToShow, targetDivId) {
    const sel = document.getElementById(selectId);
    const target = document.getElementById(targetDivId);
    if (!sel || !target) return;
    const update = () => {
      if (sel.value === valueToShow) target.classList.remove("cond-off");
      else target.classList.add("cond-off");
    };
    sel.addEventListener("change", update);
    // estado inicial
    update();
  }

  // Prioridades: evitar duplicados mostrando solo opciones no usadas
  function setupPrioritySelectors() {
    const selects = Array.from(document.querySelectorAll(".prio-select"));
    if (!selects || selects.length === 0) return;

    function update() {
      const used = selects
        .map((s) => s.value)
        .filter((v) => v !== "" && v !== null && v !== undefined);

      selects.forEach((s) => {
        Array.from(s.options).forEach((opt) => {
          if (opt.value === "") {
            opt.disabled = false;
            return;
          }
          // enable by default
          opt.disabled = false;
        });

        const otherUsed = used.filter((v) => v !== s.value);
        otherUsed.forEach((val) => {
          const opt = s.querySelector(`option[value="${val}"]`);
          if (opt) opt.disabled = true;
        });
      });
    }

    selects.forEach((s) => s.addEventListener("change", update));
    // expose update so we can call it after form.reset()
    window.__updatePrioritySelectors = update;
    // inicializar
    update();
  }

  // Validación adicional: asegurar que no haya prioridades repetidas antes de enviar
  function validatePriorities() {
    const selects = Array.from(document.querySelectorAll(".prio-select"));
    const used = {};
    let ok = true;
    // limpiar errores previos relacionados
    selects.forEach((s) => {
      const existing = form.querySelector(`[data-error-for="${s.name}"]`);
      if (existing) existing.remove();
      s.classList.remove("border-red-500");
    });

    for (const s of selects) {
      const v = s.value;
      if (!v) continue;
      if (used[v]) {
        // marca ambos como error
        showFieldError(s.name, "Prioridad duplicada");
        showFieldError(used[v], "Prioridad duplicada");
        ok = false;
      } else {
        used[v] = s.name;
      }
    }

    if (!ok) {
      // posicionar el primer error en vista
      const firstErr = form.querySelector("[data-error-for]");
      if (firstErr)
        firstErr.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    return ok;
  }

  // Navegación por pasos
  nextBtn.addEventListener("click", () => {
    if (validarPasoActual()) {
      if (currentStep < totalSteps) {
        currentStep++;
        actualizarPaso();
      }
    } else {
      alert(
        "Por favor completa todos los campos requeridos en esta sección antes de avanzar.",
      );
    }
  });

  prevBtn.addEventListener("click", () => {
    if (currentStep > 1) {
      currentStep--;
      actualizarPaso();
    }
  });

  function actualizarPaso() {
    // Visibilidad de contenedores
    document.querySelectorAll(".form-step").forEach((el, index) => {
      el.classList.toggle("hidden", index + 1 !== currentStep);
    });

    // Actualizar Pestañas de Navegación
    document.querySelectorAll(".step-tab").forEach((tab, index) => {
      if (index + 1 === currentStep) {
        tab.className =
          "step-tab text-blue-800 font-bold border-b-2 border-blue-800 pb-1";
      } else {
        tab.className = "step-tab text-gray-500";
      }
    });

    // Visibilidad de botones
    prevBtn.classList.toggle("hidden", currentStep === 1);
    nextBtn.classList.toggle("hidden", currentStep === totalSteps);
    submitBtn.classList.toggle("hidden", currentStep !== totalSteps);

    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function validarPasoActual() {
    const stepDiv = document.getElementById(`step-${currentStep}`);
    // Checar TODOS los controles del paso (no solo required): asi un valor
    // fuera de rango (ej. edad negativa con min=0) se frena AQUI, en el paso
    // visible; si no, el navegador bloquearia el envio final sin feedback
    // porque el campo invalido estaria en un paso oculto (no enfocable).
    const fields = stepDiv.querySelectorAll("input, select, textarea");
    for (let input of fields) {
      if (!input.checkValidity()) {
        input.focus();
        if (typeof input.reportValidity === "function") input.reportValidity();
        return false;
      }
    }
    return true;
  }

  // Procesamiento Final del Formulario (Submit JSON)
  // Controles que viven dentro de bloques ocultos (cond-off), incluidos los
  // inputs con la propia clase. Lo que se escribió mientras el bloque estaba
  // visible no debe guardarse si al enviar quedó oculto (ej. condición
  // física = "No"). Solo se deshabilitan los que estén habilitados, para no
  // tocar el select de tutor (deshabilitado a propósito).
  function controlesOcultos(form) {
    const set = new Set();
    form.querySelectorAll(".cond-off").forEach((el) => {
      if (el.matches("input, textarea, select")) set.add(el);
      el.querySelectorAll("input, textarea, select").forEach((c) => set.add(c));
    });
    return Array.from(set).filter((c) => !c.disabled);
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (isSubmitting) return;
    isSubmitting = true;

    // Disable navigation buttons to prevent multiple actions while submitting
    nextBtn.disabled = true;
    prevBtn.disabled = true;
    const prevSubmitText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = "Guardando...";

    // Los bloques ocultos (cond-off) se excluyen del envío deshabilitándolos
    // solo durante la construcción del FormData: el valor permanece en el DOM
    // por si el tutor regresa y reactiva el bloque.
    const ocultos = controlesOcultos(form);
    ocultos.forEach((c) => {
      c.disabled = true;
    });
    const formData = new FormData(form);
    ocultos.forEach((c) => {
      c.disabled = false;
    });

    const data = {};

    formData.forEach((value, key) => {
      // Parsear Booleans de strings '1'/'0'
      if (value === "1") value = true;
      if (value === "0") value = false;

      // Transformar datos numéricos cuando aplique
      if (!isNaN(value) && value !== "" && typeof value !== "boolean") {
        const n = Number(value);
        if (!Number.isNaN(n)) value = n;
      }

      // Manejar múltiples valores con mismo name (checkboxes)
      if (data.hasOwnProperty(key)) {
        if (!Array.isArray(data[key])) data[key] = [data[key]];
        data[key].push(value);
      } else {
        data[key] = value;
      }
    });

    // Validar prioridades antes de componer el payload
    if (!validatePriorities()) {
      // restaurar botones y estado
      isSubmitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = prevSubmitText || "Guardar Registro";
      nextBtn.disabled = false;
      prevBtn.disabled = false;
      return;
    }

    // Normalizar claves a snake_case (acepta tanto camelCase como snake_case)
    function camelToSnake(s) {
      return String(s)
        .replace(/([A-Z])/g, "_$1")
        .toLowerCase();
    }

    const payload = {};
    Object.entries(data).forEach(([k, v]) => {
      const sk = camelToSnake(k);
      payload[sk] = v;
    });

    // Normalizar valor de 'vives_con' si se usó la opción 'OTROS' y hay un campo especificador
    if (payload.vives_con === "OTROS" && payload.vives_con_otro) {
      payload.vives_con = payload.vives_con_otro;
      delete payload.vives_con_otro;
    }

    // Causa de problemas de estudio: select único (spec) + "Otro" especificador
    const causas = [];
    const causaSel = payload.causa_problemas_sel;
    if (causaSel && causaSel !== "OTRO") causas.push(causaSel);
    if (
      causaSel === "OTRO" &&
      payload.causa_problemas_estudio &&
      String(payload.causa_problemas_estudio).trim()
    )
      causas.push(String(payload.causa_problemas_estudio).trim());
    payload.causa_problemas_estudio_compuesta = causas.length
      ? causas.join("; ")
      : null;

    // Normalizar tipo de apoyo si se especificó 'OTRO'
    if (payload.tipo_apoyo === "OTRO" && payload.tipo_apoyo_otro) {
      payload.tipo_apoyo = payload.tipo_apoyo_otro;
      delete payload.tipo_apoyo_otro;
    }

    // Fusionar los demás campos "..._otro/otra" con su select
    if (payload.tipo_beca === "OTRA" && payload.tipo_beca_otro) {
      payload.tipo_beca = payload.tipo_beca_otro;
      delete payload.tipo_beca_otro;
    }
    if (payload.cual_enfermedad === "OTRA" && payload.cual_enfermedad_otro) {
      payload.cual_enfermedad = payload.cual_enfermedad_otro;
      delete payload.cual_enfermedad_otro;
    }
    if (payload.cual_condicion === "OTRA" && payload.cual_condicion_otro) {
      payload.cual_condicion = payload.cual_condicion_otro;
      delete payload.cual_condicion_otro;
    }
    if (payload.situacion_especial === "OTRO" && payload.situacion_especial_otro) {
      payload.situacion_especial = payload.situacion_especial_otro;
      delete payload.situacion_especial_otro;
    }
    if (payload.apoyo_economico === "OTRO" && payload.apoyo_economico_otro) {
      payload.apoyo_economico = payload.apoyo_economico_otro;
      delete payload.apoyo_economico_otro;
    }
    if (payload.motivo_trabajo === "OTRO" && payload.motivo_trabajo_otro) {
      payload.motivo_trabajo = payload.motivo_trabajo_otro;
      delete payload.motivo_trabajo_otro;
    }

    // Asegurar honeypot y token CSRF en snake_case
    payload.hp_email = payload.hp_email || payload.hpEmail || "";
    payload.csrf_token =
      document.getElementById("csrfToken")?.value ||
      window.__csrfToken ||
      payload.csrf_token ||
      payload.csrfToken ||
      "";

    // Tutor precargado: el select viene deshabilitado y FormData lo excluye,
    // así que se inyecta aquí (es exactamente el tutor que asignó el admin).
    const tutorSel = document.getElementById("tutorSelect");
    if (tutorSel && tutorSel.disabled && tutorSel.value) {
      payload.tutor_id = Number(tutorSel.value);
    }

    // Limpia errores previos antes de enviar
    clearFieldErrors();

    try {
      const response = await fetch("/api/estudiantes", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        await response.json();
        alert("¡Ficha capturada y registrada correctamente!");
        // Resetear formulario automáticamente y volver al panel de verificación
        form.reset();
        // Después de limpiar el formulario, restaurar opciones de prioridad
        if (window.__updatePrioritySelectors)
          window.__updatePrioritySelectors();
        clearFieldErrors();
        // Restaurar estados de navegación y volver al primer paso
        currentStep = 1;
        actualizarPaso();
        submitBtn.textContent = prevSubmitText || "Guardar Registro";
        nextBtn.disabled = false;
        prevBtn.disabled = false;
        submitBtn.disabled = false;
        isSubmitting = false;
        // Ocultar secciones del formulario y mostrar el panel de verificación
        formSections.classList.add("hidden");
        clearTutorSelectLock();
        const verifyPanel = document.getElementById("verifyPanel");
        if (verifyPanel) verifyPanel.classList.remove("hidden");
        // Limpiar inputs relacionados con numero control
        const inner = document.getElementById("numeroControl");
        if (inner) {
          inner.value = "";
          inner.readOnly = false;
          inner.classList.remove("bg-gray-100");
          inner.removeAttribute("data-prefilled");
        }
        const verifyInput = document.getElementById("numeroControlVerify");
        if (verifyInput) {
          verifyInput.value = "";
          verifyInput.focus();
        }
      } else if (response.status === 422) {
        const err = await response.json().catch(() => ({}));
        console.error("Validation errors", err);
        clearFieldErrors();
        // Mostrar errores por campo si vienen en 'fields'
        if (err.fields && typeof err.fields === "object") {
          let firstEl = null;
          for (const [field, msg] of Object.entries(err.fields)) {
            showFieldError(field, msg);
            if (!firstEl) firstEl = findField(field);
          }
          if (firstEl)
            firstEl.scrollIntoView({ behavior: "smooth", block: "center" });
          return;
        }
        // Mostrar campos faltantes si vienen en 'missing'
        if (Array.isArray(err.missing) && err.missing.length > 0) {
          let firstEl = null;
          for (const fname of err.missing) {
            showFieldError(fname, "Campo requerido");
            if (!firstEl) firstEl = findField(fname);
          }
          if (firstEl)
            firstEl.scrollIntoView({ behavior: "smooth", block: "center" });
          return;
        }
        // Si no hay campos específicos, mostrar mensaje genérico
        alert(
          "Error al guardar ficha: " +
            (err.error || JSON.stringify(err) || response.statusText),
        );
      } else {
        const err = await response.json().catch(() => ({}));
        console.error("Error response", response.status, err);
        alert("Error al guardar ficha: " + (err.error || response.statusText));
      }
    } catch (err) {
      console.error("Error al guardar registro:", err);
      alert("Hubo un problema al procesar la información.");
    } finally {
      // Asegurarnos de limpiar el flag si ocurrió cualquier error
      isSubmitting = false;
      submitBtn.disabled = false;
      submitBtn.textContent = prevSubmitText || "Guardar Registro";
      nextBtn.disabled = false;
      prevBtn.disabled = false;
    }
  });

  // No hay botón de editar número — el formulario se resetea tras guardar.

  // Reset para registrar otro estudiante
  const resetBtn = document.getElementById("resetBtn");
  if (resetBtn) {
    resetBtn.addEventListener("click", () => {
      // Limpiar formulario y estado
      form.reset();
      // Recalcular/select habilitar opciones de prioridad
      if (window.__updatePrioritySelectors) window.__updatePrioritySelectors();
      clearFieldErrors();
      // Ocultar secciones del formulario y mostrar el panel de verificación
      formSections.classList.add("hidden");
      clearTutorSelectLock();
      // volver al primer paso
      currentStep = 1;
      actualizarPaso();
      const verifyPanel = document.getElementById("verifyPanel");
      if (verifyPanel) verifyPanel.classList.remove("hidden");
      // Ocultar el propio resetBtn
      resetBtn.classList.add("hidden");
      // Limpiar mensaje de verificación
      const verifyMsg = document.getElementById("verifyMessage");
      if (verifyMsg) verifyMsg.textContent = "";
      // Foco en input de verificación
      const verifyInput = document.getElementById("numeroControlVerify");
      if (verifyInput) {
        verifyInput.value = "";
        verifyInput.focus();
      }
    });
  }

  // Inicializar selectores de prioridades
  setupPrioritySelectors();

  function clearFieldErrors() {
    form.querySelectorAll("[data-error-for]").forEach((el) => el.remove());
    form
      .querySelectorAll(".border-red-500")
      .forEach((el) => el.classList.remove("border-red-500"));
  }

  // Localizar un campo por su nombre (acepta snake_case y camelCase)
  function findField(fieldName) {
    if (!fieldName) return null;
    const snake = String(fieldName).replace(/([A-Z])/g, "_$1").toLowerCase();
    return (
      form.querySelector(`[name="${fieldName}"]`) ||
      form.querySelector(`[name="${snake}"]`)
    );
  }

  function showFieldError(fieldName, message) {
    const el = findField(fieldName);
    if (!el) return;
    const errKey = el.getAttribute("name") || fieldName;
    el.classList.add("border-red-500");
    const p = document.createElement("p");
    p.className = "text-red-600 text-sm mt-1";
    p.setAttribute("data-error-for", errKey);
    p.textContent = message;
    // Insertar inmediatamente después del elemento (si es input dentro de div, colocarlo al final del contenedor)
    if (el.parentNode) {
      // Si el siguiente hermano ya es un error para ese campo, reemplazar
      const existing = el.parentNode.querySelector(
        `[data-error-for="${errKey}"]`,
      );
      if (existing) existing.textContent = message;
      else el.parentNode.insertBefore(p, el.nextSibling);
    }
  }
});
