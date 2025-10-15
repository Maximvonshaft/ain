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

  const mapOverlay = document.querySelector(".map-overlay");
  const networkNodes = [
    {
      id: "albania",
      label: "ALBANIA",
      coords: [41.1533, 20.1683],
    },
    {
      id: "switzerland",
      label: "SWITZERLAND",
      coords: [46.8182, 8.2275],
    },
    {
      id: "beijing",
      label: "BEIJING",
      coords: [39.9042, 116.4074],
    },
    {
      id: "shanghai",
      label: "SHANGHAI",
      coords: [31.2304, 121.4737],
    },
    {
      id: "dubai",
      label: "DUBAI",
      coords: [25.2048, 55.2708],
    },
    {
      id: "huludao",
      label: "HULUDAO",
      coords: [40.709, 120.8377],
    },
    {
      id: "philippines",
      label: "PHILIPPINES",
      coords: [12.8797, 121.774],
    },
    {
      id: "zhengzhou",
      label: "ZHENGZHOU",
      coords: [34.7466, 113.6254],
    },
    {
      id: "pingdingshan",
      label: "PINGDINGSHAN",
      coords: [33.7665, 113.1927],
    },
  ];

  const projectCoordinates = (coords) => {
    if (!Array.isArray(coords) || coords.length < 2) {
      return null;
    }
    const [lat, lon] = coords;
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
      return null;
    }
    const x = ((lon + 180) / 360) * 100;
    const y = ((90 - lat) / 180) * 100;
    return {
      x: clamp(x, 0, 100),
      y: clamp(y, 0, 100),
    };
  };

  const markers = new Map();
  let activeNodeIndex = -1;
  let rotationTimerId = null;
  let targetingTimerId = null;
  const rotationInterval = 7000;

  const setOverlayLabel = (label) => {
    if (!mapOverlay) return;
    mapOverlay.setAttribute("data-active-label", label || "");
  };

  const triggerTargetingPulse = () => {
    if (!mapScreen) return;
    mapScreen.classList.add("is-targeting");
    if (targetingTimerId) {
      window.clearTimeout(targetingTimerId);
    }
    targetingTimerId = window.setTimeout(() => {
      mapScreen.classList.remove("is-targeting");
    }, 900);
  };

  const resetRotation = () => {
    if (rotationTimerId) {
      window.clearInterval(rotationTimerId);
      rotationTimerId = null;
    }
  };

  const startRotation = () => {
    if (!networkNodes.length) {
      return;
    }
    resetRotation();
    rotationTimerId = window.setInterval(() => {
      const nextIndex = (activeNodeIndex + 1) % networkNodes.length;
      setActiveNode(nextIndex);
    }, rotationInterval);
  };

  const setActiveNode = (index, { manual = false } = {}) => {
    if (!networkNodes.length) {
      resetCoordinateDisplay();
      setOverlayLabel("");
      return;
    }

    const boundedIndex = ((index % networkNodes.length) + networkNodes.length) % networkNodes.length;
    if (boundedIndex === activeNodeIndex && !manual) {
      return;
    }

    const previousNode = activeNodeIndex >= 0 ? networkNodes[activeNodeIndex] : null;
    if (previousNode) {
      const previousMarker = markers.get(resolveNodeKey(previousNode, activeNodeIndex));
      if (previousMarker) {
        previousMarker.classList.remove("is-active");
        previousMarker.setAttribute("aria-pressed", "false");
      }
    }

    activeNodeIndex = boundedIndex;
    const node = networkNodes[boundedIndex];
    const marker = markers.get(resolveNodeKey(node, boundedIndex));
    if (marker) {
      marker.classList.add("is-active");
      marker.setAttribute("aria-pressed", "true");
    }

    updateCoordinateDisplay(node.coords);
    setOverlayLabel(node.label || "");
    triggerTargetingPulse();

    if (manual) {
      startRotation();
    }
  };

  const resolveNodeKey = (node, index) => {
    if (node && typeof node.id === "string" && node.id.trim() !== "") {
      return node.id;
    }
    return `node-${index}`;
  };

  const createMarkerElement = (node, index) => {
    const position = projectCoordinates(node.coords);
    if (!position) {
      return null;
    }

    const marker = document.createElement("button");
    marker.type = "button";
    marker.className = "network-marker";
    marker.style.left = `${position.x}%`;
    marker.style.top = `${position.y}%`;
    const nodeKey = resolveNodeKey(node, index);
    marker.dataset.nodeId = nodeKey;
    marker.setAttribute("aria-label", `${node.label ?? "Network node"} node`);
    marker.setAttribute("aria-pressed", "false");
    marker.title = node.label || "";

    const activate = () => {
      setActiveNode(index, { manual: true });
    };

    marker.addEventListener("click", activate);
    marker.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        activate();
      }
    });

    return marker;
  };

  const buildNetworkLayer = (container) => {
    markers.clear();
    networkNodes.forEach((node, index) => {
      const marker = createMarkerElement(node, index);
      if (!marker) {
        return;
      }
      container.append(marker);
      markers.set(resolveNodeKey(node, index), marker);
    });
  };

  const initializeNetworkDisplay = () => {
    const mapElement = document.getElementById("map");
    if (!mapElement) {
      return;
    }

    mapElement.textContent = "";
    setOverlayLabel("");
    const layer = document.createElement("div");
    layer.className = "map-network-layer";
    mapElement.append(layer);
    buildNetworkLayer(layer);

    if (networkNodes.length) {
      setActiveNode(0);
      startRotation();
    } else {
      resetCoordinateDisplay();
    }
  };

  if (document.readyState === "complete") {
    initializeNetworkDisplay();
  } else {
    window.addEventListener("load", initializeNetworkDisplay, { once: true });
  }
})();
