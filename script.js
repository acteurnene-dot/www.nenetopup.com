const form = document.querySelector("#payment-form");
const button = document.querySelector("#submit-button");
const status = document.querySelector("#status");

form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }

  const data = new FormData(form);
  const payload = {
    fullName: String(data.get("fullName") || "").trim(),
    phone: String(data.get("phone") || "").trim(),
    transactionId: String(data.get("transactionId") || "").trim(),
    configuration: "Enskripsyon NENE Entrepreneur",
    amount: "1000 GDES",
  };

  button.disabled = true;
  button.textContent = "Nap verifye...";
  status.className = "status";
  status.textContent = "Nap verifye ID tranzaksyon an...";

  try {
    const response = await fetch("/.netlify/functions/verify-and-topup", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || "Verifikasyon an echwe.");

    status.className = "status success";
    status.textContent = "Peman an verifye. WhatsApp ap ouvri ak detay demann ou an pare.";
    const message = [
      "Bonjou NENE Entrepreneur,",
      "Mwen fè peman pou enskripsyon an.",
      `Non: ${payload.fullName}`,
      `Telefòn: ${payload.phone}`,
      `Montan: ${payload.amount}`,
      `ID tranzaksyon: ${payload.transactionId}`,
      "Tanpri verifye epi konfime demann mwen an.",
    ].join("\n");
    window.open(`https://wa.me/50941591807?text=${encodeURIComponent(message)}`, "_blank", "noopener,noreferrer");
    button.textContent = "WhatsApp pare";
  } catch (error) {
    status.className = "status error";
    status.textContent = error.message || "Gen yon pwoblèm rezo. Eseye ankò.";
    button.disabled = false;
    button.textContent = "Verifye peman an";
  }
});
