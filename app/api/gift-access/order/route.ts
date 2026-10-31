import crypto from 'node:crypto'
import { NextResponse } from 'next/server'

export const runtime = 'nodejs'

const GIFT_ACCESS_BASE_URL = 'https://portal.gift-access.com/api/v1'
const GIFT_ACCESS_PRODUCT_ID = '58462'

function getSignature(timestamp: string, method: string, path: string, body: string, secret: string) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}${method}${path}${body}`).digest('hex')
}

function findPlayerName(value: unknown): string | null {
  if (!value || typeof value !== 'object') return null
  const record = value as Record<string, unknown>
  const basicInfo = record.basicInfo
  if (basicInfo && typeof basicInfo === 'object') {
    const nickname = (basicInfo as Record<string, unknown>).nickname
    if (typeof nickname === 'string' && nickname.trim()) return nickname.trim()
  }
  for (const key of ['player_name', 'playerName', 'nickname', 'username', 'ign', 'name']) {
    if (typeof record[key] === 'string' && record[key].trim()) return record[key].trim()
  }
  for (const key of ['player', 'profile', 'lookup', 'result', 'data', 'account']) {
    const name = findPlayerName(record[key])
    if (name) return name
  }
  return null
}

async function giftAccessRequest(path: string, method: 'GET' | 'POST', body: string, apiKey: string, apiSecret: string, extraHeaders: Record<string, string> = {}) {
  const timestamp = Math.floor(Date.now() / 1000).toString()
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
        'X-API-SIGNATURE': getSignature(timestamp, method, path, body, apiSecret),
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
  const apiKey = process.env.Secret_2
  const apiSecret = process.env.Secret_3

  if (!apiKey || !apiSecret) {
    return NextResponse.json({ error: 'Gift Access API pa konfigire sou sèvè a.' }, { status: 503 })
  }

  try {
    const input = (await request.json()) as { action?: string; playerId?: string }
    if (input.action === 'lookup') {
      const playerId = input.playerId?.trim() || ''
      if (!/^\d{6,15}$/.test(playerId)) return NextResponse.json({ error: 'ID Free Fire la pa valab.' }, { status: 400 })
      const path = `/products/${GIFT_ACCESS_PRODUCT_ID}/player/lookup`
      const body = JSON.stringify({ fields: { userid: playerId } })
      const response = await giftAccessRequest(path, 'POST', body, apiKey, apiSecret)
      const result = await response.json().catch(() => ({})) as Record<string, unknown>
      if (!response.ok) return NextResponse.json({ error: 'ID Free Fire la pa jwenn oswa li pa valid.' }, { status: 422 })
      const playerName = findPlayerName(result)
      const account = result.basicInfo && typeof result.basicInfo === 'object' ? result.basicInfo : result.player ?? result.data ?? result
      if (!playerName) return NextResponse.json({ error: 'UID a pa jwenn oswa API a pa retounen non kont lan.' }, { status: 422 })
      return NextResponse.json({ verified: true, player: { name: playerName, account } })
    }
    } catch (error) {
      const message = error instanceof DOMException && error.name === 'AbortError'
        ? 'API Gift Access la pran twòp tan. Eseye ankò.'
        : 'Verifikasyon ID a pa disponib kounye a.'
      console.error('[v0] Gift Access UID lookup failed:', error)
      return NextResponse.json({ error: message }, { status: 502 })
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
      fields: { userid: playerId },
    })
    const response = await giftAccessRequest(path, 'POST', body, apiKey, apiSecret, {
      'Idempotency-Key': reference,
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
