(function () {
  const prefersReducedMotion =
    typeof window.matchMedia === "function" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const coordinateElements = (() => {
    const container = document.querySelector(".map-coords");
    if (!container) return null;
    const lat = container.querySelector('[data-coordinate="lat"]');
    const lon = container.querySelector('[data-coordinate="lon"]');
    if (!lat || !lon) return null;
    return { lat, lon };
  })();

  const directiveForm = document.querySelector("[data-directive-form]");
  const directiveLog = document.querySelector("[data-directive-log]");
  const directiveNicknameInput = directiveForm
    ? directiveForm.querySelector("input[name='nickname']")
    : null;
  const directiveMessageInput = directiveForm
    ? directiveForm.querySelector("input[name='message']")
    : null;
  const directiveStatus = directiveForm
    ? directiveForm.querySelector("[data-directive-status]")
    : null;
  const directiveTokenInput = directiveForm
    ? directiveForm.querySelector("input[name='_csrf']")
    : null;

  const channelList = document.querySelector(".channel-list");
  const channelEntries = channelList
    ? Array.from(channelList.querySelectorAll(".channel-entry"))
    : [];

  const scrambleTrack = document.querySelector(".scramble-track");
  const queueItems = document.querySelectorAll(".queue-item");

  const encryptedFile = document.querySelector(".encrypted-file");
  const decryptCommand = encryptedFile
    ? encryptedFile.querySelector("[data-command]")
    : null;
  const commandText = decryptCommand
    ? decryptCommand.querySelector(".encrypted-file__command-text")
    : null;
  const commandRotor = decryptCommand
    ? decryptCommand.querySelector(".encrypted-file__command-rotor")
    : null;
  const statusText = encryptedFile
    ? encryptedFile.querySelector(".encrypted-file__status-text")
    : null;
  const statusAnnouncer = encryptedFile
    ? encryptedFile.querySelector(".encrypted-file__status-announcer")
    : null;
  const decryptionSequence = document.querySelector(".decryption-sequence");
  const sequenceTerminalLines = decryptionSequence
    ? Array.from(decryptionSequence.querySelectorAll("[data-terminal-line]"))
    : [];
  const sequenceCursor = decryptionSequence
    ? decryptionSequence.querySelector(".terminal-cursor")
    : null;
  const dossierOverlay = document.querySelector(".dossier-overlay");
  const dossierCloseButton = dossierOverlay
    ? dossierOverlay.querySelector(".dossier__close")
    : null;
  const dossierViewer = dossierOverlay
    ? dossierOverlay.querySelector(".dossier__viewer")
    : null;
  const dossierFrame = dossierOverlay
    ? dossierOverlay.querySelector(".dossier__iframe")
    : null;
  const dossierViewerStatus = dossierOverlay
    ? dossierOverlay.querySelector(".dossier__viewer-status")
    : null;
  const dossierLanguageButtons = dossierOverlay
    ? Array.from(dossierOverlay.querySelectorAll("[data-dossier-lang]"))
    : [];
  const dossierDownloadLinks = dossierOverlay
    ? Array.from(dossierOverlay.querySelectorAll("[data-download-lang]"))
    : [];

  const remoteResumeUrl =
    "https://assets.scriptslug.com/live/pdf/scripts/the-sopranos-105-college-1999.pdf";

  // 使用统一的在线 PDF 简历链接临时占位，避免本地资源缺失导致的加载失败
  const dossierManifest = [
    {
      lang: "en",
      label: "English",
      src: remoteResumeUrl,
      filename: "resume-en.pdf",
    },
    {
      lang: "zh",
      label: "中文",
      src: remoteResumeUrl,
      filename: "resume-zh.pdf",
    },
    {
      lang: "ru",
      label: "Русский",
      src: remoteResumeUrl,
      filename: "resume-ru.pdf",
    },
  ];

  const pdfCache = new Map();
  const pdfPending = new Map();

  const defaultDossierLanguage = "en";
  let activeDossierLanguage = defaultDossierLanguage;

  const rotorFrames = ["-", "\\", "|", "/"];
  let rotorIndex = 0;
  let rotorTimerId = null;
  let standbyTimerId = null;
  let isDecrypting = false;
  let hasAnnouncedProgress = false;
  let restoreFocusTarget = null;

  const mapScreen = document.querySelector(".map-screen");
  const mapLoading = document.querySelector(".map-loading");

  const wait = (duration) =>
    new Promise((resolve) => {
      window.setTimeout(resolve, duration);
    });

  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

  const DECRYPTION_SEQUENCE_TIMINGS = {
    regular: {
      static: 1000,
      terminal: 4000,
      converge: 500,
      complete: 200,
    },
    reduced: {
      static: 320,
      terminal: 1280,
      converge: 160,
      complete: 64,
    },
  };

  const getDecryptionTimings = () =>
    prefersReducedMotion
      ? DECRYPTION_SEQUENCE_TIMINGS.reduced
      : DECRYPTION_SEQUENCE_TIMINGS.regular;

  const setEncryptedFileState = (state) => {
    if (!encryptedFile) return;
    encryptedFile.dataset.state = state;
  };

  const setCommandLabel = (label) => {
    if (!commandText) return;
    commandText.textContent = label;
  };

  const announceStatus = (message) => {
    if (!statusAnnouncer) return;
    statusAnnouncer.textContent = "";
    if (message) {
      statusAnnouncer.textContent = message;
    }
  };

  const startRotor = () => {
    if (!commandRotor) return;
    if (prefersReducedMotion) {
      commandRotor.textContent = "-";
      return;
    }
    if (rotorTimerId) {
      window.clearInterval(rotorTimerId);
    }
    rotorTimerId = window.setInterval(() => {
      rotorIndex = (rotorIndex + 1) % rotorFrames.length;
      commandRotor.textContent = rotorFrames[rotorIndex];
    }, 125);
  };

  const stopRotor = () => {
    if (rotorTimerId) {
      window.clearInterval(rotorTimerId);
      rotorTimerId = null;
    }
    if (commandRotor) {
      commandRotor.textContent = "-";
    }
  };

  const clearTerminalLines = () => {
    sequenceTerminalLines.forEach((line) => {
      line.textContent = "";
    });
    if (sequenceCursor) {
      sequenceCursor.style.visibility = "hidden";
    }
  };

  const typeTerminalLine = (element, text, duration) =>
    new Promise((resolve) => {
      if (!element) {
        resolve();
        return;
      }
      if (prefersReducedMotion || !duration) {
        element.textContent = text;
        resolve();
        return;
      }
      const characters = Array.from(text);
      const totalCharacters = characters.length;
      if (totalCharacters === 0) {
        element.textContent = "";
        resolve();
        return;
      }
      const start =
        typeof performance !== "undefined" && performance.now
          ? performance.now()
          : Date.now();
      let lastIndex = 0;
      const scheduleFrame =
        typeof window.requestAnimationFrame === "function"
          ? window.requestAnimationFrame.bind(window)
          : (callback) => window.setTimeout(callback, 16);
      const render = () => {
        const now =
          typeof performance !== "undefined" && performance.now
            ? performance.now()
            : Date.now();
        const elapsed = now - start;
        const progress = clamp(elapsed / duration, 0, 1);
        const nextIndex = Math.max(1, Math.ceil(progress * totalCharacters));
        if (nextIndex !== lastIndex) {
          lastIndex = nextIndex;
          element.textContent = characters.slice(0, nextIndex).join("");
        }
        if (progress >= 1) {
          element.textContent = text;
          resolve();
          return;
        }
        scheduleFrame(render);
      };
      render();
    });

  const runTerminalPhase = async (totalDuration) => {
    if (!sequenceTerminalLines.length) {
      await wait(totalDuration);
      return;
    }
    const start =
      typeof performance !== "undefined" && performance.now
        ? performance.now()
        : Date.now();
    if (sequenceCursor) {
      sequenceCursor.style.visibility = "visible";
    }
    const perLineDuration = totalDuration / sequenceTerminalLines.length;
    for (const line of sequenceTerminalLines) {
      const payload = line.dataset.terminalLine ?? "";
      await typeTerminalLine(line, payload, perLineDuration);
    }
    if (sequenceCursor) {
      sequenceCursor.style.visibility = "hidden";
    }
    const end =
      typeof performance !== "undefined" && performance.now
        ? performance.now()
        : Date.now();
    const elapsed = end - start;
    if (elapsed < totalDuration) {
      await wait(totalDuration - elapsed);
    }
  };

  const runDecryptionSequence = async () => {
    const timings = getDecryptionTimings();
    const totalDuration =
      timings.static +
      timings.terminal +
      timings.converge +
      timings.complete;
    if (!decryptionSequence) {
      await wait(totalDuration);
      return;
    }
    clearTerminalLines();
    decryptionSequence.classList.add("is-active");
    decryptionSequence.dataset.phase = "static";
    await wait(timings.static);
    decryptionSequence.dataset.phase = "terminal";
    await runTerminalPhase(timings.terminal);
    decryptionSequence.dataset.phase = "converge";
    await wait(timings.converge);
    decryptionSequence.dataset.phase = "complete";
    await wait(timings.complete);
    decryptionSequence.classList.remove("is-active");
    decryptionSequence.dataset.phase = "idle";
    clearTerminalLines();
  };

  const getDossierManifestEntry = (lang) =>
    dossierManifest.find((entry) => entry.lang === lang);

  const updateDownloadLinks = (activeLang) => {
    dossierDownloadLinks.forEach((link) => {
      const lang = link.dataset.downloadLang;
      const entry = getDossierManifestEntry(lang);
      if (!entry) {
        return;
      }
      link.href = entry.src;
      link.setAttribute("download", entry.filename);
      if (lang === activeLang) {
        link.classList.add("is-active");
      } else {
        link.classList.remove("is-active");
      }
    });
  };

  const setViewerState = (state, message) => {
    if (dossierViewer) {
      dossierViewer.dataset.state = state;
    }
    if (typeof message === "string" && dossierViewerStatus) {
      dossierViewerStatus.textContent = message;
    }
  };

  const isSameOrigin = (url) => {
    try {
      const parsed = new URL(url, window.location.href);
      return parsed.origin === window.location.origin;
    } catch (error) {
      console.warn("无法解析 PDF 链接，回退到直接加载。", error);
      return false;
    }
  };

  const preloadPdf = (lang) => {
    if (pdfCache.has(lang)) {
      return Promise.resolve(pdfCache.get(lang));
    }
    if (pdfPending.has(lang)) {
      return pdfPending.get(lang);
    }
    const entry = getDossierManifestEntry(lang);
    if (!entry) {
      return Promise.resolve(null);
    }
    if (!isSameOrigin(entry.src) || typeof window.fetch !== "function") {
      pdfCache.set(lang, entry.src);
      return Promise.resolve(entry.src);
    }
    const task = window
      .fetch(entry.src)
      .then((response) => {
        if (!response.ok) {
          throw new Error(`Failed to load dossier (${response.status})`);
        }
        return response.blob();
      })
      .then((blob) => {
        const objectUrl = URL.createObjectURL(blob);
        pdfCache.set(lang, objectUrl);
        return objectUrl;
      })
      .catch((error) => {
        console.error(error);
        return null;
      })
      .finally(() => {
        pdfPending.delete(lang);
      });
    pdfPending.set(lang, task);
    return task;
  };

  const setDossierLanguage = async (lang) => {
    if (!dossierViewer || !dossierFrame) {
      return;
    }
    const entry = getDossierManifestEntry(lang);
    if (!entry) {
      return;
    }
    activeDossierLanguage = lang;
    dossierLanguageButtons.forEach((button) => {
      if (button.dataset.dossierLang === lang) {
        button.classList.add("is-active");
      } else {
        button.classList.remove("is-active");
      }
    });
    updateDownloadLinks(lang);
    setViewerState("loading", `${entry.label} dossier loading…`);
    const cached = pdfCache.get(lang);
    let sourceUrl = cached;
    if (!sourceUrl) {
      sourceUrl = await preloadPdf(lang);
    }
    if (!sourceUrl) {
      setViewerState("error", "无法加载档案，请稍后重试。");
      return;
    }
    dossierFrame.src = sourceUrl;
    dossierFrame.setAttribute("data-language", lang);
    setViewerState("ready", `${entry.label} dossier ready.`);
  };

  const resetEncryptedModule = () => {
    if (standbyTimerId) {
      window.clearTimeout(standbyTimerId);
      standbyTimerId = null;
    }
    setEncryptedFileState("idle");
    if (statusText) {
      statusText.textContent = "ACCESS RESTRICTED";
    }
    setCommandLabel("INITIATE DECRYPTION SEQUENCE");
    stopRotor();
    if (decryptCommand) {
      decryptCommand.disabled = false;
      decryptCommand.removeAttribute("aria-busy");
    }
    announceStatus("");
    hasAnnouncedProgress = false;
    isDecrypting = false;
    restoreFocusTarget = null;
    if (dossierViewer) {
      setViewerState("idle", "Awaiting dossier request…");
    }
    if (dossierFrame) {
      dossierFrame.removeAttribute("src");
      dossierFrame.removeAttribute("data-language");
    }
  };

  const handleEscapeKey = (event) => {
    if (event.key !== "Escape") return;
    event.preventDefault();
    closeDossier();
  };

  const openDossier = () => {
    if (!dossierOverlay) {
      resetEncryptedModule();
      return;
    }
    dossierOverlay.hidden = false;
    document.body.classList.add("is-dossier-open");
    window.requestAnimationFrame(() => {
      dossierOverlay.classList.add("is-visible");
    });
    restoreFocusTarget = decryptCommand;
    if (dossierCloseButton) {
      dossierCloseButton.focus();
    }
    document.addEventListener("keydown", handleEscapeKey);
  };

  const closeDossier = () => {
    if (!dossierOverlay) {
      resetEncryptedModule();
      return;
    }
    dossierOverlay.classList.remove("is-visible");
    window.setTimeout(() => {
      dossierOverlay.hidden = true;
    }, 320);
    document.body.classList.remove("is-dossier-open");
    document.removeEventListener("keydown", handleEscapeKey);
    const focusTarget = restoreFocusTarget;
    if (focusTarget && typeof focusTarget.focus === "function") {
      window.requestAnimationFrame(() => {
        focusTarget.focus();
      });
    }
    resetEncryptedModule();
  };

  const enterStandby = () => {
    setEncryptedFileState("standby");
  };

  const handleDecryptCommand = async () => {
    if (!encryptedFile || !decryptCommand || isDecrypting) {
      return;
    }
    isDecrypting = true;
    setEncryptedFileState("loading");
    setCommandLabel("DECRYPTING");
    decryptCommand.disabled = true;
    decryptCommand.setAttribute("aria-busy", "true");
    startRotor();
    if (!hasAnnouncedProgress) {
      announceStatus("decrypting in progress");
      window.setTimeout(() => {
        announceStatus("");
      }, 1200);
      hasAnnouncedProgress = true;
    }
    if (statusText) {
      statusText.textContent = "ACCESS RESTRICTED";
    }
    if (standbyTimerId) {
      window.clearTimeout(standbyTimerId);
      standbyTimerId = null;
    }
    standbyTimerId = window.setTimeout(() => {
      enterStandby();
    }, 3000);
    try {
      const preloadPromise = preloadPdf(activeDossierLanguage);
      await wait(320);
      await runDecryptionSequence();
      await preloadPromise;
      await setDossierLanguage(activeDossierLanguage);
      if (standbyTimerId) {
        window.clearTimeout(standbyTimerId);
        standbyTimerId = null;
      }
      setEncryptedFileState("complete");
      stopRotor();
      if (decryptCommand) {
        decryptCommand.removeAttribute("aria-busy");
      }
      openDossier();
    } catch (error) {
      if (standbyTimerId) {
        window.clearTimeout(standbyTimerId);
        standbyTimerId = null;
      }
      setEncryptedFileState("denied");
      if (statusText) {
        statusText.textContent = "ACCESS DENIED";
      }
      stopRotor();
      if (decryptCommand) {
        decryptCommand.removeAttribute("aria-busy");
      }
      await wait(3000);
      resetEncryptedModule();
    }
  };

  if (decryptCommand) {
    decryptCommand.addEventListener("click", handleDecryptCommand);
    decryptCommand.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        handleDecryptCommand();
      }
    });
  }

  if (dossierCloseButton) {
    dossierCloseButton.addEventListener("click", closeDossier);
  }

  dossierLanguageButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const { dossierLang } = button.dataset;
      if (!dossierLang || dossierLang === activeDossierLanguage) {
        return;
      }
      setDossierLanguage(dossierLang);
    });
  });

  updateDownloadLinks(activeDossierLanguage);
  setViewerState("idle", "Awaiting dossier request…");

  const runMapLoadingSequence = () => {
    if (!mapLoading) return;

    const terminalLines = Array.from(
      mapLoading.querySelectorAll(".terminal-line[data-terminal-line]"),
    );
    const cursor = mapLoading.querySelector(".terminal-cursor");
    const progressReadout = mapLoading.querySelector(".progress-readout");
    const progressFill = mapLoading.querySelector(".progress-fill");

    const typeLine = (element, text) =>
      new Promise((resolve) => {
        if (!element) {
          resolve();
          return;
        }
        const characters = Array.from(text);
        let index = 0;
        const stride = Math.max(1, Math.round(characters.length / 22));
        const write = () => {
          if (index > characters.length) {
            const pause = 140 + Math.random() * 220;
            window.setTimeout(resolve, pause);
            return;
          }
          element.textContent = characters.slice(0, index).join("");
          index += stride;
          const delay = 68 + Math.random() * 18;
          window.setTimeout(write, delay);
        };
        write();
      });

    const runTypewriter = () =>
      Promise.all(
        terminalLines.map(async (line, index) => {
          const payload = line.dataset.terminalLine ?? "";
          await wait(index * 140);
          await typeLine(line, payload);
        }),
      );

    const buildProgressBar = (value) => {
      const segments = 12;
      const normalised = clamp(Math.round(value), 0, 100);
      const active = Math.round((normalised / 100) * segments);
      const filled = "█".repeat(active);
      const empty = "▒".repeat(Math.max(segments - active, 0));
      return { normalised, text: `${filled}${empty}` };
    };

    const setProgress = (value) => {
      if (!progressReadout || !progressFill) return;
      const { normalised, text } = buildProgressBar(value);
      progressReadout.textContent = `ЗАГРУЗКА СИСТЕМЫ: ${text} ${normalised}%`;
      const ratio = normalised / 100;
      progressFill.style.setProperty("--progress-ratio", ratio);
    };

    const progressSteps = [
      { value: 62, duration: 360 },
      { value: 18, duration: 160 },
      { value: 84, duration: 420 },
      { value: 37, duration: 180 },
      { value: 96, duration: 360 },
      { value: 100, duration: 520 },
    ];

    const runProgression = () =>
      new Promise((resolve) => {
        if (!progressReadout || !progressFill) {
          resolve();
          return;
        }
        let elapsed = 0;
        progressSteps.forEach((step, index) => {
          elapsed += step.duration;
          window.setTimeout(() => {
            setProgress(step.value + Math.random() * 2);
            if (index === progressSteps.length - 1) {
              window.setTimeout(resolve, 240);
            }
          }, elapsed);
        });
      });

    const toggleCursor = (isActive) => {
      if (!cursor) return;
      cursor.style.visibility = isActive ? "visible" : "hidden";
    };

    setProgress(0);

    const orchestrate = async () => {
      mapLoading.classList.remove("is-reduced");
      await wait(900);
      mapLoading.classList.add("is-terminal");
      toggleCursor(true);
      await runTypewriter();
      toggleCursor(false);
      await wait(220);
      mapLoading.classList.add("is-emblem");
      setProgress(12);
      await wait(200);
      await runProgression();
      await wait(320);
      mapLoading.classList.add("is-complete");
      await wait(900);
      mapLoading.classList.add("is-finished");
    };

    orchestrate();
  };

  if (mapLoading) {
    if (prefersReducedMotion) {
      mapLoading.classList.add("is-reduced", "is-finished");
    } else if (document.readyState === "complete") {
      runMapLoadingSequence();
    } else {
      window.addEventListener("load", runMapLoadingSequence, { once: true });
    }
  }

  const formatCoordinate = (value, type) => {
    const absolute = Math.abs(value).toFixed(3);
    const direction =
      type === "lat" ? (value >= 0 ? "N" : "S") : value >= 0 ? "E" : "W";
    const label = type === "lat" ? "LAT" : "LON";
    return `${label} ${absolute}° ${direction}`;
  };

  const resetCoordinateDisplay = () => {
    if (!coordinateElements) return;
    coordinateElements.lat.textContent = "LAT --° --";
    coordinateElements.lon.textContent = "LON --° --";
  };

  const updateCoordinateDisplay = (coords) => {
    if (!coordinateElements) return;
    if (!coords) {
      resetCoordinateDisplay();
      return;
    }
    const [lat, lon] = coords;
    coordinateElements.lat.textContent = formatCoordinate(lat, "lat");
    coordinateElements.lon.textContent = formatCoordinate(lon, "lon");
  };

  const directivePriorityLabels = {
    high: "高",
    medium: "中",
    low: "低",
  };

  const createDirectiveEntry = (entry, animate) => {
    if (!directiveLog) return null;
    const priority =
      typeof entry?.priority === "string" && entry.priority
        ? entry.priority
        : "medium";

    const listItem = document.createElement("li");
    listItem.className = "directive-entry";
    listItem.dataset.priority = priority;
    if (animate) {
      listItem.classList.add("is-new");
    }

    const content = document.createElement("span");
    content.className = "directive-content";

    const priorityDot = document.createElement("span");
    priorityDot.className = `directive-priority ${priority}`;
    priorityDot.setAttribute("aria-hidden", "true");

    const text = document.createElement("span");
    text.className = "directive-text";

    const directiveMessage = document.createElement("span");
    directiveMessage.className = "directive-message";
    directiveMessage.textContent =
      typeof entry?.message === "string" ? entry.message : "";

    const meta = document.createElement("span");
    meta.className = "directive-meta";

    const directiveAuthor = document.createElement("span");
    directiveAuthor.className = "directive-author";
    directiveAuthor.setAttribute("aria-label", "昵称");
    directiveAuthor.textContent =
      typeof entry?.nickname === "string" ? entry.nickname : "";

    const divider = document.createElement("span");
    divider.className = "directive-divider";
    divider.setAttribute("aria-hidden", "true");
    divider.textContent = "—";

    const timestamp = document.createElement("time");
    timestamp.className = "directive-time";
    if (typeof entry?.time_iso === "string" && entry.time_iso) {
      timestamp.dateTime = entry.time_iso;
    }
    timestamp.textContent =
      typeof entry?.time_label === "string" ? entry.time_label : "";

    meta.append(directiveAuthor, divider, timestamp);
    text.append(directiveMessage, meta);

    const hiddenPriority = document.createElement("span");
    hiddenPriority.className = "visually-hidden";
    const priorityLabel =
      directivePriorityLabels[priority] || directivePriorityLabels.medium;
    hiddenPriority.textContent = `优先级 ${priorityLabel}`;

    content.append(priorityDot, text, hiddenPriority);
    listItem.append(content);

    if (animate) {
      window.setTimeout(() => {
        listItem.classList.remove("is-new");
      }, 700);
    }

    return listItem;
  };

  const prependDirectiveEntry = (entry, animate = false) => {
    if (!directiveLog) return;
    const node = createDirectiveEntry(entry, animate);
    if (!node) return;
    directiveLog.prepend(node);
    const entries = directiveLog.querySelectorAll(".directive-entry");
    if (entries.length > 6) {
      entries[entries.length - 1].remove();
    }
  };

  if (
    directiveForm &&
    directiveLog &&
    directiveNicknameInput &&
    directiveMessageInput &&
    typeof directiveForm.action === "string"
  ) {
    directiveForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const nickname = directiveNicknameInput.value.trim();
      const message = directiveMessageInput.value.trim();
      if (!nickname || !message) {
        if (directiveStatus) {
          directiveStatus.textContent = "请输入昵称和留言";
        }
        directiveMessageInput.focus();
        return;
      }

      const formData = new FormData(directiveForm);
      if (directiveTokenInput && !formData.has("_csrf")) {
        formData.append("_csrf", directiveTokenInput.value);
      }

      try {
        if (directiveStatus) {
          directiveStatus.textContent = "正在提交…";
        }
        const response = await fetch(directiveForm.action, {
          method: "POST",
          body: formData,
          headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        const payload = await response
          .json()
          .catch(() => ({ ok: false }));
        if (!response.ok || !payload?.ok) {
          const errorMessage =
            payload?.error && typeof payload.error === "string"
              ? payload.error
              : "提交失败，请稍后重试";
          throw new Error(errorMessage);
        }

        prependDirectiveEntry(payload.entry, true);
        directiveMessageInput.value = "";
        directiveMessageInput.classList.remove("is-commit");
        window.requestAnimationFrame(() => {
          directiveMessageInput.classList.add("is-commit");
        });
        if (directiveStatus) {
          directiveStatus.textContent = "已记录";
          window.setTimeout(() => {
            if (directiveStatus.textContent === "已记录") {
              directiveStatus.textContent = "";
            }
          }, 2000);
        }
        directiveMessageInput.focus();
      } catch (error) {
        if (directiveStatus) {
          directiveStatus.textContent =
            error instanceof Error
              ? error.message
              : "提交失败，请稍后重试";
        }
      }
    });
  }

  const channelFormatterCache = new Map();
  const getChannelFormatter = (timezone) => {
    if (!timezone) return null;
    if (!channelFormatterCache.has(timezone)) {
      try {
        channelFormatterCache.set(
          timezone,
          new Intl.DateTimeFormat("en-GB", {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hourCycle: "h23",
            timeZone: timezone,
          }),
        );
      } catch (error) {
        return null;
      }
    }
    return channelFormatterCache.get(timezone) || null;
  };

  const updateChannelTimes = () => {
    channelEntries.forEach((entry) => {
      const timezone = entry.dataset.timezone;
      const target = entry.querySelector(".channel-time");
      if (!target) return;
      const formatter = getChannelFormatter(timezone);
      if (!formatter) {
        target.textContent = "--:--:--";
        return;
      }
      target.textContent = formatter.format(new Date());
    });
  };

  if (channelEntries.length) {
    updateChannelTimes();
    window.setInterval(updateChannelTimes, 1000);
  }

  if (scrambleTrack) {
    const duration = Math.max(queueItems.length, 1) * 2.6;
    scrambleTrack.style.setProperty("--scramble-duration", `${duration}s`);
  }

  const initializeMap = () => {
    const mapElement = document.getElementById("map");
    if (!mapElement || typeof L === "undefined") {
      return;
    }

    const worldBounds = [
      [-90, -180],
      [90, 180],
    ];

    const map = L.map(mapElement, {
      worldCopyJump: false,
      zoomControl: false,
      attributionControl: false,
      maxBoundsViscosity: 0.8,
      maxBounds: worldBounds,
      minZoom: 2,
      maxZoom: 8,
    }).setView([20, 0], 2);

    L.tileLayer(
      "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png",
      {
        maxZoom: 19,
        minZoom: 2,
        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors ' +
          '&copy; <a href="https://carto.com/attributions">CARTO</a>',
      },
    ).addTo(map);

    L.tileLayer(
      "https://stamen-tiles.a.ssl.fastly.net/toner-lines/{z}/{x}/{y}.png",
      {
        maxZoom: 18,
        opacity: 0.25,
        attribution:
          'Linework by <a href="http://stamen.com">Stamen Design</a> under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>',
      },
    ).addTo(map);

    L.tileLayer(
      "https://stamen-tiles.a.ssl.fastly.net/toner-labels/{z}/{x}/{y}.png",
      {
        maxZoom: 20,
        attribution:
          'Labels by <a href="http://stamen.com">Stamen Design</a> ' +
          'under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>.' +
          ' Data © <a href="http://openstreetmap.org">OpenStreetMap</a>',
      },
    ).addTo(map);

    const focusMarkerIcon = L.divIcon({
      className: "active-location-marker",
      html: `
        <span class="marker-star" aria-hidden="true">★</span>
      `,
      iconSize: [44, 44],
      iconAnchor: [22, 22],
      tooltipAnchor: [0, -24],
    });

    const focusMarker = L.marker([0, 0], {
      icon: focusMarkerIcon,
      interactive: false,
    });

    const normaliseCoords = (coords) => {
      if (!coords) return null;
      if (Array.isArray(coords) && coords.length >= 2) {
        const [lat, lon] = coords;
        if (Number.isFinite(lat) && Number.isFinite(lon)) {
          return [lat, lon];
        }
        return null;
      }
      if (typeof coords.lat === "number" && typeof coords.lng === "number") {
        return [coords.lat, coords.lng];
      }
      if (typeof coords.lat === "number" && typeof coords.lon === "number") {
        return [coords.lat, coords.lon];
      }
      return null;
    };

    const ensureMarkerVisible = (coords) => {
      if (!coords) return;
      if (!map.hasLayer(focusMarker)) {
        focusMarker.addTo(map);
      }
      focusMarker.setLatLng(coords);
    };

    const pointToLocation = (coords, { shouldFly = false, zoom } = {}) => {
      const target = normaliseCoords(coords);
      if (!target) {
        resetCoordinateDisplay();
        return;
      }
      ensureMarkerVisible(target);
      if (shouldFly) {
        map.flyTo(target, zoom ?? Math.max(map.getZoom(), 4.5), {
          duration: 0.8,
        });
      }
      updateCoordinateDisplay(target);
    };

    const networkNodes = [
      {
        id: "albania",
        label: "ALBANIA",
        coords: [41.1533, 20.1683],
        pulseDelay: 0,
      },
      {
        id: "switzerland",
        label: "SWITZERLAND",
        coords: [46.8182, 8.2275],
        pulseDelay: 0.6,
      },
      {
        id: "beijing",
        label: "BEIJING",
        coords: [39.9042, 116.4074],
        pulseDelay: 1.2,
      },
      {
        id: "shanghai",
        label: "SHANGHAI",
        coords: [31.2304, 121.4737],
        pulseDelay: 1.8,
      },
      {
        id: "dubai",
        label: "DUBAI",
        coords: [25.2048, 55.2708],
        pulseDelay: 2.4,
        zoom: 6,
      },
      {
        id: "huludao",
        label: "HULUDAO",
        coords: [40.709, 120.8377],
        pulseDelay: 3,
      },
      {
        id: "philippines",
        label: "PHILIPPINES",
        coords: [12.8797, 121.774],
        pulseDelay: 3.6,
      },
      {
        id: "zhengzhou",
        label: "ZHENGZHOU",
        coords: [34.7466, 113.6254],
        pulseDelay: 4.2,
      },
      {
        id: "pingdingshan",
        label: "PINGDINGSHAN",
        coords: [33.7665, 113.1927],
        pulseDelay: 4.8,
      },
    ];

    const nodeIcon = (delay = 0) =>
      L.divIcon({
        className: "network-pin",
        html: `
          <span class="pin-tail"></span>
          <span class="pin-core"></span>
          <span class="pin-pulse" style="animation-delay: ${delay}s"></span>
        `,
        iconSize: [28, 28],
        iconAnchor: [14, 26],
        tooltipAnchor: [0, -22],
      });

    networkNodes.forEach((node) => {
      const marker = L.marker(node.coords, { icon: nodeIcon(node.pulseDelay) })
        .addTo(map)
        .bindTooltip(node.label, {
          direction: "top",
          offset: [0, -24],
          className: "network-tooltip",
        });

      marker.on("click", () => {
        pointToLocation(node.coords, { shouldFly: true, zoom: node.zoom ?? 5 });
      });
    });

    const updateCoordinatesFromMap = () => {
      const center = map.getCenter();
      const target = normaliseCoords(center);
      if (!target) {
        resetCoordinateDisplay();
        return;
      }
      ensureMarkerVisible(target);
      updateCoordinateDisplay(target);
    };

    map.whenReady(() => {
      updateCoordinatesFromMap();
    });

    map.on("move", updateCoordinatesFromMap);
    map.on("moveend", updateCoordinatesFromMap);
    map.on("zoomend", updateCoordinatesFromMap);

    map.on("click", (event) => {
      pointToLocation(event.latlng);
    });

    let moveVisualTimeoutId = null;

    map.on("movestart", () => {
      if (moveVisualTimeoutId) {
        window.clearTimeout(moveVisualTimeoutId);
        moveVisualTimeoutId = null;
      }
      if (mapScreen) {
        mapScreen.classList.add("is-moving");
      }
    });

    map.on("moveend", () => {
      if (moveVisualTimeoutId) {
        window.clearTimeout(moveVisualTimeoutId);
      }
      moveVisualTimeoutId = window.setTimeout(() => {
        if (mapScreen) {
          mapScreen.classList.remove("is-moving");
        }
      }, 240);
    });

    map.on("unload", () => {
      if (moveVisualTimeoutId) {
        window.clearTimeout(moveVisualTimeoutId);
        moveVisualTimeoutId = null;
      }
      if (mapScreen) {
        mapScreen.classList.remove("is-moving");
      }
    });
  };

  if (document.readyState === "complete") {
    initializeMap();
  } else {
    window.addEventListener("load", initializeMap, { once: true });
  }
})();
