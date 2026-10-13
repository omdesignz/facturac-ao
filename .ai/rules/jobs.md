---
paths:
  - app/Jobs/GenerateTransactionExport.php
---

# Jobs

## Background Excel exports preserve access and a captured document selection
Capture company-scoped document IDs when the export is requested; queued generation must not recalculate the selection. Use a durable queue (never sync), private local storage shared by web and worker, and requester plus current-company checks on downloads. Recheck active membership before generating and publishing. Publish only completed files, expose safe failure messages, and expire downloads after 24 hours. The worker timeout is 60 seconds; queue retry_after/visibility must exceed it (database default is 90). The default queue worker and scheduled reports:prune-exports command must run in deployment.
