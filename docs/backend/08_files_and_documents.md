# Files and Documents

## Current Status

Laravel local filesystem configuration exists. Record image endpoints remain planned for phase 8.7; document generation remains planned for phase 8.13.

## Private Images

- Store Record images on a dedicated private local disk, separate from public storage and `FILESYSTEM_DISK` selection.
- Store sanitized original filename, content-detected MIME type, byte size, and generated storage reference in the existing `Image` row.
- Accept JPEG, PNG, and WebP up to 10 MiB; validate detected content, size, and association with a Record.
- Generate storage keys with a UUID and an extension derived from detected MIME. Never use the uploaded filename as a path.
- Expose image bytes only through authenticated and authorized Laravel requests. Do not expose physical paths, storage keys, public URLs, or public symlinks.
- Permit `EDITOR` to upload or replace on any non-canceled Record; allow authenticated `EDITOR` and `VIEWER` to view an image on a non-canceled Record.
- Return `404` when a non-canceled Record has no image. Block image access and upload/replacement on `CANCELED` Records with stable `409` errors.
- Reject replacement if the active Image has AIProcessing attempts. This preserves the association between attempts and the bytes processed.
- Store the new file before updating Image metadata transactionally under a Record lock. Remove the new file if database persistence fails; delete the old file only after commit.
- If post-commit deletion of an old file fails, keep the new image active and log the orphan for cleanup; never disclose storage details in the HTTP response.
- Image attachment alone does not create `AIProcessing`, dispatch AI jobs, alter Record source/status, or write History.
- Persist the private storage volume and include it in backup/recovery procedures.

## Generated Documents

Word and Excel files are outputs, not sources of truth. They should be generated from validated database data and returned as temporary output in the MVP rather than stored as delivery versions.

Word generation requires a confirmed Record, valid Delivery preparation, cash reconciliation, and an associated Image.

The phase 8.7 API contract and checkpoint are documented in [`03_api_contract.md`](03_api_contract.md) and the technical roadmap. Until the implementation and tests pass, these image-handling rules describe the target behavior, not a live endpoint.
