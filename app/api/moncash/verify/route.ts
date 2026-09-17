import { NextRequest, NextResponse } from 'next/server'

const MONCASH_API_BASE = process.env.MONCASH_API_BASE_URL || 'https://api.moncashpayment.net'

function isSuccessfulStatus(status: unknown) {
  return ['successful', 'success', 'completed', 'paid', 'approved'].includes(String(status ?? '').toLowerCase())
}

export async function POST(request: NextRequest) {
  try {
    const { transactionId, expectedAmount } = await request.json()
    const clientId = process.env.MONCASH_CLIENT_ID || '6be0d48a6bbad7863cd2b34d2a628d36'
    const clientSecret = process.env.Secret_3

    if (!clientSecret) {
      return NextResponse.json({ error: 'MonCash API a pa konfigire.' }, { status: 503 })
    }
    if (!transactionId || !/^\d{6,32}$/.test(String(transactionId).trim())) {
      return NextResponse.json({ error: 'Antre yon transaction code MonCash ki valab.' }, { status: 400 })
    }

    const credentials = Buffer.from(`${clientId}:${clientSecret}`).toString('base64')
    const tokenResponse = await fetch(`${MONCASH_API_BASE}/oauth/token`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Basic ${credentials}`,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'scope=read%2Cwrite&grant_type=client_credentials',
      cache: 'no-store',
    })
    if (!tokenResponse.ok) return NextResponse.json({ error: 'MonCash pa valide aksè API a.' }, { status: 502 })

    const token = (await tokenResponse.json()) as { access_token?: string }
    if (!token.access_token) return NextResponse.json({ error: 'MonCash pa retounen token API a.' }, { status: 502 })

    const paymentResponse = await fetch(`${MONCASH_API_BASE}/v1/RetrieveTransactionPayment`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token.access_token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ transactionId: String(transactionId).trim() }),
      cache: 'no-store',
    })
    const payment = (await paymentResponse.json().catch(() => null)) as Record<string, unknown> | null
    if (!paymentResponse.ok || !payment) return NextResponse.json({ error: 'MonCash pa jwenn transaction sa a.' }, { status: 402 })

    const status = payment.status ?? payment.transactionStatus ?? payment.paymentStatus
    const amount = Number(payment.amount ?? payment.transactionAmount ?? payment.totalAmount)
    const amountMatches = Number.isFinite(amount) && amount === Number(expectedAmount)
    if (!isSuccessfulStatus(status) || !amountMatches) {
      return NextResponse.json({ error: 'Transaction nan pa konfime oswa montan an pa koresponn.' }, { status: 402 })
    }

    return NextResponse.json({ verified: true })
  } catch (error) {
    console.error('[v0] MonCash verification failed:', error)
    return NextResponse.json({ error: 'Verifikasyon MonCash echwe. Eseye ankò.' }, { status: 502 })
  }
}
