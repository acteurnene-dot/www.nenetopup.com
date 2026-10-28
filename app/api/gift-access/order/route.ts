import crypto from 'node:crypto'
import { NextResponse } from 'next/server'

export const runtime = 'nodejs'

const GIFT_ACCESS_BASE_URL = 'https://portal.gift-access.com/api/v1'
const GIFT_ACCESS_PRODUCT_ID = '58462'

function getSignature(timestamp: string, method: string, path: string, body: string, secret: string) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}${method}${path}${body}`).digest('hex')
}

export async function POST(request: Request) {
  const apiKey = process.env.Secret_2
  const apiSecret = process.env.Secret_3

  if (!apiKey || !apiSecret) {
    return NextResponse.json({ error: 'Gift Access API pa konfigire sou sèvè a.' }, { status: 503 })
  }

  try {
    const input = (await request.json()) as { playerId?: string; reference?: string }
    const playerId = input.playerId?.trim() || ''
    const reference = input.reference?.trim() || `NENE-${crypto.randomUUID()}`

    if (!/^\d{6,15}$/.test(playerId)) {
      return NextResponse.json({ error: 'ID Free Fire la pa valab.' }, { status: 400 })
    }

    const path = '/orders'
    const body = JSON.stringify({
      product_id: GIFT_ACCESS_PRODUCT_ID,
      quantity: 1,
      reference,
      fields: { player_id: playerId },
    })
    const timestamp = Math.floor(Date.now() / 1000).toString()
    const response = await fetch(`${GIFT_ACCESS_BASE_URL}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-KEY': apiKey,
        'X-API-TIMESTAMP': timestamp,
        'X-API-SIGNATURE': getSignature(timestamp, 'POST', path, body, apiSecret),
      },
      body,
      cache: 'no-store',
    })

    const responseText = await response.text()
    let result: unknown = {}
    try {
      result = JSON.parse(responseText)
    } catch {
      result = { message: responseText.slice(0, 300) }
    }

    if (!response.ok) {
      console.error('[v0] Gift Access order failed:', response.status, result)
      return NextResponse.json({ error: 'Top up la pa pase. Verifye peman an oswa ID a.' }, { status: 502 })
    }

    return NextResponse.json({ success: true, order: result })
  } catch (error) {
    console.error('[v0] Gift Access request failed:', error)
    return NextResponse.json({ error: 'Sèvis top up la pa disponib kounye a.' }, { status: 502 })
  }
}
