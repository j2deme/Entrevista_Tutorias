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
    const sel = document.getElementById('tutorSelect');
    if (!sel) return;
    try {
      const r = await fetch('/api/tutores');
      const j = await r.json();
      sel.innerHTML = '';
      if (j && Array.isArray(j.tutores) && j.tutores.length > 0) {
        sel.appendChild(new Option('Selecciona un tutor...', '', true, false));
        j.tutores.forEach(t => {
          sel.appendChild(new Option(t.nombre, t.id));
        });
      } else {
        sel.appendChild(new Option('No hay tutores activos', '', true, false));
        sel.disabled = true;
      }
    } catch (err) {
      sel.innerHTML = '';
      sel.appendChild(new Option('Error cargando tutores', '', true, false));
      sel.disabled = true;
    }
  }
  loadTutors();
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
        if (j.exists) {
          verifyMessage.textContent =
            "Ya existe un registro para ese número de control.";
          verifyMessage.classList.remove("text-green-600");
          verifyMessage.classList.add("text-red-600");
          // Optionally show details
        } else {
          verifyMessage.textContent =
            "Número de control libre. Puedes continuar.";
          verifyMessage.classList.remove("text-red-600");
          verifyMessage.classList.add("text-green-600");

          // Si el formulario ya estaba abierto y tenía un NC prefijado
          // distinto al que se acaba de verificar, reseteamos todo y volvemos al
          // paso 1 antes de prefijar el nuevo NC.
          const normalized = nc.replace(/\s+/g, "").toUpperCase();
          const innerExisting = document.querySelector(
            'input[name="numeroControl"]',
          );
          const formEl = document.getElementById("fichaForm");
          if (formSections && !formSections.classList.contains("hidden")) {
            if (
              innerExisting &&
              innerExisting.getAttribute("data-prefilled") === "1" &&
              innerExisting.value &&
              innerExisting.value !== normalized
            ) {
              if (formEl) formEl.reset();
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
          const inner = document.querySelector('input[name="numeroControl"]');
          if (inner) {
            inner.value = normalized;
            inner.readOnly = true;
            inner.classList.add("bg-gray-100");
            inner.setAttribute("data-prefilled", "1");
          }
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
  });

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
  setupToggle("tienePreocupacionCurso", "divQuePreocupa");
  setupToggle("deseaApoyo", "divTipoApoyo");
  // Mostrar campo adicional cuando se elige 'Otros' en con quién vives
  setupShowOnValue("vivesCon", "OTROS", "divVivesConOtro");
  // Mostrar campo adicional cuando se elige 'OTRA' en tipo de beca
  setupShowOnValue("tipoBeca", "OTRA", "divTipoBecaOtro");

  function setupToggle(selectId, targetDivId) {
    const sel = document.getElementById(selectId);
    const target = document.getElementById(targetDivId);
    if (!sel || !target) return;

    sel.addEventListener("change", () => {
      if (sel.value === "1") {
        target.classList.remove("hidden");
      } else {
        target.classList.add("hidden");
      }
    });
  }

  // Mostrar/ocultar un div cuando el select tiene un valor concreto
  function setupShowOnValue(selectId, valueToShow, targetDivId) {
    const sel = document.getElementById(selectId);
    const target = document.getElementById(targetDivId);
    if (!sel || !target) return;
    const update = () => {
      if (sel.value === valueToShow) target.classList.remove("hidden");
      else target.classList.add("hidden");
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
    const requiredInputs = stepDiv.querySelectorAll("[required]");
    for (let input of requiredInputs) {
      if (!input.value.trim()) {
        input.focus();
        return false;
      }
    }
    return true;
  }

  // Procesamiento Final del Formulario (Submit JSON)
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

    const formData = new FormData(form);
    const data = {};

    formData.forEach((value, key) => {
      // Parsear Booleans de strings '1'/'0'
      if (value === "1") value = true;
      if (value === "0") value = false;

      // Transformar datos numéricos
      if (!isNaN(value) && value !== "" && typeof value !== "boolean") {
        value = Number(value);
      }

      data[key] = value;
    });

    // Normalizar valor de 'vivesCon' si se usó la opción 'OTROS' y hay un campo especificador
    if (data.vivesCon === "OTROS" && data.vivesConOtro) {
      data.vivesCon = data.vivesConOtro;
      delete data.vivesConOtro;
    }

    // Componer causas de problemas de estudio desde checkboxes + campo libre
    const causas = [];
    if (data.causa_me_organizo_mal) causas.push("Me organizo mal");
    if (data.causa_no_me_interesa) causas.push("No me interesa");
    if (data.causa_por_distrarme) causas.push("Por distraerme en otra cosa");
    if (data.causa_no_tengo_lugar)
      causas.push("No tengo lugar adecuado para estudiar");
    if (data.causaProblemasEstudio && String(data.causaProblemasEstudio).trim())
      causas.push(String(data.causaProblemasEstudio).trim());
    data.causaProblemasEstudioComposed = causas.length
      ? causas.join("; ")
      : null;

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

    // Estructuración de Payloads anidados
    const payload = {
      datosPersonales: {
        nombreCompleto: data.nombreCompleto,
        numeroControl: data.numeroControl || null,
        fechaNacimiento: data.fechaNacimiento,
        lugarNacimiento: data.lugarNacimiento,
        edad: data.edad,
        genero: data.genero,
        estadoCivil: data.estadoCivil,
        domicilioFamiliar: data.domicilioFamiliar,
        localidadFamiliar: data.localidadFamiliar,
        codigoPostal: String(data.codigoPostal),
        zona: data.zona,
        tipoVivienda: data.tipoVivienda,
        telefonoMovil: String(data.telefonoMovil),
        hablaOtraLengua: data.hablaOtraLengua,
        cualLengua: data.hablaOtraLengua ? data.cualLengua : null,
      },
      datosFamiliares: {
        padre: {
          nombre: data.padreNombre,
          vive: data.padreVive,
          edad: data.padreEdad,
          profesion: data.padreProfesion,
          nivelEstudios: data.padreNivelEstudios,
          ocupacion: data.padreOcupacion,
        },
        madre: {
          nombre: data.madreNombre,
          vive: data.madreVive,
          edad: data.madreEdad,
          profesion: data.madreProfesion,
          nivelEstudios: data.madreNivelEstudios,
          ocupacion: data.madreOcupacion,
        },
        numIntegrantesFamilia: data.numIntegrantesFamilia,
        numHermanos: data.numHermanos,
        lugarQueOcupa: data.lugarQueOcupa,
        vivesCon: data.vivesCon,
        situacionEspecial: data.situacionEspecial,
        relacionPadres: data.relacionPadres,
        trabajaActualmente: data.trabajaActualmente,
        horasTrabajo: data.horasTrabajo,
        empresaTrabajo: data.empresaTrabajo,
        motivoTrabajo: data.motivoTrabajo,
        tiempoTrasladoEscuela: data.tiempoTrasladoEscuela,
        apoyoEconomico: data.apoyoEconomico,
        ingresoMensualFamiliar: data.ingresoMensualFamiliar,
      },
      datosEscolares: {
        institucionProcedencia: data.institucionProcedencia,
        localidad: data.localidadEscuela,
        generacionEgreso: data.generacionEgreso,
        promedio: data.promedio,
        rendimientoEscolar: data.rendimientoEscolar,
        reprobadoCurso: data.reprobadoCurso,
        causaReprobacion: data.causaReprobacion,
        satisfechoResultados: data.satisfechoResultados,
        motivoSatisfaccion: data.motivoSatisfaccion,
        haEstadoBecado: data.haEstadoBecado,
        gradoBeca: data.gradoBeca,
        tipoBeca:
          data.tipoBeca === "OTRA"
            ? data.tipoBecaOtro || "OTRA"
            : data.tipoBeca,
        materiasFavoritas: data.materiasFavoritas,
        habilidades: {
          comprensionLectora:
            data.habilidades?.comprensionLectora || data.hab_comprensionLectora,
          comprensionOral:
            data.habilidades?.comprensionOral || data.hab_comprensionOral,
          resolucionProblemas:
            data.habilidades?.resolucionProblemas ||
            data.hab_resolucionProblemas,
          expresionOral:
            data.habilidades?.expresionOral || data.hab_expresionOral,
          expresionEscrita:
            data.habilidades?.expresionEscrita || data.hab_expresionEscrita,
          vocabulario: data.habilidades?.vocabulario || data.hab_vocabulario,
          calculo: data.habilidades?.calculo || data.hab_calculo,
          expresionGrafica:
            data.habilidades?.expresionGrafica || data.hab_expresionGrafica,
          ortografia: data.habilidades?.ortografia || data.hab_ortografia,
        },
        reaccionPadresCalificaciones: data.reaccionPadresCalificaciones,
      },
      datosMedicos: {
        padeceEnfermedad: data.padeceEnfermedad,
        cualEnfermedad: data.cualEnfermedad || null,
        condicionFisica: data.condicionFisica,
        cualCondicion: data.cualCondicion || null,
        tomaMedicacion: data.tomaMedicacion,
        cualMedicacion: data.cualMedicacion || null,
        haSidoOperado: data.haSidoOperado,
        deQueOperacion: data.deQueOperacion || null,
      },
      expectativasIngreso: {
        carreraGusta: data.carreraGusta,
        queMasAtrae: data.queMasAtrae,
        tienePreocupacionCurso: data.tienePreocupacionCurso,
        quePreocupa: data.tienePreocupacionCurso ? data.quePreocupa : null,
        estudioEs: data.estudioEs,
        deseaApoyoInstitucional: data.deseaApoyoInstitucional,
        tipoApoyo: data.deseaApoyoInstitucional ? data.tipoApoyo : null,
        pasatiempoFavorito: data.pasatiempoFavorito,
        causaProblemasEstudio: data.causaProblemasEstudioComposed,
        preferenciaTrabajo: data.preferenciaTrabajo,
        preferenciaEnClase: data.preferenciaEnClase || null,
        formaPasarTiempo: data.formaPasarTiempo || null,
        formaHacerAmigos: data.formaHacerAmigos || null,
        cuentaLugarAdecuado: data.cuentaLugarAdecuado,
        prioridadesProfesor: {
          explicacionClara: data.prio_explicacionClara,
          entiendaJovenes: data.prio_entiendaJovenes,
          justoEvaluar: data.prio_justoEvaluar,
          permitaPreguntar: data.prio_permitaPreguntar,
          respeteEImponga: data.prio_respeteEImponga,
          noSeEnoje: data.prio_noSeEnoje,
        },
      },
    };

    // Añadir campos de control: honeypot y token CSRF
    payload.hp_email = data.hp_email || "";
    payload.csrfToken =
      document.getElementById("csrfToken")?.value || window.__csrfToken || "";

    console.log(
      "Enviando JSON estructurado a Backend:",
      JSON.stringify(payload, null, 2),
    );

    // Limpia errores previos antes de enviar
    clearFieldErrors();

    try {
      const response = await fetch("/api/estudiantes", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        const body = await response.json();
        console.log("Guardado OK", body);
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
        const verifyPanel = document.getElementById("verifyPanel");
        if (verifyPanel) verifyPanel.classList.remove("hidden");
        // Limpiar inputs relacionados con numero control
        const inner = document.querySelector('input[name="numeroControl"]');
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
            if (!firstEl) {
              const el = form.querySelector(`[name="${field}"]`);
              if (el) firstEl = el;
            }
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
            if (!firstEl) {
              const el = form.querySelector(`[name="${fname}"]`);
              if (el) firstEl = el;
            }
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

  function showFieldError(fieldName, message) {
    // Intentar localizar el elemento por name
    const el =
      form.querySelector(`[name="${fieldName}"]`) ||
      form.querySelector(`[name="${fieldName}"]`);
    if (!el) return;
    el.classList.add("border-red-500");
    const p = document.createElement("p");
    p.className = "text-red-600 text-sm mt-1";
    p.setAttribute("data-error-for", fieldName);
    p.textContent = message;
    // Insertar inmediatamente después del elemento (si es input dentro de div, colocarlo al final del contenedor)
    if (el.parentNode) {
      // Si el siguiente hermano ya es un error para ese campo, reemplazar
      const existing = el.parentNode.querySelector(
        `[data-error-for="${fieldName}"]`,
      );
      if (existing) existing.textContent = message;
      else el.parentNode.insertBefore(p, el.nextSibling);
    }
  }
});
