const form = document.querySelector("#topUpForm");
const statusBox = document.querySelector("#statusMessage");
const submitButton = form.querySelector(".btn-submit");
const buttonText = submitButton.querySelector(".button-text");
const copyButton = document.querySelector(".copy-button");

function setStatus(message, type = "") {
  statusBox.className = `status-message ${type}`.trim();
  statusBox.textContent = message;
}

function getFormData() {
  return {
    playerId: form.playerId.value.trim(),
    diamondAmount: form.diamondAmount.value,
    transactionId: form.transactionId.value.trim(),
  };
}

function validateRequest({ playerId, transactionId }) {
  if (!/^\d{6,15}$/.test(playerId)) {
    return "Mete yon ID jwè ki gen ant 6 ak 15 chif.";
  }

  if (!/^[a-zA-Z0-9-]{6,32}$/.test(transactionId)) {
    return "Mete yon ID tranzaksyon Natcash ki valab.";
  }

  return "";
}

copyButton.addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText(copyButton.dataset.copy);
    copyButton.textContent = "Kopye";
    setTimeout(() => {
      copyButton.textContent = "Kopi";
    }, 1400);
  } catch {
    setStatus("Ou ka make nimewo Natcash la manyèlman: 41591807.", "error");
  }
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();

  const payload = getFormData();
  const validationMessage = validateRequest(payload);

  if (validationMessage) {
    setStatus(validationMessage, "error");
    return;
  }

  submitButton.disabled = true;
  buttonText.textContent = "Verifikasyon ap fèt...";
  setStatus("W ap konekte ak sistèm verifikasyon peman an.", "loading");

  try {
    const response = await fetch("/.netlify/functions/verify-and-topup", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
      throw new Error(data.message || "Nou pa rive verifye peman an kounye a.");
    }

    setStatus(data.message, "success");
    form.reset();
  } catch (error) {
    setStatus(error.message, "error");
  } finally {
    submitButton.disabled = false;
    buttonText.textContent = "Valide Peman & Voye Dyaman";
  }
});
