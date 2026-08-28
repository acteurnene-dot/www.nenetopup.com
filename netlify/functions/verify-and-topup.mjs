const CONFIGURATION = "Enskripsyon NENE Entrepreneur";
const AMOUNT = "1000 GDES";

export default async (request) => {
  if (request.method !== "POST") {
    return Response.json(
      { success: false, message: "Metòd sa a pa sipòte." },
      { status: 405, headers: { Allow: "POST" } },
    );
  }

  try {
    const body = await request.json();
    const fullName = String(body.fullName || "").trim();
    const phone = String(body.phone || "").trim();
    const transactionId = String(body.transactionId || "").trim();
    const configuration = String(body.configuration || "").trim();
    const amount = String(body.amount || "").trim();

    if (fullName.length < 2 || fullName.length > 80) {
      return Response.json({ success: false, message: "Tanpri mete non konplè ou." }, { status: 400 });
    }
    if (!/^\+?[0-9\s-]{8,18}$/.test(phone)) {
      return Response.json({ success: false, message: "Nimewo telefòn nan pa valab." }, { status: 400 });
    }
    if (configuration !== CONFIGURATION || amount !== AMOUNT) {
      return Response.json({ success: false, message: "Konfigurasyon oswa pri a pa valab." }, { status: 400 });
    }
    if (!/^[a-zA-Z0-9-]{6,32}$/.test(transactionId)) {
      return Response.json({ success: false, message: "ID tranzaksyon an pa valab." }, { status: 400 });
    }

    return Response.json({
      success: true,
      message: "ID tranzaksyon an resevwa. WhatsApp ka ouvri pou voye detay yo.",
      configuration: CONFIGURATION,
      amount: AMOUNT,
    });
  } catch {
    return Response.json(
      { success: false, message: "Demann lan pa nan fòma ki valab." },
      { status: 400 },
    );
  }
};

export const config = {
  path: "/.netlify/functions/verify-and-topup",
};
