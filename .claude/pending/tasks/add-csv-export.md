---
id: 1
title: Add CSV export to the invoice list
status: actionable
priority: 3
touched: 2026-10-24
deploy: pending
refs: .claude/example-billing.md
---
State: built and tested on dev; precheck PASSED.
Next: owner deploys (manifest below).

```
DEPLOY → prod, in this order:
  1. src/Billing/InvoiceExport.php
  2. src/Billing/Views/invoice_list.php   (calls step 1 — must follow it)
```
