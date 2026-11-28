# Cloudinary image pipeline

Product and admin images are uploaded to **Cloudinary**, and only the
`secure_url` is saved in the Supabase Postgres database. The API secret never
reaches a browser, an HTML page, a JSON response or a log line.

## Flow

```
Browser (admin panel)
  └─ POST multipart to /api/admin/media/upload   ← file goes to our own server first
       ├─ Auth::requireAdmin('products.manage') + CSRF + rate limit
       ├─ Security::validateUpload()              ← MIME sniff, 5 MB cap, image check
       ├─ CloudinaryService::uploadImage()        ← signed with CLOUDINARY_API_SECRET
       │     POST https://api.cloudinary.com/v1_1/<cloud>/image/upload
       └─ JSON: { provider, images: [{ url: secure_url, public_id, width, height }] }
Admin form
  └─ writes `url` into `gallery_urls` → products/product_images
Database (Supabase Postgres)
  └─ product_images.url = https://res.cloudinary.com/.../image/upload/...
Storefront
  └─ <img src="<secure_url>">  → image served by Cloudinary
```

`UploadService::store()` applies the same routing for every other admin image
field (category, brand, banner, variant, logo, favicon): Cloudinary when
configured, Supabase Storage as a fallback, local disk in development.
Private files (digital downloads, support attachments) always stay in Supabase
Storage — they must never be publicly served.

## Environment variables (Vercel → Settings → Environment Variables)

| Name | Purpose |
|---|---|
| `CLOUDINARY_CLOUD_NAME` | Cloud name |
| `CLOUDINARY_API_KEY` | API key (server-side only) |
| `CLOUDINARY_API_SECRET` | Signs each upload (server-side only) |
| `CLOUDINARY_UPLOAD_FOLDER` | Optional, default `allstore/products` |
| `CLOUDINARY_URL` | Optional single-variable form `cloudinary://key:secret@cloud` |

Add them for **Production** (and Preview if used), then **redeploy** — the
container reads the environment at boot.

## Verify after setting the variables

1. Admin → **Setup guide**: the *Image storage* card must say
   `Cloudinary · <cloud name> · /allstore/products`.
2. Admin → **Products → Edit/Add**: pick an image. The hint shows the upload
   goes through the server API to Cloudinary, a thumbnail appears and the
   gallery textarea receives a `https://res.cloudinary.com/...` URL.
3. Save, then open the product page — the image must load from
   `res.cloudinary.com`, and `product_images.url` must contain that URL.

Error messages are credential-free by design:

| Cloudinary response | Admin message |
|---|---|
| 401 / 403 | "Check `CLOUDINARY_API_KEY` and `CLOUDINARY_API_SECRET` in Vercel." |
| 400 | "Use a JPEG, PNG, WebP or GIF image under 5 MB." |
| 420 / 429 | "Cloudinary rate limit reached." |
| 0 (no route) | "Cloudinary could not be reached from the server." |

## Notes

- Uploads are signed server-side (`timestamp` + `sha1` of the sorted params +
  secret). An unsigned-upload preset is **not** required and is **not** used.
- The browser fallback: if the AJAX call fails, the normal form submit still
  uploads the same file server-side, so an admin is never blocked.
- Existing images stored in Supabase Storage keep working unchanged; only new
  uploads go to Cloudinary.
