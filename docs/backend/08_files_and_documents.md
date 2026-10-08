# Files and Documents

## Current Status

Laravel local filesystem configuration exists. TPVS image handling and document generation are planned.

## Private Images

- Supplier images must remain private.
- Store file metadata and a storage reference in PostgreSQL.
- Validate MIME type, size, and association with a Record.
- Do not expose physical paths to clients.
- Serve images only through authorized Laravel requests.
- Replacing an image changes the active workflow reference according to the global storage rules.

## Generated Documents

Word and Excel files are outputs, not sources of truth. They should be generated from validated database data and returned as temporary output in the MVP rather than stored as delivery versions.

Word generation requires a confirmed Record, valid Delivery preparation, cash reconciliation, and an associated Image.
