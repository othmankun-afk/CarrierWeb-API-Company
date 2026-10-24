# Server configuration

The supplied package defaults to `local-recette`: loopback SQL and a local
PHP HTTP test process. It changes no existing server, domain or API. This
guide explains the settings for a company test host. The public deployment
still needs to be configured and tested on that host.

## External web server mode

`server-recette` requires `httpMode: external-http` and an IT-supplied HTTPS
origin. The collector does not start or control that external web server.
Serve `Code/public/index.php` through an appropriate web server/PHP setup;
all other directories must remain outside the web root. Preserve the public
path `/cweai/{username}/{endpoint}`.

Example bootstrap options, **not production sizing advice**:

```json
{
  "deployment": {
    "mode": "server-recette",
    "publicBaseUrl": "https://api.company.example",
    "httpMode": "external-http",
    "serverAccessEnabled": false,
    "approval": null
  },
  "requestConcurrency": {
    "globalMaxConcurrent": 1,
    "tenantMaxConcurrent": 1,
    "requestTimeoutSeconds": 30
  }
}
```

Server mode requires explicit target limits; the example's 1/1/30 describes
a serialized pilot only. The origin has no path, query or credentials and is
never inferred from Host or X-Forwarded-Host. It generates URL text; it does
not provision DNS, certificates or firewall access.

Set `FLEET_API_CONFIG` to the private HTTP configuration and
`FLEET_API_SERVER_PROFILE` to the private deployment profile. Disable demo
and local-test flags for the public site. Trusted web-server configuration
must supply `HTTPS=on` or `1`. Client-supplied X-Forwarded-Proto is not TLS
evidence; a reverse proxy needs trusted header cleaning and server mapping.

Access stays disabled until `serverAccessEnabled` is true and approval
includes valid `reference`, `approvedBy` and `approvedUtc`. These fields
record the operator's approval. Keep a copy of the previous configuration
before changing it.

## Independent controls

The API enforces per-endpoint minute reservations, response limits and
global/per-tenant slots. Saturated slots are refused without a queue or
database/EAI work. Cooperative deadlines and PHP limits also need external
web-server/worker/connect timeouts for blocking operations. SQL readers are
SELECT-only. The collector reserves EAI attempts separately and does not
retry ambiguous transport automatically.

Locks and rate files coordinate a single host on private local storage.
They are not a multi-server distributed limiter. Neither rate limits nor
response caps bound the total growth of retained history. Measure storage
growth separately and monitor available disk space.

Before offering access to clients, test HTTPS and make sure logs hide URL
passwords. Check file permissions, the Windows identity used for DPAPI,
unattended startup, expected load and backup restoration. SDK licensing and
legacy runtime support also need review. Keep the local PHP development
server private; use a production web server for shared access.
