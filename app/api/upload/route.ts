import { put } from '@vercel/blob'
import { NextResponse } from 'next/server'

export const runtime = 'nodejs'
export const maxDuration = 60

const MAX_FILE_SIZE = 10 * 1024 * 1024

function getImageContentType(file: File) {
  if (file.type.startsWith('image/')) return file.type
  const extension = file.name.split('.').pop()?.toLowerCase()
  const types: Record<string, string> = {
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp',
    gif: 'image/gif',
    heic: 'image/heic',
    heif: 'image/heif',
  }
  return extension ? types[extension] : undefined
} 

function isImageFile(file: File) {
  return Boolean(getImageContentType(file))
}

export async function POST(request: Request) {
  try {
    const formData = await request.formData()
    const file = formData.get('file')

    if (!(file instanceof File)) {
      return NextResponse.json({ error: 'Pa gen fichye.' }, { status: 400 })
    }
    if (!isImageFile(file)) {
      return NextResponse.json({ error: 'Chwazi yon imaj JPG, PNG, WEBP oswa HEIC.' }, { status: 400 })
    }
    if (file.size > MAX_FILE_SIZE) {
      return NextResponse.json({ error: 'Foto a twò gwo. Maksimòm 10 MB.' }, { status: 400 })
    }

    const contentType = getImageContentType(file) || 'image/jpeg'
    const extension = contentType === 'image/jpeg' ? 'jpg' : contentType.split('/')[1] || 'jpg'
    const blob = await put(`payment-proofs/${crypto.randomUUID()}.${extension}`, file, {
      access: 'public',
      addRandomSuffix: false,
      contentType,
    })

    return NextResponse.json({ url: blob.url })
  } catch (error) {
    console.error('[v0] Payment screenshot upload failed:', error)
    return NextResponse.json({ error: 'Upload foto a echwe.' }, { status: 500 })
  }
}
