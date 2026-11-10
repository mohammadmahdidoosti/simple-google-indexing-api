# SGIA relay

A tiny forwarder for sites whose server Google blocks (HTTP 403 "Your client does not have permission").
The plugin sends its Google API calls here; this service, running on a server Google accepts, passes them on.

- Only `oauth2`, `indexing`, `searchconsole` and `www` `.googleapis.com` are reachable.
- Every call needs the header `X-Relay-Key` equal to the `RELAY_KEY` environment variable (16+ characters).
- Nothing is stored; only one status line per call is logged.

## Deploy (Coolify)

New resource → Public Repository → this repo, base directory `/relay`, build pack Dockerfile, port 3000.
Set the environment variable `RELAY_KEY`, give it an https domain, deploy. `GET /health` should answer `{"ok":true,"configured":true}`.

## Plugin

Settings → Google connection → relay address (`https://your-relay-domain`) and the same key.
