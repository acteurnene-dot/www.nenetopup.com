import crypto from 'node:crypto'
import { NextResponse } from 'next/server'

export const runtime = 'nodejs'

const GIFT_ACCESS_BASE_URL = 'https://portal.gift-access.com/api/v1'
const GIFT_ACCESS_PRODUCT_ID = '58462'

function getSignature(timestamp: string, method: string, path: string, body: string, nonce: string, secret: string) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}${method}${path}${body}${nonce}`).digest('hex')
}

async function giftAccessRequest(path: string, method: 'GET' | 'POST', body: string, apiKey: string, apiSecret: string, extraHeaders: Record<string, string> = {}) {
  const timestamp = Math.floor(Date.now() / 1000).toString()
  const signedPath = `/api/v1${path}`
  const nonce = crypto.randomUUID()
  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), 10000)
  try {
    return await fetch(`${GIFT_ACCESS_BASE_URL}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-API-KEY': apiKey,
        'X-API-TIMESTAMP': timestamp,
        'X-API-NONCE': nonce,
        'X-API-SIGNATURE': getSignature(timestamp, method, signedPath, body, nonce, apiSecret),
        ...extraHeaders,
      },
      ...(body ? { body } : {}),
      cache: 'no-store',
      signal: controller.signal,
    })
  } finally {
    clearTimeout(timeout)
  }
}

export async function POST(request: Request) {
  const apiKey = process.env.API_KEY
  const apiSecret = process.env['gasec_a6DD3d4bxFSXtGqjZz6dgDJYuUVGiWHzVjmyWEv4aKpdHw3JspfnaF']

  if (!apiKey || !apiSecret) {
    return NextResponse.json({ error: 'Gift Access API credentials yo pa konfigire sou sèvè a.' }, { status: 503 })
  }

  try {
    const input = (await request.json()) as { action?: string; playerId?: string; reference?: string }
    const playerId = input.playerId?.trim() || ''

    if (!/^\d{6,15}$/.test(playerId)) {
      return NextResponse.json({ error: 'ID Free Fire la pa valab.' }, { status: 400 })
    }

    const reference = input.reference?.trim() || `NENE-${crypto.randomUUID()}`
    const body = JSON.stringify({ product_id: GIFT_ACCESS_PRODUCT_ID, quantity: 1, reference, fields: { userid: playerId } })
    const response = await giftAccessRequest('/orders', 'POST', body, apiKey, apiSecret, { 'Idempotency-Key': reference })
    const responseText = await response.text()
    const result = (() => { try { return JSON.parse(responseText) } catch { return { message: responseText.slice(0, 300) } } })()

    if (!response.ok) return NextResponse.json({ error: 'Top up la pa pase. Verifye peman an oswa ID a.' }, { status: 502 })
    return NextResponse.json({ success: true, order: result })
  } catch (error) {
    const message = error instanceof DOMException && error.name === 'AbortError' ? 'API Gift Access la pran twòp tan. Eseye ankò.' : 'Sèvis top up la pa disponib kounye a.'
    console.error('[v0] Gift Access request failed:', error)
    return NextResponse.json({ error: message }, { status: 502 })
  }
}
