# VibeStatic Add-on: Azure Blob Storage

Uploads the generated site to an Azure Blob Storage container and removes from
it what has left the site.

## Configuration

| Setting | Notes |
|---|---|
| Storage account name | |
| Account key | From **Access keys** in the portal. Stored encrypted. |
| Container | Leave it as `$web` to publish through the account's static website endpoint |
| Path within the container | Optional |
| Cache-Control header | Optional; stored on each blob |
| Endpoint suffix | Blank for the public cloud; set it for a sovereign cloud |

```bash
wp vibestatic azure options list
```

## Why there is no SDK

The add-on this replaces declared no dependency at all and simply called
`MicrosoftAzure\Storage\Blob\BlobRestProxy`, hoping something else had installed
it. The package it meant, `microsoft/azure-storage-blob`, was retired by
Microsoft in 2024, and its replacement is generated code with a large dependency
tree. The core made the same call for S3 and signs its own requests; this does
the same. The Shared Key signature is an HMAC over a string whose shape Azure
documents, and it is covered by tests — including the two details that are only
ever a silent 403: a zero `Content-Length` signs as an empty line, and the
`x-ms-*` headers sign sorted.

## What this replaces

`wp2static-addon-azure`, April 2019, and **not runnable against any current
version of the core**: `WP2Static_SitePublisher`, `$_POST['ajax_action']`, a
sibling directory called `static-html-output-plugin`, no namespace, no tests,
no CI, no capability checks.

## Not verified

Written to Azure's documented Blob REST API; **no request has been made against
a real storage account**.

## Requirements

VibeStatic 8.1 or later, PHP 8.2, WordPress 6.5. No runtime dependencies.
