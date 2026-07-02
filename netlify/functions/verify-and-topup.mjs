export default async (request) => {
  if (request.method !== "POST") {
    return Response.json(
      { success: false, message: "Metòd sa a pa sipòte." },
      { status: 405 },
    );
  }

  try {
    const { playerId, diamondAmount, transactionId } = await request.json();
    const validAmounts = new Set(["100", "310", "520", "1060"]);

    if (!/^\d{6,15}$/.test(String(playerId || ""))) {
      return Response.json(
        { success: false, message: "ID jwè a pa valab." },
        { status: 400 },
      );
    }

    if (!validAmounts.has(String(diamondAmount))) {
      return Response.json(
        { success: false, message: "Pakè dyaman sa a pa disponib." },
        { status: 400 },
      );
    }

    if (!/^[a-zA-Z0-9-]{6,32}$/.test(String(transactionId || ""))) {
      return Response.json(
        { success: false, message: "ID tranzaksyon an pa valab." },
        { status: 400 },
      );
    }

    return Response.json({
      success: true,
      message: `Demann lan anrejistre. Lè API Natcash ak founisè top-up la konekte, ${diamondAmount} dyaman yo ap ale sou ID ${playerId}.`,
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
