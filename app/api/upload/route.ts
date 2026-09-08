import { put } from '@vercel/blob'
import { NextResponse } from 'next/server'

const MAX_FILE_SIZE = 10 * 1024 * 1024

function isImageFile(file: File) {
  return file.type.startsWith('image/') || /\.(jpe?g|png|webp|gif|heic|heif)$/i.test(file.name)
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

    const extension = file.name.split('.').pop()?.toLowerCase().replace(/[^a-z0-9]/g, '') || 'jpg'
    const blob = await put(`payment-proofs/${crypto.randomUUID()}.${extension}`, file, {
      access: 'public',
      addRandomSuffix: false,
      contentType: file.type,
    })

    return NextResponse.json({ url: blob.url })
  } catch (error) {
    console.error('[v0] Payment screenshot upload failed:', error)
    return NextResponse.json({ error: 'Upload foto a echwe.' }, { status: 500 })
  }
}
